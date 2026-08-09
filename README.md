# LoxBerry-Plugin: Weissware Cloud

Bindet vernetzte **Hausgeräte dreier Ökosysteme** an Loxone an und bringt sie
auf **ein gemeinsames Modell**. Für den Miniserver sieht ein
Miele-Geschirrspüler danach genauso aus wie eine Bosch-Waschmaschine.

| Anbieter | Marken | Anmeldung |
|---|---|---|
| **Home Connect** | Bosch, Siemens, Neff, Gaggenau, Constructa | OAuth2 Device Flow |
| **Miele** | Miele@home (3rd Party API) | OAuth2 Authorization Code, Code von Hand |
| **SmartThings** | Samsung | Personal Access Token — **siehe Vorbehalt** |

> **Fassung 0.9.0 — ungeprüft.** Das Plugin wurde ohne Entwicklerkonten und
> ohne Geräte gebaut. Endpunkte und Datenformen stammen aus den
> Entwicklerdokumentationen, nicht aus einer Messung. Geprüft ist alles übrige:
> Oberfläche, Endpunkt, Absicherung, Warteschlange, Sprachdateien und die
> Zuordnung selbst — letztere gegen nachgebaute Antworten in der dokumentierten
> Form. Schreibende Befehle sind ab Werk gesperrt.

## Warum ein Plugin und nicht der native Baustein

Vier Gründe, in der Reihenfolge ihres Gewichts:

1. **Zwei Takte statt einem.** Ein starrer Zyklus ist entweder zu langsam für
   eine brauchbare Restzeit oder zu schnell für die Ratengrenzen. Dieses Plugin
   fragt weit ab, solange nichts läuft, und eng, sobald ein Gerät arbeitet —
   umgeschaltet wird von selbst. Token werden im Hintergrund erneuert; ein
   Ausfall eines Anbieters lässt die übrigen unberührt.
2. **Jeder Rohwert.** Was der Anbieter liefert, geht als Zahl oder Text weiter
   — Programm, Fortschritt, Schleuderdrehzahl, Energie, Wasser. Der Knopf
   *Rohdaten als JSON ansehen* zeigt zusätzlich die komplette Antwort.
3. **Jede Miniserver-Generation.** Der LoxBerry übernimmt die Arbeit und
   schickt dem Miniserver einfache HTTP-Antworten oder MQTT-Nachrichten. Ein
   Gen-1-Miniserver reicht.
4. **Ein Modell für alle Marken.** In der Loxone Config gibt es eine Sorte
   virtueller Eingänge, egal von wem das Gerät ist.

## Der Vorbehalt zu SmartThings

Samsung hat die Lebensdauer neu ausgestellter Personal Access Tokens am
**30.12.2024 auf 24 Stunden** verkürzt und die Ratengrenzen für neue Tokens
gesenkt. Für Dauerbetrieb verlangt Samsung eine „Service Integration“ — eine
registrierte Anwendung mit einem von außen erreichbaren Webhook. Das kann ein
LoxBerry hinter einem Router nicht ohne Weiteres.

**Praktisch heißt das: Sie müssen das Token täglich erneuern.** Das Plugin
sagt das in der Oberfläche, im Reiter *Test* und in jeder Fehlermeldung, statt
es zu verschweigen. Wer ein Token von vor dem 30.12.2024 hat, ist nicht
betroffen. Wer damit nicht leben will, lässt SmartThings aus — Home Connect und
Miele laufen unabhängig davon.

## Kein Python-Problem

Das Plugin spricht alle drei Schnittstellen selbst an und braucht dafür nur
`requests`. Grund: die verbreiteten fertigen Pakete verlangen ein Python, das
es auf keinem LoxBerry gibt.

| Paket | verlangt | Debian 12 (3.11) | Debian 13 (3.13) |
|---|---|---|---|
| `aiohomeconnect` ab 0.31 | Python 3.13 | nein | ja |
| `pymiele` ab 0.6.2 | **Python 3.14** | nein | **nein** |
| `pysmartthings` ab 4.0 | Python 3.13 | nein | ja |
| **dieses Plugin** | **Python 3.9** | **ja** | **ja** |

## Fernstart: die Sicherung, die bleibt

Kein Anbieter startet ein Programm aus der Ferne, solange nicht **am Gerät
selbst** die Fernstart-Freigabe gegeben wurde (Home Connect: Taste
*Fernstart*; Miele: *MobileStart*). Sie erlischt meist nach dem Programm. Das
Plugin führt sie als Wert `FERNSTART` und **weist einen Start ohne Freigabe von
sich aus ab**, statt in eine 409-Antwort des Anbieters zu laufen, deren Grund
in der Fehlermeldung untergeht.

Der gedachte Ablauf: Wäsche einfüllen, Programm wählen, Fernstart drücken —
und Loxone drückt auf Start, wenn die Sonne scheint.

## Aufbau

    bin/weissware.py          Abrufdienst (Python, eigene venv)
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    webfrontend/htmlauth/     Bedienoberfläche (fünf Reiter)
    webfrontend/html/         Endpunkt für den Miniserver + gemeinsame Bibliothek

Drei Aufgaben, drei Dateien. Weder Oberfläche noch Endpunkt sprechen je selbst
mit einem Anbieter — sie lesen den Zwischenspeicher und legen Befehle in einer
Warteschlange ab.

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*. Statt
der laufenden Nummer darf überall die Kennung des Anbieters stehen.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&geraet=N` | `WEISSWARE;OK=..;ZUSTAND=..;LAEUFT=..;FERTIG=..;VERBUNDEN=..;TUER=..;FORTSCHR=..;RESTMIN=..;STARTMIN=..;LAUFMIN=..;FERNSTART=..;FERNBED=..;NETZ=..;ALTER=..` plus eine Textzeile |
| `?token=T&aktion=verbrauch&geraet=N` | `VERBRAUCH;OK=..;ENERGIE=..;WASSER=..;TEMP=..;SCHLEUDER=..;ALTER=..` |
| `?token=T&aktion=geraete` | Liste aller erkannten Geräte |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=start&geraet=N` | am Gerät gewähltes Programm starten |
| `?token=T&aktion=start&geraet=N&programm=…` | bestimmtes Programm starten |
| `?token=T&aktion=stop` / `pause` / `fortsetzen` | Programm abbrechen, anhalten, fortsetzen |
| `?token=T&aktion=ein` / `aus` | Gerät ein- und ausschalten |
| `?token=T&aktion=abruf` | sofort abrufen statt auf den Takt zu warten |

`ZUSTAND` ist eine Stufe: `0` aus, `1` bereit, `2` läuft, `3` pausiert,
`4` fertig, `5` Störung.

**Ein Strich als Wert** heißt: dieser Wert liegt nicht vor. Es wird bewusst
keine 0 gesendet. Bei Miele ist das besonders wichtig — dort heißt `-32768`
ausdrücklich „gerade kein Wert“, und 0 Minuten Restzeit hieße „fertig“.

**Was `OK=1` nicht heißt.** Der Anbieter hat die Anfrage angenommen. Ob das
Gerät anläuft, zeigt erst der nächste Abruf; wer sicher sein will, wertet
`LAEUFT` aus.

## Was nicht jeder Anbieter liefert

| Wert | Home Connect | Miele | SmartThings |
|---|---|---|---|
| Fortschritt in Prozent | ja | gerechnet aus Rest- und Laufzeit | nein |
| Restzeit | ja (Sekunden, wird umgerechnet) | ja (Stunden/Minuten) | aus dem Fertigzeitpunkt |
| Energie und Wasser | **nein** | nur mit EcoFeedback | nein |
| Fernstart-Freigabe | ja | ja (`mobileStart`) | ja |

Was fehlt, ist ein Strich — keine 0.

## Datenschutz

Zugangsdaten und Anmeldemarken liegen in
`config/plugins/weissware/zugang.json` und
`data/plugins/weissware/token.json`, beide mit den Rechten 0600, und nie in der
Loxone-Projektdatei. Verbindungen gibt es nur zu den eingeschalteten Anbietern
und, bei der Installation, zu PyPI.

## Fassung 0.9.1 — nachgemessen und korrigiert

### Zugangsdaten werden unteilbar geschrieben, Rechte vor dem Umbenennen

`zugang.json` hält die Client-Geheimnisse von Home Connect und Miele sowie das
SmartThings-Token. Geschrieben wurde sie mit `file_put_contents`, **danach**
kam `chmod 0600`. Zwei Dinge daran:

* Zwischen Anlegen und `chmod` steht die Datei mit den Rechten aus der umask
  da — mit den Geheimnissen bereits darin. Jetzt werden die Rechte an der
  Nebendatei gesetzt, **bevor** sie an ihren Platz umbenannt wird.
* Der Dienst liest dieselbe Datei. `file_put_contents` kürzt sie zuerst auf
  null; wer in diesem Augenblick liest, sieht keine Zugangsdaten und meldet
  sich vergeblich an. `rename()` ist unteilbar.

### Der Plugin-Ordner wird ermittelt, nicht geraten

`ww_paths()` fiel auf den festen Namen `weissware` zurück, sobald
`config/plugins/<ordner>` noch fehlte. Hängt LoxBerry bei einer
Zweitinstallation einen Zähler an (`weissware_01`), zeigten deren Pfade damit
auf die **erste** Installation — gemeinsame `zugang.json` mit den Geheimnissen
dreier Anbieter, gemeinsame Warteschlange, gemeinsames Protokoll. Maßgeblich
ist jetzt `LBPPLUGINDIR`.

### Eine leere Befehlsdatei konnte in die Warteschlange geraten

`ww_befehl_senden()` schrieb `json_encode($befehl)` direkt weiter. Gibt
`json_encode` bei ungültigem UTF-8 `false` zurück, macht `file_put_contents`
daraus eine leere Zeichenkette, schreibt null Byte und meldet **Erfolg** — der
Rückgabewert ist `0`, nicht `false`, die Prüfung auf `=== false` greift also
nicht. `ww_config_speichern()` im selben Modul macht es seit jeher richtig.

Dazu: Der `User-Agent` an die drei Herstellerclouds trug noch `0.9.0`.

Vierzehn Punkte aus einer Durchsicht. Zehn trafen zu, drei teilweise, einer
nicht. Bemerkenswert an dieser Liste: die Hälfte sind Altlasten aus fremden
Plugins, aus denen Bausteine übernommen wurden. Die waren alle real.

### Altlasten aus fremden Plugins

| Fundstelle | war | ist |
|---|---|---|
| `ww_vorlage()` | `'AUDI_' . $nummer . '_' . $feld` | `'WW_' …` |
| `ww_paths()`, Ersatzzweig | `config/vw.backup.json` | `config/weissware.backup.json` |
| vor `ww_zugang_speichern()` | zwei PHPDoc-Blöcke übereinander | einer |
| vor `ww_verbrauch_felder()` | „Die Werte des **Lade**-Endpunkts" *und* „…des Verbrauchs-Endpunkts" | nur der richtige |
| nach `ww_verbrauch_felder()` | „Die Werte des Wartungs-Endpunkts" ohne Code dahinter | entfernt |
| `htmlauth/index.php` | ein `\1` vor `<h2>` — Rest einer Suchen-und-Ersetzen-Aktion | entfernt |

Das `AUDI_` war der folgenreichste: in Loxone Config wären die Bausteine
unter fremdem Namen gelandet. Das `\1` stand sichtbar in der Oberfläche.

### Doppelter Block in der Selbstprüfung

Trifft zu, und die Beschreibung war genau: ohne Ausfälle erschien
*„keine Ausfälle"* zweimal, mit Ausfällen wurden sie erst einzeln je Anbieter
und danach noch einmal gesammelt aufgeführt. Der zweite Block ist weg — die
Einzelaufstellung bleibt, sie nennt den Grund.

### Zugriffsrechte beim Schreiben der Token-Datei

Trifft zu. `json_schreiben()` legte die Nebendatei mit `tmp.open("w")` an und
setzte die Rechte erst danach. Gemessen mit `umask 022`:

| | während des Schreibens | danach |
|---|---|---|
| bisher | **0644** | 0600 |
| jetzt (`os.open` mit `mode`) | 0600 | 0600 |

In `token.json` steht ein gültiger Zugang zu drei Herstellerclouds.

### Weitere zutreffende Punkte

**Antwortdateien** blieben nach dem Lesen liegen — `unlink` ergänzt.

**Wartezeit aus dem Webfrontend** war auf 30 s gedeckelt; jetzt 12. Ein
Webserver bricht vorher mit 504 ab, und der Dienst arbeitet den Befehl
ohnehin zu Ende — die Warteschlange liegt im Dateisystem, nicht in der
Anfrage.

**Nicht löschbare Befehlsdatei.** Der Fehler wurde verschluckt und der Befehl
trotzdem ausgeführt; die Datei blieb liegen und wurde in jedem weiteren
Durchgang erneut abgearbeitet. Bei einem Abruf wäre das nur Last — bei
„Waschmaschine starten" läuft das Gerät jede Runde neu an. Jetzt wird der
Befehl in diesem Fall **nicht** ausgeführt und der Grund gemeldet.

**Alte PID-Datei** blieb liegen, wenn der Prozess fort war. Sie wird jetzt
entfernt, sobald sie sich als Leiche erweist.

**Cron über `/bin/bash`.** Umgesetzt: geht das Ausführungsrecht verloren,
schlüge der unmittelbare Aufruf lautlos fehl — die Ausgabe geht nach
`/dev/null`.

**`hc_anmeldung.json` beim Verwerfen.** Umgesetzt. Blieb die Datei liegen,
versuchte der nächste Anmeldeversuch die alte, längst abgelaufene Sitzung
abzuschließen — genau das soll der Knopf verhindern.

**Protokoll ganz eingelesen.** Der Speicherhinweis war berechtigt, `tail` ist
aber der langsamste der drei Wege (rund 1,9 ms gegen 0,05 ms beim
Rückwärtslesen mit `fseek`). Umgestellt auf `fseek`.

### Was nicht zutraf

**Fehlende Timeouts bei `requests`.** Jeder einzelne Aufruf in
`weissware.py` trägt `timeout=30` — vierzehn Stellen, nachgezählt. Richtig
an dem Punkt ist nur die Folge: 30 s sind lang genug, dass `dienst.sh stop`
in den harten `kill -9` nach zehn Sekunden laufen kann. Das ist aber ein
Abwägung zwischen „Abruf abbrechen" und „sauber beenden", kein fehlender
Timeout.

### Nebenbefunde

**Die Prozessprüfung war zu weich.** `grep -qa "weissware.py"` über die ganze
Befehlszeile: hat eine wiederverwendete Prozessnummer einen Editor mit
geöffneter `weissware.py` erwischt, galt der als laufender Dienst. Geprüft
werden jetzt zwei Dinge argumentweise — argv[1] ist genau das Skript, argv[0]
ist ein Python. Nur das erste zu prüfen reicht nicht: `nano <pfad>/weissware.py`
führt den Pfad ebenfalls als zweites Argument.

**Die fünf Reiter brauchten JavaScript.** `sm-active` wurde ausschließlich
vom Skript vergeben — ohne JavaScript war keine Fläche sichtbar. Reihenfolge,
Positivliste und Beschriftung kommen jetzt aus einem einzigen Feld, der
Server setzt die Klasse selbst.

**Es gab kein Uninstall-Skript.** Die Sicherungen mit den Zugangsdaten von bis
zu drei Herstellerclouds liegen neben dem Konfigordner — gelöscht wird beim
Deinstallieren nur das Verzeichnis. Sie wären für immer auf der Karte
geblieben. `uninstall/uninstall` gibt es jetzt; es hält den Dienst an,
überschreibt Sicherungen, Anmeldemarken und die halbe Anmeldung und entfernt
sie.

## Lizenz

MIT — siehe [LICENSE](LICENSE).

Home Connect, Bosch, Siemens, Neff, Gaggenau und Constructa sind Marken der
BSH Hausgeräte GmbH, Miele und Miele@home Marken der Miele & Cie. KG,
SmartThings und Samsung Marken der Samsung Electronics Co., Ltd. Dieses
Projekt steht in keiner Verbindung zu diesen Unternehmen und wird von ihnen
weder herausgegeben noch unterstützt; es benutzt lediglich deren öffentlich
angebotene Schnittstellen. Alle drei können sie ohne Ankündigung ändern, womit
dieses Plugin ganz oder teilweise unbrauchbar würde.
