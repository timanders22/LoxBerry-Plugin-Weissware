#!/bin/bash
# Weissware Cloud - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Vor dem Upgrade: laufenden Dienst anhalten und alles, was das Upgrade nicht
# ueberlebt, ausserhalb des Plugin-Ordners sichern.
#
# WARUM SO VIEL: plugininstall.pl raeumt beim Upgrade BEIDE Verzeichnisse ab -
# config/plugins/<ordner>/ UND data/plugins/<ordner>/. Bis 0.9.17 sicherte
# dieses Skript nur die zwei Dateien aus config/. Damit gingen bei jedem
# Auto-Update verloren:
#
#   data/.../token.json           die Anmeldung an ALLEN DREI Herstellerclouds.
#                                 Home Connect verlangt danach den Geraetecode
#                                 neu, Miele den Browsergang von Hand.
#   data/.../geraetenummern.json  die feste Zuordnung Geraet -> Nummer. Ohne
#                                 sie nummeriert der erste Lauf neu, und zwar
#                                 nach dem, was in diesem Augenblick antwortet:
#                                 faellt ein Anbieter aus, verschieben sich
#                                 genau die Adressen, gegen die die Zuordnung
#                                 gebaut wurde.
#   data/.../laeufe.json          die beendeten Programmlaeufe.
#   data/.../soll_laufen          der Merker "der Dienst soll laufen". Ohne ihn
#                                 startet auch der Waechter nicht mehr
#                                 (bin/dienst.sh, Zweig 'waechter'): das Plugin
#                                 schaltete sich beim Update still selbst ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-weissware}"
BASE="${ARGV5:-$LBHOMEDIR}"

CFGDIR="$BASE/config/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
SICHER="$BASE/config/plugins"

# ZUERST merken, ob der Dienst laufen soll - 'dienst.sh stop' entfernt den
# Merker selbst, und danach waere die Antwort nicht mehr zu bekommen.
if [ -f "$PDATA/soll_laufen" ]; then
    : > "$SICHER/$PFOLDER.backup.lief" || true
    echo "<INFO> Der Dienst lief - er wird nach dem Upgrade wieder gestartet."
else
    rm -f "$SICHER/$PFOLDER.backup.lief" 2>/dev/null || true
fi

# Anhalten ueber dienst.sh: dessen laeuft() prueft argumentweise gegen
# /proc/<pid>/cmdline, ob die Nummer wirklich unser Dienst ist. Ein blankes
# kill auf den Inhalt der PID-Datei traefe nach Nummernrecycling einen
# fremden Prozess - erst TERM, zwei Sekunden spaeter KILL.
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
# Die Meldung haengt am Merker, den der Abschnitt darueber setzt.
#
# `anhalten()` in dienst.sh gibt auch ohne laufenden Dienst 0 zurueck,
# und hier stand die Zeile ohnehin unbedingt: das Protokoll meldete bei
# jedem Update einen angehaltenen Dienst. Gemessen 11.09.2026 ueber den
# Bestand; derselbe Fehler steckte in vier Linien.
if [ -x "$DIENST" ]; then
    "$DIENST" stop >/dev/null 2>&1 || true
    if [ -f "$SICHER/$PFOLDER.backup.lief" ]; then
        echo "<INFO> Laufender Dienst angehalten."
    else
        echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
    fi
elif [ -f "$PDATA/dienst.pid" ]; then
    P=$(cat "$PDATA/dienst.pid" 2>/dev/null)
    case "$P" in
        ''|*[!0-9]*) P="" ;;
    esac
    if [ -n "$P" ] && tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null \
         | sed -n '2p' | grep -q 'weissware\.py$'; then
        kill "$P" 2>/dev/null || true
        sleep 2
        kill -9 "$P" 2>/dev/null || true
        echo "<INFO> Laufender Dienst angehalten (Rueckfallweg)."
    fi
    rm -f "$PDATA/dienst.pid"
fi

# Ist diese Datei gueltiges JSON UND traegt sie darin mindestens einen der
# genannten Schluessel mit einem nicht leeren Wert?
#
# "[ -s DATEI ]" heisst nur "nicht leer" und ist zu schwach: eine
# abgeschnittene weissware.json ist nicht leer, aber fuer jeden JSON-Leser
# unbrauchbar - und wenn sie ueber die Zweitschrift kopiert wird, ist das
# Aktionstoken endgueltig fort (Regeln/05, "Die Selbstheilung entscheidet
# nach Inhalt"; gemessen 18.09.2026, Pruefung-Weissware-0.9.25,
# Messstelle preupgrade).
#
# Ein blosses grep nach dem Schluessel genuegt ebenfalls nicht: die
# abgeschnittene Datei traegt den Text "aktionstoken":"..." weiterhin. Eine
# Mustersuche misst das Muster, nicht die Aussage - mit grep allein blieben
# am 18.09.2026 zwei Zeilen des Pruefstands rot. Deshalb wird wirklich
# gelesen. PHP ist auf jedem LoxBerry da (die gesamte Oberflaeche ist PHP);
# fehlt es doch, bleibt nur die schwaechere Antwort, und sie wird als solche
# gemeldet statt fuer eine Messung ausgegeben.
ww_hat_wert() {
    D=$1
    shift
    [ -f "$D" ] || return 1
    if command -v php >/dev/null 2>&1; then
        php -r '$d=json_decode((string)@file_get_contents($argv[1]),true); $rc=1; if (is_array($d)) { for ($i=2;$i<$argc;$i++) { $k=$argv[$i]; if (isset($d[$k]) && trim((string)$d[$k]) !== "") { $rc=0; break; } } } exit($rc);' "$D" "$@" >/dev/null 2>&1
        return $?
    fi
    echo "<INFO> Kein PHP gefunden - die Konfiguration wird nur ueberschlaegig geprueft."
    # Schwaecherer Ersatz: Schluessel vorhanden UND die Datei endet auf eine
    # schliessende Klammer. Sie erkennt die uebliche abgeschnittene Datei,
    # aber nicht eine, die zufaellig hinter einem verschachtelten Block
    # endet (in weissware.json waere das der tts-Abschnitt).
    [ "$(tr -d ' \t\n\r' < "$D" 2>/dev/null | tail -c 1)" = "}" ] || return 1
    for k in "$@"; do
        if grep -q "\"$k\"[[:space:]]*:[[:space:]]*\"[^\"]\{1,\}\"" "$D" 2>/dev/null; then
            return 0
        fi
    done
    return 1
}

# Konfiguration
#
# Die Zweitschrift wird NUR erneuert, wenn der neue Stand traegt, was dort
# schon steht. Sonst bleibt sie stehen, und das Protokoll sagt es - das
# Upgrade laeuft trotzdem weiter.
for f in weissware.json zugang.json; do
    [ -f "$CFGDIR/$f" ] || continue
    case "$f" in
        weissware.json) SCHLUESSEL="aktionstoken" ;;
        *)              SCHLUESSEL="hc_client_secret miele_client_secret st_token" ;;
    esac
    if ww_hat_wert "$SICHER/$PFOLDER.backup.$f" $SCHLUESSEL && ! ww_hat_wert "$CFGDIR/$f" $SCHLUESSEL; then
        echo "<INFO> $f traegt nicht mehr, was die vorhandene Sicherung fuehrt -"
        echo "<INFO> die Sicherung bleibt unveraendert ($SCHLUESSEL)."
        continue
    fi
    cp -p "$CFGDIR/$f" "$SICHER/$PFOLDER.backup.$f" || true
done

# Ist diese Datei gueltiges JSON mit mindestens einem Eintrag? Wortgleich mit
# postinstall.sh - die beiden Skripte muessen dieselbe Frage gleich
# beantworten.
#
# Warum nicht ww_hat_wert(): fuer die drei Datendateien gibt es keinen festen
# Schluesselnamen, den man abfragen koennte - geraetenummern.json fuehrt die
# Geraetekennungen selbst als Schluessel, laeufe.json eine Liste. Gefragt wird
# deshalb nach dem, was sie gemeinsam haben: lesbares JSON, das etwas enthaelt.
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

# Daten, die ein Upgrade sonst nicht ueberleben
#
# Dieselbe Wache wie oben bei der Konfiguration, nur fuer die Daten: eine
# abgeschnittene Datei darf die heile Sicherung nicht verdraengen. Bis 0.9.25
# wurde hier unbedingt kopiert - eine halb geschriebene token.json
# ueberschrieb die letzte heile Abschrift der Anmeldung an drei
# Herstellerclouds, und postinstall.sh konnte danach nichts mehr zurueckholen.
# Nachgestellt in Pruefung-Weissware-0.9.26, Fall C6.
for f in token.json geraetenummern.json laeufe.json; do
    [ -f "$PDATA/$f" ] || continue
    if ww_json_traegt "$SICHER/$PFOLDER.backup.$f" && ! ww_json_traegt "$PDATA/$f"; then
        echo "<INFO> $f traegt keinen lesbaren Inhalt mehr - die vorhandene"
        echo "<INFO> Sicherung bleibt unveraendert."
        continue
    fi
    cp -p "$PDATA/$f" "$SICHER/$PFOLDER.backup.$f" || true
done

# Beide Geheimnisdateien auf 0600 - weissware.json traegt das Aktionstoken,
# mit dem der Endpunkt schaltende Befehle annimmt, token.json die Anmeldung.
for f in zugang.json weissware.json token.json; do
    chmod 600 "$SICHER/$PFOLDER.backup.$f" 2>/dev/null || true
done
echo "<OK> preupgrade abgeschlossen."
exit 0
