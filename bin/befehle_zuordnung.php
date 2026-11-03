<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Weissware Cloud (Gruppe 44, 0.9.44).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php und .sh, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/, 1.0.2)
 * abonniert <mqtt_topic>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE
 * Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - ww_befehl_annehmen() in ww_lib.php, Quelle 'mqtt'
 * (Reihenfolge wie dort: Aktion, Muster, Geraet angegeben, Steuerung, nur MQTT: Geraet im Abbild und bei start die
 * Fernstart-Freigabe, Dienst laeuft) - und gibt array(ok, grund) fuer die Quittung zurueck (ww_befehl_quittung()).
 * Ein Token gibt es auf diesem Weg nicht; an seiner Stelle steht der Haken "Befehle ueber MQTT annehmen"
 * (befehle_mqtt_ein, ab Werk und beim Update aus; UDP 11884 ist im Heimnetz ohne Anmeldung erreichbar). Schaltende
 * Befehle wirken zusaetzlich nur mit "Schreibende Befehle zulassen" (steuerung_ein). Einen zweiten Haken gibt es
 * nicht (Entscheidung E4): ein Start braucht ohnehin die Fernstart-Freigabe AM GERAET (sonst lehnt auch der
 * Hersteller mit 409 ab).
 *
 * Quittungsart "uebergeben" (E3): die Bruecke wartet nie - angenommen heisst, der Befehl liegt in der Warteschlange
 * des Dienstes. Der Dienst prueft danach ein zweites Mal (Steuerung, Geraet, Fernstart-Freigabe, Programmschluessel,
 * 60-s-Sollwertbremse fuer ein/aus) und schreibt das Ergebnis ins Protokoll.
 *
 * Sperrzeiten: Zustaende (netz 1/0 -> ein/aus) 0 s - die 60-s-Sollwertbremse X-7 des Dienstes bleibt die eine
 * Pruefung. Auftraege (start, stop, pause, fortsetzen, auffrischen) 60 s (Vorgabe der Bruecke): ein zweiter gleicher
 * Auftrag binnen einer Minute ist die Mehrfachanfrage, vor der der Hersteller sperrt. Geraet 1 unter
 * <p>/befehl/<name>, Geraet 2-9 unter <p>/befehl/<n>/<name>; auffrischen gilt der ganzen Anlage (der Abruf hat kein
 * Geraet). Ein Programmschluessel (nur Home Connect) bleibt dem HTTP-Weg vorbehalten.
 * Die Datei setzt keine Konfiguration und schreibt nichts; wie der unangemeldete Endpunkt heilt die Bruecke nichts
 * (ww_config(false)).
 */
if (!function_exists('ww_befehl_annehmen')) {
    $ww_kand = array();
    $ww_kand[] = 'REPLACELBPHTMLDIR/ww_lib.php';
    $ww_kand[] = dirname(__DIR__) . '/webfrontend/html/ww_lib.php';
    if (basename(dirname(__DIR__)) === 'plugins') {
        $ww_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/ww_lib.php';
    }
    foreach ($ww_kand as $ww_k) {
        if (strpos($ww_k, 'REPLACE') !== 0 && is_file($ww_k)) {
            require_once $ww_k;
            break;
        }
    }
}
$ww_grund_soll = 'Die 60-s-Sollwertbremse X-7 des Dienstes bleibt die eine Pruefung (Bauvorschrift Gruppe 44, G2).';
/* Ein Befehl der Bruecke -> (Aktion des Endpunkts) fuer ww_befehl_annehmen(). */
$ww_tun = function ($wie) {
    return function ($w, $t) use ($wie) {
        $nr = isset($t['nr']) ? (string) (int) $t['nr'] : '1';
        $aktion = $wie((string) $w);
        return ww_befehl_quittung(ww_befehl_annehmen($aktion, array(), $aktion === 'abruf' ? '' : $nr, 'mqtt'));
    };
};
$ww_fest = function ($a) { return function ($w) use ($a) { return $a; }; };
/* Name => array(Art, sperre_s|null fuer die Vorgabe 60, Titel in Loxone, Kachel, Aufruf) */
$ww_def = array(
    'start'      => array('puls', null, 'Weissware Start (MQTT)', 'BEFEHL.K_START', $ww_fest('start')),
    'stop'       => array('puls', null, 'Weissware Abbrechen (MQTT)', 'BEFEHL.K_STOP', $ww_fest('stop')),
    'pause'      => array('puls', null, 'Weissware Pause (MQTT)', 'BEFEHL.K_PAUSE', $ww_fest('pause')),
    'fortsetzen' => array('puls', null, 'Weissware Fortsetzen (MQTT)', 'BEFEHL.K_FORTSETZEN', $ww_fest('fortsetzen')),
    'netz'       => array('schalter', 0, 'Weissware Ein/Aus (MQTT)', 'BEFEHL.K_NETZ',
                          function ($w) { return $w === '1' ? 'ein' : 'aus'; }),
);
$ww_befehle_mqtt = array();
foreach (array('' => '', '{nr}/' => ' Geraet N') as $ww_vor => $ww_zus) {
    foreach ($ww_def as $ww_name => $ww_d) {
        $ww_e = array('art' => $ww_d[0], 'eingreifend' => false,
                      'titel' => $ww_zus === '' ? $ww_d[2] : preg_replace('/^Weissware /', 'Weissware Gerät N ', $ww_d[2]),
                      'kachel' => $ww_d[3], 'quittung' => 'uebergeben', 'tun' => $ww_tun($ww_d[4]));
        if ($ww_d[1] !== null) {
            $ww_e['sperre_s'] = $ww_d[1];
            $ww_e['begruendung'] = $ww_grund_soll;
        }
        $ww_befehle_mqtt[$ww_vor . $ww_name] = $ww_e;
    }
}
/* Der Sofortabruf gilt der ganzen Anlage - nur einmal, ohne Geraetenummer. */
$ww_befehle_mqtt['auffrischen'] = array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Weissware Werte abfragen (MQTT)',
    'kachel' => 'BEFEHL.K_AUFFRISCHEN', 'quittung' => 'uebergeben', 'tun' => $ww_tun($ww_fest('abruf')));
return array(
    'fassung' => 1,
    'linie'   => 'weissware',
    'plugin'  => 'Weissware',
    'geladen' => function_exists('ww_befehl_annehmen') && function_exists('ww_befehl_quittung')
                 && function_exists('ww_config'),
    'einstellungen' => function () {
        $c = ww_config(false);   // wie der unangemeldete Endpunkt: nichts heilen, nichts anlegen
        $p = trim((string) $c['mqtt_topic'], '/ ');
        return array('praefix'     => $p === '' ? 'weissware' : $p,
                     'annehmen'    => !empty($c['befehle_mqtt_ein']),
                     'eingreifend' => false,
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) {
        // 0.9.47: bb_log() setzt "Befehlsbruecke: " schon vor den Text - bis 0.9.46 stand es doppelt (Nachzug 01.11.2026, wie MGiSmart 1.1.30).
        ww_log('(' . $stufe . ') ' . $text);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $ww_befehle_mqtt,
);
