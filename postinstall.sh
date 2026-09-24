#!/bin/bash
# Weissware Cloud - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Legt an: Konfigurations-, Daten- und Logordner, die Zugangsdatei mit Rechten
# 0600 und die virtuelle Python-Umgebung.
#
# Dieses Plugin braucht genau EINE fremde Bibliothek: requests. Alle drei
# Anbieter (Home Connect, Miele, SmartThings) werden ueber ihre dokumentierten
# REST-Schnittstellen angesprochen. Grund: die verbreiteten fertigen Pakete
# verlangen ein Python, das es auf keinem LoxBerry gibt - aiohomeconnect ab
# 0.31 verlangt 3.13, pymiele ab 0.6.2 sogar 3.14. So laeuft das Plugin ab
# Python 3.9, also auf Debian 12 UND 13.
#
# WICHTIG (PEP 668): Debian 12/13 kennzeichnen die System-Python-Umgebung als
# extern verwaltet. Ein systemweites "pip3 install" wird abgewiesen - auch mit
# --user, auch als root. Deshalb eine eigene venv, und der Shebang der Skripte
# zeigt direkt darauf. JEDER Rueckgabewert wird geprueft.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-weissware}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Ohne brauchbares $5/LBHOMEDIR wird aufwaerts GESUCHT, nicht gerechnet:
# Wurzel ist, was config/plugins, data/plugins UND config/system/general.json
# traegt (Regeln/06) - dieselbe Regel wie in uninstall/uninstall. Bis 0.9.28
# stand hier BASE=$(cd "$SELF/../.."), ungeprueft. Gemessen am 19.09.2026 in
# WSL (Pruefung-Weissware-0.9.29, messe_h2.sh): abgelegt unter
# <fremd>/pruef/weissware/ legte das Skript in <fremd> (ohne general.json)
# data/, log/ und config/plugins/weissware an und stellte dort eine
# weissware.json aus dessen Sicherung wieder her (Fall I1); abgelegt tief
# unter einer echten Wurzel richtete es alles zwei Ebenen ueber sich ein,
# nicht in der Wurzel (Fall I3). Ohne Wurzel wird nichts angelegt.
ww_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if [ -d "$v/config/plugins" ] && [ -d "$v/data/plugins" ] \
           && [ -f "$v/config/system/general.json" ]; then
            printf '%s\n' "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    BASE=$(ww_wurzel_suchen)
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - nichts angelegt, nichts eingerichtet."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"

# ---------- Die Marke "Aktualisierung laeuft" faellt hier ----------
# preupgrade.sh legt sie als Erstes an (data/plugins/<ordner>.upgrade_laeuft);
# solange sie liegt, startet bin/dienst.sh keinen Dienst.
#
# Warum hier und nicht in postupgrade.sh: postupgrade.sh ruft nur dieses
# Skript ein zweites Mal auf. Der Dienststart steht HIER, weiter unten - und
# die Marke muss NACH ihm fallen, nicht davor: zwischen dem "touch
# soll_laufen" in bin/dienst.sh und dem Schreiben der PID-Datei ist ein
# Fenster offen, in dem der Minutentakt denselben Dienst ein zweites Mal
# startet, sobald die Marke weg ist. Der eigene Start bekommt deshalb die
# Ausnahme WW_START_TROTZ_MARKE=1. In WSL gegen eine Waechterschleife ohne
# Pause gemessen (Pruefung-Weissware-0.9.27, messe_reihenfolge.sh): ohne
# Marke in 3 von 200 Durchgaengen zwei oder drei Dienste, Marke vor dem
# Start 1 von 100, Marke nach dem Start 0 von 100.
#
# Entfernt wird sie ueber einen trap auf EXIT, nicht am Dateiende: dieses
# Skript steigt an mehreren Stellen mit "exit 1" aus (Python, venv, pip). Ohne
# trap bliebe der Dienst nach einer gescheiterten Installation eine Stunde
# gesperrt, ohne dass irgendwo stuende, warum (Fall C13).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
ww_marke_weg() { rm -f "$MARKE"; }
trap ww_marke_weg EXIT

# Auf eine Fassung festgenagelt, damit eine Installation von heute morgen und
# eine von heute abend dasselbe ergeben.
REQUESTS="2.32.3"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" "$PDATA/befehle" "$PDATA/antworten" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ---------- Konfiguration ----------
[ -f "$PCONFIG/weissware.json" ] || echo '{}' > "$PCONFIG/weissware.json"
[ -f "$PCONFIG/zugang.json" ] || echo '{}' > "$PCONFIG/zugang.json"
chmod 600 "$PCONFIG/zugang.json"

# Ist diese Datei gueltiges JSON UND traegt sie darin mindestens einen der
# genannten Schluessel mit einem nicht leeren Wert? Wortgleich mit
# preupgrade.sh - die beiden Skripte muessen dieselbe Frage gleich
# beantworten.
#
# Bis 0.9.25 stand hier "[ ! -s "$CF" ] || [ "$INHALT" = "{}" ]" - ein
# Entscheid nach der FORM. Eine abgeschnittene weissware.json ist weder leer
# noch "{}"; sie wurde also NICHT zurueckgeholt, und der erste Aufruf der
# Oberflaeche wuerfelte danach ein neues Aktionstoken (Regeln/05; gemessen
# 18.09.2026, Pruefung-Weissware-0.9.25, Messstelle postinstall).
ww_hat_wert() {
    D=$1
    shift
    [ -f "$D" ] || return 1
    if command -v php >/dev/null 2>&1; then
        php -r '$d=json_decode((string)@file_get_contents($argv[1]),true); $rc=1; if (is_array($d)) { for ($i=2;$i<$argc;$i++) { $k=$argv[$i]; if (isset($d[$k]) && trim((string)$d[$k]) !== "") { $rc=0; break; } } } exit($rc);' "$D" "$@" >/dev/null 2>&1
        return $?
    fi
    echo "<INFO> Kein PHP gefunden - die Konfiguration wird nur ueberschlaegig geprueft."
    [ "$(tr -d ' \t\n\r' < "$D" 2>/dev/null | tail -c 1)" = "}" ] || return 1
    for k in "$@"; do
        if grep -q "\"$k\"[[:space:]]*:[[:space:]]*\"[^\"]\{1,\}\"" "$D" 2>/dev/null; then
            return 0
        fi
    done
    return 1
}

for f in weissware.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    [ -f "$BK" ] || continue
    case "$f" in
        weissware.json) SCHLUESSEL="aktionstoken" ;;
        *)              SCHLUESSEL="hc_client_secret miele_client_secret st_token" ;;
    esac
    # Zurueckgeholt wird, wenn die Sicherung traegt und die Konfiguration
    # nicht. Der verdraengte Stand wird nicht weggeworfen: er liegt als
    # <datei>.kaputt daneben (0600 - es koennen Zugangsdaten darin stehen),
    # wie in ww_selbstheilung().
    if ww_hat_wert "$BK" $SCHLUESSEL && ! ww_hat_wert "$CF" $SCHLUESSEL; then
        if [ -s "$CF" ] && [ "$(tr -d ' \t\n\r' < "$CF" 2>/dev/null)" != "{}" ]; then
            cp -p "$CF" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null
            echo "<INFO> Der bisherige Inhalt von $f liegt als $f.kaputt daneben."
        fi
        cp -p "$BK" "$CF" && echo "<OK> $f aus Sicherung wiederhergestellt."
    fi
done
chmod 600 "$PCONFIG/zugang.json"
# weissware.json traegt das Aktionstoken, mit dem der Endpunkt schaltende
# Befehle annimmt. Bis 0.9.17 bekam nur zugang.json 0600 - wer auf dem
# LoxBerry lesen durfte, konnte damit Geraete schalten.
chmod 600 "$PCONFIG/weissware.json"

# ---------- Daten aus dem Upgrade zuruecklegen ----------
# plugininstall.pl raeumt data/plugins/<ordner>/ beim Upgrade ab. preupgrade.sh
# hat diese drei Dateien danebengelegt; ohne sie waere nach jedem Update die
# Anmeldung an drei Herstellerclouds fort und die Geraetenummern - also die
# Adressen in Loxone - wuerden neu vergeben.
#
# Ist diese Datei gueltiges JSON mit mindestens einem Eintrag? Wortgleich mit
# preupgrade.sh - die beiden Skripte muessen dieselbe Frage gleich
# beantworten.
#
# Warum nicht ww_hat_wert(): fuer die drei Datendateien gibt es keinen festen
# Schluesselnamen, den man abfragen koennte - geraetenummern.json fuehrt die
# Geraetekennungen selbst als Schluessel, laeufe.json eine Liste. Gefragt wird
# deshalb nach dem, was sie gemeinsam haben: lesbares JSON, das etwas enthaelt.
#
# Bis 0.9.25 stand hier "[ -f "$BK" ] && [ ! -s "$DF" ]" - ein Entscheid nach
# der GROESSE. Eine abgeschnittene Datei ist nicht leer und bestand ihn.
# Gemessen 18.09.2026 (Bestand-2026-09-18/klasse-C, Fall 10; nachgestellt in
# Pruefung-Weissware-0.9.26, Faelle C1 bis C3): die Anmeldung an drei
# Herstellerclouds wurde nicht zurueckgeholt, und die Geraetenummern - also
# die Adressen in Loxone - wurden neu vergeben.
ww_json_traegt() {
    D=$1
    [ -f "$D" ] || return 1
    if command -v php >/dev/null 2>&1; then
        php -r '$d=json_decode((string)@file_get_contents($argv[1]),true); exit((is_array($d) && count($d) > 0) ? 0 : 1);' "$D" >/dev/null 2>&1
        return $?
    fi
    echo "<INFO> Kein PHP gefunden - die Datendatei wird nur ueberschlaegig geprueft."
    # Schwaecherer Ersatz: nicht leer, nicht das leere Objekt, und sie endet
    # auf eine schliessende Klammer. Erkennt die uebliche abgeschnittene
    # Datei, aber nicht eine, die zufaellig hinter einem verschachtelten
    # Block endet.
    I=$(tr -d ' \t\n\r' < "$D" 2>/dev/null)
    case "$I" in
        ''|'{}'|'[]') return 1 ;;
    esac
    case "$I" in
        *'}'|*']') return 0 ;;
    esac
    return 1
}

for f in token.json geraetenummern.json laeufe.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    DF="$PDATA/$f"
    # Zurueckgeholt wird, wenn die Sicherung Inhalt traegt und der Datenstand
    # nicht. Der verdraengte Stand wird nicht weggeworfen: er liegt als
    # <datei>.kaputt daneben (0600 - in token.json steht ein gueltiger Zugang
    # zu drei Herstellerclouds), wie in ww_selbstheilung().
    if ww_json_traegt "$BK" && ! ww_json_traegt "$DF"; then
        if [ -s "$DF" ]; then
            cp -p "$DF" "$DF.kaputt" 2>/dev/null && chmod 600 "$DF.kaputt" 2>/dev/null
            echo "<INFO> Der bisherige Inhalt von $f liegt als $f.kaputt daneben."
        fi
        cp -p "$BK" "$DF" && echo "<OK> $f aus Sicherung wiederhergestellt."
    fi
done
chmod 600 "$PDATA/token.json" 2>/dev/null || true

# ---------- Python suchen ----------
PY=""
for k in python3.13 python3.12 python3.11 python3.10 python3.9; do
    if command -v "$k" >/dev/null 2>&1; then PY="$k"; break; fi
done
if [ -z "$PY" ] && command -v python3 >/dev/null 2>&1; then
    if python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3,9) else 1)'; then
        PY="python3"
    fi
fi
if [ -z "$PY" ]; then
    HAVE=$(python3 -V 2>&1 || echo "kein python3")
    echo "<FAIL> Es wurde kein Python 3.9 oder neuer gefunden (gefunden: $HAVE)."
    echo "<FAIL> Das Plugin bleibt installiert, der Dienst kann aber nicht starten."
    exit 1
fi
echo "<INFO> Verwendetes Python: $PY ($($PY -V 2>&1))"

# ---------- virtuelle Umgebung ----------
BRAUCHBAR=0
if [ -x "$VENV/bin/python3" ]; then
    if "$VENV/bin/python3" -c 'import sys; sys.exit(0 if sys.version_info >= (3,9) else 1)' 2>/dev/null; then
        BRAUCHBAR=1
    fi
fi
if [ "$BRAUCHBAR" -eq 0 ]; then
    rm -rf "$VENV"
    if ! "$PY" -m venv "$VENV"; then
        echo "<FAIL> Virtuelle Umgebung konnte nicht angelegt werden ($VENV)."
        echo "<FAIL> Fehlt das Paket python3-venv? (apt install python3-venv)"
        exit 1
    fi
    echo "<OK> Virtuelle Umgebung angelegt: $VENV"
fi
if [ ! -x "$VENV/bin/python3" ]; then
    echo "<FAIL> $VENV/bin/python3 fehlt - Abbruch."
    exit 1
fi

"$VENV/bin/python3" -m pip install --upgrade pip setuptools wheel >/dev/null 2>&1 || \
    echo "<INFO> pip liess sich nicht aktualisieren - wird mit der vorhandenen Fassung versucht."

echo "<INFO> Installiere requests $REQUESTS (benoetigt eine Internetverbindung) ..."
if ! "$VENV/bin/python3" -m pip install --no-cache-dir "requests==$REQUESTS"; then
    echo "<INFO> Feste Fassung nicht installierbar - versuche die neueste."
    if ! "$VENV/bin/python3" -m pip install --no-cache-dir "requests"; then
        echo "<FAIL> requests konnte nicht installiert werden."
        echo "<FAIL> Haeufigste Ursachen: keine Internetverbindung, oder PyPI war"
        echo "<FAIL> nicht erreichbar."
        exit 1
    fi
    echo "<INFO> ERSATZWEG: Es wurde die neueste Fassung statt $REQUESTS installiert."
fi

if ! "$VENV/bin/python3" -c 'import requests' 2>/dev/null; then
    echo "<FAIL> requests ist installiert, laesst sich aber nicht laden."
    exit 1
fi
IST=$("$VENV/bin/python3" -c 'import requests; print(requests.__version__)' 2>/dev/null || echo "unbekannt")
echo "<OK> requests geladen, Fassung $IST"

# ---------- Rechte ----------
chmod 755 "$PBIN/weissware.py" 2>/dev/null
chmod 755 "$PBIN/dienst.sh" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 600 "$PCONFIG/zugang.json"
chmod 600 "$PDATA/token.json" 2>/dev/null

# ---------- Dienst wieder anwerfen ----------
# Der Merker stammt aus preupgrade.sh und sagt, dass der Dienst vor dem
# Upgrade laufen sollte. Bis 0.9.17 gab es ihn nicht: nach jedem Auto-Update
# lag das Plugin still, bis jemand von Hand auf "Dienst starten" drueckte -
# und weil 'soll_laufen' im abgeraeumten Datenordner lag, griff auch der
# minuetliche Waechter nicht.
LIEF="$BASE/config/plugins/$PFOLDER.backup.lief"
# Liegt die Marke, laeuft gerade ein Upgrade: dann darf hier KEIN Dienst mehr
# laufen - auch keiner ohne PID-Datei, die hat purge_installation mit dem
# Datenordner geloescht. Gemessen (Pruefung-Weissware-0.9.27, Fall R4): ein
# Knopfdruck "Dienst starten" zwischen preupgrade.sh und purge_installation -
# dort ist noch die ALTE Fassung installiert, die keine Marke kennt - liess
# einen Dienst ohne PID-Datei zurueck, und nach dem Upgrade liefen zwei.
# bin/dienst.sh stop beendet seit dieser Fassung auch Dienste ohne PID-Datei,
# argumentweise (waisen_beenden).
if [ -f "$MARKE" ] && [ -x "$PBIN/dienst.sh" ]; then
    WW_HALT=$("$PBIN/dienst.sh" stop 2>&1)
    case "$WW_HALT" in
        angehalten*) echo "<INFO> Waehrend des Upgrades lief ein Dienst: $WW_HALT" ;;
    esac
fi
DIENST_LIEF=0
if [ -f "$LIEF" ]; then
    DIENST_LIEF=1
    if [ -x "$PBIN/dienst.sh" ] && WW_START_TROTZ_MARKE=1 "$PBIN/dienst.sh" start >/dev/null 2>&1; then
        echo "<OK> Der Dienst wurde wieder gestartet."
    else
        echo "<INFO> Der Dienst lief vor dem Upgrade, liess sich aber nicht"
        echo "<INFO> starten. Bitte im Reiter Einstellungen nachsehen."
    fi
    rm -f "$LIEF"
fi

# ---------- Schlusszeile ----------
# Dieses Skript laeuft bei der Erstinstallation UND bei jedem Upgrade
# (plugininstall.pl uebergibt kein Kennzeichen). Bis 0.9.30 standen hier nach
# jedem Upgrade die "Naechsten Schritte" samt "Zugangsdaten eintragen",
# obwohl sie oben gerade zurueckgespielt worden waren - wer das liest, haelt
# sie fuer verloren, und der Fall, in dem sie es wirklich sind, sieht
# genauso aus.
# Entschieden wird nach dem INHALT von zugang.json nach dem Zurueckspielen,
# mit derselben Pruefung wie fuer die Sicherung oben (ww_hat_wert mit
# hc_client_secret, miele_client_secret, st_token), nicht nach der
# Upgrade-Marke. Traegt die Datei keines davon, erscheint die Anleitung.
# Gemessen am 24.09.2026: Pruefung-Weissware-0.9.31/postinstall_hinweis.md.
if ww_hat_wert "$PCONFIG/zugang.json" hc_client_secret miele_client_secret st_token; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen (Zugangsdaten vorhanden)."
    if [ "$DIENST_LIEF" = 0 ]; then
        echo "<INFO> Der Dienst lief vor dem Upgrade nicht und wurde nicht gestartet"
        echo "<INFO> (Reiter Einstellungen)."
    fi
else
    echo "<OK> Installation abgeschlossen."
    echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche, Reiter Einstellungen:"
    echo "<INFO>   1. Anbieter einschalten, die Sie haben"
    echo "<INFO>   2. Zugangsdaten eintragen (Home Connect und Miele brauchen ein"
    echo "<INFO>      kostenloses Entwicklerkonto), speichern"
    echo "<INFO>   3. Anmelden (Home Connect ueber Code, Miele ueber Browser)"
    echo "<INFO>   4. Dienst starten"
fi
exit 0
