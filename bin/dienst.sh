#!/bin/bash
# Weissware Cloud - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/weissware.log"
PY="$SELF/venv/bin/python3"
SKRIPT="$SELF/weissware.py"

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    if [ -z "$P" ]; then rm -f "$PID"; return 1; fi
    if ! kill -0 "$P" 2>/dev/null; then
        # Der Prozess ist fort - die PID-Datei ist eine Leiche und wird
        # entfernt. Bis 0.9.0 blieb sie liegen; "status" meldete dann zwar
        # richtig, dass nichts laeuft, aber die Datei stand weiter herum und
        # jeder folgende Aufruf las sie erneut.
        rm -f "$PID"
        return 1
    fi
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    #
    # Bis 0.9.0 ein grep ueber die ganze Befehlszeile. Die enthaelt alle
    # Argumente; hat die wiederverwendete Nummer einen Editor mit geoeffneter
    # weissware.py erwischt, galt der als laufender Dienst. Geprueft werden
    # jetzt zwei Dinge argumentweise: das zweite Argument ist genau unser
    # Skript, und das erste ist ein Python. Nur das zweite zu pruefen reicht
    # nicht - "nano <pfad>/weissware.py" fuehrt den Pfad ebenfalls dort.
    ARGS=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null)
    if [ "$(echo "$ARGS" | sed -n '2p')" != "$SKRIPT" ]; then rm -f "$PID"; return 1; fi
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)python[0-9.]*$' || { rm -f "$PID"; return 1; }
    return 0
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if [ ! -x "$PY" ]; then
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/zugang.json" ]; then
        echo "FEHLER: Zugangsdaten fehlen ($PCONFIG/zugang.json). Erst in der Oberflaeche eintragen."
        return 1
    fi
    touch "$SOLL"
    # Ausgabe geht in die Logdatei. Das Python-Skript protokolliert deshalb
    # NICHT zusaetzlich nach stdout - sonst stuende jede Zeile doppelt darin.
    nohup "$PY" "$SKRIPT" >> "$LOGDATEI" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $LOGDATEI"
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        [ -f "$SOLL" ] || exit 0
        if ! laeuft; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$LOGDATEI" 2>&1
            exit 0
        fi
        # Der Prozess steht - aber arbeitet er auch? Eine PID-Datei beantwortet
        # das nicht, genauso wenig wie "systemctl is-active". Massgeblich ist,
        # was der Dienst HINTERLAESST: das Abbild wird in JEDEM Durchlauf
        # geschrieben, auch ohne eingerichtetes Geraet.
        #
        # Drei Bedingungen, alle drei noetig:
        #  * Fail safe. Kein Abbild, kein stat, keine Auskunft -> NICHT neu
        #    starten. Ein Waechter, der im Zweifel zuschlaegt, ist schlimmer
        #    als keiner.
        #  * Deutlich ueber dem Takt. Die Grenze steht in ww_wache_grenze()
        #    und hat zwei Verbraucher; hier wird sie geholt, nicht
        #    abgeschrieben.
        #  * Eine Bremse. Hilft der Neustart nicht, darf der Waechter nicht im
        #    Minutentakt nachsetzen und das Protokoll fluten.
        ABBILD="$PDATA/loxone.json"
        [ -f "$ABBILD" ] || exit 0
        JETZT=$(date +%s 2>/dev/null) || exit 0
        STAND=$(stat -c %Y "$ABBILD" 2>/dev/null) || exit 0
        [ -n "$STAND" ] || exit 0
        LIB="$LBHOMEDIR/webfrontend/html/plugins/$PNAME/ww_lib.php"
        GRENZE=""
        [ -f "$LIB" ] && GRENZE=$(php -r "require '$LIB'; echo ww_wache_grenze();" 2>/dev/null)
        case "$GRENZE" in
            ''|*[!0-9]*) GRENZE=180 ;;   # Auskunft nicht zu bekommen: Untergrenze
        esac
        ALTER=$((JETZT - STAND))
        [ "$ALTER" -gt "$GRENZE" ] || exit 0
        # Bremse: hoechstens ein Neustart je Grenzfenster.
        BREMSE="$PDATA/wache_letzter_neustart"
        if [ -f "$BREMSE" ]; then
            LETZT=$(cat "$BREMSE" 2>/dev/null)
            case "$LETZT" in
                ''|*[!0-9]*) LETZT=0 ;;
            esac
            [ $((JETZT - LETZT)) -gt "$GRENZE" ] || exit 0
        fi
        echo "$JETZT" > "$BREMSE"
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: der Dienst laeuft, hat aber seit ${ALTER} s kein Abbild geschrieben (Grenze ${GRENZE} s) - Neustart." >> "$LOGDATEI"
        anhalten >> "$LOGDATEI" 2>&1
        starten >> "$LOGDATEI" 2>&1
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
