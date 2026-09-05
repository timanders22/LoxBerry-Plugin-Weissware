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


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
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
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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
    /* Frueher wurde hier auf den festen Namen "weissware" zurueckgefallen,
     * sobald config/plugins/<ordner> noch fehlte - etwa im Augenblick der
     * Installation. Haengt LoxBerry bei einer Zweitinstallation einen Zaehler
     * an (weissware_01, weil der Name schon belegt war), zeigten deren Pfade
     * damit auf die ERSTE Installation: gemeinsame zugang.json - und darin
     * stehen die Client-Geheimnisse dreier Anbieter -, gemeinsame
     * Warteschlange, gemeinsames Protokoll.
     *
     * LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und bleibt deshalb.
     * Der feste Name greift nur noch dort, wo der ermittelte nachweislich kein
     * Plugin-Ordner sein kann: aus dem ausgepackten Archiv heraus heisst er
     * "html". */
    $lbp = getenv('LBPPLUGINDIR');
    if ($lbp) {
        $dir = $lbp;
    } elseif ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html') {
        $dir = 'weissware';
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
            'sicherung' => $basis . '/config/weissware.backup.json',
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
        // Ansage bewusst AUS als Vorgabe: ein Update soll nicht ungefragt
        // anfangen zu sprechen, womoeglich nachts.
        'ansage_ein'     => 0,
        // Je Ereignis ein eigener Haken, alle ab Werk AUS. Ein Update soll
        // nicht ungefragt anfangen zu sprechen.
        'ansage_stoerung'  => 0,
        'ansage_fernstart' => 0,
        // Ruhezeit. Bis 0.9.6 gab es keine: der Kommentar begruendete den
        // Vorgabewert ausdruecklich mit "womoeglich nachts", und genau dagegen
        // half nichts, sobald jemand die Ansage einschaltete.
        // Gleiche Werte = keine Ruhezeit.
        'ansage_ruhe_von'  => '22:00',
        'ansage_ruhe_bis'  => '07:00',
        'wartezeit'      => 12,
        // Mitschnitt: Unixzeit, bis zu der mitgeschrieben wird. 0 = aus, und
        // das ist die Werkseinstellung. Muss zu VORGABEN in bin/weissware.py
        // passen; der Reiter Test misst die Uebereinstimmung nach.
        'mitschnitt_bis' => 0,
        /* Sprachausgabe. Stand bis 0.9.17 NICHT in den Vorgaben, obwohl
         * die Oberflaeche den Schluessel bei jedem Speichern schreibt.
         * Folge: ww_sicherung_lesen() pruefte gegen diese Liste und wies
         * die vom Plugin selbst erzeugte Sicherungsdatei als "unbekannte
         * Einstellung: tts" ab - der Umzug auf einen zweiten LoxBerry,
         * der erklaerte Zweck der beiden Knoepfe, war nie moeglich. */
        'tts' => array(
            'mode'     => 'musicserver',
            'ip'       => '',
            'port'     => 7091,
            'zones'    => '1',
            'volume'   => 8,
            'lang'     => 'de',
            'template' => '',
        ),
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

/**
 * Liest die Konfiguration und ergaenzt sie um die Vorgaben.
 *
 * $erzeugen = false schaltet die Selbstheilung ab. Der UNANGEMELDETE
 * Endpunkt ruft so - er darf nichts anlegen.
 *
 * Bis 0.9.17 heilte diese Funktion bedingungslos, und webfrontend/html/
 * index.php rief sie VOR der Tokenpruefung. Gemessen unter PHP 7.4.33 und
 * 8.4.24: ein Aufruf ohne Token wurde richtig mit GRUND=TOKEN abgewiesen -
 * und legte dabei config/plugins/<ordner>/weissware.json aus der
 * Zweitschrift an. Wer die Adresse kennt, schaltete damit eine alte
 * Sicherung wieder scharf, samt steuerung_ein und altem Aktionstoken.
 */
function ww_config($erzeugen = true)
{
    $p = ww_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if ($erzeugen && ($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        // is_dir() davor: @mkdir mit einem vorhandenen Verzeichnis ruft den
        // Fehleraufnehmer trotzdem ("File exists") und faerbt jeden Pruef-
        // lauf mit einer Warnung, die keine ist.
        if (!is_dir($p['configdir'])) {
            @mkdir($p['configdir'], 0775, true);
        }
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
    if ($json === false) {
        return false;
    }
    /* Wie ww_zugang_speichern(): Nebendatei, Laengenvergleich, Rechte VOR
     * dem Umbenennen. Diese Datei traegt das Aktionstoken, mit dem der
     * Endpunkt schaltende Befehle annimmt - sie gehoert auf 0600, so wie
     * zugang.json. Bis 0.9.17 wurde sie mit den Rechten aus der umask
     * geschrieben und nie gechmoddet.
     *
     * === false allein genuegt nicht: ein Kurzschreiben (volle Ramdisk)
     * liefert die Byte-Zahl, nicht false. */
    $tmp = $p['config'] . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json) !== strlen($json)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $p['config'])) {
        @unlink($tmp);
        return false;
    }
    /* Die Zweitschrift wird NUR mitgezogen, wenn der Stand ein Aktionstoken
     * traegt. Sonst ueberschreibt ein Stand ohne Token die einzige Kopie,
     * die ihn noch hat - und mit ihr jede im Miniserver eingetragene
     * Adresse, still. */
    if (trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '')) !== '') {
        @copy($p['config'], $p['sicherung']);
        @chmod($p['sicherung'], 0600);
    }
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
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine LEERE Datei - hier waeren das die Zugangsdaten.
    if ($json === false) {
        return false;
    }
    /* Ueber eine Nebendatei, und die Rechte werden VOR dem Umbenennen gesetzt.
     * "schreiben, dann chmod" liesse die Datei fuer die Dauer des Schreibens
     * mit den Rechten aus der umask dastehen - und darin stehen die
     * Client-Geheimnisse von Home Connect und Miele sowie das
     * SmartThings-Token. Ausserdem liest der Dienst diese Datei; ein
     * einfaches file_put_contents kuerzt sie zuerst auf null, und wer in
     * diesem Augenblick liest, sieht keine Zugangsdaten und meldet sich
     * vergeblich an. */
    $tmp = $p['zugang'] . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json) !== strlen($json)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $p['zugang'])) {
        @unlink($tmp);
        return false;
    }
    return true;
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
        // -1, nicht 0: die Aufrufer pruefen auf === 0 und meldeten sonst
        // "Home Connect ist angemeldet", waehrend gar nichts lief.
        return array(-1, 'Die virtuelle Python-Umgebung oder weissware.py fehlt.');
    }
    if (!function_exists('exec')) {
        return array(-1, 'Die PHP-Funktion exec ist auf diesem System gesperrt. '
                       . 'Anmeldung und Selbsttest lassen sich darueber nicht ausfuehren.');
    }
    $befehl = escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' ' . escapeshellarg($schalter);
    if ($wert !== '') {
        $befehl .= ' ' . escapeshellarg($wert);
    }
    $ausgabe = array();
    // Vorbelegung -1: bleibt exec wirkungslos, ist das KEIN Erfolg.
    $code = -1;
    @exec($befehl . ' 2>&1', $ausgabe, $code);
    return array($code, implode("\n", $ausgabe));
}

/* ---------------- Trockenlauf und Mitschnitt ---------------- */

/**
 * Trockenlauf: zeigt, WAS der Befehl taete. Sendet nichts.
 *
 * Laeuft ueber dasselbe Skript wie die Selbstpruefung, nicht ueber die
 * Warteschlange: der Trockenlauf soll gerade dann etwas sagen, wenn der Dienst
 * NICHT laeuft. Rueckgabe: array(rueckgabewert, ausgabe).
 */
function ww_trockenlauf($aktion, $nummer, $programm = '')
{
    $p = ww_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/weissware.py';
    if (!is_file($py) || !is_file($skript)) {
        return array(1, ww_t('TEST.A_VENV_FEHLT'));
    }
    $befehl = escapeshellcmd($py) . ' ' . escapeshellarg($skript)
            . ' --trockenlauf ' . escapeshellarg($aktion) . ' ' . escapeshellarg($nummer);
    if ($programm !== '') {
        $befehl .= ' ' . escapeshellarg($programm);
    }
    $ausgabe = array();
    // Vorbelegung -1, siehe ww_dienst_schalter().
    $code = -1;
    @exec($befehl . ' 2>&1', $ausgabe, $code);
    return array($code, implode("\n", $ausgabe));
}

/**
 * Die zuletzt beendeten Programmlaeufe, neueste zuerst.
 *
 * Geschrieben werden sie vom Dienst im Augenblick des Uebergangs
 * laeuft -> fertig; danach sind die Verbrauchswerte beim Anbieter fort.
 */
function ww_laeufe($anzahl = 20)
{
    $d = ww_json_lesen(ww_paths()['datadir'] . '/laeufe.json');
    $l = isset($d['laeufe']) && is_array($d['laeufe']) ? $d['laeufe'] : array();
    return array_slice(array_reverse($l), 0, max(1, (int) $anzahl));
}

/** Laeuft gerade ein Mitschnitt? Rueckgabe: verbleibende Sekunden oder 0. */
function ww_mitschnitt_rest()
{
    $cfg = ww_config();
    return max(0, (int) $cfg['mitschnitt_bis'] - time());
}

/**
 * Schaltet den Mitschnitt fuer $sekunden ein, 0 schaltet ihn ab.
 *
 * Eine FRIST, kein Schalter: ein vergessener Mitschnitt schriebe sonst
 * wochenlang auf die Speicherkarte, und log/plugins liegt auf einer Ramdisk.
 */
function ww_mitschnitt_schalten($sekunden)
{
    $sekunden = max(0, min(3600, (int) $sekunden));
    $cfg = ww_config();
    $cfg['mitschnitt_bis'] = $sekunden > 0 ? time() + $sekunden : 0;
    return ww_config_speichern($cfg) ? $sekunden : -1;
}

/** Die letzten Zeilen des Mitschnitts. */
function ww_mitschnitt_zeilen($anzahl = 200)
{
    $f = ww_paths()['logdir'] . '/mitschnitt.log';
    return is_file($f) ? array_reverse(ww_log_ende($f, $anzahl)) : array();
}

/* ---------------- Der eigene Endpunkt ----------------
 *
 * Alle uebrigen Pruefzeilen sehen sich Dateien an. Nur diese eine spricht die
 * Stelle an, die spaeter der Miniserver anspricht - und nur sie findet die
 * Klasse, bei der html/ und htmlauth/ installiert in getrennten Baeumen liegen
 * und der Endpunkt mit HTTP 500 antwortet, ohne dass es jemand merkt. Der
 * Miniserver liest kein Protokoll.
 *
 * Serverseitig ist 127.0.0.1 dabei die RICHTIGE Adresse. Das widerspricht
 * nicht der Regel "ein Knopf auf 127.0.0.1 kann nie funktionieren" - die gilt
 * fuer einen Verweis, den ein Mensch im Browser anklickt.
 *
 * Drei Ausgaenge, und der dritte ist der wichtige:
 *   1 geantwortet und plausibel
 *   0 geantwortet und falsch (mit Code und Rumpfanfang)
 *  -1 NICHT FESTSTELLBAR - weder allow_url_fopen noch curl, oder ein
 *     Webserver, der waehrend des Seitenaufbaus keine zweite Anfrage annimmt.
 *     "Ich kann es nicht messen" darf nicht wie "in Ordnung" aussehen.
 *
 * Das Ergebnis wird zwischengespeichert: alle Reiter werden bei jedem Klick
 * mitgerendert, und ohne Speicher riefe sich der Webserver bei jedem Klick
 * selbst auf.
 */
define('WW_ENDPUNKT_SPEICHER_S', 120);

function ww_endpunkt_pruefen($frisch = false)
{
    $p = ww_paths();
    $speicher = $p['datadir'] . '/endpunkt_pruefung.json';
    if (!$frisch) {
        $alt = ww_json_lesen($speicher);
        if (isset($alt['ts']) && (time() - (int) $alt['ts']) < WW_ENDPUNKT_SPEICHER_S) {
            $alt['alter'] = time() - (int) $alt['ts'];
            return $alt;
        }
    }
    $geraete = ww_geraete();
    $nr = $geraete ? (string) array_keys($geraete)[0] : '1';
    $url = 'http://127.0.0.1/plugins/' . $p['plugin'] . '/index.php?token='
         . rawurlencode(ww_token()) . '&aktion=status&geraet=' . rawurlencode($nr);

    $rumpf = false;
    $stand = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                                     CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false));
        $rumpf = curl_exec($ch);
        $stand = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 5, 'ignore_errors' => true)));
        $rumpf = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0])
            && preg_match('# (\d{3}) #', ' ' . $http_response_header[0] . ' ', $m)) {
            $stand = (int) $m[1];
        }
    } else {
        $erg = array('stand' => -1, 'http' => 0, 'text' => ww_t('TEST.A_EP_UNMESSBAR'),
                     'ts' => time(), 'alter' => 0);
        ww_endpunkt_merken($speicher, $erg);
        return $erg;
    }

    if ($rumpf === false || $rumpf === '') {
        // Kein Kreuz: im Pruefaufbau faellt genau dieser Fall an.
        $erg = array('stand' => -1, 'http' => $stand,
                     'text' => sprintf(ww_t('TEST.A_EP_KEINE_ANTWORT'), $stand));
    } elseif ($stand === 200 && strpos($rumpf, 'WEISSWARE;OK=') === 0) {
        $erg = array('stand' => 1, 'http' => $stand,
                     'text' => substr(trim(strtok($rumpf, "\n")), 0, 120));
    } else {
        $erg = array('stand' => 0, 'http' => $stand,
                     'text' => sprintf(ww_t('TEST.A_EP_FALSCH'), $stand,
                                       substr(trim($rumpf), 0, 120)));
    }
    $erg['ts'] = time();
    $erg['alter'] = 0;
    ww_endpunkt_merken($speicher, $erg);
    return $erg;
}

function ww_endpunkt_merken($datei, $erg)
{
    $json = json_encode($erg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        if (!is_dir(dirname($datei))) {
            @mkdir(dirname($datei), 0775, true);
        }
        @file_put_contents($datei, $json);
    }
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
    if (!function_exists('exec')) {
        return array(0, 'Die PHP-Funktion exec ist auf diesem System gesperrt - '
                      . 'der Dienst laesst sich von der Oberflaeche aus nicht schalten.');
    }
    $ausgabe = array();
    // Vorbelegung -1: sonst ergaebe ein wirkungsloses exec unten
    // ($code === 0 ? 1 : 0) eine gemeldete 1 - "Dienst gestartet", ohne dass
    // etwas gestartet wurde.
    $code = -1;
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
/** Obergrenze fuer eine Wartezeit, die aus einer Web-Anfrage kommt. */
define('WW_WARTEN_WEB', 12);

function ww_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = ww_paths();
    $cfg = ww_config();
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    /* Bis 0.9.0 bei 30 Sekunden gedeckelt. Das ist fuer einen Aufruf aus dem
     * Webfrontend zu lang: ein Webserver bricht die Anfrage typischerweise
     * nach 15 bis 30 Sekunden mit 504 ab, und der Benutzer sieht einen
     * Serverfehler statt einer Auskunft.
     *
     * Der Dienst arbeitet den Befehl trotzdem zu Ende - die Warteschlange
     * liegt im Dateisystem, nicht in dieser Anfrage. Was danach geschah,
     * steht im Protokoll. */
    $wartezeit = max(0, min(WW_WARTEN_WEB, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    /* json_encode gibt bei ungueltigem UTF-8 false zurueck. file_put_contents
     * macht daraus eine leere Zeichenkette, schreibt null Byte und meldet das
     * als Erfolg - der Rueckgabewert ist 0, nicht false, die Pruefung auf
     * "=== false" greift also nicht, und rename schiebt die leere Datei in die
     * Warteschlange. Der Dienst faende dort einen Befehl, den er nicht deuten
     * kann. Deshalb zuerst kodieren und den Rueckgabewert ansehen - so, wie es
     * ww_config_speichern() weiter oben schon tut. */
    $ww_js = json_encode($befehl);
    if ($ww_js === false) {
        return array(0, 'Der Befehl liess sich nicht als JSON darstellen (ungueltiges UTF-8).');
    }
    if (@file_put_contents($tmp, $ww_js) !== strlen($ww_js) || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = ww_json_lesen($antwort);
            /* Gelesen ist erledigt. Bis 0.9.0 blieb die Datei liegen und
             * sammelte sich im Datenordner an. */
            @unlink($antwort);
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
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0,
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
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt. */
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'websocket'  => (string) $hol('Websocketport', 'websocketport'),
    );
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function ww_abo_text()
{
    $m = ww_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return ww_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(ww_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return ww_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
}


/** Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung. */
function ww_mqtt_themen()
{
    return array(
        'ok'                        => 'WW_MQTT.OK',
        // Der Zeitstempel des letzten ERFOLGREICHEN Abrufs und der Zaehler
        // fehlgeschlagener Versuche in Folge gehen bei jedem Durchlauf hinaus,
        // auch bei einer Stoerung. Ueber MQTT gibt es kein "Alter" - beim
        // Senden ist es immer null; die Gegenseite rechnet es aus ts.
        'ts'                        => 'WW_MQTT.TS',
        'fehler_folge'              => 'WW_MQTT.FEHLER_FOLGE',
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
        'geraetN/fertig_um'         => 'WW_MQTT.FERTIGUM',
        'geraetN/gewaehlt_text'     => 'WW_MQTT.GEWAEHLT',
        'ausfaelle'                 => 'WW_MQTT.AUSFAELLE',
        'ausfall/homeconnect'       => 'WW_MQTT.AUSFALL_HC',
        'ausfall/miele'             => 'WW_MQTT.AUSFALL_MIELE',
        'ausfall/smartthings'       => 'WW_MQTT.AUSFALL_ST',
    );
}

/**
 * Die Grenze, ab der ein Abbild als zu alt gilt.
 *
 * ACHTUNG, WER HIER AUFRAEUMT: diese Funktion wird von einem SHELL-Skript
 * gerufen, nicht nur aus PHP -
 *
 *     bin/dienst.sh, Waechterzweig:  php -r "require ...; echo ww_wache_grenze();"
 *
 * In 0.9.11 wurde sie als vermeintlich toter Helfer entfernt, weil das
 * Suchwerkzeug nur .php-Dateien durchsucht hatte. Der Fatalfehler des
 * php -r geht im Waechter nach /dev/null, die Grenze fiel auf die
 * Untergrenze zurueck, und der Waechter startete den Dienst im Ruhebetrieb
 * etwa alle drei Minuten neu. In JEDER fremden Anlage, per Auto-Update.
 *
 * Der zweite Verbraucher steht in webfrontend/htmlauth/ww_test.php und ist
 * absichtlich einer aus PHP: so sieht jedes Werkzeug und jeder Leser, dass
 * die Funktion benutzt wird - und die Pruefzeile misst gegen dieselbe Zahl
 * wie der Waechter, statt gegen eine zweite Formel.
 *
 * Fuenffacher Ruhetakt, mindestens 180 s: ein einzelner langsamer Durchlauf
 * soll nichts ausloesen.
 */
function ww_wache_grenze()
{
    $cfg = ww_config();
    return max(180, 5 * (int) $cfg['takt_ruhe']);
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original.
 *
 * Der Erzeuger stammte urspruenglich aus LoxBerry-Plugin-APC-UPS-1.0.0. Diese
 * Fassung ist auf den Stand von EVCC 0.9.18 (ev_xml_virtual_in_http /
 * ev_xml_virtual_out) gezogen; dort fehlten dieselben Bestandteile, und sie
 * wurden gegen Ausfuhren aus einer laufenden Anlage nachgetragen:
 *
 *   - HintText="" am Wurzelelement, bei VirtualOut zusaetzlich CmdInit=""
 *   - als erstes Kindelement <Info templateType="2" .../>, bei VirtualOut "3"
 *   - je Befehl Unit="<v.1> <Einheit>" und HintText=""
 *   - je VirtualOutCmd CmdOnMethod, CmdOffMethod, Repeat="0", RepeatRate="0"
 *   - eigene MinVal/MaxVal statt pauschal +/-2147483647: Loxone zieht daraus
 *     die Reglergrenzen und die Plausibilitaetspruefung.
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
    $o .= 'HintText="" ';
    $o .= 'Title="' . ww_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ww_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ww_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ww_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $min = isset($c['min']) ? (int) $c['min'] : -2147483647;
        $max = isset($c['max']) ? (int) $c['max'] : 2147483647;
        $einheit = isset($c['unit']) ? trim((string) $c['unit']) : '';
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . ww_x($c['title']) . '" ';
        $o .= 'Comment="' . ww_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . ww_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="' . ($min < 0 ? 'true' : 'false') . '" ';
        // Ein Wert, der nur 0 oder 1 kennt, gehoert als DIGITALER Eingang nach
        // Loxone - dann laesst er sich unmittelbar an ein UND oder einen
        // Merker haengen, ohne Schwellwertschalter davor.
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . $min . '" ';
        $o .= 'MaxVal="' . $max . '" ';
        $o .= 'Unit="' . ww_x($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Virtuelle AUSGAENGE - die Befehle, die Loxone an das Plugin schickt.
 *
 * Ein Zustand gehoert an EINEN Ausgang mit Ein- UND Ausbefehl, nicht an zwei
 * Ausgaenge: "Programm starten" und "Programm abbrechen" sind die beiden
 * Flanken derselben Sache. Wo es keinen Ausbefehl gibt, bleibt CmdOff leer -
 * das Attribut fehlt nicht, es ist leer.
 *
 * Und: der Titel eines Ausgangs darf kein '=' tragen. Beim EVCC-Plugin wurde
 * aus '&lp=1' durch blosses Ersetzen von '&' der Name 'EVCC_MODUS_LP=1'.
 */
function ww_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . ww_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ww_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ww_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . ww_x($c['title']) . '" ';
        $o .= 'Comment="' . ww_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOn="' . ww_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOff="' . ww_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'Analog="false" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Die Werte des Status-Endpunkts mit Einheit und Bedeutung.
 *
 * Reihenfolge und Namen sind zugleich die Reihenfolge der Befehlserkennungen
 * in der Loxone-Vorlage. Wer hier etwas einfuegt, aendert die Vorlage mit.
 */
/*
 * Zu 'min' und 'max': das sind GEWAEHLTE Grenzen, keine gemessenen. Kein
 * Anbieter dokumentiert eine Obergrenze fuer eine Restzeit. Loxone zieht aus
 * ihnen die Reglergrenzen und die Plausibilitaetspruefung; pauschal
 * +/-2147483647 macht aus jedem Eingang einen, bei dem jeder Wert plausibel
 * aussieht. Wo eine Grenze knapp werden koennte, ist sie bewusst weit gesetzt.
 *
 * 'analog' => false heisst: DIGITALER Eingang in Loxone. Das gilt fuer jeden
 * Wert, der nur 0 oder 1 kennt - der laesst sich dann unmittelbar an ein UND
 * haengen, ohne Schwellwertschalter davor.
 *
 * 'feld' ist der Name im Abbild. Daran haengt die Filterung in
 * ww_vorlage_felder(): eine Vorlage soll nur anlegen, was das Geraet auch
 * liefert.
 */
function ww_status_felder()
{
    return array(
        'ZUSTAND'   => array('',    'WW_FELD.ZUSTAND',   'min' => 0, 'max' => 5,    'analog' => true,  'feld' => 'zustand'),
        'LAEUFT'    => array('',    'WW_FELD.LAEUFT',    'min' => 0, 'max' => 1,    'analog' => false, 'feld' => 'laeuft'),
        'FERTIG'    => array('',    'WW_FELD.FERTIG',    'min' => 0, 'max' => 1,    'analog' => false, 'feld' => 'fertig'),
        'VERBUNDEN' => array('',    'WW_FELD.VERBUNDEN', 'min' => 0, 'max' => 1,    'analog' => false, 'feld' => 'verbunden'),
        'TUER'      => array('',    'WW_FELD.TUER',      'min' => 0, 'max' => 1,    'analog' => false, 'feld' => 'tuer_offen'),
        'FORTSCHR'  => array('%',   'WW_FELD.FORTSCHR',  'min' => 0, 'max' => 100,  'analog' => true,  'feld' => 'fortschritt'),
        'RESTMIN'   => array('min', 'WW_FELD.RESTMIN',   'min' => 0, 'max' => 1440, 'analog' => true,  'feld' => 'restzeit_min'),
        'STARTMIN'  => array('min', 'WW_FELD.STARTMIN',  'min' => 0, 'max' => 1440, 'analog' => true,  'feld' => 'startzeit_min'),
        'LAUFMIN'   => array('min', 'WW_FELD.LAUFMIN',   'min' => 0, 'max' => 1440, 'analog' => true,  'feld' => 'laufzeit_min'),
        'FERNSTART' => array('',    'WW_FELD.FERNSTART', 'min' => 0, 'max' => 1,    'analog' => false, 'feld' => 'fernstart_frei'),
        'FERNBED'   => array('',    'WW_FELD.FERNBED',   'min' => 0, 'max' => 1,    'analog' => false, 'feld' => 'fernbedienung_frei'),
        'NETZ'      => array('',    'WW_FELD.NETZ',      'min' => 0, 'max' => 1,    'analog' => false, 'feld' => 'netz_ein'),
        // ALTER ist -1, solange es kein Abbild gibt - die Untergrenze muss das
        // zulassen, sonst wird aus "nie abgerufen" eine 0 und damit "frisch".
        'ALTER'     => array('s',   'WW_FELD.ALTER',     'min' => -1, 'max' => 2147483647, 'analog' => true,  'feld' => ''),
        'OK'        => array('',    'WW_FELD.OK',        'min' => 0, 'max' => 1,    'analog' => false, 'feld' => ''),
        /* Neue Groessen werden HINTEN angehaengt, nie dazwischen: Loxone sucht
         * den Suchtext woertlich und nimmt den ersten Treffer in der Zeile;
         * bestehende Projekte finden sonst nicht mehr, was sie suchen.
         *
         * Gegengeprueft, ob 'FERTIGUM=' als Zeichenfolge in einem anderen
         * Feldnamen dieser Zeile vorkommt: nein. Und 'FERTIG=' steckt NICHT
         * in 'FERTIGUM=', weil dort auf FERTIG ein U folgt und kein
         * Gleichheitszeichen. Beide Suchmuster bleiben eindeutig. */
        'FERTIGUM'  => array('s',   'WW_FELD.FERTIGUM',  'min' => 0, 'max' => 2147483647, 'analog' => true,  'feld' => 'fertig_um'),
    );
}

/** Die Werte des Verbrauchs-Endpunkts. Grenzen gewaehlt, siehe oben. */
function ww_verbrauch_felder()
{
    return array(
        'ENERGIE'   => array('kWh', 'WW_VFELD.ENERGIE',   'min' => 0, 'max' => 1000,  'analog' => true,  'feld' => 'energie_kwh'),
        'WASSER'    => array('l',   'WW_VFELD.WASSER',    'min' => 0, 'max' => 10000, 'analog' => true,  'feld' => 'wasser_l'),
        'TEMP'      => array('&deg;C', 'WW_VFELD.TEMP',   'min' => 0, 'max' => 300,   'analog' => true,  'feld' => 'temperatur'),
        'SCHLEUDER' => array('U/min', 'WW_VFELD.SCHLEUDER', 'min' => 0, 'max' => 2000, 'analog' => true, 'feld' => 'schleuderdrehzahl'),
        'OK'        => array('',    'WW_VFELD.OK',        'min' => 0, 'max' => 1,     'analog' => false, 'feld' => ''),
    );
}

/**
 * Filtert eine Feldtabelle auf das, was dieses Geraet wirklich liefert.
 *
 * Eine Importdatei, die alle Messgroessen anlegt, legt auch die an, die kein
 * Anbieter je fuellt: Home Connect liefert weder Energie noch Wasser,
 * SmartThings keinen Fortschritt und keine Schleuderdrehzahl. Loxone traegt
 * dort DefVal="0" ein - und eine 0 sieht aus wie ein Messwert.
 *
 * Massgeblich ist die letzte ERFOLGREICHE Messung, nicht eine handgepflegte
 * Liste: eine dritte Stelle liefe mit dem Code auseinander. Felder ohne
 * Geraetebezug (OK, ALTER) bleiben immer drin.
 *
 * Hat das Geraet noch NIE geantwortet, wird alles ausgeliefert - dann sagt der
 * Hinweis in der Oberflaeche, die Datei nach dem ersten Abruf erneut zu holen.
 *
 * Rueckgabe: array(gefilterte Tabelle, wie viele Felder weggefallen sind)
 */
function ww_vorlage_felder($tabelle, $nummer)
{
    $geraete = ww_geraete();
    $g = isset($geraete[(string) $nummer]) ? $geraete[(string) $nummer] : null;
    if (!is_array($g)) {
        return array($tabelle, -1);   // -1 = noch nie geantwortet
    }
    $aus = array();
    $weg = 0;
    foreach ($tabelle as $name => $info) {
        $feld = isset($info['feld']) ? $info['feld'] : '';
        if ($feld === '') {
            $aus[$name] = $info;
            continue;
        }
        if (array_key_exists($feld, $g) && $g[$feld] !== null && $g[$feld] !== '') {
            $aus[$name] = $info;
        } else {
            $weg++;
        }
    }
    return array($aus, $weg);
}

/**
 * Die letzten $anzahl Zeilen einer Datei, neueste zuerst.
 *
 * Bis 0.9.0 las die Oberflaeche das Protokoll mit file() vollstaendig ein.
 * Der Hinweis auf den Speicher war berechtigt - der vorgeschlagene Weg ueber
 * exec("tail") ist aber der langsamste der drei. An einer Datei an der
 * Rotationsgrenze gemessen, PHP 7.4 und 8.1:
 *
 *   file() ganz einlesen     rund 0,3 ms   Spitze rund 1,4 MB
 *   exec("tail -n 400")      rund 1,9 ms   Spitze rund  75 kB
 *   rueckwaerts mit fseek    rund 0,05 ms  Spitze rund 125 kB
 *
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat.
 */
function ww_log_ende($datei, $anzahl = 400, $block = 8192)
{
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/**
 * Der Name, unter dem ein Baustein in Loxone Config steht.
 *
 * Bis 0.9.8 hiess er WW_1_CMD_NETZ - eindeutig, aber niemand liest das gern.
 * Der SUCHTEXT bleibt unveraendert; es aendert sich nur der Name. Das Praefix
 * bleibt, damit die Bausteine in der Bausteinsuche beieinander stehen.
 *
 * Ist ein Name unbekannt, wird der Rohschluessel genommen - dann faellt beim
 * Durchsehen auf, was fehlt, statt dass ein Baustein namenlos bleibt.
 */
function ww_titel($nummer, $schluessel)
{
    $t = ww_t('WW_NAME.' . $schluessel);
    if ($t === 'WW_NAME.' . $schluessel) {
        $t = $schluessel;
    }
    // Der Sofortabruf gilt der Anlage, nicht einem Geraet - deshalb ohne Nummer.
    return ($schluessel === 'CMD_ABRUF') ? 'WW ' . $t : 'WW ' . (int) $nummer . ' ' . $t;
}

/** Der Name, unter dem der Miniserver diesen LoxBerry erreicht. */
function ww_host()
{
    return isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

/**
 * Ein Text aus den Sprachdateien, wie er in eine XML-Datei gehoert.
 *
 * Er laeuft gleich durch ww_x() und wuerde dort ein zweites Mal maskiert.
 * Deshalb erst Auszeichnung entfernen und Entitaeten aufloesen - sonst stuende
 * in Loxone Config wortwoertlich 'l&auml;dt' statt 'laedt'.
 */
function ww_klartext($schluessel_oder_text)
{
    return trim(strip_tags(html_entity_decode(
        (string) $schluessel_oder_text, ENT_QUOTES, 'UTF-8')));
}

/** Gemeinsamer Kopftext aller erzeugten Importdateien. */
function ww_vorlage_fussnote($weg)
{
    /* KURZ halten. Am Geraet gemessen (18.08.2026): Loxone Config setzt den
     * Kommentar eines Rahmens als ANZEIGENAME ein - beim virtuellen Eingang
     * genauso wie beim Ausgang. Bei einem Anwender stand deshalb
     * "Erzeugt vom LoxBerry-Plugin Weissware Cloud (...)" als Name eines
     * Bausteins. Ein Dokumentationsfeld, das den Import uebersteht, gibt es
     * nicht; die ausfuehrlichen Hinweise stehen im Reiter "Einbindung in
     * Loxone". Hier nur ein Merkmal, das man als Namen lesen kann. */
    $t = 'vom LoxBerry-Plugin, ' . date('d.m.Y');
    if ($weg === -1) {
        $t .= ' - alle Felder, Geraet hat noch nie geantwortet';
    } elseif ($weg > 0) {
        $t .= ' - ohne ' . $weg . ' nicht gelieferte Felder';
    }
    return $t;
}

/**
 * Vorlage fuer die STATUS-Eingaenge eines Geraets.
 * Rueckgabe: array(dateiname, inhalt)
 */
function ww_vorlage_status($nummer = 1)
{
    $p = ww_paths();
    $nummer = (int) $nummer;
    list($tabelle, $weg) = ww_vorlage_felder(ww_status_felder(), $nummer);
    $cmds = array();
    foreach ($tabelle as $feld => $info) {
        $einheit = ww_klartext($info[0]);
        $cmds[] = array(
            // WW_, nicht AUDI_. Der Rest stammte aus dem Plugin, aus dem die
            // Vorlagenfunktion uebernommen wurde - in Loxone Config standen
            // die Bausteine damit unter fremdem Namen.
            'title'   => ww_titel($nummer, $feld),
            'comment' => ww_klartext(ww_t($info[1])) . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'unit'    => $einheit,
            'min'     => isset($info['min']) ? $info['min'] : 0,
            'max'     => isset($info['max']) ? $info['max'] : 2147483647,
            'analog'  => !empty($info['analog']),
        );
    }
    return array(
        'VI_weissware_geraet' . $nummer . '_status.xml',
        ww_xml_virtual_in_http(array(
            'title'   => 'Weissware ' . $nummer . ' Status',
            'address' => 'http://' . ww_host() . '/plugins/' . $p['plugin']
                       . '/index.php?token=' . ww_token() . '&aktion=status&geraet=' . $nummer,
            'polling' => '60',
            'comment' => ww_vorlage_fussnote($weg),
        ), $cmds),
    );
}

/** Vorlage fuer die VERBRAUCHS-Eingaenge eines Geraets. */
function ww_vorlage_verbrauch($nummer = 1)
{
    $p = ww_paths();
    $nummer = (int) $nummer;
    list($tabelle, $weg) = ww_vorlage_felder(ww_verbrauch_felder(), $nummer);
    $cmds = array();
    foreach ($tabelle as $feld => $info) {
        $einheit = ww_klartext($info[0]);
        /* 'OK' heisst in BEIDEN Zeilen so. Wer Status- und Verbrauchsvorlage
         * einliest, haette sonst zweimal den Eingang WW_1_OK im Projekt und
         * saehe keinem der beiden an, wozu er gehoert. Der Suchtext bleibt
         * derselbe - nur der Baustein heisst anders. */
        $titel = ww_titel($nummer, $feld === 'OK' ? 'VOK' : $feld);
        $cmds[] = array(
            'title'   => $titel,
            'comment' => ww_klartext(ww_t($info[1])) . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'unit'    => $einheit,
            'min'     => isset($info['min']) ? $info['min'] : 0,
            'max'     => isset($info['max']) ? $info['max'] : 2147483647,
            'analog'  => !empty($info['analog']),
        );
    }
    return array(
        'VI_weissware_geraet' . $nummer . '_verbrauch.xml',
        ww_xml_virtual_in_http(array(
            'title'   => 'Weissware ' . $nummer . ' Verbrauch',
            'address' => 'http://' . ww_host() . '/plugins/' . $p['plugin']
                       . '/index.php?token=' . ww_token() . '&aktion=verbrauch&geraet=' . $nummer,
            'polling' => '300',
            'comment' => ww_vorlage_fussnote($weg),
        ), $cmds),
    );
}

/**
 * Die Befehle als virtuelle AUSGAENGE.
 *
 * Drei Ausgaenge je Geraet, jeder mit Ein- UND Ausbefehl, weil das die beiden
 * Flanken derselben Sache sind. Zwei getrennte Ausgaenge fuer "start" und
 * "stop" waeren zwei Bausteine fuer einen Zustand.
 *
 * 'abruf' bekommt einen eigenen Ausgang ohne Ausbefehl: er loest nur aus.
 */
function ww_vorlage_ausgang($nummer = 1)
{
    $p = ww_paths();
    $nummer = (int) $nummer;
    $frage = '/plugins/' . $p['plugin'] . '/index.php?token=' . ww_token() . '&aktion=';
    $ziel = '&geraet=' . $nummer;

    /* Titel ohne '=' - siehe ww_xml_virtual_out(). Und mit dem Zusatz CMD:
     * WW_1_NETZ gibt es bereits als EINGANG (Geraet eingeschaltet ja/nein).
     * Ein Ausgang gleichen Namens waere ein zweiter Baustein, dem man nicht
     * ansieht, ob er misst oder schaltet. */
    $cmds = array(
        array('title' => ww_titel($nummer, 'CMD_PROGRAMM'),
              'comment' => ww_klartext(ww_t('LOX.VA_PROGRAMM')),
              'on'  => $frage . 'start' . $ziel,
              'off' => $frage . 'stop' . $ziel),
        array('title' => ww_titel($nummer, 'CMD_PAUSE'),
              'comment' => ww_klartext(ww_t('LOX.VA_PAUSE')),
              'on'  => $frage . 'pause' . $ziel,
              'off' => $frage . 'fortsetzen' . $ziel),
        array('title' => ww_titel($nummer, 'CMD_NETZ'),
              'comment' => ww_klartext(ww_t('LOX.VA_NETZ')),
              'on'  => $frage . 'ein' . $ziel,
              'off' => $frage . 'aus' . $ziel),
    );
    /* 'abruf' gilt fuer die ganze Anlage, nicht fuer ein Geraet - und der
     * Baustein heisst deshalb ohne Nummer. Er darf nur in EINE der Dateien,
     * sonst hat wer beide importiert den Ausgang WW_ABRUF zweimal im Projekt.
     * Genommen wird die kleinste bekannte Geraetenummer. */
    $ww_erste = ww_geraete();
    $ww_erste = $ww_erste ? (int) min(array_map('intval', array_keys($ww_erste))) : 1;
    if ($nummer === $ww_erste) {
        $cmds[] = array('title' => ww_titel(0, 'CMD_ABRUF'),
                        'comment' => ww_klartext(ww_t('LOX.VA_ABRUF')),
                        'on'  => $frage . 'abruf',
                        'off' => '');
    }
    return array(
        'VQ_weissware_geraet' . $nummer . '_befehle.xml',
        ww_xml_virtual_out(array(
            'title'   => 'Weissware ' . $nummer . ' Befehle',
            'address' => 'http://' . ww_host(),
            /* KURZ halten. Loxone Config setzt den Kommentar eines virtuellen
             * AUSGANGS als Anzeigenamen ein - am Geraet gemessen am
             * 18.08.2026: dort stand der ganze Satz "Schreibende Befehle
             * muessen im Reiter Einstellungen freigegeben sein ..." als Name
             * des Bausteins. Bei einem virtuellen EINGANG landet derselbe Text
             * dagegen in der Beschreibung, wo er richtig aufgehoben ist.
             * Die ausfuehrliche Erklaerung steht deshalb an den einzelnen
             * Befehlen, nicht am Rahmen. */
            'comment' => ww_klartext(ww_t('LOX.VA_KURZ')),
        ), $cmds),
    );
}

/**
 * Vorlage fuer die Eingaenge des MQTT-Gateways.
 *
 * Gateway-Eingaenge sind nackte VirtualIn - dafuer kennt Loxone Config kein
 * Vorlagenformat ("Als Vorlage speichern" ist nicht waehlbar), das Gateway
 * legt sie beim ersten Empfang selbst an. Der Kunstgriff, mit dem sechs
 * Plugins im Bestand trotzdem einen Knopf anbieten: ein VirtualInHttp mit
 * Scheinadresse http://localhost und PollingTime 604800 (eine Woche). Loxone
 * legt daraus die richtig benannten Eingaenge an; die Werte kommen danach vom
 * Gateway, nicht ueber diese Adresse. Check ist deshalb ein Leerzeichen.
 *
 * Textthemen (Name, Anbieter, Zustandstext, Programm) bleiben AUSSEN VOR:
 * das nachgebaute Format ist nur fuer Zahlenwerte belegt.
 */
function ww_vorlage_mqtt()
{
    $cfg = ww_config();
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    if ($praefix === '') {
        $praefix = 'weissware';
    }
    /* Textthemen bekommen keinen virtuellen Eingang - das nachgebaute
     * Vorlagenformat ist nur fuer Zahlenwerte belegt. 'gewaehlt_text'
     * fehlte bis 0.9.17 in dieser Liste; Loxone bekam dafuer einen
     * analogen Eingang, der dauerhaft auf 0 stand. */
    $text = array('name', 'anbieter', 'zustand_text', 'programm_text',
                  'gewaehlt_text');
    $geraete = ww_geraete();
    $nummern = $geraete ? array_keys($geraete) : array('1');

    $cmds = array();
    $ohne = 0;
    foreach (ww_mqtt_themen() as $thema => $schluessel) {
        $blatt = substr($thema, strrpos($thema, '/') === false ? 0 : strrpos($thema, '/') + 1);
        if (in_array($blatt, $text, true)) {
            $ohne++;
            continue;
        }
        $liste = (strpos($thema, 'geraetN/') === 0)
            ? array_map(function ($n) use ($thema) {
                return str_replace('geraetN/', 'geraet' . (int) $n . '/', $thema);
            }, $nummern)
            : array($thema);
        foreach ($liste as $t) {
            $cmds[] = array(
                'title'   => str_replace('/', '_', $praefix . '/' . $t),
                'comment' => ww_klartext(ww_t($schluessel)),
                'check'   => ' ',
                'unit'    => '',
                'min'     => -2147483647,
                'max'     => 2147483647,
                'analog'  => true,
            );
        }
    }
    return array(
        'VI_weissware_mqtt.xml',
        ww_xml_virtual_in_http(array(
            'title'   => 'Weissware MQTT',
            'address' => 'http://localhost',
            'polling' => '604800',
            'comment' => ww_klartext(ww_t('LOX.MQTT_VORLAGE_HINWEIS')) . ' '
                       . $ohne . ' Textthema(en) sind bewusst nicht enthalten. '
                       . ww_vorlage_fussnote(0),
        ), $cmds),
    );
}

/** Waehlt die Vorlage nach Art. Rueckgabe: array(dateiname, inhalt) oder null. */
function ww_vorlage($art = 'status', $nummer = 1)
{
    if ($art === 'verbrauch') { return ww_vorlage_verbrauch($nummer); }
    if ($art === 'ausgang')   { return ww_vorlage_ausgang($nummer); }
    if ($art === 'mqtt')      { return ww_vorlage_mqtt(); }
    if ($art === 'status')    { return ww_vorlage_status($nummer); }
    return null;
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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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

/* ==================================================================
 * Sprachausgabe (Ansage "Geraet ist fertig")
 *
 * Mechanismus 1:1 uebernommen aus AWM-Abfuhr 1.3.3 (dort seit 1.2.0 mit
 * dem Fix, dass die IP nur verlangt wird, wenn die Vorlage sie benutzt);
 * Kuerzel getauscht, Ansagetext und Ausloeser sind Weissware-eigen:
 * gesprochen wird beim Uebergang eines Geraets auf Stufe "fertig".
 * ================================================================== */

/** Sperre gegen parallele Cron-Laeufe - 1:1 nach fer_sperre (FerienFeiertage). */
function ww_sperre($name = 'ansage')
{
    $p = ww_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $f = $p['datadir'] . '/' . preg_replace('/[^a-z0-9_]/', '', $name) . '.lock';
    $fh = @fopen($f, 'c');
    if ($fh === false) {
        ww_ansage_log('WARNUNG: Sperrdatei ' . $f . ' laesst sich nicht oeffnen - '
              . 'Platz im Verzeichnis und Eigentuemer pruefen.');
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

/** Protokollzeile der Ansage-Strecke. */
function ww_ansage_log($msg)
{
    $p = ww_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    $ww_al = $p['logdir'] . '/ansage.log';
    /* Kappung nach dem Hausmuster (fer_log, FerienFeiertage): ab 500 kB
     * bleiben die letzten 200 Zeilen stehen. Ohne sie waechst die Datei
     * unbegrenzt - auf einem LoxBerry mit SD-Karte ist das kein
     * Schoenheitsfehler. */
    clearstatcache(true, $ww_al);
    if (is_file($ww_al) && filesize($ww_al) > 512000) {
        $rest = array_slice(file($ww_al, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($ww_al, implode("\n", $rest) . "\n");
    }
    @file_put_contents($ww_al,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function ww_http_get($url, $tmo = 20)
{
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $tmo,
        'user_agent' => 'Mozilla/5.0 (LoxBerry Weissware)',
        'follow_location' => 1,
    ), 'ssl' => array('verify_peer' => true)));
    return @file_get_contents($url, false, $ctx);
}

/** TTS-Einstellungen mit Vorgaben (wie AWM-Abfuhr). */
function ww_tts()
{
    $cfg = ww_config();
    $tts = isset($cfg['tts']) && is_array($cfg['tts']) ? $cfg['tts'] : array();
    $tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                  'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
    return $tts;
}

function ww_tts_url($text)
{
    $tts = ww_tts();
    $mode = $tts['mode'];
    if ($mode === 'audioserver') {
        return null; // Original Loxone Audioserver: TTS nur ueber Loxone Config (Textgenerator -> TTS-Eingang)
    }

    /* Zonenliste EINMAL fuer alle Modi normalisieren.
     *
     * Bis hierher wurde nur im Modus musicserver je Zone getrimmt. In den
     * Modi ms4h und "eigene Vorlage" ging die Eingabe roh in {zones} - aus
     * "2, 4, 6" wurde eine Adresse mit Leerzeichen, also eine kaputte
     * Adresse. Der Hilfetext sagt zu, dass beide Schreibweisen gehen;
     * hier wird das eingeloest. */
    $zl = array();
    foreach (explode(',', (string) $tts['zones']) as $z) {
        $z = trim($z);
        if ($z !== '') { $zl[] = $z; }
    }
    $tts['zones'] = implode(',', $zl);
    if ($mode === 'musicserver' && (string) $tts['ip'] === '') {
        return '';   // ohne IP laesst sich die Music-Server-Adresse nicht bauen
    }
    if ($mode === 'musicserver') {
        // Zonenliste normalisieren: "2,4,6" + Lautstaerke-Feld -> "2~8,4~8,6~8".
        // Explizite Angaben "Zone~Lautstaerke" haben Vorrang.
        $vol = max(1, min(100, (int) $tts['volume']));
        $zones = array();
        foreach (explode(',', (string) $tts['zones']) as $z) {
            $z = trim($z);
            if ($z === '') {
                continue;
            }
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    // ms4h (MusicServer4Home / Audioserver4Home) und custom: Vorlage mit Platzhaltern
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') {
        // Standard-Vorlage MusicServer4Home
        $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}';
    }
    // Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet.
    if ((string) $tts['ip'] === '' && strpos($tpl, '{ip}') !== false) {
        return '';
    }
    return str_replace(
        array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)),
        $tpl
    );
}

function ww_say($text)
{
    $url = ww_tts_url($text);
    if ($url === null) {
        ww_ansage_log('Ansage: Modus "Original Loxone Audioserver" - Sprachausgabe erfolgt ueber Loxone Config (Textgenerator)');
        return false;
    }
    if ($url === '') {
        ww_ansage_log('Ansage uebersprungen: keine TTS-IP konfiguriert');
        return false;
    }
    $r = ww_http_get($url, 10);
    ww_ansage_log('Ansage gesendet: "' . $text . '" -> ' . ($r !== false ? 'OK' : 'FEHLER'));
    return $r !== false;
}

/**
 * Minutenpruefung (Cron): sprechen, wenn ein Geraet auf "fertig" wechselt.
 *
 * Der letzte gesehene fertig-Stand je Geraet liegt in einer Merkdatei -
 * gesprochen wird nur beim Uebergang auf 1, nie bei jedem Cron-Lauf.
 * Ein unbekannter Vorzustand (Erstlauf, Neustart) loest bewusst KEINE
 * Ansage aus: wer nachts hochfaehrt, soll nicht verkuenden, dass die
 * Waschmaschine von gestern fertig ist.
 */
/**
 * Ist gerade Ruhezeit?
 *
 * Gleiche Zeiten heissen: keine Ruhezeit. Ueber Mitternacht hinweg wird
 * richtig gerechnet (22:00 bis 07:00 ist ein Fenster, keine leere Menge).
 */
function ww_ansage_ruhe($cfg = null, $jetzt = null)
{
    $cfg = is_array($cfg) ? $cfg : ww_config();
    $lies = function ($w) {
        return preg_match('/^([0-9]{1,2}):([0-9]{2})$/', trim((string) $w), $m)
            ? ((int) $m[1]) * 60 + (int) $m[2] : -1;
    };
    $von = $lies($cfg['ansage_ruhe_von']);
    $bis = $lies($cfg['ansage_ruhe_bis']);
    if ($von < 0 || $bis < 0 || $von === $bis) {
        return false;
    }
    $jetzt = $jetzt === null ? ((int) date('G')) * 60 + (int) date('i') : (int) $jetzt;
    return ($von < $bis) ? ($jetzt >= $von && $jetzt < $bis)
                         : ($jetzt >= $von || $jetzt < $bis);
}

/** Ansagetext zu einem Ereignis. */
function ww_ansage_text_zu($ereignis, $name)
{
    $name = trim((string) $name);
    $name = $name === '' ? ww_t('ANSAGE.EIN_GERAET')
                         : preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', $name);
    if ($ereignis === 'stoerung') {
        return sprintf(ww_t('ANSAGE.T_STOERUNG'), $name);
    }
    if ($ereignis === 'fernstart') {
        return sprintf(ww_t('ANSAGE.T_FERNSTART'), $name);
    }
    return sprintf(ww_t('ANSAGE.T_FERTIG'), $name);
}

function ww_ansage_check()
{
    $cfg = ww_config();
    if (empty($cfg['ansage_ein'])) {
        return;
    }
    /* Ruhezeit: geprueft wird VOR dem Sprechen, aber der Stand wird trotzdem
     * fortgeschrieben. Sonst spraeche das Plugin um sieben Uhr alles nach, was
     * in der Nacht geschehen ist - und der Uebergang ist dann laengst kalt. */
    $ruhe = ww_ansage_ruhe($cfg);
    $merk = ww_paths()['datadir'] . '/ansage_stand.json';
    $alt = ww_json_lesen($merk);
    $alt = is_array($alt) ? $alt : array();
    $neu = array();
    $erstlauf = !is_file($merk);

    // Je Ereignis: Merkfeld, Haken, und woran der Uebergang haengt.
    $ereignisse = array(
        'fertig'    => array('ansage_ein',       'fertig',         1),
        'stoerung'  => array('ansage_stoerung',  'zustand',        5),
        // Die Freigabe erlischt meist mit dem Programmende. Gesprochen wird
        // beim Uebergang 1 -> 0, also genau umgekehrt zu den anderen beiden.
        'fernstart' => array('ansage_fernstart', 'fernstart_frei', 0),
    );

    foreach (ww_geraete() as $nr => $g) {
        $kennung = isset($g['anbieter'], $g['name']) ? $g['anbieter'] . '|' . $g['name'] : (string) $nr;
        foreach ($ereignisse as $ereignis => $wie) {
            list($haken, $feld, $ausloeser) = $wie;
            $wert = isset($g[$feld]) ? $g[$feld] : null;
            // Ein unbekannter Wert ist kein Ereignis - und auch kein Gegenteil.
            $ist = ($wert === null || $wert === '') ? null
                 : (((int) $wert === (int) $ausloeser) ? 1 : 0);
            $schluessel = $kennung . '#' . $ereignis;
            $neu[$schluessel] = $ist;
            if ($erstlauf || $ruhe || empty($cfg[$haken])) {
                continue;
            }
            $vorher = array_key_exists($schluessel, $alt) ? $alt[$schluessel] : null;
            // Nur der echte Uebergang spricht. Ein unbekannter Vorzustand
            // (Erstlauf, Neustart) loest bewusst nichts aus.
            if ($ist === 1 && $vorher === 0) {
                ww_say(ww_ansage_text_zu($ereignis, isset($g['name']) ? $g['name'] : ''));
            }
        }
    }
    // json_encode liefert bei ungueltigem UTF-8 false - dann lieber die alte
    // Merkdatei stehen lassen als eine leere schreiben (vgl. ww_config_speichern).
    $json = json_encode($neu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents($merk, $json);
    }
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
/**
 * Taugt der Wert ueberhaupt fuer eine Konfigurationsdatei?
 *
 * Erste von zwei Stufen. Hier faellt heraus, was in KEINEN Schluessel passt:
 * Felder, Objekte, Wahrheitswerte, null, ueberlange Zeichenketten und
 * Steuerzeichen. 'tts' ist als einziger Schluessel ein Feld und wird gesondert
 * geprueft.
 */
function ww_wert_taugt($w)
{
    if (is_array($w) || is_object($w) || is_bool($w) || is_null($w)) {
        return false;
    }
    $s = (string) $w;
    if (strlen($s) > 4096) {
        return false;
    }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) !== 1;
}

/**
 * Ist der Wert fuer DIESEN Schluessel zulaessig?
 *
 * Zweite Stufe, mit denselben Mustern und Grenzen wie das Formular in
 * webfrontend/htmlauth/index.php. Bis 0.9.17 prueften beide Knoepfe nur die
 * SCHLUESSELNAMEN; jeder Wert wurde ungeprueft uebernommen. Gemessen unter 7.4
 * und 8.4: eine Sicherung mit takt_ruhe = {"a":1} und aktionstoken als Feld
 * ging ohne eine einzige Beanstandung durch, und aus dem Feld wurde im
 * Endpunkt die Zeichenkette "Array" - ein Token, das jeder kennt.
 */
function ww_wert_pruefen($schluessel, $w)
{
    switch ($schluessel) {
        case 'takt_betrieb':
            return preg_match('/^[0-9]+$/', (string) $w) === 1
                   && (int) $w >= 60 && (int) $w <= 3600;
        case 'takt_ruhe':
            return preg_match('/^[0-9]+$/', (string) $w) === 1
                   && (int) $w >= 60 && (int) $w <= 7200;
        case 'wartezeit':
            return preg_match('/^[0-9]+$/', (string) $w) === 1
                   && (int) $w >= 0 && (int) $w <= WW_WARTEN_WEB;
        case 'mitschnitt_bis':
            return preg_match('/^[0-9]+$/', (string) $w) === 1;
        case 'mqtt_ein':
        case 'steuerung_ein':
        case 'hc_ein':
        case 'hc_simulator':
        case 'miele_ein':
        case 'st_ein':
        case 'ansage_ein':
        case 'ansage_stoerung':
        case 'ansage_fernstart':
            return (string) $w === '0' || (string) $w === '1';
        case 'ansage_ruhe_von':
        case 'ansage_ruhe_bis':
            return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string) $w) === 1;
        case 'mqtt_topic':
            return preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', (string) $w) === 1;
        case 'sprache':
            return preg_match('/^[a-z]{2}-[A-Z]{2}$/', (string) $w) === 1;
        case 'aktionstoken':
            /* Weit gefasst und ausdruecklich mit der Laenge 0: ein leeres
             * Token in einer Sicherungsdatei heisst "kein Token gesichert" und
             * ist kein unzulaessiger Wert. Ob eines FEHLT, entscheidet die
             * Lesefunktion unten an der Lage, nicht diese Wertpruefung.
             * Zugelassen wird, was ohne Kodierung in eine Adresse passt. */
            return preg_match('/^[A-Za-z0-9_.\-]{0,64}$/', (string) $w) === 1;
    }
    return true;
}

/** Die Sprachausgabe ist der einzige Schluessel mit einem Unterbau. */
function ww_tts_pruefen($w, &$mangel)
{
    if (!is_array($w)) {
        $mangel[] = sprintf(ww_t('EINST.SICH_WERT'), 'tts');
        return false;
    }
    $ok = true;
    $regeln = array(
        'mode'     => '/^(musicserver|ms4h|audioserver|custom)$/',
        'ip'       => '/^[A-Za-z0-9_.\-]{0,80}$/',
        'port'     => '/^[0-9]{1,5}$/',
        'zones'    => '/^[0-9 ,~]{0,120}$/',
        'volume'   => '/^[0-9]{1,3}$/',
        'lang'     => '/^[a-z]{1,8}$/',
        'template' => '#^[^\x00-\x1F\x7F]{0,300}$#',
    );
    foreach ($regeln as $f => $muster) {
        if (!array_key_exists($f, $w)) {
            continue;
        }
        if (!ww_wert_taugt($w[$f]) || preg_match($muster, (string) $w[$f]) !== 1) {
            $mangel[] = sprintf(ww_t('EINST.SICH_WERT'), 'tts.' . $f);
            $ok = false;
        }
    }
    foreach (array_keys($w) as $f) {
        if (!isset($regeln[$f])) {
            $mangel[] = sprintf(ww_t('EINST.SICH_FREMD'),
                                 htmlspecialchars('tts.' . (string) $f, ENT_QUOTES, 'UTF-8'));
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Baut den Inhalt der Sicherungsdatei.
 *
 * Zweck der beiden Knoepfe ist der UMZUG auf einen zweiten LoxBerry. Deshalb
 * gehoeren die Zugangsdaten hinein - ohne sie stuenden dort alle Felder
 * richtig, und das Plugin kaeme trotzdem an keinen Anbieter. Bis 0.9.17 gab
 * die Ausfuhr nur ww_config() aus; der Warntext am Knopf versprach dennoch
 * "Die Datei enthaelt Ihre Zugangsdaten", und das stimmte nicht.
 *
 * Der Formulartoken gehoert NICHT hinein: er ist ein Sitzungsmerkmal und
 * wohnt ohnehin im Datenverzeichnis, nicht in der Konfiguration.
 */
function ww_sicherung_bauen()
{
    $cfg = ww_config();
    $cfg['_hinweis'] = 'Sicherung des Plugins Weissware Cloud. Enthaelt das '
                     . 'Aktionstoken und die Zugangsdaten der Anbieter - wie ein '
                     . 'Passwort behandeln.';
    $cfg['_stand']   = date('Y-m-d H:i:s');
    $cfg['zugang']   = ww_json_lesen(ww_paths()['zugang']);
    return $cfg;
}

/**
 * Liest eine Sicherungsdatei.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Grundlage ist der BESTAND, nicht die Werkseinstellung. Bis 0.9.17 begann
 * die Funktion mit ww_vorgaben(); alles, was in der Datei fehlte, kam damit
 * aus den Vorgaben. Gemessen: eine Sicherung ohne 'aktionstoken' wurde
 * angenommen, meldete "17 Werte uebernommen" - und setzte das Token auf leer.
 * Der Endpunkt antwortet danach KEIN_TOKEN_GESETZT, jeder Virtuelle Eingang
 * bekommt 403 und wertet ihn nicht aus, und beim naechsten Oeffnen der
 * Oberflaeche wird ein neues Token gewuerfelt: jede im Miniserver eingetragene
 * Adresse ist still tot.
 *
 * Rueckgabe: array(Konfiguration|null, Zugangsdaten|null, Beanstandungen[],
 *                  uebernommene Werte).
 */
function ww_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, null, array(ww_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = ww_config();
    $bekannt = array_keys(ww_vorgaben());
    $zugang = null;
    $anzahl = 0;
    $hatte_token = false;
    foreach ($daten as $k => $w) {
        // Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet.
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if ($k === 'zugang') {
            if (!is_array($w)) {
                $mangel[] = sprintf(ww_t('EINST.SICH_WERT'), 'zugang');
                continue;
            }
            $zfelder = array('hc_client_id', 'hc_client_secret', 'miele_client_id',
                             'miele_client_secret', 'st_token');
            $zneu = array();
            foreach ($w as $zk => $zw) {
                if (!in_array($zk, $zfelder, true)) {
                    $mangel[] = sprintf(ww_t('EINST.SICH_FREMD'),
                        htmlspecialchars('zugang.' . (string) $zk, ENT_QUOTES, 'UTF-8'));
                    continue;
                }
                if (!ww_wert_taugt($zw) || strlen((string) $zw) > 512) {
                    $mangel[] = sprintf(ww_t('EINST.SICH_WERT'), 'zugang.' . $zk);
                    continue;
                }
                $zneu[$zk] = (string) $zw;
                $anzahl++;
            }
            $zugang = $zneu;
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ww_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        if ($k === 'tts') {
            if (ww_tts_pruefen($w, $mangel)) {
                $neu['tts'] = array_merge(ww_vorgaben()['tts'], $w);
                $anzahl++;
            }
            continue;
        }
        if (!ww_wert_taugt($w) || !ww_wert_pruefen($k, $w)) {
            $mangel[] = sprintf(ww_t('EINST.SICH_WERT'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        if ($k === 'aktionstoken') {
            $hatte_token = true;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ww_t('EINST.SICH_LEER');
    }
    /* Fehlt das Aktionstoken, obwohl sonst etwas uebernommen wurde, wird die
     * Datei ABGEWIESEN statt das Token auf leer zu setzen. Das ist der Fall,
     * der jede Loxone-Adresse still entwertet hat. */
    if ($anzahl > 0 && !$hatte_token) {
        $mangel[] = ww_t('EINST.SICH_OHNE_TOKEN');
    }
    return array($mangel ? null : $neu, $mangel ? null : $zugang, $mangel, $anzahl);
}


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function ww_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = ww_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt: zwischen Anlegen und chmod laege sonst ein
     * Fenster, in dem das Merkwort fuer alle lesbar ist. */
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $neu) !== false) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
    $wort = $neu;
    return $wort;
}

function ww_formtoken()
{
    $grund = ww_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function ww_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(ww_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function ww_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = ww_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return ww_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return ww_t('WACHE.FALSCH');
    }
    return '';
}
