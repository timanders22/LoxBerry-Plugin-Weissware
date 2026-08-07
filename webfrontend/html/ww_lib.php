<?php
/**
 * Weissware Cloud - gemeinsame Bibliothek
 *
 * Liegt bewusst unter webfrontend/html/, weil der Miniserver-Endpunkt sie
 * ebenso braucht wie die Oberflaeche. Nur so gibt es EINE Datei statt zweier
 * Kopien, die auseinanderlaufen. Die Oberflaeche unter htmlauth/ laedt sie von
 * hier (drei Kandidatenpfade: installiert und im Archiv).
 *
 * Die Bibliothek spricht NIE mit der Hersteller-Schnittstelle. Sie liest den
 * Zwischenspeicher, den bin/weissware.py schreibt, und legt Schreibbefehle in
 * einer Warteschlange ab. Ein Plugin, das den Datenabruf in der Oberflaeche
 * oder im Endpunkt erledigt, ist falsch gebaut - auch wenn es funktioniert.
 *
 * Praefix 'ww_', weil LBWeb::lbheader() SDK-Globale setzt und gleichnamige
 * Plugin-Variablen ueberschreiben wuerde.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('ww_e')) {
    function ww_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function ww_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) {
                $home = $k;
                break;
            }
        }
    }
    // Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(getenv('LBPPLUGINDIR'), 'weissware') as $kand) {
            if ($kand && is_dir($home . '/config/plugins/' . $kand)) {
                $dir = $kand;
                break;
            }
        }
    }
    if ($home) {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/weissware.json',
            'zugang'    => $home . '/config/plugins/' . $dir . '/zugang.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.weissware.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/weissware.log',
        );
    } else {
        // Nicht installiert (Entwicklung, Attrappe): neben dem Plugin arbeiten.
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/weissware.json',
            'zugang'    => $basis . '/config/zugang.json',
            'sicherung' => $basis . '/config/vw.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/weissware.log',
        );
    }
    return $p;
}

/** Voreinstellungen. Muessen zu VORGABEN in bin/weissware.py passen. */
function ww_vorgaben()
{
    return array(
        'takt_ruhe'      => 300,
        'takt_betrieb'   => 60,
        'mqtt_ein'       => 0,
        'mqtt_topic'     => 'weissware',
        'steuerung_ein'  => 0,
        'hc_ein'         => 0,
        'hc_simulator'   => 0,
        'miele_ein'      => 0,
        'st_ein'         => 0,
        'sprache'        => 'de-DE',
        'aktionstoken'   => '',
        'wartezeit'      => 12,
    );
}

function ww_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

function ww_config()
{
    $p = ww_paths();
    // Selbstheilung: fehlende oder leere Konfiguration aus der Sicherung holen.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    $cfg = ww_json_lesen($p['config']);
    return array_merge(ww_vorgaben(), $cfg);
}

function ww_config_speichern($cfg)
{
    $p = ww_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($json === false || @file_put_contents($p['config'], $json) === false) {
        return false;
    }
    @copy($p['config'], $p['sicherung']);
    return true;
}

/**
 * Zugangsdaten.
 *
 * Eigene Datei mit Rechten 0600, nicht in der Konfiguration, die die
 * Oberflaeche anzeigt. Passwort und S-PIN werden nie zurueckgegeben - nur
 * ihre Laenge.
 */
function ww_zugang()
{
    $z = ww_json_lesen(ww_paths()['zugang']);
    $l = function ($k) use ($z) {
        return isset($z[$k]) ? strlen((string) $z[$k]) : 0;
    };
    return array(
        // Die Client-ID ist keine Geheimzahl im engeren Sinne und wird
        // angezeigt; alles uebrige nur als Laenge. Ein Pruefknopf darf die
        // FORM eines Geheimnisses beurteilen, nie seinen Wert zeigen.
        'hc_client_id'     => isset($z['hc_client_id']) ? (string) $z['hc_client_id'] : '',
        'hc_secret_laenge' => $l('hc_client_secret'),
        'miele_client_id'  => isset($z['miele_client_id']) ? (string) $z['miele_client_id'] : '',
        'miele_secret_laenge' => $l('miele_client_secret'),
        'st_laenge'        => $l('st_token'),
    );
}

/**
 * Speichert die Zugangsdaten.
 *
 * Ein leer zurueckgegebenes Passwortfeld loescht nichts: sonst stuende
 * irgendwann ein leeres Passwort in der Datei, ohne dass es jemand merkt.
 * Genau dieser Fehler hat im ACTi-Plugin 21 vergebliche Anmeldeversuche
 * verursacht.
 */
/**
 * Speichert die Zugangsdaten der drei Anbieter.
 *
 * Ein leer zurueckgegebenes Geheimfeld loescht nichts: sonst stuende
 * irgendwann ein leeres Geheimnis in der Datei, ohne dass es jemand merkt.
 * Genau dieser Fehler hat im ACTi-Plugin 21 vergebliche Anmeldeversuche
 * verursacht.
 */
function ww_zugang_speichern($neu)
{
    $p = ww_paths();
    if (!is_dir($p['configdir'])) {
        @mkdir($p['configdir'], 0775, true);
    }
    $alt = ww_json_lesen($p['zugang']);
    $felder = array('hc_client_id', 'hc_client_secret', 'miele_client_id',
                    'miele_client_secret', 'st_token');
    $aus = array();
    foreach ($felder as $f) {
        $wert = isset($neu[$f]) ? (string) $neu[$f] : '';
        $aus[$f] = ($wert !== '') ? $wert : (isset($alt[$f]) ? $alt[$f] : '');
    }
    $json = json_encode($aus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = @file_put_contents($p['zugang'], $json) !== false;
    @chmod($p['zugang'], 0600);
    return $ok;
}

/** Ist ein Anbieter angemeldet? Liest die Markendatei des Dienstes. */
function ww_angemeldet($anbieter)
{
    $m = ww_json_lesen(ww_paths()['datadir'] . '/token.json');
    return !empty($m[$anbieter]['refresh_token']) ? 1 : 0;
}

/** Laeuft gerade eine Home-Connect-Anmeldung? Rueckgabe: array oder leer. */
function ww_hc_anmeldung()
{
    $a = ww_json_lesen(ww_paths()['datadir'] . '/hc_anmeldung.json');
    if (empty($a['user_code']) || time() > (int) $a['laeuft_ab']) {
        return array();
    }
    return $a;
}

/**
 * Ruft das Dienstskript mit einem Schalter auf (Anmeldung, Selbsttest).
 *
 * Zugangsdaten werden NIE ueber die Kommandozeile uebergeben - Argumente
 * stehen in der Prozessliste. Der Miele-Code ist kein Dauergeheimnis, er
 * verfaellt nach einmaligem Einloesen; er wird trotzdem ueber eine Datei
 * gereicht, damit die Regel keine Ausnahme bekommt.
 */
function ww_dienst_schalter($schalter, $wert = '')
{
    $p = ww_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/weissware.py';
    if (!is_file($py) || !is_file($skript)) {
        return array(0, 'Die virtuelle Python-Umgebung oder weissware.py fehlt.');
    }
    $befehl = escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' ' . escapeshellarg($schalter);
    if ($wert !== '') {
        $befehl .= ' ' . escapeshellarg($wert);
    }
    $ausgabe = array();
    $code = 0;
    @exec($befehl . ' 2>&1', $ausgabe, $code);
    return array($code, implode("\n", $ausgabe));
}

/** Zufallstoken fuer den unangemeldeten Endpunkt. */
function ww_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/** Sorgt dafuer, dass ein Token vorhanden ist, und gibt es zurueck. */
function ww_token()
{
    $cfg = ww_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = ww_token_erzeugen();
        ww_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Zwischenspeicher lesen ---------------- */

function ww_loxone()
{
    return ww_json_lesen(ww_paths()['datadir'] . '/loxone.json');
}

function ww_zustand()
{
    return ww_json_lesen(ww_paths()['datadir'] . '/zustand.json');
}

/** Geraete aus dem Abbild, 1-basiert. */
function ww_geraete()
{
    $l = ww_loxone();
    return isset($l['geraete']) && is_array($l['geraete']) ? $l['geraete'] : array();
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function ww_alter()
{
    $l = ww_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Dienst ---------------- */

function ww_dienst_pid()
{
    $f = ww_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    // Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'weissware.py') !== false ? $pid : 0;
}

function ww_dienst_soll()
{
    return is_file(ww_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function ww_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = ww_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/** Fassung von requests in der virtuellen Umgebung, oder ''. */
function ww_bibliothek_fassung()
{
    $py = ww_paths()['bindir'] . '/venv/bin/python3';
    if (!is_file($py)) {
        return '';
    }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' -c ' . escapeshellarg(
        'import requests; print(requests.__version__)'
    ) . ' 2>/dev/null', $ausgabe);
    return trim(implode('', $ausgabe));
}

/** Fassung des Python in der virtuellen Umgebung, oder ''. */
function ww_python_fassung()
{
    $py = ww_paths()['bindir'] . '/venv/bin/python3';
    if (!is_file($py)) {
        return '';
    }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' -c ' . escapeshellarg(
        'import sys; print("%d.%d.%d" % sys.version_info[:3])'
    ) . ' 2>/dev/null', $ausgabe);
    return trim(implode('', $ausgabe));
}

/** Ausgabe von weissware.py --selbsttest. */
function ww_selbsttest()
{
    $p = ww_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/weissware.py';
    if (!is_file($py) || !is_file($skript)) {
        return "[FEHL] Die virtuelle Python-Umgebung oder weissware.py fehlt.\n"
             . "       Erwartet: " . $py . "\n"
             . "                 " . $skript . "\n"
             . "       Abhilfe: Plugin neu installieren; die Installation legt beides an.";
    }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' --selbsttest 2>&1', $ausgabe);
    return implode("\n", $ausgabe);
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - also Ergebnis unbekannt.
 * Es wird bewusst kein Erfolg gemeldet, den niemand geprueft hat.
 */
function ww_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = ww_paths();
    $cfg = ww_config();
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    $wartezeit = max(0, min(30, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, json_encode($befehl)) === false || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = ww_json_lesen($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht geantwortet.');
}

/* ---------------- MQTT-Gateway ----------------
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
 * eingeschaltet.
 *
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt. Eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
 * Massgeblich ist Gatewayautostart.
 */
function ww_mqtt_zustand()
{
    $p = ww_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0,
                  'broker' => '', 'brokerport' => '', 'websocket' => '');
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = ww_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    $auto = $hol('Gatewayautostart', 'gatewayautostart');
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $auto, array('1', 'true'), true) ? 1 : 0,
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'websocket'  => (string) $hol('Websocketport', 'websocketport'),
    );
}

/** Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung. */
function ww_mqtt_themen()
{
    return array(
        'ok'                        => 'WW_MQTT.OK',
        'geraete'                   => 'WW_MQTT.GERAETE',
        'geraetN/name'              => 'WW_MQTT.NAME',
        'geraetN/anbieter'          => 'WW_MQTT.ANBIETER',
        'geraetN/zustand'           => 'WW_MQTT.ZUSTAND',
        'geraetN/zustand_text'      => 'WW_MQTT.ZUSTAND_TEXT',
        'geraetN/laeuft'            => 'WW_MQTT.LAEUFT',
        'geraetN/fertig'            => 'WW_MQTT.FERTIG',
        'geraetN/verbunden'         => 'WW_MQTT.VERBUNDEN',
        'geraetN/tuer_offen'        => 'WW_MQTT.TUER',
        'geraetN/fortschritt'       => 'WW_MQTT.FORTSCHRITT',
        'geraetN/restzeit_min'      => 'WW_MQTT.RESTZEIT',
        'geraetN/startzeit_min'     => 'WW_MQTT.STARTZEIT',
        'geraetN/laufzeit_min'      => 'WW_MQTT.LAUFZEIT',
        'geraetN/fernstart_frei'    => 'WW_MQTT.FERNSTART',
        'geraetN/fernbedienung_frei' => 'WW_MQTT.FERNBED',
        'geraetN/netz_ein'          => 'WW_MQTT.NETZ',
        'geraetN/energie_kwh'       => 'WW_MQTT.ENERGIE',
        'geraetN/wasser_l'          => 'WW_MQTT.WASSER',
        'geraetN/temperatur'        => 'WW_MQTT.TEMPERATUR',
        'geraetN/schleuderdrehzahl' => 'WW_MQTT.SCHLEUDER',
        'geraetN/programm_text'     => 'WW_MQTT.PROGRAMM',
    );
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.0.0 (ap_xml_virtual_in_http) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist.
 * ================================================================== */

function ww_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function ww_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . ww_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ww_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ww_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ww_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . ww_x($c['title']) . '" ';
        $o .= 'Comment="' . ww_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . ww_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Werte des Status-Endpunkts mit Einheit und Bedeutung.
 *
 * Reihenfolge und Namen sind zugleich die Reihenfolge der Befehlserkennungen
 * in der Loxone-Vorlage. Wer hier etwas einfuegt, aendert die Vorlage mit.
 */
function ww_status_felder()
{
    return array(
        'ZUSTAND'   => array('',    'WW_FELD.ZUSTAND'),
        'LAEUFT'    => array('',    'WW_FELD.LAEUFT'),
        'FERTIG'    => array('',    'WW_FELD.FERTIG'),
        'VERBUNDEN' => array('',    'WW_FELD.VERBUNDEN'),
        'TUER'      => array('',    'WW_FELD.TUER'),
        'FORTSCHR'  => array('%',   'WW_FELD.FORTSCHR'),
        'RESTMIN'   => array('min', 'WW_FELD.RESTMIN'),
        'STARTMIN'  => array('min', 'WW_FELD.STARTMIN'),
        'LAUFMIN'   => array('min', 'WW_FELD.LAUFMIN'),
        'FERNSTART' => array('',    'WW_FELD.FERNSTART'),
        'FERNBED'   => array('',    'WW_FELD.FERNBED'),
        'NETZ'      => array('',    'WW_FELD.NETZ'),
        'ALTER'     => array('s',   'WW_FELD.ALTER'),
        'OK'        => array('',    'WW_FELD.OK'),
    );
}

/** Die Werte des Lade-Endpunkts. */
/** Die Werte des Verbrauchs-Endpunkts. */
function ww_verbrauch_felder()
{
    return array(
        'ENERGIE'   => array('kWh', 'WW_VFELD.ENERGIE'),
        'WASSER'    => array('l',   'WW_VFELD.WASSER'),
        'TEMP'      => array('&deg;C', 'WW_VFELD.TEMP'),
        'SCHLEUDER' => array('U/min', 'WW_VFELD.SCHLEUDER'),
        'OK'        => array('',    'WW_VFELD.OK'),
    );
}

/** Die Werte des Wartungs-Endpunkts. */


/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function ww_vorlage($nummer = 1)
{
    $p = ww_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $token = ww_token();
    $cmds = array();
    foreach (ww_status_felder() as $feld => $info) {
        // Der Text laeuft gleich durch ww_x() und wuerde dort ein zweites Mal
        // maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
        // aufloesen - sonst stuende in Loxone Config wortwoertlich
        // 'l&auml;dt' statt 'laedt'.
        $bedeutung = trim(strip_tags(html_entity_decode(ww_t($info[1]), ENT_QUOTES, 'UTF-8')));
        $einheit = trim(strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')));
        $cmds[] = array(
            'title'   => 'AUDI_' . $nummer . '_' . $feld,
            'comment' => $bedeutung . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
        );
    }
    $adresse = 'http://' . $host . '/plugins/' . $p['plugin']
             . '/index.php?token=' . $token . '&aktion=status&geraet=' . (int) $nummer;
    return array(
        'weissware_geraet' . (int) $nummer . '.xml',
        ww_xml_virtual_in_http(array(
            'title'   => 'Weissware ' . (int) $nummer,
            'address' => $adresse,
            'polling' => '60',
            'comment' => 'Erzeugt vom LoxBerry-Plugin Weissware Cloud (' . date('d.m.Y') . ')',
        ), $cmds),
    );
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein ww_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function ww_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel 'ABSCHNITT.SCHLUESSEL'.
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt beim
 * Durchsehen sofort auf, was fehlt, statt dass die Seite leer bleibt.
 */
function ww_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . ww_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    $a = $teile[0];
    $s = $teile[1];
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
