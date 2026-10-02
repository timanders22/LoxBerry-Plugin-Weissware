#!/bin/bash
# Weissware Cloud - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu im Durchgangsbau vom 02.10.2026 (Bauliste I1, X-1, Entscheidung Nr. 1
# vom 29.09.2026; Muster: Govee 0.9.24 und Abfahrts-Assistent 1.6.19). Der
# Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der
# alten Fassung und VOR dem Kopieren von Konfiguration, Cron-Datei und
# Oberflaeche (sbin/plugininstall.pl: preupgrade :846, purge :874,
# preinstall :877, Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Sicherungen braucht
# postinstall.sh zum Zurueckspielen.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Sicherungen einer
# frueheren Installation - <ordner>.backup.weissware.json (Konfiguration mit
# Aktionstoken, zugleich die Zweitschrift der Selbstheilung), .backup.zugang.json
# (Client-Geheimnisse), .backup.token.json (Anmeldung an drei Herstellerclouds),
# .backup.geraetenummern.json, .backup.laeufe.json und der Merker .backup.lief -
# gehen nach <name>.alt, gemeldet mit genau einer <WARNING>.
#
# Bis 0.9.36 spielte eine Neuinstallation ueber solche Reste die alte Anmeldung,
# das alte Aktionstoken und die eingeschaltete Steuerung ein und startete den
# Dienst; das Protokoll nannte es "Aktualisierung" (weissware_agenten/installer
# I1, Faelle D und D3). Warum schon hier und nicht erst in postinstall.sh:
# zwischen dem Kopieren und postinstall.sh laeuft der Minutentakt
# (bin/ansage.php -> ww_config()), und die Selbstheilung haette die neue
# weissware.json aus der alten Zweitschrift geheilt. Die Selbstheilung liest
# .alt nie; die Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-weissware}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie in preupgrade.sh, postinstall.sh und uninstall: ohne
# config/plugins, data/plugins UND config/system/general.json wird nichts
# angefasst (Regeln/06, Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

SB="$BASE/config/plugins/$PFOLDER.backup"
BEISEITE=""
FEST=""
for ZIEL in "$SB.weissware.json" "$SB.zugang.json" "$SB.token.json" \
            "$SB.geraetenummern.json" "$SB.laeufe.json" "$SB.lief"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
            [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    WW_TEXT="<WARNING> Neuinstallation: Einstellungen und Anmeldungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && WW_TEXT="$WW_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && WW_TEXT="$WW_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$WW_TEXT"
fi
exit 0
