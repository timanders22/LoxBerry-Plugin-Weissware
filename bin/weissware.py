#!REPLACELBPBINDIR/venv/bin/python3
"""Weissware Cloud - Abrufdienst fuer LoxBerry.

Bindet vernetzte Hausgeraete dreier Oekosysteme an Loxone an und bringt sie auf
EIN gemeinsames Modell:

    Home Connect   Bosch, Siemens, Neff, Gaggenau, Constructa
    Miele          Miele@home ueber die 3rd-Party-API
    SmartThings    Samsung (mit Vorbehalt, siehe unten)

Warum ohne fertige Bibliothek: Die verbreiteten Pakete verlangen ein Python,
das es auf keinem LoxBerry gibt - aiohomeconnect ab 0.31 verlangt 3.13,
pymiele ab 0.6.2 sogar 3.14; Debian 12 liefert 3.11, Debian 13 liefert 3.13.
Alle drei Schnittstellen sind dokumentierte OAuth2-REST-Schnittstellen; der
eigene Zugriff laeuft ab Python 3.9, reicht jeden Rohwert unveraendert weiter
und laesst Token-Erneuerung und Takt selbst steuern.

Herkunft der Angaben: Endpunkte, Datenfelder und Antwortformen stammen aus den
Entwicklerdokumentationen (Home Connect api-docs.home-connect.com, Miele
developer.miele.com, SmartThings developer.smartthings.com), abgerufen am
06.08.2026. Sie sind NICHT an einem Geraet nachgemessen - wo geraten werden
musste, steht es im Kommentar.

Drei Aufgaben, drei Dateien - dieses Skript ist der Dienst. Die Oberflaeche
(webfrontend/htmlauth/index.php) und der Miniserver-Endpunkt
(webfrontend/html/index.php) rufen es nie direkt auf, sondern lesen den
Zwischenspeicher beziehungsweise legen Befehle ab.

Aufrufe:
    weissware.py                 Dienst (Dauerbetrieb)
    weissware.py --einmal        ein einzelner Abruf, dann Ende
    weissware.py --selbsttest    Pruefungen ohne Netz, Ausgabe als Klartext
"""

from __future__ import annotations

import json
import logging
import os
import signal
import socket
import sys
import time
from logging.handlers import RotatingFileHandler
from pathlib import Path


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def mqtt_wert_saeubern(wert):
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest zeilenweise. Ein Zeilenumbruch im Wert zerlegt die
    Uebertragung, und aus den Bruchstuecken bildet das Gateway erfundene
    Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und Wert
    trennt.
    """
    text = str(wert)
    for zeichen in ("\r\n", "\r", "\n", "\t"):
        text = text.replace(zeichen, " ")
    while "  " in text:
        text = text.replace("  ", " ")
    return text.strip()


# ---------------------------------------------------------------------------
# Pfade aus dem EIGENEN Ablageort ableiten.
#
# Nicht ueber LoxBerry::System: das leitet den Pluginordner aus dem Aufrufort
# ab und liefert bei einem Start aus postinstall.sh oder aus dem Cron ueberall
# Leerstring.
# ---------------------------------------------------------------------------
SELF = Path(__file__).resolve().parent            # <home>/bin/plugins/<ordner>
PNAME = SELF.name
if len(SELF.parents) >= 3:
    LBHOME = SELF.parents[2]
else:
    LBHOME = Path(os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln())
PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "weissware.json"
DATEI_ZUGANG = PCONFIG / "zugang.json"
DATEI_TOKEN = PDATA / "token.json"
DATEI_CACHE = PDATA / "cache.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_ZUSTAND = PDATA / "zustand.json"
ORDNER_BEFEHLE = PDATA / "befehle"
ORDNER_ANTWORTEN = PDATA / "antworten"
DATEI_LOG = PLOG / "weissware.log"

ANBIETER = ("homeconnect", "miele", "smartthings")

# Muessen zu ww_vorgaben() in webfrontend/html/ww_lib.php passen.
VORGABEN = {
    "takt_ruhe": 300,          # Abstand, wenn kein Geraet laeuft
    "takt_betrieb": 60,        # Abstand, solange mindestens eines laeuft
    "mqtt_ein": 0,
    "mqtt_topic": "weissware",
    "steuerung_ein": 0,
    "hc_ein": 0,
    "hc_simulator": 0,
    "miele_ein": 0,
    "st_ein": 0,
    "sprache": "de-DE",
}

# Basisadressen. An einer Stelle, damit sie sich nachziehen lassen, wenn ein
# Anbieter umzieht.
HC_HOST = "https://api.home-connect.com"
HC_HOST_SIM = "https://simulator.home-connect.com"
MIELE_HOST = "https://api.mcs3.miele.com"
ST_HOST = "https://api.smartthings.com/v1"

# Home Connect: Umfang der Berechtigung. IdentifyAppliance ist Pflicht;
# Monitor liest, Settings und Control schreiben.
HC_SCOPE = "IdentifyAppliance Monitor Settings Control"

# ---------------------------------------------------------------------------
# Das gemeinsame Geraetemodell
#
# Der ganze Zweck dieses Plugins: drei Oekosysteme, EIN Satz Felder. Fuer
# Loxone sieht ein Miele-Geschirrspueler genauso aus wie eine Bosch-
# Waschmaschine.
#
# Zustandsstufen - bewusst grob, weil jeder Hersteller sie anders benennt:
#     0 aus        Geraet ist ausgeschaltet oder im Standby
#     1 bereit     eingeschaltet, laeuft aber nicht
#     2 laeuft     Programm laeuft
#     3 pausiert   Programm angehalten
#     4 fertig     Programm beendet, noch nicht quittiert
#     5 stoerung   Fehler gemeldet
#     None         unbekannt - am Endpunkt ein Strich, NIE eine 0
# ---------------------------------------------------------------------------
AUS, BEREIT, LAEUFT, PAUSIERT, FERTIG, STOERUNG = 0, 1, 2, 3, 4, 5

ZUSTAND_TEXT = {
    AUS: "aus", BEREIT: "bereit", LAEUFT: "laeuft", PAUSIERT: "pausiert",
    FERTIG: "fertig", STOERUNG: "stoerung",
}

# Home Connect: BSH.Common.EnumType.OperationState.* -> Stufe
HC_ZUSTAND = {
    "Inactive": AUS, "Ready": BEREIT, "DelayedStart": BEREIT, "Run": LAEUFT,
    "Pause": PAUSIERT, "ActionRequired": PAUSIERT, "Finished": FERTIG,
    "Error": STOERUNG, "Aborting": LAEUFT,
}

# Miele: status.value_raw -> Stufe. Zahlen aus der Miele-Dokumentation
# (Device capabilities, Abschnitt "status").
MIELE_ZUSTAND = {
    1: AUS,        # OFF
    2: BEREIT,     # ON / STAND_BY
    3: BEREIT,     # PROGRAMMED
    4: BEREIT,     # WAITING_TO_START
    5: LAEUFT,     # RUNNING
    6: PAUSIERT,   # PAUSE
    7: FERTIG,     # END_PROGRAMMED
    8: FERTIG,     # FAILURE -> siehe unten, wird zu STOERUNG korrigiert
    9: FERTIG,     # PROGRAMME_INTERRUPTED
    10: BEREIT,    # IDLE
    11: LAEUFT,    # RINSE_HOLD
    12: LAEUFT,    # SERVICE
    13: LAEUFT,    # SUPERFREEZING
    14: LAEUFT,    # SUPERCOOLING
    15: LAEUFT,    # SUPERHEATING
    144: BEREIT,   # DEFAULT
    145: LAEUFT,   # LOCKED
    146: AUS,      # SUPERCOOLING_SUPERFREEZING
}
MIELE_ZUSTAND[8] = STOERUNG   # FAILURE ist eine Stoerung, kein Programmende

# SmartThings: der Zustand steckt je nach Geraet in einem anderen
# Capability-Attribut. Diese drei decken Waschmaschine, Trockner und
# Geschirrspueler ab.
ST_ZUSTAND = {
    "stop": BEREIT, "ready": BEREIT, "run": LAEUFT, "running": LAEUFT,
    "pause": PAUSIERT, "paused": PAUSIERT, "finish": FERTIG, "finished": FERTIG,
    "error": STOERUNG, "power off": AUS, "off": AUS, "on": BEREIT,
}

# Miele: -32768 heisst laut Dokumentation ausdruecklich "kein aktueller Wert",
# null heisst "kann das Geraet nicht". Beides wird zu None - eine 0 waere hier
# eine besonders heimtueckische Falschaussage (0 Minuten Restzeit = fertig).
MIELE_LEER = -32768

_LAUF = True
_LOG = logging.getLogger("weissware")
_LETZTE_MELDUNG: dict[str, float] = {}


# ---------------------------------------------------------------------------
# Protokollierung
# ---------------------------------------------------------------------------
def log_einrichten() -> None:
    PLOG.mkdir(parents=True, exist_ok=True)
    _LOG.setLevel(logging.INFO)
    try:
        h = RotatingFileHandler(DATEI_LOG, maxBytes=512000, backupCount=1, encoding="utf-8")
    except OSError as err:
        h = logging.StreamHandler(sys.stderr)
        print(f"Logdatei nicht beschreibbar ({err}) - schreibe nach stderr.", file=sys.stderr)
    h.setFormatter(logging.Formatter("[%(asctime)s] %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S"))
    _LOG.handlers = [h]
    _LOG.propagate = False
    for fremd in ("requests", "urllib3"):
        logging.getLogger(fremd).setLevel(logging.WARNING)


def melde_gebremst(schluessel: str, text: str, sekunden: int = 3600) -> None:
    """Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
    Logdatei durch eine Dauerstoerung unlesbar."""
    jetzt = time.time()
    if jetzt - _LETZTE_MELDUNG.get(schluessel, 0) >= sekunden:
        _LETZTE_MELDUNG[schluessel] = jetzt
        _LOG.warning(text)


# ---------------------------------------------------------------------------
# Dateien
# ---------------------------------------------------------------------------
def json_lesen(pfad: Path) -> dict:
    try:
        with pfad.open("r", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def json_schreiben(pfad: Path, daten, rechte: int | None = None) -> bool:
    """Erst in eine Nebendatei, dann umbenennen. So liest die Oberflaeche nie
    eine halb geschriebene Datei."""
    try:
        pfad.parent.mkdir(parents=True, exist_ok=True)
        tmp = pfad.with_suffix(pfad.suffix + ".tmp")
        # Die Rechte gehoeren an das ANLEGEN, nicht hinterher.
        #
        # Bis 0.9.0 wurde die Nebendatei mit tmp.open("w") angelegt und erst
        # nach dem Schreiben auf 0600 gesetzt. Nachgemessen mit umask 022:
        #     bisher: waehrend des Schreibens 0644, danach 0600
        #     jetzt:  waehrend des Schreibens 0600, danach 0600
        # In token.json steht ein gueltiger Zugang zu drei Herstellerclouds.
        # Ein Zeitfenster, in dem ihn jeder Systembenutzer lesen kann, ist
        # kurz - aber es gibt keinen Grund, es offen zu lassen.
        fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC,
                     rechte if rechte is not None else 0o644)
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(daten, f, ensure_ascii=False, indent=1, default=str)
        if rechte is not None:
            # Falls die Datei schon bestand: O_CREAT setzt die Rechte dann
            # nicht, deshalb hier noch einmal ausdruecklich.
            os.chmod(tmp, rechte)
        os.replace(tmp, pfad)
        return True
    except (OSError, TypeError, ValueError) as err:
        _LOG.error("Datei %s konnte nicht geschrieben werden: %s", pfad, err)
        return False


def ganz(wert, ersatz: int) -> int:
    try:
        return int(wert)
    except (TypeError, ValueError):
        return ersatz


def config() -> dict:
    c = dict(VORGABEN)
    c.update(json_lesen(DATEI_CONFIG))
    # Home Connect nennt in den Best Practices ausdruecklich, dass haeufiges
    # Abfragen zu HTTP 429 fuehrt. 60 s ist die Untergrenze, die dieses Plugin
    # zulaesst - darunter faengt man sich Sperren ein.
    c["takt_betrieb"] = max(60, min(3600, ganz(c.get("takt_betrieb"), 60)))
    c["takt_ruhe"] = max(60, min(7200, ganz(c.get("takt_ruhe"), 300)))
    if c["takt_ruhe"] < c["takt_betrieb"]:
        c["takt_ruhe"] = c["takt_betrieb"]
    if str(c.get("sprache") or "")[:2] not in ("de", "en"):
        c["sprache"] = "de-DE"
    return c


def zugang() -> dict:
    z = json_lesen(DATEI_ZUGANG)
    return {
        "hc_client_id": str(z.get("hc_client_id") or "").strip(),
        "hc_client_secret": str(z.get("hc_client_secret") or "").strip(),
        "miele_client_id": str(z.get("miele_client_id") or "").strip(),
        "miele_client_secret": str(z.get("miele_client_secret") or "").strip(),
        "st_token": str(z.get("st_token") or "").strip(),
    }


def token_lesen() -> dict:
    return json_lesen(DATEI_TOKEN)


def token_schreiben(daten: dict) -> None:
    json_schreiben(DATEI_TOKEN, daten, rechte=0o600)


# ---------------------------------------------------------------------------
# MQTT ueber das LoxBerry-Gateway
#
# Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
# Mqtt.Brokerhost ist ab Werk gesetzt - massgeblich ist Gatewayautostart.
# ---------------------------------------------------------------------------
def mqtt_zustand() -> dict:
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}
    autostart = m.get("Gatewayautostart", m.get("gatewayautostart"))
    try:
        udp = int(m.get("Udpinport", m.get("udpinport")))
    except (TypeError, ValueError):
        udp = 0
    return {
        "gefunden": bool(m),
        "autostart": 1 if str(autostart) in ("1", "true", "True") else 0,
        "udpport": udp,
        "broker": str(m.get("Brokerhost", m.get("brokerhost", ""))),
        "brokerport": str(m.get("Brokerport", m.get("brokerport", ""))),
    }


def mqtt_senden(paare: dict, praefix: str) -> None:
    z = mqtt_zustand()
    if not z["udpport"]:
        melde_gebremst("mqtt_kein_port",
                       "MQTT: kein UDP-Eingangsport in general.json gefunden - nichts gesendet.")
        return
    if not z["autostart"]:
        melde_gebremst("mqtt_aus",
                       "MQTT: das Gateway ist nicht auf Autostart gestellt "
                       "(System -> MQTT Gateway). Es wird gesendet, aber vermutlich "
                       "hoert niemand zu.")
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        melde_gebremst("mqtt_socket", f"MQTT: Socket nicht moeglich ({err}).")
        return
    try:
        for k, v in paare.items():
            if v is None:
                continue
            s.sendto(f"publish {praefix}/{k} {mqtt_wert_saeubern(v)}".encode("utf-8"), ("127.0.0.1", z["udpport"]))
    except OSError as err:
        melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
    finally:
        s.close()


# ---------------------------------------------------------------------------
# Hilfsfunktionen
# ---------------------------------------------------------------------------
def zahl(wert, nachkomma: int | None = 0):
    """Zahl oder None. Nie 0 als Ersatz fuer 'weiss nicht'."""
    if wert is None or wert == "" or wert is True or wert is False:
        return None if not isinstance(wert, bool) else (1 if wert else 0)
    try:
        f = float(str(wert).replace(",", "."))
    except (TypeError, ValueError):
        return None
    if nachkomma is None:
        return f
    return round(f, nachkomma) if nachkomma else int(round(f))


def miele_zahl(wert, nachkomma: int = 0):
    """Miele-Zahl. null = kann das Geraet nicht, -32768 = gerade kein Wert."""
    if wert is None:
        return None
    try:
        f = float(wert)
    except (TypeError, ValueError):
        return None
    if int(f) == MIELE_LEER:
        return None
    return round(f, nachkomma) if nachkomma else int(round(f))


def miele_dauer(paar) -> int | None:
    """Miele fuehrt Zeiten als [Stunden, Minuten]."""
    if not isinstance(paar, (list, tuple)) or len(paar) < 2:
        return None
    h, m = miele_zahl(paar[0]), miele_zahl(paar[1])
    if h is None and m is None:
        return None
    return (h or 0) * 60 + (m or 0)


def hc_kurz(wert) -> str:
    """Aus 'BSH.Common.EnumType.OperationState.Run' wird 'Run'."""
    s = str(wert or "")
    return s.rsplit(".", 1)[-1] if "." in s else s


def kopfzeilen(token: str, sprache: str = "") -> dict:
    """Vor mancher Schnittstelle sitzt ein Waechter, der die Vorgabe von
    python-requests als User-Agent abweist. An jede Anfrage gehoeren
    User-Agent, Accept, Accept-Language und Accept-Encoding."""
    h = {
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.bsh.sdk.v1+json, application/json",
        "Accept-Encoding": "gzip, deflate",
        "User-Agent": "LoxBerry-Weissware/0.9.1",
    }
    if sprache:
        h["Accept-Language"] = sprache
    return h


# ---------------------------------------------------------------------------
# Fehlermeldungen, die sagen, wer geantwortet hat
# ---------------------------------------------------------------------------
def fehlertext(err: Exception) -> str:
    name = type(err).__name__
    inhalt = str(err) or name
    klein = inhalt.lower()

    status = getattr(getattr(err, "response", None), "status_code", None)
    if status == 429:
        return ("Der Anbieter hat wegen zu vieler Anfragen abgewiesen (429). Den Takt in "
                "den Einstellungen vergroessern. Home Connect nennt das in seinen Best "
                "Practices ausdruecklich als haeufigsten Fehler.")
    if status == 401:
        return ("Die Anmeldung wurde abgewiesen (401). Das Zugriffstoken ist abgelaufen oder "
                "zurueckgezogen worden - im Reiter Einstellungen neu anmelden.")
    if status == 403:
        return ("Zugriff verweigert (403). Meist fehlt eine Berechtigung: bei Home Connect "
                "der Umfang 'Control' oder 'Settings', bei Miele der Umfang "
                "'mcs_thirdparty_write'.")
    if status == 404:
        return ("Der Anbieter kennt diesen Abruf fuer dieses Geraet nicht (404). Meist "
                "beherrscht das Geraet die Funktion nicht.")
    if status == 409:
        return ("Der Anbieter hat den Befehl abgelehnt (409). Bei Home Connect heisst das fast "
                "immer: die Fernstart-Freigabe ist am Geraet nicht gedrueckt, oder das Geraet "
                "ist gerade nicht in einem Zustand, in dem der Befehl erlaubt ist.")
    if status and 500 <= int(status) < 600:
        return f"Der Anbieter meldet einen eigenen Fehler (HTTP {status}). Das liegt nicht am LoxBerry."

    if "timed out" in klein or name in ("Timeout", "ReadTimeout", "ConnectTimeout"):
        return ("Zeitueberlauf: der Anbieter hat nicht geantwortet. Meist eine gestoerte "
                "Internetverbindung oder eine Stoerung beim Anbieter.")
    if name in ("ConnectionError", "NewConnectionError"):
        if "name or service not known" in klein or "nodename" in klein:
            return ("Namensaufloesung fehlgeschlagen: der DNS-Server des LoxBerry antwortet "
                    "nicht.")
        return f"Verbindung nicht moeglich: {inhalt}"
    if "<html" in klein or "<!doctype" in klein:
        return ("Es kam HTML statt JSON zurueck - geantwortet hat also ein vorgelagerter "
                "Dienst (Proxy, Portal, Fehlerseite), nicht die Schnittstelle des Anbieters.")
    return f"{name}: {inhalt}"


# ===========================================================================
# Anbieter: Home Connect
#
# Anmeldung ueber den Device Flow. Der passt zu einem Geraet ohne Bildschirm:
# der Dienst holt einen Benutzercode, der Mensch tippt ihn in einem beliebigen
# Browser ein, der Dienst fragt derweil im Sekundentakt nach dem Token.
# Belegt: api-docs.home-connect.com/authorization (Device Flow).
# ===========================================================================
def hc_host(cfg: dict) -> str:
    return HC_HOST_SIM if cfg.get("hc_simulator") else HC_HOST


def hc_geraete_anfordern(sitzung, cfg: dict, token: str) -> list:
    """GET /api/homeappliances -> data.homeappliances[]"""
    a = sitzung.get(hc_host(cfg) + "/api/homeappliances",
                    headers=kopfzeilen(token, cfg["sprache"]), timeout=30)
    a.raise_for_status()
    return ((a.json() or {}).get("data") or {}).get("homeappliances") or []


def hc_abschnitt(sitzung, cfg: dict, token: str, haid: str, pfad: str, schluessel: str) -> dict:
    """Einen Abschnitt lesen und als {key: value} zurueckgeben.

    Ein Geraet, das gerade kein Programm faehrt, antwortet auf
    /programs/active mit 404. Das ist KEIN Fehler, sondern die Antwort
    'laeuft nichts' - deshalb wird sie hier zu einem leeren Verzeichnis und
    nicht zu einer Stoerung.
    """
    a = sitzung.get(hc_host(cfg) + "/api/homeappliances/" + haid + pfad,
                    headers=kopfzeilen(token, cfg["sprache"]), timeout=30)
    if a.status_code in (404, 409):
        return {}
    a.raise_for_status()
    d = (a.json() or {}).get("data") or {}
    if schluessel == "":            # /programs/active liefert ein einzelnes Objekt
        out = {"__key": d.get("key")}
        for o in d.get("options") or []:
            out[str(o.get("key"))] = o.get("value")
        return out
    return {str(e.get("key")): e.get("value") for e in (d.get(schluessel) or [])}


def hc_abbilden(geraet: dict, status: dict, settings: dict, aktiv: dict) -> dict:
    """Home-Connect-Antworten auf das gemeinsame Modell.

    Die Schluesselnamen stammen aus der Dokumentation (States, Settings,
    Programs and Options).
    """
    zustand = HC_ZUSTAND.get(hc_kurz(status.get("BSH.Common.Status.OperationState")))
    # Ein ausgeschaltetes Geraet meldet oft gar keinen OperationState; dann
    # entscheidet der Netzschalter.
    if zustand is None and "BSH.Common.Setting.PowerState" in settings:
        zustand = AUS if hc_kurz(settings["BSH.Common.Setting.PowerState"]) != "On" else BEREIT
    tuer = hc_kurz(status.get("BSH.Common.Status.DoorState"))
    rest = zahl(aktiv.get("BSH.Common.Option.RemainingProgramTime"))
    start = zahl(aktiv.get("BSH.Common.Option.StartInRelative"))
    return {
        "anbieter": "homeconnect",
        "id": str(geraet.get("haId") or ""),
        "name": str(geraet.get("name") or ""),
        "marke": str(geraet.get("brand") or ""),
        "typ": str(geraet.get("type") or ""),
        "verbunden": 1 if geraet.get("connected") else 0,
        "zustand": zustand,
        "zustand_text": hc_kurz(status.get("BSH.Common.Status.OperationState")),
        "tuer_offen": None if tuer == "" else (1 if tuer == "Open" else 0),
        "programm": str(aktiv.get("__key") or ""),
        "programm_text": str(aktiv.get("__key") or "").rsplit(".", 1)[-1],
        "fortschritt": zahl(aktiv.get("BSH.Common.Option.ProgramProgress")),
        # Home Connect liefert Zeiten in SEKUNDEN. Fuer Loxone sind Minuten
        # brauchbarer, und die uebrigen Anbieter liefern ohnehin Minuten.
        "restzeit_min": None if rest is None else int(round(rest / 60)),
        "startzeit_min": None if start is None else int(round(start / 60)),
        "laufzeit_min": (lambda v: None if v is None else int(round(v / 60)))(
            zahl(aktiv.get("BSH.Common.Option.ElapsedProgramTime"))),
        "fernstart_frei": (lambda v: None if v is None else (1 if v else 0))(
            status.get("BSH.Common.Status.RemoteControlStartAllowed")),
        "fernbedienung_frei": (lambda v: None if v is None else (1 if v else 0))(
            status.get("BSH.Common.Status.RemoteControlActive")),
        "netz_ein": (lambda v: None if v == "" else (1 if v == "On" else 0))(
            hc_kurz(settings.get("BSH.Common.Setting.PowerState"))),
        # Home Connect liefert weder Energie- noch Wasserverbrauch.
        "energie_kwh": None,
        "wasser_l": None,
        "temperatur": zahl(aktiv.get("LaundryCare.Washer.Option.Temperature")
                           or aktiv.get("Cooking.Oven.Option.SetpointTemperature")),
        "schleuderdrehzahl": zahl(str(aktiv.get("LaundryCare.Washer.Option.SpinSpeed") or "")
                                  .rsplit(".", 1)[-1].replace("RPM", "")),
        "roh": {"status": status, "settings": settings, "programm": aktiv},
    }


def hc_lesen(sitzung, cfg: dict, token: str) -> list:
    geraete = []
    for g in hc_geraete_anfordern(sitzung, cfg, token):
        haid = str(g.get("haId") or "")
        if not haid:
            continue
        if not g.get("connected"):
            # Ein getrenntes Geraet nach Werten zu fragen kostet nur Anfragen
            # und liefert 409. Der Eintrag bleibt trotzdem bestehen, damit in
            # Loxone nicht ploetzlich ein Geraet verschwindet.
            geraete.append(hc_abbilden(g, {}, {}, {}))
            continue
        status = hc_abschnitt(sitzung, cfg, token, haid, "/status", "status")
        settings = hc_abschnitt(sitzung, cfg, token, haid, "/settings", "settings")
        aktiv = hc_abschnitt(sitzung, cfg, token, haid, "/programs/active", "")
        geraete.append(hc_abbilden(g, status, settings, aktiv))
    return geraete


def hc_befehl(sitzung, cfg: dict, token: str, haid: str, aktion: str, wert=None):
    """Schreibende Befehle. 204 heisst laut Dokumentation: angenommen."""
    kopf = kopfzeilen(token, cfg["sprache"])
    kopf["Content-Type"] = "application/vnd.bsh.sdk.v1+json"
    basis = hc_host(cfg) + "/api/homeappliances/" + haid
    if aktion == "start":
        # Ohne Programmschluessel wird das am Geraet gewaehlte Programm
        # gestartet - dafuer muss es dort ausgewaehlt sein.
        if not wert:
            a = sitzung.get(basis + "/programs/selected", headers=kopf, timeout=30)
            a.raise_for_status()
            wert = ((a.json() or {}).get("data") or {}).get("key")
            if not wert:
                raise ValueError("Am Geraet ist kein Programm gewaehlt.")
        return sitzung.put(basis + "/programs/active", headers=kopf,
                           data=json.dumps({"data": {"key": wert, "options": []}}), timeout=30)
    if aktion == "stop":
        return sitzung.delete(basis + "/programs/active", headers=kopf, timeout=30)
    if aktion in ("pause", "fortsetzen"):
        schluessel = ("BSH.Common.Command.PauseProgram" if aktion == "pause"
                      else "BSH.Common.Command.ResumeProgram")
        return sitzung.put(basis + "/commands/" + schluessel, headers=kopf,
                           data=json.dumps({"data": {"key": schluessel, "value": True}}),
                           timeout=30)
    if aktion in ("ein", "aus"):
        z = ("BSH.Common.EnumType.PowerState.On" if aktion == "ein"
             else "BSH.Common.EnumType.PowerState.Off")
        return sitzung.put(basis + "/settings/BSH.Common.Setting.PowerState", headers=kopf,
                           data=json.dumps({"data": {"key": "BSH.Common.Setting.PowerState",
                                                     "value": z}}), timeout=30)
    raise ValueError("Home Connect kennt die Aktion '%s' nicht." % aktion)


# ===========================================================================
# Anbieter: Miele
#
# Anmeldung ueber den Authorization Code Grant Flow - einen Device Flow gibt
# es bei Miele nicht. Der Mensch oeffnet die Anmeldeadresse im Browser, meldet
# sich an und wird auf die redirect_uri umgeleitet; aus deren Adresse kopiert
# er den Code zurueck in die Oberflaeche. Umstaendlich, aber der einzige Weg,
# der ohne oeffentlich erreichbaren LoxBerry auskommt.
# Belegt: developer.miele.com/docs/authorization2.
# ===========================================================================
def miele_lesen(sitzung, cfg: dict, token: str) -> list:
    a = sitzung.get(MIELE_HOST + "/v1/devices",
                    params={"language": str(cfg["sprache"])[:2]},
                    headers=kopfzeilen(token), timeout=30)
    a.raise_for_status()
    roh = a.json() or {}
    geraete = []
    for kennung, g in (roh.items() if isinstance(roh, dict) else []):
        geraete.append(miele_abbilden(str(kennung), g or {}))
    return geraete


def miele_abbilden(kennung: str, g: dict) -> dict:
    ident = g.get("ident") or {}
    zustand_roh = g.get("state") or {}
    typ = (ident.get("type") or {})
    status = zustand_roh.get("status") or {}
    stufe = MIELE_ZUSTAND.get(miele_zahl(status.get("value_raw")))
    fern = zustand_roh.get("remoteEnable") or {}
    eco = zustand_roh.get("ecoFeedback") or {}
    # ecoFeedback kann null sein - dann gibt es keinen Verbrauch, keine 0.
    if not isinstance(eco, dict):
        eco = {}
    return {
        "anbieter": "miele",
        "id": kennung,
        "name": str(ident.get("deviceName") or typ.get("value_localized") or kennung),
        "marke": "Miele",
        "typ": str(typ.get("value_localized") or ""),
        "verbunden": 0 if stufe is None else 1,
        "zustand": stufe,
        "zustand_text": str(status.get("value_localized") or ""),
        "tuer_offen": (lambda v: None if v is None else (1 if v else 0))(
            zustand_roh.get("signalDoor")),
        "programm": str((zustand_roh.get("ProgramID") or {}).get("value_raw") or ""),
        "programm_text": str((zustand_roh.get("ProgramID") or {}).get("value_localized") or ""),
        "fortschritt": miele_fortschritt(zustand_roh),
        "restzeit_min": miele_dauer(zustand_roh.get("remainingTime")),
        "startzeit_min": miele_dauer(zustand_roh.get("startTime")),
        "laufzeit_min": miele_dauer(zustand_roh.get("elapsedTime")),
        # Miele nennt drei Freigaben; mobileStart ist die, die einen Start
        # aus der Ferne ueberhaupt erlaubt.
        "fernstart_frei": (lambda v: None if v is None else (1 if v else 0))(
            fern.get("mobileStart")),
        "fernbedienung_frei": (lambda v: None if v is None else (1 if v else 0))(
            fern.get("fullRemoteControl")),
        "netz_ein": None if stufe is None else (0 if stufe == AUS else 1),
        "energie_kwh": miele_zahl(eco.get("currentEnergyConsumption", {}).get("value")
                                  if isinstance(eco.get("currentEnergyConsumption"), dict)
                                  else None, 2),
        "wasser_l": miele_zahl(eco.get("currentWaterConsumption", {}).get("value")
                               if isinstance(eco.get("currentWaterConsumption"), dict)
                               else None, 1),
        "temperatur": miele_temperatur(zustand_roh.get("targetTemperature")),
        "schleuderdrehzahl": miele_zahl((zustand_roh.get("spinningSpeed") or {}).get("value_raw")),
        "roh": g,
    }


def miele_fortschritt(zustand_roh: dict):
    """Miele liefert keinen Fortschritt in Prozent - er wird aus verstrichener
    und verbleibender Zeit gerechnet. Fehlt eine der beiden, gibt es keinen
    Wert; eine 0 waere geraten."""
    vergangen = miele_dauer(zustand_roh.get("elapsedTime"))
    rest = miele_dauer(zustand_roh.get("remainingTime"))
    if vergangen is None or rest is None or (vergangen + rest) <= 0:
        return None
    return int(round(100.0 * vergangen / (vergangen + rest)))


def miele_temperatur(liste):
    """targetTemperature ist eine Liste von Zonen. Genommen wird die erste,
    die einen Wert hat. Miele fuehrt Temperaturen in Hundertstel Grad."""
    if not isinstance(liste, (list, tuple)):
        return None
    for eintrag in liste:
        if not isinstance(eintrag, dict):
            continue
        v = miele_zahl(eintrag.get("value_raw"))
        if v is None:
            continue
        return round(v / 100.0, 1)
    return None


# Miele-Aktionen: processAction. Zahlen aus der Dokumentation.
MIELE_AKTION = {"start": 1, "stop": 2, "pause": 3, "fortsetzen": 1}


def miele_befehl(sitzung, cfg: dict, token: str, kennung: str, aktion: str, wert=None):
    kopf = kopfzeilen(token)
    kopf["Content-Type"] = "application/json"
    if aktion in ("ein", "aus"):
        # powerOn/powerOff sind eigene Felder, nicht processAction.
        rumpf = {"powerOn": True} if aktion == "ein" else {"powerOff": True}
    elif aktion in MIELE_AKTION:
        rumpf = {"processAction": MIELE_AKTION[aktion]}
    else:
        raise ValueError("Miele kennt die Aktion '%s' nicht." % aktion)
    return sitzung.put(MIELE_HOST + "/v1/devices/" + kennung + "/actions",
                       headers=kopf, data=json.dumps(rumpf), timeout=30)


# ===========================================================================
# Anbieter: SmartThings
#
# ACHTUNG - der Vorbehalt zu diesem Anbieter:
# Samsung hat die Lebensdauer neu ausgestellter Personal Access Tokens am
# 30.12.2024 auf 24 Stunden verkuerzt und die Ratengrenzen gesenkt. Fuer
# Dauerbetrieb verlangt Samsung eine "Service Integration" - eine registrierte
# SmartApp mit oeffentlich erreichbarem Webhook, was ein LoxBerry hinter einem
# Router nicht ohne Weiteres kann.
#
# Dieses Plugin unterstuetzt deshalb nur den PAT-Weg, und die Oberflaeche sagt
# ausdruecklich, dass das Token taeglich erneuert werden muss. Ein Plugin, das
# so etwas verschweigt, waere schlimmer als keins.
# ===========================================================================
ST_TYPEN = {
    "washer": "Waschmaschine", "dryer": "Trockner", "dishwasher": "Geschirrspueler",
    "oven": "Backofen", "refrigerator": "Kuehlschrank", "microwave": "Mikrowelle",
}


def st_lesen(sitzung, cfg: dict, token: str) -> list:
    a = sitzung.get(ST_HOST + "/devices", headers=kopfzeilen(token), timeout=30)
    a.raise_for_status()
    geraete = []
    for g in (a.json() or {}).get("items") or []:
        kennung = str(g.get("deviceId") or "")
        if not kennung:
            continue
        z = sitzung.get(ST_HOST + "/devices/" + kennung + "/status",
                        headers=kopfzeilen(token), timeout=30)
        if z.status_code == 404:
            zustand = {}
        else:
            z.raise_for_status()
            zustand = z.json() or {}
        geraete.append(st_abbilden(g, zustand))
    return geraete


def st_wert(zustand: dict, faehigkeit: str, feld: str):
    """SmartThings schachtelt: components -> main -> capability -> attribut."""
    haupt = ((zustand.get("components") or {}).get("main") or {})
    return ((haupt.get(faehigkeit) or {}).get(feld) or {}).get("value")


def st_abbilden(g: dict, zustand: dict) -> dict:
    haupt = ((zustand.get("components") or {}).get("main") or {})
    # Der Betriebszustand steckt je nach Geraeteart in einer anderen
    # Faehigkeit. Genommen wird die erste, die etwas liefert.
    roh_zustand = None
    for faehigkeit, feld in (("washerOperatingState", "machineState"),
                             ("dryerOperatingState", "machineState"),
                             ("dishwasherOperatingState", "machineState"),
                             ("ovenOperatingState", "machineState"),
                             ("switch", "switch")):
        v = st_wert(zustand, faehigkeit, feld)
        if v:
            roh_zustand = str(v)
            break
    rest = None
    for faehigkeit in ("washerOperatingState", "dryerOperatingState",
                       "dishwasherOperatingState", "ovenOperatingState"):
        v = st_wert(zustand, faehigkeit, "completionTime")
        if v:
            rest = st_restzeit(v)
            break
    typ = str((g.get("deviceTypeName") or g.get("name") or "")).lower()
    return {
        "anbieter": "smartthings",
        "id": str(g.get("deviceId") or ""),
        "name": str(g.get("label") or g.get("name") or ""),
        "marke": str((g.get("manufacturerName") or "Samsung")),
        "typ": next((v for k, v in ST_TYPEN.items() if k in typ), typ),
        "verbunden": 1 if roh_zustand is not None else 0,
        "zustand": ST_ZUSTAND.get((roh_zustand or "").lower()),
        "zustand_text": roh_zustand or "",
        "tuer_offen": (lambda v: None if v is None else (1 if str(v) == "open" else 0))(
            st_wert(zustand, "contactSensor", "contact")),
        "programm": str(st_wert(zustand, "washerOperatingState", "washerJobState")
                        or st_wert(zustand, "dryerOperatingState", "dryerJobState") or ""),
        "programm_text": str(st_wert(zustand, "washerOperatingState", "washerJobState")
                             or st_wert(zustand, "dryerOperatingState", "dryerJobState") or ""),
        # SmartThings liefert keinen Fortschritt in Prozent.
        "fortschritt": None,
        "restzeit_min": rest,
        "startzeit_min": None,
        "laufzeit_min": None,
        "fernstart_frei": (lambda v: None if v is None else (1 if str(v) == "on" else 0))(
            st_wert(zustand, "remoteControlStatus", "remoteControlEnabled")),
        "fernbedienung_frei": (lambda v: None if v is None else (1 if str(v) == "on" else 0))(
            st_wert(zustand, "remoteControlStatus", "remoteControlEnabled")),
        "netz_ein": (lambda v: None if v is None else (1 if str(v) == "on" else 0))(
            st_wert(zustand, "switch", "switch")),
        "energie_kwh": zahl(st_wert(zustand, "powerConsumptionReport", "powerConsumption"), 2)
                       if isinstance(st_wert(zustand, "powerConsumptionReport",
                                             "powerConsumption"), (int, float)) else None,
        "wasser_l": None,
        "temperatur": zahl(st_wert(zustand, "temperatureMeasurement", "temperature"), 1),
        "schleuderdrehzahl": None,
        "roh": {"geraet": g, "zustand": haupt},
    }


def st_restzeit(zeitpunkt) -> int | None:
    """SmartThings meldet einen Fertigzeitpunkt (ISO 8601), keine Restzeit."""
    from datetime import datetime, timezone
    try:
        s = str(zeitpunkt).replace("Z", "+00:00")
        ende = datetime.fromisoformat(s)
        if ende.tzinfo is None:
            ende = ende.replace(tzinfo=timezone.utc)
        rest = (ende - datetime.now(timezone.utc)).total_seconds() / 60.0
    except (TypeError, ValueError):
        return None
    return max(0, int(round(rest)))


ST_BEFEHL = {
    "start": ("washerOperatingState", "setMachineState", ["run"]),
    "stop": ("washerOperatingState", "setMachineState", ["stop"]),
    "pause": ("washerOperatingState", "setMachineState", ["pause"]),
    "fortsetzen": ("washerOperatingState", "setMachineState", ["run"]),
    "ein": ("switch", "on", []),
    "aus": ("switch", "off", []),
}


def st_befehl(sitzung, cfg: dict, token: str, kennung: str, aktion: str, wert=None):
    if aktion not in ST_BEFEHL:
        raise ValueError("SmartThings kennt die Aktion '%s' nicht." % aktion)
    faehigkeit, befehl, argumente = ST_BEFEHL[aktion]
    kopf = kopfzeilen(token)
    kopf["Content-Type"] = "application/json"
    rumpf = {"commands": [{"component": "main", "capability": faehigkeit,
                           "command": befehl, "arguments": argumente}]}
    return sitzung.post(ST_HOST + "/devices/" + kennung + "/commands",
                        headers=kopf, data=json.dumps(rumpf), timeout=30)


# ===========================================================================
# Token verwalten
# ===========================================================================
def hc_token_erneuern(sitzung, cfg: dict, z: dict, marken: dict) -> str:
    """Gibt ein gueltiges Zugriffstoken zurueck, erneuert es bei Bedarf.

    Das Zugriffstoken laeuft laut Dokumentation nach 86400 s ab. Erneuert wird
    zehn Minuten vorher - wer bis zur letzten Sekunde wartet, faengt sich bei
    jeder Uhrabweichung eine 401 ein.
    """
    hc = marken.get("homeconnect") or {}
    if hc.get("access_token") and time.time() < ganz(hc.get("gueltig_bis"), 0) - 600:
        return hc["access_token"]
    if not hc.get("refresh_token"):
        raise RuntimeError("Home Connect ist nicht angemeldet.")
    a = sitzung.post(hc_host(cfg) + "/security/oauth/token",
                     data={"grant_type": "refresh_token",
                           "refresh_token": hc["refresh_token"],
                           "client_secret": z["hc_client_secret"]},
                     timeout=30)
    a.raise_for_status()
    neu = a.json() or {}
    marken["homeconnect"] = {
        "access_token": neu.get("access_token"),
        "refresh_token": neu.get("refresh_token") or hc["refresh_token"],
        "gueltig_bis": int(time.time()) + ganz(neu.get("expires_in"), 86400),
    }
    token_schreiben(marken)
    _LOG.info("Home Connect: Zugriffstoken erneuert.")
    return marken["homeconnect"]["access_token"]


def miele_token_erneuern(sitzung, cfg: dict, z: dict, marken: dict) -> str:
    mi = marken.get("miele") or {}
    if mi.get("access_token") and time.time() < ganz(mi.get("gueltig_bis"), 0) - 600:
        return mi["access_token"]
    if not mi.get("refresh_token"):
        raise RuntimeError("Miele ist nicht angemeldet.")
    a = sitzung.post(MIELE_HOST + "/thirdparty/token",
                     data={"grant_type": "refresh_token",
                           "refresh_token": mi["refresh_token"],
                           "client_id": z["miele_client_id"],
                           "client_secret": z["miele_client_secret"]},
                     timeout=30)
    a.raise_for_status()
    neu = a.json() or {}
    marken["miele"] = {
        "access_token": neu.get("access_token"),
        "refresh_token": neu.get("refresh_token") or mi["refresh_token"],
        "gueltig_bis": int(time.time()) + ganz(neu.get("expires_in"), 2592000),
    }
    token_schreiben(marken)
    _LOG.info("Miele: Zugriffstoken erneuert.")
    return marken["miele"]["access_token"]


# ===========================================================================
# Abruf ueber alle eingeschalteten Anbieter
# ===========================================================================
def alle_lesen(sitzung, cfg: dict, z: dict, marken: dict) -> tuple:
    """Rueckgabe: (Liste der Geraete, Verzeichnis der Ausfaelle je Anbieter).

    Faellt ein Anbieter aus, bleiben die uebrigen gueltig. Was ausgefallen
    ist, wird benannt - schweigend eine kurze Liste zu liefern waere
    schlimmer als ein benannter Fehler.
    """
    geraete = []
    ausfaelle = {}

    if cfg.get("hc_ein"):
        try:
            token = hc_token_erneuern(sitzung, cfg, z, marken)
            geraete += hc_lesen(sitzung, cfg, token)
        except Exception as err:  # noqa: BLE001
            ausfaelle["homeconnect"] = fehlertext(err)
            melde_gebremst("ab_hc", "Home Connect: " + ausfaelle["homeconnect"], 900)

    if cfg.get("miele_ein"):
        try:
            token = miele_token_erneuern(sitzung, cfg, z, marken)
            geraete += miele_lesen(sitzung, cfg, token)
        except Exception as err:  # noqa: BLE001
            ausfaelle["miele"] = fehlertext(err)
            melde_gebremst("ab_miele", "Miele: " + ausfaelle["miele"], 900)

    if cfg.get("st_ein"):
        try:
            if not z["st_token"]:
                raise RuntimeError("Es ist kein SmartThings-Token hinterlegt.")
            geraete += st_lesen(sitzung, cfg, z["st_token"])
        except Exception as err:  # noqa: BLE001
            text = fehlertext(err)
            if "401" in text or "abgewiesen" in text:
                text += (" Bei SmartThings ist das fast immer das 24-Stunden-Ende des "
                         "Personal Access Token: Samsung laesst neu ausgestellte Token seit "
                         "dem 30.12.2024 nur noch einen Tag gelten.")
            ausfaelle["smartthings"] = text
            melde_gebremst("ab_st", "SmartThings: " + text, 900)

    # Stabile Reihenfolge, damit die laufenden Nummern in Loxone nicht bei
    # jedem Abruf springen.
    geraete.sort(key=lambda g: (g.get("anbieter") or "", g.get("id") or ""))
    return geraete, ausfaelle


def befehl_senden(sitzung, cfg: dict, z: dict, marken: dict, geraet: dict,
                  aktion: str, wert=None):
    anbieter = geraet.get("anbieter")
    if anbieter == "homeconnect":
        return hc_befehl(sitzung, cfg, hc_token_erneuern(sitzung, cfg, z, marken),
                         geraet["id"], aktion, wert)
    if anbieter == "miele":
        return miele_befehl(sitzung, cfg, miele_token_erneuern(sitzung, cfg, z, marken),
                            geraet["id"], aktion, wert)
    if anbieter == "smartthings":
        return st_befehl(sitzung, cfg, z["st_token"], geraet["id"], aktion, wert)
    raise ValueError("Unbekannter Anbieter '%s'." % anbieter)


# ===========================================================================
# Warteschlange
# ===========================================================================
def antwort_schreiben(kennung: str, ok: int, meldung: str, zusatz: dict | None = None) -> None:
    ORDNER_ANTWORTEN.mkdir(parents=True, exist_ok=True)
    d = {"ok": ok, "meldung": meldung, "ts": int(time.time())}
    if zusatz:
        d.update(zusatz)
    json_schreiben(ORDNER_ANTWORTEN / f"{kennung}.json", d)
    grenze = time.time() - 900
    for alt in ORDNER_ANTWORTEN.glob("*.json"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


def geraet_waehlen(geraete: list, schluessel):
    """Nimmt die laufende Nummer (1-basiert) oder die Kennung des Anbieters."""
    s = str(schluessel or "").strip()
    for g in geraete:
        if str(g.get("id") or "").upper() == s.upper():
            return g
    try:
        n = int(s)
    except (TypeError, ValueError):
        n = 1
    return geraete[n - 1] if 1 <= n <= len(geraete) else None


def befehl_ausfuehren(sitzung, cfg: dict, z: dict, marken: dict, geraete: list,
                      b: dict) -> tuple:
    """Rueckgabe: (ok, Meldung, Zusatzfelder).

    ok = 1 angenommen, 0 abgelehnt. "Angenommen" heisst: der Anbieter hat die
    Anfrage mit 2xx quittiert. Ob das Geraet den Befehl ausfuehrt, zeigt erst
    der naechste Abruf - das steht so in jeder Antwort.
    """
    aktion = str(b.get("aktion") or "")

    if aktion == "abruf":
        return (1, "Sofortabruf eingeplant.", {})

    if not cfg.get("steuerung_ein"):
        return (0, "Die Steuerung ist ausgeschaltet. Reiter Einstellungen, "
                   "Haken 'Schreibende Befehle zulassen'.", {})
    if not geraete:
        return (0, "Es ist noch kein Geraet bekannt. Erst einen Abruf abwarten.", {})

    g = geraet_waehlen(geraete, b.get("geraet"))
    if g is None:
        return (0, f"Geraet '{b.get('geraet')}' gibt es nicht. Bekannt sind "
                   f"{len(geraete)} Geraete.", {})

    if aktion == "start" and g.get("fernstart_frei") == 0:
        # Abweisen statt senden: der Anbieter antwortet sonst mit 409, und der
        # Grund - die fehlende Freigabe am Geraet - geht in der Fehlermeldung
        # unter. So steht er in der Antwort.
        return (0, f"'{g.get('name')}' hat keine Fernstart-Freigabe. Diese Freigabe wird am "
                   f"Geraet selbst gegeben (bei Home Connect die Taste 'Fernstart', bei Miele "
                   f"'MobileStart') und gilt meist nur bis zum Programmende.", {})

    nachsatz = (" Der Anbieter hat die Anfrage angenommen; ob das Geraet den Befehl "
                "ausfuehrt, zeigt der naechste Abruf.")
    try:
        antwort = befehl_senden(sitzung, cfg, z, marken, g, aktion, b.get("programm") or None)
    except ValueError as err:
        return (0, str(err), {})
    if antwort is None:
        return (0, "Der Anbieter hat nicht geantwortet.", {})
    if 200 <= antwort.status_code < 300:
        return (1, f"'{aktion}' an '{g.get('name')}' gesendet." + nachsatz,
                {"geraet": g.get("id"), "anbieter": g.get("anbieter"),
                 "http": antwort.status_code})
    kurz = (antwort.text or "")[:300].replace("\n", " ")
    if antwort.status_code == 409:
        kurz += (" - bei Home Connect heisst 409 fast immer: Fernstart am Geraet nicht "
                 "freigegeben, oder der Befehl passt nicht zum aktuellen Zustand.")
    return (0, f"Der Anbieter hat '{aktion}' abgelehnt (HTTP {antwort.status_code}): {kurz}",
            {"geraet": g.get("id"), "http": antwort.status_code})


def warteschlange(sitzung, cfg: dict, z: dict, marken: dict, geraete: list) -> bool:
    ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    sofort = False
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        b = json_lesen(datei)
        kennung = datei.stem
        # ERST loeschen, DANN ausfuehren - und wenn das Loeschen scheitert,
        # gar nicht ausfuehren.
        #
        # Bis 0.9.0 wurde ein Fehler beim Loeschen verschluckt und der Befehl
        # trotzdem abgearbeitet. Die Datei bleibt dann liegen und wird in
        # JEDEM weiteren Durchgang erneut ausgefuehrt. Bei einem Abruf waere
        # das nur Last; bei "Waschmaschine starten" laeuft das Geraet jede
        # Runde neu an. Lieber ein gemeldeter Stillstand als eine Schleife,
        # die Geraete schaltet.
        try:
            datei.unlink()
        except OSError as err:
            melde_gebremst("queue_" + kennung,
                           "Befehlsdatei {0} liess sich nicht entfernen ({1}) - der "
                           "Befehl wird NICHT ausgefuehrt, sonst liefe er endlos "
                           "erneut. Schreibrechte auf {2} pruefen.".format(
                               datei.name, err, ORDNER_BEFEHLE), 900)
            continue
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            continue
        try:
            ok, meldung, zusatz = befehl_ausfuehren(sitzung, cfg, z, marken, geraete, b)
        except Exception as err:  # noqa: BLE001
            ok, meldung, zusatz = 0, fehlertext(err), {}
        antwort_schreiben(kennung, ok, meldung, zusatz)
        _LOG.info("Befehl %s (%s): ok=%s %s", kennung, b.get("aktion"), ok, meldung)
        if b.get("aktion") == "abruf" and ok:
            sofort = True
    return sofort


# ===========================================================================
# Abbild schreiben
# ===========================================================================
MQTT_FELDER = (
    "name", "anbieter",
    "zustand", "zustand_text", "laeuft", "fertig", "verbunden", "tuer_offen",
    "fortschritt", "restzeit_min", "startzeit_min", "laufzeit_min",
    "fernstart_frei", "fernbedienung_frei", "netz_ein", "energie_kwh",
    "wasser_l", "temperatur", "schleuderdrehzahl", "programm_text",
)


def ableiten(g: dict) -> dict:
    """Zwei Felder, die aus dem Zustand folgen und in Loxone am meisten
    gebraucht werden."""
    z = g.get("zustand")
    g["laeuft"] = None if z is None else (1 if z == LAEUFT else 0)
    g["fertig"] = None if z is None else (1 if z == FERTIG else 0)
    if not g.get("zustand_text"):
        g["zustand_text"] = ZUSTAND_TEXT.get(z, "")
    rest = g.get("restzeit_min")
    g["fertig_um"] = None if rest is None else int(time.time() + rest * 60)
    return g


def abbild_schreiben(stand: dict, cfg: dict, ok: int, fehler: str, ausfaelle: dict) -> dict:
    """Bei einem fehlgeschlagenen Abruf bleiben die zuletzt gueltigen Werte
    stehen, und der Zeitstempel wird NICHT aufgefrischt - sonst bliebe ALTER
    klein, woran aber die Ausfallerkennung in Loxone haengt."""
    geraete = stand.get("geraete") or {}
    lox = {
        "ok": ok,
        "fehler": fehler,
        "ausfaelle": ausfaelle,
        "letzter_versuch": int(time.time()),
        "anzahl_geraete": len(geraete),
        "geraete": geraete,
    }
    if stand.get("ts"):
        lox["ts"] = int(stand["ts"])
    json_schreiben(DATEI_LOXONE, lox)
    json_schreiben(DATEI_CACHE, {"ts": int(time.time()), "ok": ok, "fehler": fehler,
                                 "ausfaelle": ausfaelle, "geraete": geraete})

    praefix = str(cfg.get("mqtt_topic") or "weissware").strip("/") or "weissware"
    if not ok:
        if cfg.get("mqtt_ein"):
            mqtt_senden({"ok": 0}, praefix)
        return lox
    if cfg.get("mqtt_ein"):
        paare = {"ok": ok, "geraete": len(geraete)}
        for nummer, g in geraete.items():
            for feld in MQTT_FELDER:
                paare[f"geraet{nummer}/{feld}"] = g.get(feld)
        mqtt_senden(paare, praefix)
    return lox


def zustand_schreiben(**felder) -> None:
    z = json_lesen(DATEI_ZUSTAND)
    z.update(felder)
    z["ts"] = int(time.time())
    json_schreiben(DATEI_ZUSTAND, z)


# ===========================================================================
# Dienst
# ===========================================================================
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


def dienst(einmal: bool = False) -> int:
    import requests

    cfg = config()
    z = zugang()
    marken = token_lesen()
    if not any(cfg.get(k) for k in ("hc_ein", "miele_ein", "st_ein")):
        _LOG.error("Es ist kein Anbieter eingeschaltet. Reiter Einstellungen oeffnen.")
        zustand_schreiben(ok=0, fehler="Kein Anbieter eingeschaltet.")
        return 1

    _LOG.info("Dienst startet (Takt %s s in Ruhe, %s s im Betrieb, Steuerung %s).",
              cfg["takt_ruhe"], cfg["takt_betrieb"],
              "ein" if cfg.get("steuerung_ein") else "aus")

    sitzung = requests.Session()
    stand: dict = {"ts": 0, "geraete": {}}
    fehler_folge = 0
    liste: list = []

    try:
        while _LAUF:
            cfg = config()
            z = zugang()
            marken = token_lesen()
            ok = 0
            fehler = ""
            geraete: dict = {}
            try:
                liste, ausfaelle = alle_lesen(sitzung, cfg, z, marken)
                for i, g in enumerate(liste, start=1):
                    geraete[str(i)] = ableiten(g)
                ok = 1 if geraete else 0
                if not liste and not ausfaelle:
                    fehler = "Die eingeschalteten Anbieter fuehren kein Geraet."
                elif ausfaelle and not geraete:
                    fehler = "; ".join(f"{a}: {t}" for a, t in ausfaelle.items())
                fehler_folge = 0 if ok else fehler_folge + 1
            except Exception as err:  # noqa: BLE001
                ausfaelle = {}
                fehler = fehlertext(err)
                fehler_folge += 1
                melde_gebremst("abruf", f"Abruf fehlgeschlagen: {fehler}", 900)

            if ok:
                stand = {"ts": int(time.time()), "geraete": geraete}
            abbild_schreiben(stand, cfg, ok, fehler, ausfaelle)
            zustand_schreiben(ok=ok, fehler=fehler, fehler_folge=fehler_folge,
                              pid=os.getpid(), anzahl_geraete=len(stand["geraete"]),
                              ausfaelle=ausfaelle)
            if einmal:
                return 0 if ok else 1

            # Adaptiver Takt: laeuft ein Geraet, wird enger abgefragt. Das ist
            # der Grund, warum ein Plugin hier besser ist als ein starrer
            # Abfragezyklus - man bekommt eine brauchbare Restzeit, ohne im
            # Leerlauf Anfragen zu verbrennen.
            laeuft_etwas = any(g.get("laeuft") for g in stand["geraete"].values())
            rest = cfg["takt_betrieb"] if laeuft_etwas else cfg["takt_ruhe"]
            if fehler_folge >= 3:
                rest = min(3600, rest * min(8, fehler_folge))
                melde_gebremst("bremse",
                               f"{fehler_folge} Fehlversuche - naechster Abruf erst in {rest} s.",
                               1800)
            while rest > 0 and _LAUF:
                try:
                    if warteschlange(sitzung, cfg, z, marken, liste):
                        break
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Warteschlange: %s", fehlertext(err))
                time.sleep(1)
                rest -= 1
    finally:
        try:
            sitzung.close()
        except Exception:  # noqa: BLE001
            pass
    _LOG.info("Dienst beendet.")
    return 0


# ===========================================================================
# Anmeldung - wird von der Oberflaeche ueber die Warteschlange angestossen
# ===========================================================================
def hc_anmeldung_starten() -> int:
    """Device Flow, Schritt 1: Benutzercode holen und ablegen."""
    import requests
    cfg, z = config(), zugang()
    if not z["hc_client_id"]:
        print("[FEHL] Es ist keine Home-Connect-Client-ID hinterlegt.")
        return 1
    a = requests.post(hc_host(cfg) + "/security/oauth/device_authorization",
                      data={"client_id": z["hc_client_id"], "scope": HC_SCOPE}, timeout=30)
    if a.status_code != 200:
        print("[FEHL] Home Connect hat den Anmeldebeginn abgelehnt (HTTP %d): %s"
              % (a.status_code, (a.text or "")[:300]))
        return 1
    d = a.json() or {}
    json_schreiben(PDATA / "hc_anmeldung.json", {
        "device_code": d.get("device_code"),
        "user_code": d.get("user_code"),
        "verification_uri": d.get("verification_uri"),
        "verification_uri_complete": d.get("verification_uri_complete"),
        "laeuft_ab": int(time.time()) + ganz(d.get("expires_in"), 300),
        "intervall": ganz(d.get("interval"), 5),
    }, rechte=0o600)
    print("[OK]   Anmeldung begonnen. Code: %s" % d.get("user_code"))
    print("[OK]   Adresse: %s" % (d.get("verification_uri_complete") or d.get("verification_uri")))
    return 0


def hc_anmeldung_abschliessen() -> int:
    """Device Flow, Schritt 2: einmal nach dem Token fragen."""
    import requests
    cfg, z = config(), zugang()
    an = json_lesen(PDATA / "hc_anmeldung.json")
    if not an.get("device_code"):
        print("[FEHL] Es laeuft keine Anmeldung. Erst 'Anmeldung beginnen' druecken.")
        return 1
    if time.time() > ganz(an.get("laeuft_ab"), 0):
        print("[FEHL] Die Anmeldung ist abgelaufen (fuenf Minuten). Bitte neu beginnen.")
        return 1
    a = requests.post(hc_host(cfg) + "/security/oauth/token",
                      data={"grant_type": "device_code",
                            "device_code": an["device_code"],
                            "client_id": z["hc_client_id"]}, timeout=30)
    d = a.json() if a.content else {}
    if a.status_code != 200:
        grund = str(d.get("error") or a.status_code)
        if grund == "authorization_pending":
            print("[INFO] Noch nicht bestaetigt. Code im Browser eingeben und dann erneut "
                  "auf 'Anmeldung abschliessen' druecken.")
            return 2
        if grund == "expired_token":
            print("[FEHL] Die Anmeldung ist abgelaufen. Bitte neu beginnen.")
            return 1
        print("[FEHL] Home Connect hat abgelehnt: %s - %s"
              % (grund, d.get("error_description") or ""))
        return 1
    marken = token_lesen()
    marken["homeconnect"] = {
        "access_token": d.get("access_token"),
        "refresh_token": d.get("refresh_token"),
        "gueltig_bis": int(time.time()) + ganz(d.get("expires_in"), 86400),
    }
    token_schreiben(marken)
    try:
        (PDATA / "hc_anmeldung.json").unlink()
    except OSError:
        pass
    print("[OK]   Home Connect ist angemeldet.")
    return 0


def miele_anmeldung_abschliessen(code: str) -> int:
    """Authorization Code Flow: den vom Browser zurueckgegebenen Code eintauschen."""
    import requests
    z = zugang()
    cfg = config()
    if not z["miele_client_id"] or not z["miele_client_secret"]:
        print("[FEHL] Es sind keine Miele-Zugangsdaten hinterlegt.")
        return 1
    a = requests.post(MIELE_HOST + "/thirdparty/token",
                      data={"grant_type": "authorization_code",
                            "code": code,
                            "client_id": z["miele_client_id"],
                            "client_secret": z["miele_client_secret"],
                            "redirect_uri": str(cfg.get("miele_redirect")
                                                or "http://localhost")}, timeout=30)
    if a.status_code != 200:
        print("[FEHL] Miele hat den Code abgelehnt (HTTP %d): %s"
              % (a.status_code, (a.text or "")[:300]))
        return 1
    d = a.json() or {}
    marken = token_lesen()
    marken["miele"] = {
        "access_token": d.get("access_token"),
        "refresh_token": d.get("refresh_token"),
        "gueltig_bis": int(time.time()) + ganz(d.get("expires_in"), 2592000),
    }
    token_schreiben(marken)
    print("[OK]   Miele ist angemeldet.")
    return 0


# ===========================================================================
# Selbsttest
# ===========================================================================
def selbsttest() -> int:
    zeilen = []
    fehler = 0

    v = sys.version_info
    if v >= (3, 9):
        zeilen.append(f"[OK]   Python {v.major}.{v.minor}.{v.micro} (dieses Plugin verlangt 3.9)")
    else:
        fehler += 1
        zeilen.append(f"[FEHL] Python {v.major}.{v.minor}.{v.micro} ist zu alt")

    venv = SELF / "venv" / "bin" / "python3"
    zeilen.append(f"[{'OK]  ' if venv.exists() else 'FEHL]'} Virtuelle Umgebung: {venv}")
    if not venv.exists():
        fehler += 1
    try:
        import requests
        zeilen.append(f"[OK]   Bibliothek requests geladen, Fassung {requests.__version__}")
    except Exception as err:  # noqa: BLE001
        fehler += 1
        zeilen.append(f"[FEHL] Bibliothek requests laesst sich nicht laden: {err}")

    for name, pfad in (("Konfiguration", PCONFIG), ("Daten", PDATA), ("Log", PLOG)):
        schreibbar = os.access(pfad, os.W_OK) if pfad.exists() else False
        zeilen.append(f"[{'OK]  ' if schreibbar else 'FEHL]'} Ordner {name} beschreibbar: {pfad}")
        if not schreibbar:
            fehler += 1

    cfg = config()
    z = zugang()
    marken = token_lesen()

    ein = [a for a in ANBIETER if cfg.get({"homeconnect": "hc_ein", "miele": "miele_ein",
                                           "smartthings": "st_ein"}[a])]
    if ein:
        zeilen.append("[OK]   Eingeschaltete Anbieter: " + ", ".join(ein))
    else:
        fehler += 1
        zeilen.append("[FEHL] Es ist kein Anbieter eingeschaltet")

    if cfg.get("hc_ein"):
        # Ein Pruefknopf darf die FORM eines Geheimnisses beurteilen, nie
        # seinen Wert anzeigen.
        zeilen.append(f"[{'OK]  ' if z['hc_client_id'] else 'FEHL]'} Home Connect: Client-ID "
                      f"hinterlegt ({len(z['hc_client_id'])} Zeichen)")
        if not z["hc_client_id"]:
            fehler += 1
        hc = marken.get("homeconnect") or {}
        if hc.get("refresh_token"):
            rest = ganz(hc.get("gueltig_bis"), 0) - int(time.time())
            zeilen.append(f"[OK]   Home Connect: angemeldet, Zugriffstoken noch {max(0, rest)} s "
                          f"gueltig (wird selbst erneuert)")
        else:
            fehler += 1
            zeilen.append("[FEHL] Home Connect: nicht angemeldet - Reiter Einstellungen")
    if cfg.get("miele_ein"):
        zeilen.append(f"[{'OK]  ' if z['miele_client_id'] else 'FEHL]'} Miele: Client-ID "
                      f"hinterlegt ({len(z['miele_client_id'])} Zeichen)")
        if not z["miele_client_id"]:
            fehler += 1
        mi = marken.get("miele") or {}
        if mi.get("refresh_token"):
            zeilen.append("[OK]   Miele: angemeldet")
        else:
            fehler += 1
            zeilen.append("[FEHL] Miele: nicht angemeldet - Reiter Einstellungen")
    if cfg.get("st_ein"):
        if z["st_token"]:
            zeilen.append(f"[OK]   SmartThings: Token hinterlegt ({len(z['st_token'])} Zeichen)")
        else:
            fehler += 1
            zeilen.append("[FEHL] SmartThings: kein Token hinterlegt")
        zeilen.append("[INFO] SmartThings: seit dem 30.12.2024 laufen neu ausgestellte Personal "
                      "Access Tokens nach 24 Stunden ab. Das Token muss dann von Hand "
                      "erneuert werden - das ist kein Fehler dieses Plugins.")

    for name, p in (("Zugangsdatei", DATEI_ZUGANG), ("Markendatei", DATEI_TOKEN)):
        try:
            rechte = p.stat().st_mode & 0o777
            passt = (rechte & 0o077) == 0
            zeilen.append(f"[{'OK]  ' if passt else 'FEHL]'} Rechte {name}: {oct(rechte)} "
                          f"(erwartet 0o600)")
            if not passt:
                fehler += 1
        except OSError:
            zeilen.append(f"[INFO] {name} noch nicht angelegt: {p}")

    zeilen.append(f"[INFO] Takt {cfg['takt_ruhe']} s in Ruhe, {cfg['takt_betrieb']} s im Betrieb")
    zeilen.append(f"[INFO] Schreibende Befehle: "
                  f"{'zugelassen' if cfg.get('steuerung_ein') else 'gesperrt'}")

    m = mqtt_zustand()
    if not m["gefunden"]:
        fehler += 1
        zeilen.append("[FEHL] Im general.json des LoxBerry ist kein MQTT-Abschnitt zu finden")
    elif m["autostart"]:
        zeilen.append(f"[OK]   MQTT-Gateway auf Autostart, Broker {m['broker']}:{m['brokerport']}, "
                      f"UDP-Eingang {m['udpport']}")
    else:
        fehler += 1
        zeilen.append("[FEHL] Das MQTT-Gateway ist nicht auf Autostart gestellt "
                      "(System -> MQTT Gateway). Ohne das kommt am Miniserver nichts an.")

    lox = json_lesen(DATEI_LOXONE)
    if lox:
        alter = int(time.time()) - ganz(lox.get("ts"), 0)
        zeilen.append(f"[INFO] Letzter erfolgreicher Abruf vor {alter} s, "
                      f"{lox.get('anzahl_geraete')} Geraet(e)")
        for nummer, g in (lox.get("geraete") or {}).items():
            zeilen.append(f"[INFO] Geraet {nummer}: {g.get('name')} ({g.get('anbieter')}, "
                          f"{g.get('typ')}), Zustand {g.get('zustand_text') or '?'}")
        for anbieter, text in (lox.get("ausfaelle") or {}).items():
            zeilen.append(f"[FEHL] Anbieter {anbieter} hat nicht geantwortet: {text}")
    else:
        zeilen.append("[INFO] Es hat noch kein Abruf stattgefunden")

    zeilen.append("")
    zeilen.append("Nicht geprueft, weil dafuer Entwicklerkonten und Geraete noetig sind:")
    zeilen.append("  - ob die Anmeldung bei den Anbietern gelingt")
    zeilen.append("  - ob die Feldnamen der Antworten zu der Zuordnung hier passen")
    zeilen.append("  - ob die schreibenden Befehle am Geraet die erwartete Wirkung haben")
    print("\n".join(zeilen))
    return 1 if fehler else 0


def main() -> int:
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    if "--hc-anmelden" in sys.argv:
        return hc_anmeldung_starten()
    if "--hc-fertig" in sys.argv:
        return hc_anmeldung_abschliessen()
    if "--miele-code" in sys.argv:
        i = sys.argv.index("--miele-code")
        if i + 1 >= len(sys.argv):
            print("[FEHL] Es wurde kein Code uebergeben.")
            return 1
        return miele_anmeldung_abschliessen(sys.argv[i + 1])
    signal.signal(signal.SIGTERM, signal_behandeln)
    signal.signal(signal.SIGINT, signal_behandeln)
    try:
        return dienst(einmal="--einmal" in sys.argv)
    except KeyboardInterrupt:
        return 0
    except Exception as err:  # noqa: BLE001
        _LOG.error("Dienst abgebrochen: %s", fehlertext(err))
        zustand_schreiben(ok=0, fehler=fehlertext(err))
        return 1


if __name__ == "__main__":
    sys.exit(main())
