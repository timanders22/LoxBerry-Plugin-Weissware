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

## Lizenz

MIT — siehe [LICENSE](LICENSE). Das Projekt ist mit keinem der drei Hersteller
verbunden. Alle drei können ihre Schnittstellen ohne Ankündigung ändern, womit
dieses Plugin ganz oder teilweise unbrauchbar würde.
