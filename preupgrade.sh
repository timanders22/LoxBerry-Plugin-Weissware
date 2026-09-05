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
if [ -x "$DIENST" ]; then
    "$DIENST" stop >/dev/null 2>&1 || true
    echo "<INFO> Laufender Dienst angehalten."
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

# Konfiguration
for f in weissware.json zugang.json; do
    if [ -f "$CFGDIR/$f" ]; then
        cp -p "$CFGDIR/$f" "$SICHER/$PFOLDER.backup.$f" || true
    fi
done

# Daten, die ein Upgrade sonst nicht ueberleben
for f in token.json geraetenummern.json laeufe.json; do
    if [ -f "$PDATA/$f" ]; then
        cp -p "$PDATA/$f" "$SICHER/$PFOLDER.backup.$f" || true
    fi
done

# Beide Geheimnisdateien auf 0600 - weissware.json traegt das Aktionstoken,
# mit dem der Endpunkt schaltende Befehle annimmt, token.json die Anmeldung.
for f in zugang.json weissware.json token.json; do
    chmod 600 "$SICHER/$PFOLDER.backup.$f" 2>/dev/null || true
done
echo "<OK> preupgrade abgeschlossen."
exit 0
