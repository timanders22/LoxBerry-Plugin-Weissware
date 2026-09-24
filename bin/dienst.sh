#!/bin/bash
# Weissware Cloud - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade kommen aus der UMGEBUNG, solange sie etwas sagt
# ($LBHOMEDIR, $LBPPLUGINDIR); erst danach aus dem Ablageort. Nicht ueber
# LoxBerry::System: das leitet den Pluginordner aus dem Aufrufort ab; wird
# dieses Skript aus postinstall.sh oder aus dem Cron gestartet, kommt dort
# ueberall Leerstring zurueck - das Skript werkelt dann gegen /-Pfade und
# meldet trotzdem Erfolg. Die Abstufung steht bei ww_wurzel() weiter unten.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>

# Wurzel und Ordnername werden GELESEN, nicht geraten (Regeln/03: Stufe 1 ist
# $LBHOMEDIR; Regeln/06: "Ein Systempfad wird nicht fest verdrahtet").
#
# Bis 0.9.25 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# - beides aus dem Ablageort, und ein gesetztes $LBHOMEDIR wurde dabei
# UEBERSCHRIEBEN. Am 18.09.2026 nachgestellt (Pruefung-Weissware-0.9.26,
# Fall H1; Bauart H1 aus Bestand-2026-09-18/klasse-H): 'dienst.sh status' aus
# einem Pruefarchiv unter <Wurzel>/pruefung/<plugin>/bin legte in der
# LAUFENDEN Installation data/plugins/bin und log/plugins/bin an - mit
# 'status', also mit einem Aufruf, den jeder fuer folgenlos haelt.
ww_wurzel() {
    # Ein gesetztes $LBHOMEDIR gilt nur mit config/plugins darunter (nicht
    # general.json - Attrappen wie Werkzeuge/lb tragen keine). Bis 0.9.28
    # genuegte ein beliebiges Verzeichnis; gemessen am 19.09.2026
    # (Pruefung-Weissware-0.9.29, messe_h2.sh, Fall S1): 'start' legte darin
    # data/plugins/weissware an. Dieselbe Regel wie ww_lbhome() in der
    # Oberflaeche und _lbhome_ermitteln() in bin/weissware.py.
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ]; then
        printf '%s\n' "$LBHOMEDIR"
        return 0
    fi
    # Aufwaerts suchen statt raten: das erste Verzeichnis, das config/plugins,
    # webfrontend UND config/system/general.json traegt, ist die Wurzel.
    # Dieselbe Suche wie in bin/weissware.py und in ww_lib.php.
    #
    # general.json unterscheidet einen LoxBerry von einem Rest aus
    # Pruefstaenden: ein LoxBerry hat sie immer, ein solcher Rest nie
    # (Regeln/06). Ohne sie galt ein fremder Baum mit config/plugins und
    # webfrontend als Wurzel - gemessen am 18.09.2026 in WSL
    # (Pruefung-Weissware-0.9.28, Faelle F1 bis F3): 'start' legte dort
    # data/plugins/weissware und log/plugins/weissware an, 'stop' loeschte
    # dessen soll_laufen.
    #
    # Nach der Suche kommt KEIN Rueckfall auf den Ablageort mehr. Hier stand
    # '(cd "$SELF/../../.." && pwd)'; er fuehrte in den Baum zurueck, den die
    # Suche gerade abgelehnt hatte - installiert genau auf dessen Wurzel, aus
    # einem Archiv darin auf einen Ordner darin. Mit general.json in der Suche
    # allein blieben F1 bis F3 deshalb rot (dieselbe Messung, Stufe A). Ohne
    # Wurzel steigt das Skript unten aus, bevor es etwas anlegt.
    ww_d=$SELF
    ww_i=0
    while [ "$ww_i" -lt 8 ]; do
        if [ -d "$ww_d/config/plugins" ] && [ -d "$ww_d/webfrontend" ] \
           && [ -f "$ww_d/config/system/general.json" ]; then
            printf '%s\n' "$ww_d"
            return 0
        fi
        ww_e=$(dirname "$ww_d")
        [ "$ww_e" = "$ww_d" ] && break
        ww_d=$ww_e
        ww_i=$((ww_i + 1))
    done
    return 1
}
# LBPPLUGINDIR steht am Geraet nicht in jeder Umgebung (Regeln/03, an 43
# Linien gemessen) - deshalb die zweite Stufe. Die dritte greift nur dort, wo
# der Ablageort nachweislich kein Pluginordner sein kann: aus einem
# Pruefarchiv heraus heisst er "bin".
ww_ordner() {
    case "${LBPPLUGINDIR:-}" in
        ''|bin|plugins) ;;
        *) printf '%s\n' "${LBPPLUGINDIR%/}"; return 0 ;;
    esac
    ww_b=$(basename "$SELF")
    case "$ww_b" in
        ''|/|bin|plugins) printf 'weissware\n' ;;
        *) printf '%s\n' "$ww_b" ;;
    esac
}
PNAME=$(ww_ordner)
LBHOMEDIR=$(ww_wurzel)
# Ohne Wurzel wird nichts angelegt und nichts gestartet - die Pfade darunter
# begannen sonst mit "/data/plugins/...". Die Pruefung steht VOR dem ersten
# Anlegen, nicht danach.
if [ -z "$LBHOMEDIR" ] || [ ! -d "$LBHOMEDIR" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden."
    echo "        \$LBHOMEDIR ist nicht gesetzt oder traegt kein config/plugins,"
    echo "        und oberhalb von"
    echo "        $SELF traegt kein Verzeichnis config/plugins, webfrontend"
    echo "        und config/system/general.json."
    echo "        Es wurde nichts angelegt und nichts gestartet."
    exit 1
fi
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
# Die Marke "Aktualisierung laeuft". Sie liegt NEBEN dem Datenordner, weil
# purge_installation data/plugins/<ordner>/ zwischen preupgrade.sh und
# postinstall.sh restlos abraeumt (Regeln/06) - im Ordner waere sie genau
# dann fort, wenn sie gebraucht wird. preupgrade.sh legt sie als Erstes an,
# postinstall.sh entfernt sie beim Verlassen (trap), NACH seinem Dienststart.
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
LOGDATEI="$PLOG/weissware.log"
# Eigene Datei fuer alles, was NEBEN dem Protokoll anfaellt: Meldungen des
# Starts und alles, was das Programm nach stderr schreibt, bevor sein
# Protokoll steht (Syntaxfehler, fehlende Bibliothek, Abbruch im Importpfad).
#
# Bis 0.9.19 ging diese Ausgabe mit ">> $LOGDATEI" in DIESELBE Datei, die
# bin/weissware.py mit einem umlaufenden Handler fuehrt. Das haelt einen zweiten,
# anhaengenden Deskriptor auf diese Datei offen. Beim Ueberlauf benennt der
# Handler um, beim Leeren der Ramdisk verschwindet die Datei ganz - der
# Deskriptor dieser Shell zeigt danach weiter auf die weggeschobene oder
# geloeschte Datei, und was er traegt, sieht niemand mehr. Am Geraet gemessen
# (06.09.2026): sieben Dienste hielten so eine geloeschte Protokolldatei offen.
# Regel: genau einer schreibt in eine Protokolldatei.
STARTLOG="$PLOG/weissware_start.log"
PY="$SELF/venv/bin/python3"
SKRIPT="$SELF/weissware.py"
# Der Dienst laeuft als loxberry (siehe den Abstieg oben); wo es den Benutzer
# nicht gibt, als der eigene. Gebraucht fuer die Suche nach Diensten ohne
# PID-Datei: ohne Benutzerfilter liefe sie ueber fremde Prozesse.
DIENSTUID=$(id -u loxberry 2>/dev/null || id -u)

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
    # cat statt Umlenkung wie in ist_dienst() unten: endet der Prozess
    # zwischen "kill -0" und dem Lesen, meldete die Schale die gescheiterte
    # Umlenkung selbst ("line 185: /proc/<n>/cmdline: No such file",
    # gemessen Pruefung-Weissware-0.9.30, Fall C2) - und das landet ueber
    # ww_dienst() in der Oberflaeche.
    ARGS=$(cat "/proc/$P/cmdline" 2>/dev/null | tr '\0' '\n')
    if [ "$(echo "$ARGS" | sed -n '2p')" != "$SKRIPT" ]; then rm -f "$PID"; return 1; fi
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)python[0-9.]*$' || { rm -f "$PID"; return 1; }
    return 0
}

# Dieselbe Probe fuer eine BELIEBIGE Nummer, zusaetzlich mit dem Benutzer -
# gebraucht fuer die Suche nach Diensten ohne PID-Datei. Wortgleich mit
# ww_ist_dienst() in preupgrade.sh. Argumentweise (Regeln/06, Berichtigung
# vom 18.09.2026): argv[0] ist ein Python, argv[1] ist GENAU dieses Skript
# (ein relativer Start wird gegen /proc/<pid>/cwd aufgeloest), und es folgt
# KEIN drittes Argument - "weissware.py --selbsttest" oder "--hc-anmelden"
# aus der Oberflaeche ist ein Einmallauf, kein Dienst.
ist_dienst() {   # $1 PID
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$DIENSTUID" ] || return 1
    # cat statt Umlenkung: sonst meldet die Schale einen Prozess, der zwischen
    # Auflistung und Lesen endet, auf der Fehlerausgabe - und die landet ueber
    # ww_dienst() in der Oberflaeche.
    ROH=$(cat "/proc/$1/cmdline" 2>/dev/null | tr '\0' '\n')
    [ -n "$ROH" ] || return 1
    A0=$(printf '%s\n' "$ROH" | sed -n '1p')
    A1=$(printf '%s\n' "$ROH" | sed -n '2p')
    A2=$(printf '%s\n' "$ROH" | sed -n '3p')
    [ -n "$A0" ] && [ -n "$A1" ] && [ -z "$A2" ] || return 1
    case "${A0##*/}" in python|python[0-9.]*) ;; *) return 1 ;; esac
    case "$A1" in
        /*) ZIEL=$A1 ;;
        *)  WD=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            ZIEL="${WD% (deleted)}/$A1" ;;
    esac
    [ "$ZIEL" = "$SKRIPT" ]
}
dienste_suchen() {
    for D in /proc/[0-9]*; do
        ist_dienst "${D#/proc/}" && echo "${D#/proc/}"
    done
    return 0
}
# Beendet jeden eigenen Dienst, der gerade laeuft - auch den, der in keiner
# PID-Datei steht. Zehn Sekunden Zeit, dann hart; vor jedem Signal steht die
# Probe oben. Gibt die Nummern aus.
#
# Warum: die PID-Datei liegt im Datenordner, und den raeumt
# purge_installation beim Upgrade restlos ab (Regeln/06). Ein Dienst, der in
# keiner PID-Datei steht, blieb bis 0.9.26 bei "stop" stehen - und
# postinstall.sh stellte nach dem Upgrade einen zweiten daneben. In WSL
# gemessen (Pruefung-Weissware-0.9.27, Faelle R3 bis R5): zwei Dienste nach
# dem Upgrade, einer nach "stop".
waisen_beenden() {
    LISTE=$(dienste_suchen)
    [ -n "$LISTE" ] || return 0
    kill $LISTE 2>/dev/null
    N=0
    while [ $N -lt 10 ] && [ -n "$(dienste_suchen)" ]; do
        sleep 1
        N=$((N + 1))
    done
    REST=$(dienste_suchen)
    [ -n "$REST" ] && kill -9 $REST 2>/dev/null
    echo $LISTE
}

# Laeuft gerade eine Aktualisierung dieses Plugins?
#
# Gemessen (Pruefung-Weissware-0.9.27, Faelle P2/P3, 18.09.2026): ohne Marke
# legte der Knopf "Dienst starten" der Oberflaeche im Fenster, in dem
# postinstall.sh die Bibliothek installiert, soll_laufen an - der Start
# selbst scheiterte, weil requests noch fehlte, und die Oberflaeche meldete
# "Start fehlgeschlagen". Der Minutentakt startete den Dienst trotzdem, und
# ein vor dem Upgrade BEWUSST angehaltener Dienst lief danach. (Den Merker
# raeumt zusaetzlich das "stop" in postinstall.sh bei liegender Marke weg -
# erst ohne beide Stellen wird P2 rot, Eichfall K2K7.)
#
# Ausgaenge:
#   Marke hoechstens 3600 s alt  -> gesperrt (Fall C1)
#   Marke aelter, aus der Zukunft, leer oder unlesbar -> sie gilt nicht
#                                (Faelle C4 bis C7; eine abgebrochene
#                                Installation darf den Dienst nicht fuer
#                                immer stilllegen)
#   keine lesbare Uhr            -> die Pruefung faellt GESCHLOSSEN aus
#                                (CLAUDE.md 4; Fall C8)
#   WW_START_TROTZ_MARKE=1       -> Ausnahme fuer postinstall.sh (Fall C12)
marke_sperrt() {
    [ -f "$MARKE" ] || return 1
    [ "${WW_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$SEIT" in ''|*[!0-9]*) return 1 ;; esac
    ALTER=$((JETZT - SEIT))
    [ "$ALTER" -lt 0 ] && return 1
    [ "$ALTER" -le 3600 ]
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    # Diese Frage steht VOR dem mkdir und VOR dem touch auf soll_laufen
    # weiter unten. Stuende sie dahinter, legte der abgewiesene Start den
    # Merker trotzdem an, und der Waechter startete den Dienst eine Minute
    # spaeter doch (Fall C3). Rueckgabewert 0: eine laufende Aktualisierung
    # ist kein Fehlschlag.
    if marke_sperrt; then
        echo "Eine Aktualisierung dieses Plugins laeuft - der Dienst wird danach gestartet, falls er vorher lief."
        return 0
    fi
    # Angelegt wird nur da, wo wirklich etwas geschrieben wird - also beim
    # START. Bis 0.9.25 stand dieses mkdir auf oberster Ebene und lief bei
    # JEDEM Aufruf, auch bei 'status'. Zusammen mit der geratenen Wurzel
    # entstanden dadurch Ordner in einer fremden Installation
    # (Pruefung-Weissware-0.9.26, Fall H1). Nebenbefund derselben Zeile: in
    # der Upgrade-Luecke legte schon ein 'status' data/plugins/<ordner>
    # wieder an - "der Ordner ist da" sagte dort also nichts ueber eine
    # gelungene Ruecksicherung.
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
    if [ ! -x "$PY" ]; then
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/zugang.json" ]; then
        echo "FEHLER: Zugangsdaten fehlen ($PCONFIG/zugang.json). Erst in der Oberflaeche eintragen."
        return 1
    fi
    touch "$SOLL"
    # Die Ausgabe des Dienstes geht in die Startdatei, NICHT in das Protokoll:
    # dort schreibt allein der Handler des Programms. Beim Start gekappt, damit
    # sie nur die Ausgabe EINES Laufes sammelt und nicht unbegrenzt waechst.
    : > "$STARTLOG"
    nohup "$PY" "$SKRIPT" >> "$STARTLOG" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    ETWAS=0
    if laeuft; then
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
        ETWAS=1
    fi
    rm -f "$PID"
    # Und jeder eigene Dienst, der in KEINER PID-Datei steht (waisen_beenden
    # oben; Fall R5).
    WAISEN=$(waisen_beenden)
    if [ -n "$WAISEN" ]; then
        echo "angehalten; zusaetzlich ein Dienst ohne PID-Datei beendet (PID $WAISEN)"
        return 0
    fi
    if [ "$ETWAS" = "1" ]; then
        echo "angehalten"
        return 0
    fi
    echo "laeuft nicht"
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
            starten >> "$STARTLOG" 2>&1
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
        if command -v php >/dev/null 2>&1 && [ -f "$LIB" ]; then
            GRENZE=$(php -r "require '$LIB'; echo ww_wache_grenze();" 2>/dev/null)
        fi
        # FAIL SAFE, und diesmal wirklich. Bis 0.9.11 galt hier bei fehlender
        # Auskunft die Untergrenze von 180 s - der Kommentar oben sagte das
        # Gegenteil. Bei einem Ruhetakt von 300 s ist das Abbild routinemaessig
        # aelter als 180 s, der Waechter startete den Dienst also etwa alle
        # drei Minuten neu. Genau so ist es passiert, als ww_wache_grenze() aus
        # der Bibliothek entfernt wurde: der Fatalfehler ging nach /dev/null.
        #
        # Eine Untergrenze IST ein Zuschlagen, sobald der Takt groesser ist.
        # Wer die Grenze nicht kennt, laesst den Dienst in Ruhe - und sagt es.
        case "$GRENZE" in
            ''|*[!0-9]*)
                MERK="$PDATA/wache_keine_auskunft"
                MELDEN=1
                if [ -f "$MERK" ]; then
                    L=$(cat "$MERK" 2>/dev/null)
                    case "$L" in
                        ''|*[!0-9]*) L=0 ;;
                    esac
                    [ $((JETZT - L)) -gt 3600 ] || MELDEN=0
                fi
                if [ "$MELDEN" -eq 1 ]; then
                    echo "$JETZT" > "$MERK"
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: die Grenze laesst sich nicht ermitteln (php oder $LIB nicht verfuegbar, oder ww_wache_grenze() fehlt). Es wird NICHT neu gestartet - der Waechter ist bis auf Weiteres wirkungslos." >> "$LOGDATEI"
                fi
                exit 0 ;;
        esac
        rm -f "$PDATA/wache_keine_auskunft" 2>/dev/null
        # Die Untergrenze gilt weiterhin nach OBEN hin absichernd.
        [ "$GRENZE" -ge 180 ] || GRENZE=180
        ALTER=$((JETZT - STAND))
        [ "$ALTER" -gt "$GRENZE" ] || exit 0
        # NEU in 0.9.23: schlaeft der Dienst mit ABSICHT?
        #
        # Das Abbild sagt nur, wann zuletzt ein Durchlauf fertig wurde. Ob
        # niemand mehr arbeitet oder ob die Fehlerbremse laeuft, steht da
        # nicht drin - und die Bremse erreicht ab dem fuenften Fehlversuch
        # genau diese Grenze (beide 1500 s bei takt_ruhe 300). Bis 0.9.22
        # startete der Waechter deshalb einen gesunden Dienst neu, und weil
        # der Neustart fehler_folge zuruecksetzt, hob er die Bremse gleich
        # mit auf. Am Geraet gemessen am 15.09.2026.
        #
        # Gefragt wird erst HIER, nicht oben: nur wenn ohnehin ein Neustart
        # anstuende. Der Waechter laeuft minuetlich; ein zweiter
        # PHP-Aufruf je Minute waere Verschwendung fuer einen Fall, der fast
        # nie eintritt.
        PAUSE=$(php -r "require '$LIB'; echo ww_pause_bis();" 2>/dev/null)
        case "$PAUSE" in
            ''|*[!0-9]*) PAUSE=0 ;;
        esac
        # Obergrenze gegen einen verdorbenen oder liegengebliebenen Wert: der
        # Dienst deckelt seine Bremse selbst bei 3600 s. Was weiter in der
        # Zukunft liegt, ist keine Pause, sondern ein Fehler - und ein
        # Waechter, den man mit einer einzigen Zahl dauerhaft stilllegen kann,
        # ist keiner.
        if [ "$PAUSE" -gt "$JETZT" ] && [ $((PAUSE - JETZT)) -le 3900 ]; then
            MERK="$PDATA/wache_pause_gemeldet"
            MELDEN=1
            if [ -f "$MERK" ]; then
                L=$(cat "$MERK" 2>/dev/null)
                case "$L" in
                    ''|*[!0-9]*) L=0 ;;
                esac
                [ $((JETZT - L)) -gt 3600 ] || MELDEN=0
            fi
            if [ "$MELDEN" -eq 1 ]; then
                echo "$JETZT" > "$MERK"
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: das Abbild ist ${ALTER} s alt (Grenze ${GRENZE} s), der Dienst pausiert aber mit Absicht noch $((PAUSE - JETZT)) s - kein Neustart." >> "$LOGDATEI"
            fi
            exit 0
        fi
        rm -f "$PDATA/wache_pause_gemeldet" 2>/dev/null
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
        anhalten >> "$STARTLOG" 2>&1
        starten >> "$STARTLOG" 2>&1
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
