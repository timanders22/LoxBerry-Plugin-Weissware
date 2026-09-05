# LoxBerry-Plugin: Weissware Cloud

Bindet vernetzte **Hausgeräte dreier Ökosysteme** an Loxone an und bringt sie
auf **ein gemeinsames Modell**. Für den Miniserver sieht ein
Miele-Geschirrspüler danach genauso aus wie eine Bosch-Waschmaschine.

| Anbieter | Marken | Anmeldung |
|---|---|---|
| **Home Connect** | Bosch, Siemens, Neff, Gaggenau, Constructa | OAuth2 Device Flow |
| **Miele** | Miele@home (3rd Party API) | OAuth2 Authorization Code, Code von Hand |
| **SmartThings** | Samsung | Personal Access Token — **siehe Vorbehalt** |

> **Fassung 0.9.18 — ungeprüft.** Das Plugin wurde ohne Entwicklerkonten und
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
| `?token=T&aktion=status&geraet=N` | `WEISSWARE;OK=..;ZUSTAND=..;LAEUFT=..;FERTIG=..;VERBUNDEN=..;TUER=..;FORTSCHR=..;RESTMIN=..;STARTMIN=..;LAUFMIN=..;FERNSTART=..;FERNBED=..;NETZ=..;ALTER=..;FERTIGUM=..` plus eine Textzeile |
| `?token=T&aktion=verbrauch&geraet=N` | `VERBRAUCH;OK=..;ENERGIE=..;WASSER=..;TEMP=..;SCHLEUDER=..;ALTER=..` |
| `?token=T&aktion=geraete` | Liste aller erkannten Geräte |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=start&geraet=N` | am Gerät gewähltes Programm starten |
| `?token=T&aktion=start&geraet=N&programm=…` | bestimmtes Programm starten — **nur Home Connect** |
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
| Energie und Wasser | **nein** | nur mit EcoFeedback | nur Energie |
| Fernstart-Freigabe | ja | ja (`mobileStart`) | ja |

Was fehlt, ist ein Strich — keine 0.

## Datenschutz

Zugangsdaten und Anmeldemarken liegen in
`config/plugins/weissware/zugang.json` und
`data/plugins/weissware/token.json`, beide mit den Rechten 0600, und nie in der
Loxone-Projektdatei. Verbindungen gibt es nur zu den eingeschalteten Anbietern,
bei der Installation zu PyPI und — wenn die Ansage eingeschaltet ist — zu dem
Audio-Server, dessen Adresse Sie im Reiter Test eintragen.

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
| vor `ww_verbrauch_felder()` | „Die Werte des **Lade**-Endpunkts“ *und* „…des Verbrauchs-Endpunkts“ | nur der richtige |
| nach `ww_verbrauch_felder()` | „Die Werte des Wartungs-Endpunkts“ ohne Code dahinter | entfernt |
| `htmlauth/index.php` | ein `\1` vor `<h2>` — Rest einer Suchen-und-Ersetzen-Aktion | entfernt |

Das `AUDI_` war der folgenreichste: in Loxone Config wären die Bausteine
unter fremdem Namen gelandet. Das `\1` stand sichtbar in der Oberfläche.

### Doppelter Block in der Selbstprüfung

Trifft zu, und die Beschreibung war genau: ohne Ausfälle erschien
*„keine Ausfälle“* zweimal, mit Ausfällen wurden sie erst einzeln je Anbieter
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
„Waschmaschine starten“ läuft das Gerät jede Runde neu an. Jetzt wird der
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
`weissware.py` trägt `timeout=30` — 17 Stellen, nachgezählt. Richtig
an dem Punkt ist nur die Folge: 30 s sind lang genug, dass `dienst.sh stop`
in den harten `kill -9` nach zehn Sekunden laufen kann. Das ist aber eine
Abwägung zwischen „Abruf abbrechen“ und „sauber beenden“, kein fehlender
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
Positivliste und Beschriftung kommen jetzt aus einem Feld, der Server setzt
die Klasse selbst. Die `id` der Bereiche im Rumpf ist die zweite Stelle —
sie lässt sich nicht miterzeugen, deshalb misst der Reiter Test die
Übereinstimmung aller drei Stellen nach.

**Es gab kein Uninstall-Skript.** Die Sicherungen mit den Zugangsdaten von bis
zu drei Herstellerclouds liegen neben dem Konfigordner — gelöscht wird beim
Deinstallieren nur das Verzeichnis. Sie wären für immer auf der Karte
geblieben. `uninstall/uninstall` gibt es jetzt; es hält den Dienst an,
überschreibt Sicherungen, Anmeldemarken und die halbe Anmeldung und entfernt
sie.

## Fassung 0.9.7 — Befunde behoben, acht Funktionen ergänzt

### Vier schwere Befunde

* **Die Baustein-Liste widersprach sich.** Typ- und Eingangsspalte stammten
  wörtlich aus AudiConnect, Namen und Parameter waren für Weissware neu
  geschrieben — um eine Zeile versetzt. Zehn von 34 Zeilen trugen dadurch
  einen Typ, der nicht zu ihrem Parameter passte (ein virtueller Ausgang mit
  Schwellwerten, eine Benachrichtigung mit „Ein > 0,5“). Die Kette ist neu
  gelegt: streng zweieingängige UND/ODER, keine Vorwärtsverweise. Alle 22
  Namen sind die bisherigen.
* `htmlauth/index.php` gab `$ww_fz['modell']` aus — das Feld gibt es nicht.
  Unter PHP 8 stand die Warnung **in der Tabellenzelle**.
* Die Prüfzeile „Wie frisch ist das Abbild?“ rechnete mit `$cfg['intervall']`,
  einem Schlüssel, den es nirgends gibt. Die Schwelle lag damit unabhängig vom
  eingestellten Takt immer bei 600 s — ein rotes Kreuz, das nichts bedeutet.
* **Ein Programmschlüssel wurde bei Miele und SmartThings verworfen** und der
  Befehl trotzdem mit `OK=1` quittiert. Er wird jetzt abgewiesen; er wirkt nur
  bei Home Connect.

Dazu: `SOC=` in der Gegenprobe-Tabelle, `ST_BEFEHL` fest auf
`washerOperatingState` (SmartThings-Geschirrspüler wurden gelesen, aber nicht
geschaltet), die stillschweigend auf 12 s gedeckelte Wartezeit, die Reiterleiste
als Schleife — und „aus der **Audi**-Schnittstelle“ im Reiter Einstellungen.

### Neue Funktionen

* **Feste Gerätenummern.** Die Nummer entstand aus der Position in einer
  sortierten Liste. Fiel ein Anbieter aus, rückten die übrigen Geräte auf, und
  virtueller Eingang, MQTT-Thema und Ausgangsadresse zeigten still auf ein
  anderes Gerät. Die Zuordnung steht jetzt in
  `data/plugins/weissware/geraetenummern.json`; der erste Lauf nummeriert
  genau so, wie bisher gezählt wurde, damit bestehende Anlagen ihre Adressen
  behalten.
* **Nachfass-Abruf** nach jedem angenommenen Schaltbefehl statt Warten auf den
  Takt.
* **`FERTIGUM`** — der voraussichtliche Fertigzeitpunkt wurde gerechnet und
  nirgends ausgeliefert.
* **`ts`, `fehler_folge` und Ausfälle über MQTT**, auch bei einer Störung. Über
  MQTT gibt es kein Alter; die Gegenseite rechnet es aus dem Zeitstempel.
* **Trockenlauf** — zeigt, welche Sperre greift und welche Anfrage hinausginge,
  und sendet nichts. Er läuft durch denselben Code wie ein echter Befehl.
* **Mitschnitt** des Datenverkehrs: ab Werk aus, feste Frist, 500 kB Grenze,
  Zugangsdaten und Token werden vor dem Schreiben entfernt.
* **Vorlagen** für Status, Verbrauch, virtuelle **Ausgänge** und den MQTT-Weg,
  je erkanntem Gerät ein Knopf. Angelegt wird nur, was das Gerät beim letzten
  Abruf geliefert hat — sonst trägt Loxone `DefVal="0"` ein, und eine 0 sieht
  aus wie ein Messwert.
* **Ansage** auch bei Störung und erloschener Fernstart-Freigabe, mit
  Ruhezeit. **Beendete Programmläufe** mit Verbrauch werden festgehalten.
* Der **Wächter** misst das Erzeugnis statt der Prozessnummer, der **Reiter
  Test** ruft den eigenen Endpunkt wirklich auf und stellt 25 Fragen in 35
  Prüfzeilen statt 15.

`?aktion=dienst` ist neu: `DIENST;OK=..;GERAETE=..;LAEUFT=..;FERTIG=..;FEHLERFOLGE=..;AUSFAELLE=..;ALTER=..`

## Fassung 0.9.18 — Sicherung, Endpunkt, Update

Eine Durchsicht der veröffentlichten 0.9.17. Fünf schwere Befunde, alle
gemessen, alle gegen PHP 7.4.33 **und** 8.4.24.

**Die eigene Sicherung war nicht zurückspielbar.** Die Oberfläche schreibt
bei jedem Speichern den Schlüssel `tts` in die Konfiguration; `ww_vorgaben()`
kannte ihn nicht, und `ww_sicherung_lesen()` prüft jeden Schlüssel gegen genau
diese Liste. Ergebnis: der Knopf *Einstellungen sichern* erzeugte eine Datei,
die der Knopf *Einstellungen zurückspielen* mit „Unbekannte Einstellung: tts“
grundsätzlich verweigerte — der Umzug auf einen zweiten LoxBerry, der erklärte
Zweck der beiden Knöpfe, war nie möglich.

**Der unangemeldete Endpunkt legte eine Datei an, bevor er das Token prüfte.**
`webfrontend/html/index.php` rief `ww_config()`, und die Funktion heilte eine
fehlende Konfiguration aus der Zweitschrift. Gemessen: ein Aufruf ohne Token
wurde richtig mit `GRUND=TOKEN` abgewiesen — und legte `weissware.json`
trotzdem an. Wer die Adresse kannte, schaltete damit eine alte Sicherung
wieder scharf, samt `steuerung_ein` und altem Aktionstoken. `ww_config()` hat
jetzt einen Schalter, der Endpunkt ruft `ww_config(false)`.

**Eine Sicherung ohne Aktionstoken leerte das Token — und die Zweitschrift.**
Die Lesefunktion begann mit `ww_vorgaben()`; alles, was in der Datei fehlte,
kam aus den Werkseinstellungen. Der Bediener las „17 Werte übernommen“ und
hatte danach kein Token mehr: der Endpunkt antwortet `KEIN_TOKEN_GESETZT`,
jeder virtuelle Eingang bekommt 403 und wertet ihn nicht aus, und beim
nächsten Öffnen der Oberfläche wird ein neues gewürfelt. Grundlage ist jetzt
der **Bestand**, ein fehlendes Token ist eine Beanstandung, und die
Zweitschrift wird nur mitgezogen, wenn der Stand ein Token trägt.

**Werte aus der Sicherungsdatei wurden nie geprüft.** Nur die Schlüsselnamen.
Eine Datei mit `takt_ruhe` als Objekt und `aktionstoken` als Feld ging ohne
eine einzige Beanstandung durch; aus dem Feld wurde im Endpunkt die
Zeichenkette `Array` — ein Token, das jeder kennt. Jeder Wert läuft jetzt
durch dieselben Muster und Grenzen wie das Formular.

**Vier Dateien überlebten kein Update.** `preupgrade.sh` sicherte nur
`weissware.json` und `zugang.json`. Der Installer räumt beim Upgrade aber auch
`data/plugins/<ordner>/` ab, und dort liegen `token.json` (die Anmeldung an
allen drei Herstellerclouds), `geraetenummern.json` (die Adressen in Loxone),
`laeufe.json` und der Merker `soll_laufen`. Ohne den letzten startete auch der
Wächter nicht mehr: **das Plugin schaltete sich bei jedem Auto-Update still
selbst ab.** Alle vier werden jetzt gesichert und zurückgelegt, und der Dienst
läuft danach wieder an, wenn er vorher lief.

Dazu:

* Die **Sicherungsdatei trägt jetzt die Zugangsdaten** — der Warntext am Knopf
  behauptete das schon, sie enthielt sie aber nicht. Die Anmeldemarken der
  Anbieter bleiben draußen; nach einem Umzug ist einmal neu anzumelden.
* Nach dem Zurückspielen wird der **Dienst nachgezogen**, und es steht dabei,
  was mit ihm geschah. Die beiden Handler stehen jetzt vor dem Ladeblock —
  vorher zeigte die Seite nach einem Zurückspielen jedes Feld im Vorzustand
  und im Reiter *Einbindung in Loxone* das alte Token.
* **Zugangsdaten werden erst geschrieben, wenn das Formular fehlerfrei ist.**
  Wer den Takt vertippte und gleichzeitig ein Client-Geheimnis austauschte,
  las eine Fehlermeldung — und hatte das Geheimnis doch schon ausgetauscht.
* `weissware.json` bekommt **0600** und wird unteilbar geschrieben. Sie trägt
  das Aktionstoken, mit dem der Endpunkt schaltende Befehle annimmt; bisher
  bekam nur `zugang.json` diese Rechte.
* `ww_dienst_schalter()`, `ww_trockenlauf()` und `ww_dienst()` melden den
  **Fehlerfall nicht mehr als Erfolg**. Fehlte die virtuelle Umgebung, meldete
  die Oberfläche „Home Connect ist angemeldet“, während gar nichts lief; ist
  `exec` gesperrt, blieb der vorbelegte Rückgabewert 0 stehen.
* Die **Erläuterung zur Baustein-Liste** verwies auf die alte Nummerierung:
  sechs von sieben Verweisen zeigten auf den falschen Baustein (die
  UND-Verknüpfung ist #23, nicht #14; die Einschaltverzögerung #18, nicht #15;
  die Benachrichtigung #28, nicht #21). Wer die Liste abarbeitete, verdrahtete
  falsch.
* Die Prüfzeile *Vorlage und Statuszeile* suchte den Endpunkt über
  `dirname(__DIR__)`. Installiert liegen die Bäume getrennt — sie konnte auf
  keiner Anlage je etwas messen. Jetzt mit Kandidatenliste.
* `gewaehlt_text` ist ein **Textthema** und bekommt keinen analogen Eingang
  mehr; er stand in Loxone dauerhaft auf 0.
* **19 ASCII-Umschriften** in angezeigtem deutschem Text (`waehrend`,
  `Oberflaeche`, `heisst`, …) und Reste eines Fahrzeug-Plugins
  (`carconnectivity`, „mit Fahrzeug prüfen“) sind fort. Der `User-Agent` kommt
  aus einer Fassungskonstante statt fest aus `0.9.1`.
* Die englische Hilfe nennt beim MQTT-Abo jetzt wie die deutsche den
  Unterschied zwischen Gateway **V1** und **V2**.

Nicht behoben, weil ohne Konto und Gerät nicht entscheidbar: ob die Zuordnung
der Miele-Statuscodes, die SmartThings-Energieeinheit und der
Home-Connect-Temperatur-Enum stimmen. Das steht weiter unter *ungeprüft*.

## Fassung 0.9.16 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/ww_lib.php:1456`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT — siehe [LICENSE](LICENSE).

Home Connect, Bosch, Siemens, Neff, Gaggenau und Constructa sind Marken der
BSH Hausgeräte GmbH, Miele und Miele@home Marken der Miele & Cie. KG,
SmartThings und Samsung Marken der Samsung Electronics Co., Ltd. Dieses
Projekt steht in keiner Verbindung zu diesen Unternehmen und wird von ihnen
weder herausgegeben noch unterstützt; es benutzt lediglich deren öffentlich
angebotene Schnittstellen. Alle drei können sie ohne Ankündigung ändern, womit
dieses Plugin ganz oder teilweise unbrauchbar würde.
