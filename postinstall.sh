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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"

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

for f in weissware.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    if [ -f "$BK" ]; then
        INHALT=$(cat "$CF" 2>/dev/null)
        if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
            cp -p "$BK" "$CF" && echo "<OK> $f aus Sicherung wiederhergestellt."
        fi
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
for f in token.json geraetenummern.json laeufe.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    DF="$PDATA/$f"
    if [ -f "$BK" ] && [ ! -s "$DF" ]; then
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
if [ -f "$LIEF" ]; then
    if [ -x "$PBIN/dienst.sh" ] && "$PBIN/dienst.sh" start >/dev/null 2>&1; then
        echo "<OK> Der Dienst wurde wieder gestartet."
    else
        echo "<INFO> Der Dienst lief vor dem Upgrade, liess sich aber nicht"
        echo "<INFO> starten. Bitte im Reiter Einstellungen nachsehen."
    fi
    rm -f "$LIEF"
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche, Reiter Einstellungen:"
echo "<INFO>   1. Anbieter einschalten, die Sie haben"
echo "<INFO>   2. Zugangsdaten eintragen (Home Connect und Miele brauchen ein"
echo "<INFO>      kostenloses Entwicklerkonto), speichern"
echo "<INFO>   3. Anmelden (Home Connect ueber Code, Miele ueber Browser)"
echo "<INFO>   4. Dienst starten"
exit 0
