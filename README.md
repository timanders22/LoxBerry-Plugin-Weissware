# LoxBerry-Plugin: Weissware Cloud

Bindet vernetzte **Hausgeräte dreier Ökosysteme** an Loxone an und bringt sie
auf **ein gemeinsames Modell**. Für den Miniserver sieht ein
Miele-Geschirrspüler danach genauso aus wie eine Bosch-Waschmaschine.

| Anbieter | Marken | Anmeldung |
|---|---|---|
| **Home Connect** | Bosch, Siemens, Neff, Gaggenau, Constructa | OAuth2 Device Flow |
| **Miele** | Miele@home (3rd Party API) | OAuth2 Authorization Code, Code von Hand |
| **SmartThings** | Samsung | Personal Access Token — **siehe Vorbehalt** |

> **Fassung 0.9.31 — ungeprüft.** Das Plugin wurde ohne Entwicklerkonten und
> ohne Geräte gebaut. Endpunkte und Datenformen stammen aus den
> Entwicklerdokumentationen, nicht aus einer Messung. Geprüft ist alles übrige:
> Oberfläche, Endpunkt, Absicherung, Warteschlange, Sprachdateien und die
> Zuordnung selbst — letztere gegen nachgebaute Antworten in der dokumentierten
> Form. Schreibende Befehle sind ab Werk gesperrt.

## Neu in 0.9.31

- **Nach einem Upgrade verlangt das Installationsprotokoll die Zugangsdaten
  nicht mehr neu.** Bis 0.9.30 endete es jedes Mal mit den „Naechsten
  Schritten" samt „Zugangsdaten eintragen", auch wenn sie gerade
  zurückgespielt worden waren. Jetzt erscheint die Anleitung nur, wenn
  `zugang.json` danach kein Geheimnis trägt (dieselbe Prüfung wie für die
  Sicherung: `hc_client_secret`, `miele_client_secret` oder `st_token`),
  sonst „Aktualisierung abgeschlossen, Einstellungen übernommen".

## Neu in 0.9.30

- **Die Kachel „MQTT" zeigt jetzt, ob dieses Plugin veröffentlicht.** Bis 0.9.29
  stand dort als großer Wert der Autostart des MQTT-Gateways von LoxBerry, und
  „MQTT ein" las sich, als sende das Plugin — auch wenn es im Reiter MQTT
  ausgeschaltet war. Der Autostart des Gateways steht jetzt klein darunter;
  fehlt der MQTT-Abschnitt in der LoxBerry-Konfiguration, heißt er dort
  „nicht feststellbar" statt „aus".
- **Die Deinstallation leert die zurückbehaltenen (retained) MQTT-Themen.**
  Bis 0.9.29 blieben die Gerätezustände (`geraete`, `geraetN/…`) nach dem
  Entfernen des Plugins im Broker stehen, dazu, was ältere Fassungen
  zurückbehalten gesendet hatten (`ok`, `ts`, `fehler_folge`, `ausfaelle`,
  `ausfall/…`), und der Miniserver bekam sie nach jedem Neustart von Broker
  oder Gateway wieder. Jetzt schickt `uninstall` — nach dem Anhalten des
  Dienstes — für jedes dieser Themen eine leere Nachricht mit `retain` an den
  UDP-Eingang des MQTT-Gateways, für jede je vergebene Gerätenummer, unter dem
  eingestellten Themenpräfix. Themen, die nie zurückbehalten gesendet wurden
  (Restzeit, Fortschritt, Verbrauch, Temperatur), bleiben unberührt.
  **Grenze:** das ist der einzige Weg, den dieses Plugin zum Broker hat, und
  er bestätigt nichts. Der UDP-Eingang des Gateways verwirft unter Last
  Datagramme, und ob eine Löschung ankam, lässt sich darüber nicht nachlesen.
  Jede Löschung geht deshalb dreimal hinaus, mit einer Sekunde Abstand; das
  senkt den Verlust, schließt ihn aber nicht aus. Was danach noch im Broker
  steht, lässt sich mit `mosquitto_pub -r -n -t <thema>` von Hand löschen.
  Ein früher benutztes, später geändertes Themenpräfix wird nicht mehr
  erfasst. Das gilt ebenso für das einmalige Abräumen der Altwerte im Betrieb
  (seit 0.9.26): der Dienst setzt seinen Merker, sobald die Löschung ohne
  Fehler gesendet ist — angekommen sein muss sie deshalb nicht.
- **Die eigene Fassungsnummer wird gelesen, nicht mehr eingetragen.** Der
  Dienst schickte an Home Connect, Miele und SmartThings seit 0.9.19 den
  User-Agent `LoxBerry-Weissware/0.9.18` — die Nummer stand als Konstante im
  Quelltext, und das Werkzeug, das beim Hochsetzen die Fassung nachzieht,
  kennt diese Stelle nicht. Jetzt liest der Dienst sie aus der
  Plugin-Datenbank des LoxBerry (über den Ordnernamen), im ausgepackten
  Archiv aus der `plugin.cfg`; findet er keine, geht der User-Agent ohne
  Nummer hinaus. Die Selbstprüfung nennt Fassung und Quelle.
- **Die Deinstallation meldet keinen Prozess mehr, der während der Suche
  endet.** Im Installationsprotokoll stand dann
  „`/proc/<n>/cmdline: No such file or directory`"; dieselbe Stelle gab es in
  `bin/dienst.sh` (die Meldung erschien dort in der Oberfläche) und im
  Rückfallweg von `preupgrade.sh`.
- **Ohne gefundene LoxBerry-Wurzel liest die Oberfläche nur ihre eigenen
  Dateien.** Die Sprachtexte (`ww_t()`) und eine Prüfzeile im Reiter *Test*
  bildeten bis 0.9.29 den Installationspfad auch ohne Wurzel und fragten ihn
  ab — also `/templates/plugins/html/lang` und
  `/webfrontend/html/plugins/weissware/index.php` ab der Laufwerkswurzel. Lag
  dort etwas, zeigte die Oberfläche fremde Texte. Auf einem LoxBerry gibt es
  die Wurzel immer; betroffen war das ausgepackte Archiv.

Gemessen in WSL, nicht am Gerät und nicht an einem echten Broker (UDP-Horcher
an der Stelle des Gateway-Eingangs): 33 Prüfzeilen, vorher 23 rot, nachher
alle grün; 20 Rückbauten geeicht; die Prüfstände von 0.9.29 bleiben grün.

## Neu in 0.9.29

**Die Oberfläche fällt ohne gefundene Wurzel nicht mehr auf einen festen
Standardort zurück.** Die Bibliothek der Oberfläche (`ww_paths()` und die
Sprachtexte in `ww_t()`) nahm bis 0.9.28 ein gesetztes `LBHOMEDIR`, sobald es
ein Verzeichnis war, und fand die Suche keine Wurzel, versuchte sie noch
`/home/loxberry/loxberry`. Auf einem LoxBerry liegt dort keine Wurzel — die
Wurzel ist `/home/loxberry` selbst. In WSL Ubuntu nachgestellt
(19.09.2026, `Pruefung-Weissware-0.9.29`, `messe_h2.sh`; der fremde Baum nur in
einem eigenen Mount-Namensraum): aus einem ausgepackten Archiv heraus las die
Oberfläche die Konfiguration eines fremden Baums unter diesem Pfad, schrieb
dort aus dessen Zweitschrift eine `weissware.json` und zeigte dessen
Sprachtexte (Fälle B1 bis B4, B7); ein `LBHOMEDIR`, das auf nichts zeigte,
blieb als Wurzel stehen (B5), ein beliebiges Verzeichnis wurde Wurzel (B6,
B8). Jetzt gilt `LBHOMEDIR` nur mit `config/plugins` darunter, sonst sucht die
Oberfläche aufwärts nach `config/system/general.json`; findet sie nichts,
arbeitet sie auf dem eigenen Ordner. Installiert findet sie ihre Wurzel wie
bisher, mit und ohne `LBHOMEDIR` (A1, A2).

**Dieselbe Regel in Dienst, Deinstallation und Installationsskripten.**
`bin/dienst.sh` und `bin/weissware.py` nahmen ebenfalls jedes Verzeichnis als
`LBHOMEDIR` — `dienst.sh start` legte darin `data/plugins/weissware` an (S1),
`weissware.py` arbeitete darin (P1); `uninstall/uninstall` entfernte dort Marke
und `soll_laufen` und endete mit 0 (U1). `postinstall.sh` rechnete ohne
fünftes Argument und ohne `LBHOMEDIR` zwei Ebenen über seinem Ablageort,
ungeprüft: unter einem fremden Baum ohne `general.json` richtete es dort
Daten-, Protokoll- und Konfigurationsordner ein und stellte eine
`weissware.json` aus dessen Sicherung wieder her (I1); tief unter einer echten
Wurzel richtete es alles an der falschen Stelle ein (I3). `preupgrade.sh` lief
ohne Wurzel mit Pfaden ab `/` weiter (V2) und legte bei einem beliebigen
`LBHOMEDIR` dort seine Marke an (V1). Alle prüfen jetzt `config/plugins`,
suchen sonst aufwärts mit `general.json` und tun ohne Wurzel nichts
(`dienst.sh`, `weissware.py`: Meldung und Abbruch; die drei Skripte:
`<WARNING>` und Rückgabe 1). Beim Installieren, Aktualisieren und
Deinstallieren über LoxBerry ändert sich nichts — der Installer übergibt die
Wurzel als fünftes Argument (I2, V3).

**Aussagen über den Zustand des Dienstes gehen nicht mehr zurückbehalten
(retained) hinaus.** Der Hausherr hat am 19.09.2026 entschieden: neben `ok`
auch `fehler_folge`, `ausfaelle` und `ausfall/homeconnect`, `ausfall/miele`,
`ausfall/smartthings`. Am UDP-Eingang gemessen (Fall R1 in `messe_retain.sh`):
bis 0.9.28 `retain weissware/fehler_folge 0`, `retain weissware/ausfall/miele 0`
und so fort — zurückbehalten stünde „keine Fehlversuche, kein Ausfall" auch
dann noch im Broker, wenn der Dienst längst nicht mehr läuft. Geändert in
beiden Tabellen (`RETAIN` in `bin/weissware.py`, `ww_mqtt_retain()` der
Oberfläche); die Zeile im Reiter *Test* heißt jetzt „Gehen Lebenszeichen und
Dienstzustand nie zurückbehalten hinaus?" und hält alle sieben Themen fest.
Die Altwerte werden einmal abgeräumt — je Thema eine leere Nutzlast mit
`retain`, der gültige Wert unmittelbar dahinter; alle drei `ausfall/*`, auch
für einen nicht eingerichteten Anbieter, weil ein Altwert aus einer früheren
Einrichtung stehen kann. Ein Merker aus 0.9.28 (`weissware ts,ok`) überspringt
das nicht (R3); `ts` und `ok` gehen dabei ein zweites Mal leer hinaus. Die
Gerätezustände (`geraete`, `geraetN/…`) bleiben retained. Der Preis: nach
einem Neustart von Broker oder Gateway fehlen die Dienstzustände, bis der
Dienst wieder sendet (bis zum Ruhetakt, ab Werk 300 s). Ob das Gateway der
Anlage die leere Nachricht als Löschung weitergibt, ist weiterhin nicht am
Gerät gemessen.

Gemessen in WSL, nicht am Gerät: 33 und 25 Prüfzeilen, vorher 19 und 19 rot,
nachher alle grün; 13 Rückbauten geeicht, jeder genau an seinen Zeilen rot; die
Prüfstände von 0.9.28 bleiben grün (17 und 24 Zeilen).

## Neu in 0.9.28

**Die Suche nach der LoxBerry-Wurzel verlangt jetzt `config/system/general.json`,
und ohne Wurzel wird nichts mehr geraten.** Wird `bin/dienst.sh`,
`bin/weissware.py` oder die Bibliothek der Oberfläche ohne `LBHOMEDIR`
aufgerufen, sucht sie die Wurzel vom eigenen Ablageort aufwärts. Bis 0.9.27
galt dabei jedes Verzeichnis mit `config/plugins` und `webfrontend` als
Wurzel, und fand die Suche keines, rechneten `dienst.sh` und `weissware.py`
eine feste Zahl Ebenen über sich. Solche Ordner liegen auf einem Prüfrechner
auch außerhalb eines LoxBerry, etwa als Reste früherer Prüfläufe. In WSL Ubuntu
nachgestellt (18.09.2026, `Pruefung-Weissware-0.9.28`, Fälle F1 bis F6) — in
einem solchen Baum ohne `general.json`:

* `bin/dienst.sh start` legte `data/plugins/weissware` und `log/plugins/weissware` an, `stop` löschte `soll_laufen`;
* der Dienst und die Oberfläche hielten den Baum für die Wurzel und hätten dort gelesen und geschrieben.

Die drei Suchen prüfen jetzt zusätzlich `config/system/general.json` — ein
LoxBerry hat sie immer. `general.json` allein genügte in `dienst.sh` und
`weissware.py` nicht: der Rückfall auf die feste Ebenenzahl führte in denselben
fremden Baum zurück — installiert genau auf dessen Wurzel, aus einem Archiv
darin auf einen Ordner darin. Er ist entfallen; ohne
Wurzel melden beide „Es wurde kein LoxBerry-Wurzelverzeichnis gefunden" und
legen nichts an. In der installierten Lage (mit `general.json`) finden alle
drei die Wurzel weiterhin, auch ohne `LBHOMEDIR` und aus `/` aufgerufen (Fälle
G1 bis G4); ein gesetztes `LBHOMEDIR` gilt unverändert (U1 bis U3). Am Gerät
ändert sich damit nichts.

**Dieselbe Regel gilt jetzt in `uninstall/uninstall`.** Ohne fünftes Argument
und ohne `LBHOMEDIR` rechnete das Skript bis 0.9.27 drei Ebenen über seinem
Ablageort und prüfte nichts. In WSL nachgestellt (Fall D1): abgelegt unter
`<fremder Baum>/pruef/weissware/uninstall/`, löschte es in dem fremden Baum
(ohne `general.json`) die Sicherung `weissware.backup.zugang.json`, die
Upgrade-Marke und `soll_laufen` und meldete „Sicherung entfernt". Jetzt sucht
es aufwärts nach `config/plugins`, `data/plugins` **und**
`config/system/general.json`; ohne Wurzel meldet es `<WARNING>`, beendet und
entfernt nichts und endet mit 1. Beim Deinstallieren über LoxBerry ändert
sich nichts — der Installer übergibt die Wurzel als fünftes Argument (Fall D3),
und abgelegt unter `data/system/uninstall/` findet die Suche sie auch ohne
(Fall D2). Ein laufender Dienst wird weiterhin beendet, der einer anderen
Installation nicht (Fall D6).

**`weissware/ok` geht nicht mehr zurückbehalten (retained) hinaus.** Am
UDP-Eingang gemessen (Fall R1): bis 0.9.27 `retain weissware/ok 1`. Der
Hausherr hat am 18.09.2026 entschieden, dass `ok` nie retained ist — ein
zurückbehaltenes `ok=1` bliebe stehen, wenn der Dienst stirbt, und nach einem
Neustart von Broker oder Gateway läse Loxone „in Ordnung" von einem Dienst,
der nicht mehr läuft. Der Preis: nach einem solchen Neustart fehlt `ok`, bis
der Dienst wieder sendet (bis zum Ruhetakt, ab Werk 300 s). Geändert in beiden
Tabellen (`RETAIN` in `bin/weissware.py`, `ww_mqtt_retain()` der Oberfläche);
die Zeile „Geht das Lebenszeichen nie zurückbehalten hinaus?" im Reiter
*Test* hält jetzt `ts` **und** `ok` fest. Der alte zurückbehaltene Wert wird
wie `ts` in 0.9.26 einmal abgeräumt — eine leere Nutzlast mit `retain` auf
`<präfix>/ok`, der gültige Wert unmittelbar dahinter. Der Merker
`retain_ts_geraeumt` trägt dafür jetzt Präfix **und** Themenliste
(`weissware ts,ok`); ein Merker aus 0.9.26/0.9.27 trägt nur das Präfix und
hätte das Abräumen von `ok` übersprungen (Fall R3). Ob das Gateway der Anlage
die leere Nachricht als Löschung an den Broker weitergibt, ist weiterhin nicht
am Gerät gemessen. Ein Alter sendet die Linie über MQTT nicht, einen Letzten
Willen hat sie nicht (sie spricht über den UDP-Eingang des Gateways, nicht
selbst mit dem Broker). Unverändert retained bleiben die Zustände, darunter
`fehler_folge` und die Ausfallmerker. Gemessen in WSL, nicht am Gerät
(`Pruefung-Weissware-0.9.28`, `messe_teil2.sh`, 24 Prüfzeilen, sieben
Rückbauten geeicht).

## Neu in 0.9.27

Die Upgrade-Marke, nachgerüstet nach der Bauweise aus Regeln/06 (Einspeisebremse
0.9.20, Govee 0.9.19), und die Dienste ohne PID-Datei. Alles in WSL/Ubuntu
gemessen, mit nachgebautem Installationsablauf (preupgrade, Abräumen von
`config/`, `data/`, `bin/` samt venv, `templates/` und `webfrontend/`, neue
Dateien und Cron-Datei, postinstall mit wartendem pip, postupgrade), vor und
nach der Korrektur, jede Korrektur einzeln durch Rückbau geeicht
(`Pruefung-Weissware-0.9.27/`, 47 Prüfzeilen). Nichts davon ist am Gerät
gemessen.

**Was in der Lücke geschah.** In der eigentlichen Lücke zwischen dem Abräumen
und `postinstall.sh` startet nichts: der Minutentakt findet `soll_laufen` nicht
mehr, und der Knopf „Dienst starten" scheitert an der fehlenden venv. Die Lücke
reicht aber bis in `postinstall.sh` hinein, und dort wurde gemessen:

* **Ein bewusst angehaltener Dienst lief nach dem Upgrade wieder.** Wer im
  Fenster, in dem `postinstall.sh` die Bibliothek installiert, auf „Dienst
  starten" drückte, bekam „Start fehlgeschlagen" (requests fehlte noch) — aber
  `soll_laufen` war schon angelegt, und der Minutentakt startete den Dienst
  danach trotzdem.
* **Zwei oder drei Dienste nach dem Neustart in `postinstall.sh`.** Zwischen
  dem Anlegen von `soll_laufen` und dem Schreiben der PID-Datei kann der
  Minutentakt denselben Dienst ein zweites Mal starten. Gegen eine
  Wächterschleife ohne Pause: in 3 von 200 Durchgängen ohne Marke, in 1 von 100
  mit einer Marke, die **vor** dem Start fällt, in 0 von 100 mit einer Marke,
  die **nach** dem Start fällt. Im Betrieb läuft der Wächter einmal je Minute —
  der Fall ist dort selten, aber möglich.
* **Ein Dienst ohne PID-Datei überlebte das Upgrade.** `preupgrade.sh` hielt nur
  den Dienst aus der PID-Datei an, `dienst.sh stop` ebenso. Lief einer ohne sie
  (von Hand gestartet, oder mit dem Knopf der alten Fassung zwischen
  `preupgrade.sh` und dem Abräumen), liefen nach dem Upgrade zwei — zwei Abrufe
  mit derselben Anmeldung gegen dieselben Herstellerclouds.

**Was jetzt gilt.**

* `preupgrade.sh` legt als Erstes `data/plugins/<ordner>.upgrade_laeuft` mit
  der Unixzeit an — **neben** dem Datenordner, der beim Upgrade abgeräumt wird.
* `bin/dienst.sh` startet nicht, solange die Marke höchstens eine Stunde alt
  ist, und fragt das **vor** dem Anlegen von `soll_laufen`. Eine ältere, leere,
  unlesbare oder in der Zukunft liegende Marke gilt nicht; ohne lesbare Uhr
  gilt sie (der Schutz fällt geschlossen aus). Anhalten bleibt jederzeit
  möglich.
* `postinstall.sh` hält bei liegender Marke zuerst jeden eigenen Dienst an,
  startet dann den Dienst, falls er vor dem Upgrade lief, mit der Ausnahme
  `WW_START_TROTZ_MARKE=1`, und entfernt die Marke erst beim Verlassen (`trap`
  auf EXIT) — also **nach** dem Start, und auch dann, wenn es vorzeitig mit
  einem Fehler aussteigt.
* `dienst.sh stop` und `preupgrade.sh` beenden auch Dienste ohne PID-Datei.
  Erkannt wird argumentweise über `/proc/<pid>/cmdline`: ein Python, genau
  dieses `weissware.py`, **kein** drittes Argument (ein Einmallauf wie
  `--selbsttest` ist kein Dienst), und der Prozess gehört dem Dienstbenutzer.
  Ein Dienst einer Nachbarinstallation, ein Einmallauf und ein `tail -f` auf die
  Datei bleiben unberührt (gemessen).
* `uninstall` räumt die Marke weg.
* Der Reiter Test zeigt eine Zeile „Läuft gerade eine Aktualisierung dieses
  Plugins?" — mit einem Kreuz, wenn eine alte Marke liegengeblieben ist.

**Die Oberfläche wird nicht gesperrt.** Gemessen wurde ein Seitenaufruf und ein
Speichern in der Lücke und im pip-Fenster, dazu der Minutenlauf der Ansage:
Aktionstoken, Client-Geheimnis, Anmeldung und die gespeicherte Änderung waren
nach dem Upgrade in jedem Fall erhalten. Eine Sperre ohne gemessenen Verlust
nähme dem Anwender nur die Seite (Regeln/06).

## Neu in 0.9.26

Drei Befunde aus der Bestandsmessung vom 18.09.2026, alle in WSL/Ubuntu
nachgestellt, vor und nach der Korrektur gemessen und jede Korrektur einzeln
durch Rückbau geeicht (`Pruefung-Weissware-0.9.26/`, 24 Prüfzeilen, 9
Rückbaufälle). Nichts davon ist am Gerät gemessen.

**Das Lebenszeichen `ts` ging zurückbehalten (retained) hinaus.** Am
UDP-Eingang gemessen: `retain weissware/ts 1789…`. Der Hausstandard ist
eindeutig — das Lebenszeichen ist nie retained, sonst liefert der Broker nach
einem Neustart einen Zeitstempel aus, zu dem längst kein Dienst mehr läuft.
`ts` geht jetzt mit `publish` hinaus, in beiden Tabellen (`RETAIN` in
`bin/weissware.py`, `ww_mqtt_retain()` in der Oberfläche). Der Preis ist
benannt: nach einem Neustart des Miniservers steht `ts` erst mit dem nächsten
Takt wieder an (bis zum Ruhetakt, ab Werk 300 s).

Der alte zurückbehaltene Wert verschwindet davon nicht von selbst. Der Dienst
räumt ihn deshalb **einmal** ab — eine leere Nutzlast mit `retain` auf
`<präfix>/ts`, der gültige Wert unmittelbar dahinter — und merkt sich das
im Datenordner (`retain_ts_geraeumt`, Inhalt: das Themenpräfix; wer das
Präfix umstellt, bekommt die Abräumung unter dem neuen Stamm noch einmal).
Ob das Gateway der Anlage die leere Nachricht als Löschung an den Broker
weitergibt, ist nicht gemessen.

Neu im Reiter **Test**: „Geht das Lebenszeichen nie zurückbehalten hinaus?"
Die vorhandene Zeile „Stimmen die beiden Retain-Tabellen überein?" hielt die
Tabellen nur gegeneinander — beide sagten dasselbe, und beide sagten es
falsch.

Unverändert und ausdrücklich **nicht** angefasst: `weissware/ok` geht weiter
retained hinaus. Ob `ok` zum Lebenszeichen gehört, ist in den Hausregeln
widersprüchlich festgehalten; die Entscheidung steht aus. Ebenso bleibt
`geraetN/laeuft` retained — es ist der Zustand des Geräts, nicht des Dienstes.

**Eine abgeschnittene Datendatei verhinderte die Rückholung nach dem Update.**
`postinstall.sh` holte `token.json`, `geraetenummern.json` und `laeufe.json`
nur zurück, wenn die Datei **leer** war (`[ ! -s … ]`). Eine halb geschriebene
Datei ist nicht leer: die Anmeldung an drei Herstellerclouds blieb fort, und
die Gerätenummern — also die Adressen in Loxone — wurden neu vergeben.
Entschieden wird jetzt nach dem Inhalt (`ww_json_traegt()`: lesbares JSON mit
mindestens einem Eintrag); der verdrängte Stand liegt als `<datei>.kaputt`
(0600) daneben. Dieselbe Lücke saß auf der anderen Seite: `preupgrade.sh`
kopierte die drei Dateien **unbedingt** über die Sicherung, eine abgeschnittene
`token.json` verdrängte also die letzte heile Abschrift. Beide Skripte stellen
jetzt dieselbe Frage.

**`bin/dienst.sh` und `bin/weissware.py` rieten Wurzel und Ordnernamen.**
Beide rechneten sie aus dem eigenen Ablageort und übergingen ein gesetztes
`$LBHOMEDIR`. Nachgestellt: `dienst.sh status` aus einem Prüfarchiv unter
`<Wurzel>/pruefung/<plugin>/bin` legte in der **laufenden** Installation
`data/plugins/bin` und `log/plugins/bin` an. Jetzt gilt die Reihenfolge
Umgebung (`$LBHOMEDIR`, `$LBPPLUGINDIR`) → Aufwärtssuche → Ablageort, wie in
der Oberfläche schon lange. Außerdem legt `dienst.sh` Daten- und Logordner
nur noch beim **Start** an, nicht mehr bei jedem Aufruf: vorher legte schon
ein `status` den eben abgeräumten Datenordner wieder an.

## Neu in 0.9.25

**Eine abgeschnittene `weissware.json` kostete das Aktionstoken — und mit ihm
jede Adresse im Miniserver.** Gemessen am 18.09.2026 in WSL/Ubuntu unter
PHP 8.3.6 und unter Windows-PHP 7.4.33 und 8.4.24
(`Pruefung-Weissware-0.9.25/`).

Die Selbstheilung entschied nach der **Form** der Datei:

```php
if ($erzeugen && ($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
```

Eine halb geschriebene Datei — Stromausfall, volle Speicherkarte,
Handbearbeitung — ist weder leer noch `{}`. Sie ging an dieser Zeile vorbei,
`json_decode` gab `null`, die Konfiguration bestand nur noch aus den
Werkseinstellungen, `ww_token()` würfelte ein **neues** Aktionstoken, und
`ww_config_speichern()` kopierte es über die Zweitschrift. Danach antwortet
jeder virtuelle Eingang im Miniserver mit HTTP 403, und das alte Token lässt
sich nicht zurückrechnen.

Entschieden wird jetzt nach dem **Inhalt**: trägt die Datei noch ein
Aktionstoken? Der Entscheid steht in **einer** Funktion (`ww_selbstheilung()`),
die alle vier Wege benutzen — Oberfläche, Speichern, unangemeldeter Endpunkt
und der Minutenlauf aus `cron/cron.01min`. Geheilt wird nur aus einer
Zweitschrift, die selbst ein Token trägt; der verdrängte Inhalt wird nicht
weggeworfen, sondern liegt als `weissware.json.kaputt` daneben (Rechte 0600 —
in der Konfiguration steht das Aktionstoken). Gemeldet wird einmal, nicht bei
jedem Minutenlauf.

Dazu drei Wege, die an der Heilung vorbeischreiben konnten:

* **Die Zweitschrift** wurde bisher mitgezogen, sobald der neue Stand
  *irgendein* nicht leeres Token trug. Verglichen wird jetzt mit dem, was die
  Zweitschrift selbst führt (`ww_zweitschrift_ziehen()`): ein Stand ohne das,
  was dort steht, ersetzt sie nicht. Gespeichert wird trotzdem — nur der
  Rückweg bleibt stehen, und das Protokoll sagt es.
* **Die Tokenerzeugung** schrieb durch diese Wache hindurch, weil ein frisch
  gewürfeltes Token ein gültiger Wert ist. Ein neues Token entsteht deshalb
  nur noch, wenn **keine** Zweitschrift mit Token danebenliegt
  (`ww_token_gesperrt()`). Sonst erscheint eine Meldung, es wird nichts
  geschrieben, und die nächste gelungene Selbstheilung holt das alte Token
  zurück. Derselbe Schutz sperrt den Knopf „Neues Token", solange die
  Konfiguration unlesbar ist.
* **`preupgrade.sh`** kopierte die Konfiguration ungeprüft über die
  Zweitschrift — bei einem Auto-Update mit beschädigter Datei war das der
  sichere Verlust. `postinstall.sh` holte die Sicherung nach demselben
  Formentscheid zurück wie die Bibliothek und ließ die abgeschnittene Datei
  deshalb stehen. Beide fragen jetzt nach dem Inhalt, und beide lesen die
  Datei wirklich (`php -r`, kein `grep`): eine abgeschnittene Datei trägt den
  Text `"aktionstoken":"…"` weiterhin.

Neu im Reiter **Test**: „War die Konfiguration heil, als diese Seite aufgebaut
wurde?" Die Zeile merkt sich die **zuerst** festgestellte Lage, bevor die
Selbstheilung sie beseitigt — sonst meldete sie „in Ordnung" für eine Datei,
die beim selben Seitenaufruf beschädigt war.

Was diese Fassung **nicht** misst: nichts davon ist am Gerät gemessen, sondern
über die PHP-Kommandozeile gegen eine LoxBerry-Nachbildung; und es wurde kein
Hausgerät und keine Cloud angesprochen.

## Neu in 0.9.23

**Der Wächter startete den gesunden Dienst neu — und hob dabei die
Fehlerbremse auf.** Am Gerät gemessen:

```
Waechter: der Dienst laeuft, hat aber seit 1508 s kein Abbild geschrieben
          (Grenze 1500 s) - Neustart.
```

Zwei Zahlen aus zwei Stellen, die nichts voneinander wussten:

| | Formel | bei `takt_ruhe = 300` |
|---|---|---|
| Wächtergrenze | `max(180, 5 × takt_ruhe)` | **1500 s** |
| Fehlerbremse | `min(3600, takt_ruhe × min(8, fehler_folge))` | ab 5 Fehlversuchen **1500 s**, dann 1800, 2100 … |

Der Wächter misst, was der Dienst **hinterlässt** — das Abbild, und das
entsteht nur nach einem abgeschlossenen Durchlauf. Sobald die Bremse die
Grenze erreichte, schlief der Dienst planmäßig länger, als der Wächter
Stillstand duldete.

**Schlimmer als der überflüssige Neustart:** er setzt `fehler_folge` zurück.
Die Bremse, die eine ausgefallene Anbieter-Schnittstelle schonen soll
(Ratengrenzen, HTTP 429), wurde damit im Takt der Wächtergrenze aufgehoben
und konnte ihren Zweck **nie** erreichen. Ausgelöst hat es ein ganz
gewöhnlicher Anlass: das Plugin war eine Stunde lang nicht angemeldet.

**Der Dienst sagt jetzt, bis wann er absichtlich pausiert.** Er schreibt
`pause_bis` in seinen Zustand, und der Wächter liest es über die neue
Auskunft `ww_pause_bis()`. Damit kann er unterscheiden, was er vorher nicht
unterscheiden konnte: *schläft mit Absicht* gegen *hängt*. Gehalten wird
weiterhin beides — ein wirklich stehengebliebener Dienst wird neu gestartet
wie bisher.

Drei Vorsichtsmaßnahmen, jede gegen einen Fehler, den es im Haus schon
gegeben hat:

- `pause_bis` geht bei **jedem** Zustandsschreiben mit, auch als `0`.
  `zustand_schreiben()` mischt in den Bestand — ein weggelassenes Feld bliebe
  stehen, und eine liegengebliebene Pause hätte den Wächter dauerhaft
  stillgelegt. Genau diese Falle hatte 0.9.7 mit der Ausfallliste.
- Eine Pause, die weiter als **3900 s** in der Zukunft liegt, wird nicht
  geglaubt: der Dienst deckelt seine Bremse selbst bei 3600 s. Ein Wächter,
  den eine einzige verdorbene Zahl dauerhaft stilllegen kann, ist keiner.
- Fehlt das Feld ganz — also bei einer Zustandsdatei aus 0.9.22 oder älter —,
  verhält sich der Wächter wie bisher.

Der Wächter fragt erst dann nach der Pause, wenn ohnehin ein Neustart
anstünde; er läuft minütlich, und ein zweiter PHP-Aufruf je Minute wäre
Verschwendung für einen Fall, der fast nie eintritt.

**Am Gerät in beide Richtungen geeicht** (Wegwerfbaum unter `/tmp`, echter
Vorgang als laufender Dienst), sechs Fälle, alle wie vorher festgelegt:
altes Abbild mit echter Pause → kein Neustart; mit `pause_bis = 0`, mit
verdorbenem Wert und mit fehlendem Feld → Neustart; frisches Abbild mit und
ohne Pause → nichts.

Geprüft gegen PHP 7.4.33 und 8.4.24, `bash -n` für `dienst.sh`. Freigabetor:
17 Prüfungen, 0 Beanstandungen.

## Neu in 0.9.22

**Die Home-Connect-Anmeldung war zum Abtippen gebaut — und daran gescheitert.**
Bis 0.9.21 zeigte die Oberfläche den Benutzercode und die Adresse in **zwei
Tabellenzellen**. Wer den Code mit der Maus markierte und dabei über die
Zellgrenze geriet, kopierte einen **Tabulator** mit; Home Connect wies die
Adresse dann ab:

```
{"error":"invalid_request","error_description":"Illegal URI reference:
 Invalid input '\t', expected query-char … ?user_code=\tI5JI-9119"}
```

Dabei liefert Home Connect die **fertige Adresse** mit dem Code darin
(`verification_uri_complete`), und der Dienst legte sie auch ab — angezeigt
wurde sie nie. Jetzt steht sie als **anklickbarer Verweis** in der Tabelle:
ein Klick, kein Kopieren. Angezeigt wird sie nur, wenn sie mit `http://` oder
`https://` beginnt — eine Adresse aus fremder Hand gehört geprüft, bevor sie
in ein `href` geht. Zusätzlich streift der Dienst Leerraum von Code und
Adressen ab, bevor er sie ablegt.

**Das Client Secret ist bei Home Connect optional — jetzt behandelt das Plugin
es auch so.** Eine Anwendung, die im Entwicklerportal mit dem **Device Flow**
angelegt wurde, ist ein öffentlicher Client: sie bekommt eine Client-ID und
**kein** Geheimnis. Die Anmeldung kam damit immer schon aus; die **Erneuerung**
des Zugriffstokens schickte `client_secret` aber bedingungslos mit, bei leerem
Feld also `client_secret=`. Eine leere Client-Anmeldung ist etwas anderes als
gar keine und kann abgewiesen werden — das Plugin hätte rund einen Tag
gearbeitet und dann aufgehört, Token zu erneuern. Jetzt geht das Geheimnis nur
mit, wenn eines hinterlegt ist; dafür geht die Client-ID immer mit, wie es für
einen öffentlichen Client vorgesehen ist. Für Anwendungen **mit** Geheimnis
ändert sich nichts.

> **Abgeleitet, nicht gemessen.** Zu dieser Linie gibt es hier kein
> Home-Connect-Konto. Dass ein leeres `client_secret` abgewiesen *wird*, ist
> nicht nachgemessen — der neue Weg ist aber in jedem Fall der sichere: ein
> weggelassener optionaler Parameter ist nie schlechter als ein leerer.

Geprüft gegen PHP 7.4.33 und 8.4.24, Sprachdateien 553 Schlüssel DE/EN
deckungsgleich. Freigabetor: 17 Prüfungen, 0 Beanstandungen.

## Neu in 0.9.21

**Zustände gehen jetzt zurückbehalten (retained) an den Broker.** Bis 0.9.20
sendete dieses Plugin *alles* mit dem Befehlswort `publish`. Nach einem
Neustart des Miniservers oder des MQTT-Gateways stand in Loxone deshalb bis
zum nächsten Takt — bei Ruhetakt **bis zu 300 Sekunden** — der alte Wert, und
ein virtueller Eingang zeigt einen alten Wert genauso an wie einen frischen.

Der Hausstandard (Regeln/07, seit 03.09.2026) lautet: Zustände zurückbehalten,
Messwerte mit Zeitbezug nicht, das Lebenszeichen nie. Die Entscheidung fällt
**je Themenstamm in einer Tabelle**, nicht am einzelnen Aufruf — ein Aufruf,
der Zustand und Lebenszeichen zusammen verschickt, kann nur eines von beiden
richtig machen.

**23 von 30 Themenstämmen** sind zurückbehalten. Nicht zurückbehalten sind die
sieben, die von selbst altern:

| Thema | warum nicht |
|---|---|
| `geraetN/fortschritt` | wächst mit der Zeit |
| `geraetN/restzeit_min` | eine Dauer — 60 Minuten Restzeit, eine Woche aufbewahrt, wären eine stille Falschaussage |
| `geraetN/startzeit_min` | Dauer |
| `geraetN/laufzeit_min` | Dauer |
| `geraetN/energie_kwh` | Momentanwert des laufenden Programms, kein Zählerstand — nach dem Quittieren ist er fort |
| `geraetN/wasser_l` | ebenso |
| `geraetN/temperatur` | Messwert |

Zwei Entscheidungen, die nicht auf der Hand liegen, und ihre Begründung:

* **`ts` ist zurückbehalten.** Das ist kein Lebenszeichen „ich lief gerade":
  der Wert wandert nur bei einem *erfolgreichen* Abruf weiter und sagt damit
  genau, wann zuletzt gemessen wurde. Ein absoluter Zeitpunkt kann nicht
  „aktuell erscheinen"; ohne ihn könnte Loxone nach einem Neustart das Alter
  gar nicht rechnen, und ein toter Dienst wäre von einem gesunden nicht zu
  unterscheiden. `geraetN/fertig_um` ist aus demselben Grund zurückbehalten.
* **`geraetN/schleuderdrehzahl` ist zurückbehalten** — sie ist keine Messung,
  sondern eine Einstellung des gewählten Programms.

**Ein leerer Wert geht immer als `publish` hinaus**, egal was die Tabelle
sagt: eine leere Nutzlast mit Retain *löscht* das Thema im Broker.

Die Tabelle steht an zwei Stellen — `ww_mqtt_retain()` in der Oberfläche und
`RETAIN` in `bin/weissware.py` —, weil die eine sie anzeigen und die andere
sie anwenden muss. Zwei Tabellen sind zwei Wahrheiten, deshalb hält die neue
Zeile **„Stimmen die beiden Retain-Tabellen überein?"** im Reiter *Test* beide
samt der Themenliste gegeneinander; sie ist durch Rückbau in beide Richtungen
geeicht (ein entfernter Eintrag und ein gekippter Wert machen sie rot und
nennen den Namen). Die Themenliste im Reiter *MQTT* hat eine Spalte **Retain**
bekommen.

> **Was das für bestehende Anlagen heißt:** nichts, was jemand einstellen
> müsste. Beim ersten Durchlauf nach dem Update schreibt der Dienst die
> Zustände zurückbehalten in den Broker; ab da überstehen sie einen Neustart.
> Alte, nicht zurückbehaltene Werte verschwinden von selbst.

**Nicht am Gerät gemessen:** dass die zurückbehaltenen Themen wirklich im
Broker stehen. Der UDP-Eingang des Gateways verwirft an dieser Anlage in
Stößen; „Thema X ist nicht angekommen" ist dort kein Beweis (Regeln/07).

## Neu in 0.9.20

**Das Installationsprotokoll behauptete, einen Dienst angehalten zu haben, der
gar nicht lief.** „Laufender Dienst angehalten." stand bedingungslos hinter
`dienst.sh stop || true`; `anhalten()` gibt ohne laufenden Dienst „laeuft
nicht" und 0 zurück, und die Antwort ging nach `/dev/null`. Die Meldung hängt
jetzt am Merker `backup.lief`, den der Abschnitt darüber ohnehin setzt — und
der auch darüber entscheidet, ob `postinstall.sh` den Dienst wieder startet.

Geprüft mit `Werkzeuge/preupgrade_meldung_pruefen.py`: gegen 0.9.20 grün, gegen
0.9.19 rot. **Am Verhalten ändert sich nichts.**

## Neu in 0.9.19

- **Der Reiter Test sagt jetzt, ob die MQTT-Veröffentlichung dieses Plugins
  eingeschaltet ist.** Bis 0.9.18 stand dort nur der Zustand des MQTT-Gateways
  von LoxBerry — das ist eine Aussage über den LoxBerry, nicht über dieses
  Plugin. Wer die Veröffentlichung ausgeschaltet hatte, sah trotzdem einen
  grünen Haken und konnte am Reiter nicht erkennen, dass nichts an den Broker
  geht. Die neue Zeile steht vor der Gateway-Zeile und ist **grau**, wenn
  ausgeschaltet — das ist eine Entscheidung, kein Fehler. Anlass: derselbe
  Befund an BatterieBMS 0.9.17, dort am Gerät gemessen (`Regeln/04`).

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 0.9.18 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

### Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel

`log/plugins` liegt auf einer Ramdisk (`/dev/zram0`). Wird sie geleert — beim
Neustart, durch LoxBerrys `log_maint`, oder von Hand —, ist die Datei fort. Ein
`RotatingFileHandler`, der sie beim Start **einmal** geöffnet hat, schreibt
danach bis zum nächsten Neustart in einen gelöschten Inode: keine
Fehlermeldung, keine Datei, kein Hinweis. Auch die Rotation greift dann nicht
mehr.

Diese Fassung benutzt deshalb `WachsameRotation` in `bin/weissware.py` — einen
umlaufenden Handler, der vor jeder Zeile Gerätenummer und Inode vergleicht und
nötigenfalls neu öffnet. Die Standardbibliothek hat für den einen Fall den
`WatchedFileHandler` und für den anderen den `RotatingFileHandler`, aber
nichts, was beides kann; deshalb die eigene Klasse.

Auf dem LoxBerry geeicht, vier Prüfungen und in beide Richtungen: schreiben,
nach dem Löschen weiterschreiben, Umlauf bei Überlänge, nach dem Umlauf erneut
löschen. Mit dem alten Handler ist die Zeile nach dem Löschen verloren und
bleibt es, mit dem neuen steht sie in der wieder angelegten Datei. Auf einem
Windows-Arbeitsplatz lässt sich das nicht messen — dort kann eine offene Datei
gar nicht gelöscht werden.

Aufgefallen ist die Bauart am Heimkino-Plugin, dessen Dienst sieben Stunden
ohne Protokolldatei lief, und am laufenden Gerät belegt: der
Midea2Lox-Dienst hielt `midea2lox.log (deleted)` offen, während unter
demselben Namen längst eine neue Datei fortgeschrieben wurde — von außen sah
das Plugin gesund aus. Elf Linien tragen dieselbe Bauart; alle elf sind am
06.09.2026 nachgezogen worden.

**Die zweite Hälfte gehört dem Startskript.** `bin/dienst.sh` hängte die
Ausgabe des Dienstes mit `nohup … >> "$LOGDATEI"` an **dieselbe** Datei, die
der Handler führt. Damit hält die Shell einen zweiten, anhängenden Deskriptor
darauf — und der bleibt auf der gelöschten Datei stehen, gleich wie gut das
Programm nachfasst. Am Gerät gemessen (06.09.2026): sieben laufende Dienste
hielten so eine gelöschte Protokolldatei offen. Die Ausgabe geht jetzt in
`weissware_start.log`, das bei jedem Start geleert wird; das Protokoll gehört
allein dem Handler. Übernommen von AnkerSolix, das es seit 0.9.6 so macht.

Im Sandkasten am Gerät geprüft, in beide Richtungen: mit dem alten Skript
steht die Dienstausgabe im Protokoll und es gibt keine Startdatei, mit dem
neuen ist es umgekehrt — Start, Startdatei, unberührtes Protokoll und Stopp
je sechs von sechs.


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
