<?php
/**
 * Weissware Cloud - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der Datenabruf laeuft im Dienst
 * (bin/weissware.py), der Miniserver spricht mit webfrontend/html/index.php.
 * Ein Plugin, das den Abruf hier erledigt, ist falsch gebaut - auch wenn es
 * funktioniert.
 *
 * Praefix 'ww_', weil LBWeb::lbheader() SDK-Globale setzt (unter anderem $cfg
 * aus der general.json als stdClass) und gleichnamige Plugin-Variablen
 * ueberschreiben wuerde.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek einbinden. Sie liegt unter webfrontend/html/, weil der
 * Miniserver-Endpunkt sie ebenfalls braucht - installiert unter
 * .../html/plugins/<ordner>/, im Archiv unter ../html/. */
$ww_gefunden = false;
foreach (array(
    // installiert: <home>/webfrontend/htmlauth/plugins/<ordner>  ->
    //              <home>/webfrontend/html/plugins/<ordner>
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/ww_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/ww_lib.php',
    // im Archiv: <plugin>/webfrontend/htmlauth -> <plugin>/webfrontend/html
    dirname(__DIR__) . '/html/ww_lib.php',
) as $ww_kandidat) {
    if (is_file($ww_kandidat)) {
        require_once $ww_kandidat;
        $ww_gefunden = true;
        break;
    }
}
if (!$ww_gefunden) {
    echo '<p><b>Fehler:</b> ww_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/ww_test.php';

$ww_p = ww_paths();
if ($ww_p['home'] !== '' && is_file($ww_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $ww_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $ww_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Aktiver Reiter. Wer einen Reiter hinzufuegt, muss diese Positivliste
 * mitziehen - sonst springt die Seite nach jedem Absenden zurueck auf
 * Einstellungen, obwohl der Reiter sichtbar und anklickbar ist. */
/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung. Die Namen
 * standen bis 0.9.0 an drei Stellen: in diesem Muster, in der Reiterleiste
 * und in den fuenf Flaechen-ids. Wer einen Reiter ergaenzt und eine davon
 * vergisst, bekommt keinen Fehler, sondern eine Seite, die nach jedem
 * Absenden auf Einstellungen zurueckspringt. */
/* Die Positivliste steht AUSGESCHRIEBEN und mit dem Praefix da. Vorher war sie
 * ein aus implode() zusammengesetzter regulaerer Ausdruck - fuer
 * hausstandard_pruefen.py unsichtbar, weil im Quelltext weder die eine noch
 * die andere gesuchte Schreibweise steht. Die Spalte stand deshalb auf "Liste
 * 0". Verglichen wird jetzt mit in_array(..., true), also streng. */
$ww_reiter_ids = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$ww_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $ww_reiter_ids, true)) {
    $ww_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $ww_reiter_ids, true)) {
    $ww_tab = 'tab-' . (string) $_GET['form'];
}

$ww_meldungen = array();   // Erfolgsmeldungen
$ww_fehler = array();      // Beanstandungen - gesammelt, nicht ueberschrieben
/* Die dritte Art (Bauliste O7, I6): eine Lage, die der Bediener lesen soll,
 * die aber weder Erfolg noch Beanstandung ist - gelb. */
$ww_hinweise = array();
$ww_testausgabe = '';
/* X-2 (Bauliste O2, Regeln/04): nach einer Beanstandung reisen die Eingaben
 * des EINEN Formulars mit der Einmalmeldung zurueck - ohne Geheimnisse.
 * array('formular' => Name, 'werte' => Feld => Wert, 'falsch' => Felder) */
$ww_eingaben = array();
$ww_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Einmalmeldung (PRG, Bauliste O1) ----------------
 * Nur beim GET gelesen und dabei geloescht (ww_einmal_lesen()). Der Reiter
 * kommt aus der Adresse (?form=), auf die der POST umgeleitet hat. */
if (!$ww_post) {
    $ww_flash = ww_einmal_lesen();
    foreach (array('meldungen', 'fehler', 'hinweise') as $ww_fk) {
        if (!isset($ww_flash[$ww_fk]) || !is_array($ww_flash[$ww_fk])) { continue; }
        foreach ($ww_flash[$ww_fk] as $ww_fz) {
            if (!is_string($ww_fz)) { continue; }
            if ($ww_fk === 'meldungen') { $ww_meldungen[] = $ww_fz; }
            elseif ($ww_fk === 'fehler') { $ww_fehler[] = $ww_fz; }
            else { $ww_hinweise[] = $ww_fz; }
        }
    }
    if (isset($ww_flash['testausgabe']) && is_string($ww_flash['testausgabe'])) {
        $ww_testausgabe = $ww_flash['testausgabe'];
    }
    if (isset($ww_flash['eingaben']) && is_array($ww_flash['eingaben'])) {
        $ww_eingaben = $ww_flash['eingaben'];
    }
}

/* ---- X-2: Werte und Markierung nach einer Beanstandung (Regeln/04) ----
 * Bauform tb_fa/tb_fw/tb_fh/tb_fm (Spotpreis Tibber, Durchgang 01.10.2026). */
/** Ist dieses Formular das beanstandete? */
function ww_fa($formular)
{
    global $ww_eingaben;
    return is_array($ww_eingaben) && isset($ww_eingaben['formular'])
        && $ww_eingaben['formular'] === $formular;
}
/** Wert eines Feldes: nach einer Beanstandung die Eingabe, sonst der gespeicherte. */
function ww_fw($formular, $feld, $gespeichert)
{
    global $ww_eingaben;
    if (ww_fa($formular) && isset($ww_eingaben['werte'][$feld])
        && is_string($ww_eingaben['werte'][$feld])) {
        return $ww_eingaben['werte'][$feld];
    }
    return is_scalar($gespeichert) ? (string) $gespeichert : '';
}
/** Haken: nach einer Beanstandung so, wie er abgeschickt wurde. */
function ww_fh($formular, $feld, $gespeichert)
{
    global $ww_eingaben;
    if (!ww_fa($formular)) { return !empty($gespeichert); }
    return isset($ww_eingaben['werte'][$feld]) && $ww_eingaben['werte'][$feld] === '1';
}
/** Markierung eines beanstandeten Feldes (Attribute, schon maskiert). */
function ww_fm($feld)
{
    global $ww_eingaben;
    return (is_array($ww_eingaben) && isset($ww_eingaben['falsch']) && is_array($ww_eingaben['falsch'])
            && in_array($feld, $ww_eingaben['falsch'], true))
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
/** Ein beanstandetes Zahlenfeld als Textfeld - sonst verwirft der Browser
 *  die Eingabe "abc", und X-2 zeigte ein leeres Feld. */
function ww_ftyp($feld)
{
    return ww_fm($feld) !== '' ? 'text' : 'number';
}
/** Die abgeschickten Werte eines Formulars fuer X-2 einsammeln (nur Zeichenketten,
 *  hoechstens 2100 Zeichen, gueltiges UTF-8). Geheimnisfelder stehen nie in
 *  $felder - sie reisen nicht zurueck. */
function ww_eingaben_sammeln($formular, array $felder, array $haken, array $falsch)
{
    $werte = array();
    foreach ($felder as $f) {
        if (isset($_POST[$f]) && is_string($_POST[$f]) && strlen($_POST[$f]) <= 2100
            && preg_match('//u', $_POST[$f])) {
            $werte[$f] = $_POST[$f];
        }
    }
    foreach ($haken as $f) {
        if (isset($_POST[$f])) { $werte[$f] = '1'; }
    }
    return array('formular' => $formular, 'werte' => $werte,
                 'falsch' => array_values(array_unique($falsch)));
}
/** Ein Feld als Zeichenkette, am Rand getrimmt (Leerraum am Rand bleibt still,
 *  Nr. 19); null, wenn es fehlt oder eine Liste ist. */
function ww_post_text($feld)
{
    return (isset($_POST[$feld]) && is_string($_POST[$feld])) ? trim($_POST[$feld]) : null;
}

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war. Auch die Abweisung endet mit
 * der Umleitung (PRG).
 * ---------------------------------------------------------------- */
$ww_wache = ww_wachposten();
if ($ww_wache !== '') {
    $ww_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab'])
        ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($ww_reiter_merk !== null) {
        $_POST['activetab'] = $ww_reiter_merk;
    }
    $ww_fehler[] = $ww_wache;
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Einmalmeldung, Wachposten,
 * Reiterwahl, ALLE Handler samt Downloads, die Umleitung (PRG), dann erst
 * lbheader(), dann HTML.
 * ================================================================== */
/* ---------------- Vorlage herunterladen ---------------- */
if ($ww_post && isset($_POST['vorlage'])) {
    /* Bis 0.9.6 gab es genau einen Knopf mit fest verdrahteter 1 - bei zwei
     * Geraeten musste die zweite Datei von Hand gebaut werden. Jetzt Art und
     * Nummer getrennt; was nicht ins Muster passt, wird abgewiesen. */
    $ww_art = (string) ww_post_text('vorlage');
    if (!in_array($ww_art, array('status', 'verbrauch', 'ausgang', 'mqtt'), true)) {
        $ww_fehler[] = ww_t('LOX.FEHLER_VORLAGE');
    } else {
        $ww_rohnr = isset($_POST['vorlage_nr']) ? (string) ww_post_text('vorlage_nr') : '1';
        if (!preg_match('/^[0-9]{1,3}$/', $ww_rohnr)) {
            $ww_fehler[] = ww_t('LOX.FEHLER_VORLAGE');
        } else {
            $ww_v = ww_vorlage($ww_art, (int) $ww_rohnr);
            if (is_array($ww_v)) {
                list($ww_name, $ww_inhalt) = $ww_v;
                header('Content-Type: application/xml; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $ww_name . '"');
                echo $ww_inhalt;
                exit;
            }
            $ww_fehler[] = ww_t('LOX.FEHLER_VORLAGE');
        }
    }
    $ww_tab = 'tab-loxone';
}

/* ---------------- Einstellungen speichern ----------------
 *
 * Bauliste O2-O4, A1 (02.10.2026; Entscheidungen Nr. 16 und 19): erst ALLES
 * pruefen, alle Maengel sammeln, dann - nur ohne Beanstandung - Zugangsdaten
 * und Konfiguration schreiben. Nichts wird geklemmt, ersetzt oder still
 * entfernt; still bleiben nur Leerraum am Rand und das Kleinschreiben des
 * Sprachkuerzels der Ansage (sinngemaess Nr. 21). Bis 0.9.36 wurde Port
 * 70000 zu 65535, Lautstaerke 150 zu 100, ein unbekannter Modus zu
 * musicserver, und aus Client-Geheimnissen verschwanden Anfuehrungszeichen
 * ohne ein Wort (weissware_agenten/oberflaeche Befund 3); bei einer
 * Beanstandung war das neue Miele-Geheimnis trotzdem gespeichert (Befund 4),
 * und die Eingaben waren fort (Befund 2). */
if ($ww_post && isset($_POST['speichern'])) {
    $ww_cfg = ww_config();
    $ww_falsch = array();

    foreach (array(
        // Untergrenze 60 s: Home Connect nennt haeufiges Abfragen in seinen
        // Best Practices ausdruecklich als haeufigste Ursache fuer HTTP 429.
        'takt_betrieb' => array(60, 3600),
        'takt_ruhe'    => array(60, 7200),
        /* Obergrenze WW_WARTEN_WEB, nicht 60. Bis 0.9.6 nahm das Formular 60
         * an, und ww_befehl_absetzen() deckelte danach stillschweigend auf 12
         * - wer 45 eintrug, bekam 12 und erfuhr es nicht. Was nicht ins Muster
         * passt, wird gemeldet, nicht zurechtgebogen. */
        'wartezeit'    => array(0, WW_WARTEN_WEB),
    ) as $ww_feld => $ww_grenzen) {
        $ww_wert = (string) ww_post_text($ww_feld);
        if (!preg_match('/^[0-9]+$/', $ww_wert)) {
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_ZAHL'), ww_t('EINST.L_' . strtoupper($ww_feld)));
            $ww_falsch[] = $ww_feld;
            continue;
        }
        $ww_zahl = (int) $ww_wert;
        if ($ww_zahl < $ww_grenzen[0] || $ww_zahl > $ww_grenzen[1]) {
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_BEREICH'),
                ww_t('EINST.L_' . strtoupper($ww_feld)), $ww_grenzen[0], $ww_grenzen[1]);
            $ww_falsch[] = $ww_feld;
            continue;
        }
        $ww_cfg[$ww_feld] = $ww_zahl;
    }
    if (isset($ww_cfg['takt_ruhe'], $ww_cfg['takt_betrieb'])
        && $ww_cfg['takt_ruhe'] < $ww_cfg['takt_betrieb']) {
        $ww_fehler[] = ww_t('EINST.FEHLER_TAKT_TAUSCH');
        $ww_falsch[] = 'takt_ruhe';
        $ww_falsch[] = 'takt_betrieb';
    }

    /* 'mqtt_ein' steht hier NICHT mehr: der Haken wohnt im Reiter MQTT.
     * Bliebe er in dieser Liste, schaltete jedes Speichern der Einstellungen
     * MQTT stillschweigend ab. */
    $ww_haken_liste = array('steuerung_ein', 'hc_ein', 'hc_simulator',
                            'miele_ein', 'st_ein', 'ansage_ein',
                            'ansage_stoerung', 'ansage_fernstart');
    foreach ($ww_haken_liste as $ww_haken) {
        $ww_cfg[$ww_haken] = isset($_POST[$ww_haken]) ? 1 : 0;
    }

    /* Ruhezeit der Ansage. Was nicht ins Muster passt, wird gemeldet - eine
     * stillschweigend auf 00:00 zurechtgebogene Zeit hiesse: es spricht doch
     * nachts. Der Sprachschluessel steht AUSGESCHRIEBEN neben dem Feld. */
    foreach (array('ansage_ruhe_von' => 'ANSAGE.L_RUHE_VON',
                   'ansage_ruhe_bis' => 'ANSAGE.L_RUHE_BIS') as $ww_zf => $ww_zk) {
        $ww_zw = (string) ww_post_text($ww_zf);
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $ww_zw)) {
            $ww_fehler[] = sprintf(ww_t('ANSAGE.FEHLER_ZEIT'), ww_t($ww_zk));
            $ww_falsch[] = $ww_zf;
        } else {
            $ww_cfg[$ww_zf] = $ww_zw;
        }
    }

    /* Sprachausgabe - EINE Musterliste mit der Sicherung (ww_tts_feld_ok(),
     * Bauliste O6). Fehlt ein Feld ganz (eine aeltere Seite, ein anderes
     * Werkzeug), bleibt der gespeicherte Wert; ein Fehler ist das nicht. */
    $ww_tts = ww_tts();
    $ww_tts_texte = array(
        'mode' => 'ANSAGE.FEHLER_MODUS', 'ip' => 'ANSAGE.FEHLER_IP', 'port' => 'ANSAGE.FEHLER_PORT',
        'zones' => 'ANSAGE.FEHLER_ZONEN', 'volume' => 'ANSAGE.FEHLER_LAUTSTAERKE',
        'lang' => 'ANSAGE.FEHLER_SPRACHE', 'template' => 'ANSAGE.FEHLER_VORLAGE',
        'alexa_geraet' => 'ANSAGE.FEHLER_ALEXA_GERAET', 'alexa_laut' => 'ANSAGE.FEHLER_ALEXA_LAUT',
        'google_geraet' => 'ANSAGE.FEHLER_GOOGLE_GERAET', 'google_laut' => 'ANSAGE.FEHLER_GOOGLE_LAUT',
    );
    foreach ($ww_tts_texte as $ww_tf => $ww_tk) {
        $ww_tw = ww_post_text('tts_' . $ww_tf);
        if ($ww_tw === null) {
            if (isset($_POST['tts_' . $ww_tf])) {     // eine Liste statt eines Werts
                $ww_fehler[] = ww_t($ww_tk);
                $ww_falsch[] = 'tts_' . $ww_tf;
            }
            continue;
        }
        if ($ww_tf === 'lang') {
            $ww_tw = strtolower($ww_tw);
        }
        if (($ww_tf === 'alexa_laut' || $ww_tf === 'google_laut') && $ww_tw === '') {
            $ww_tw = '-1';
        }
        if (!ww_tts_feld_ok($ww_tf, $ww_tw)) {
            $ww_fehler[] = ww_t($ww_tk);
            $ww_falsch[] = 'tts_' . $ww_tf;
            continue;
        }
        $ww_tts[$ww_tf] = in_array($ww_tf, array('port', 'volume', 'alexa_laut', 'google_laut'), true)
            ? (int) $ww_tw : $ww_tw;
    }
    /* Die Sprechtoken (Bauliste A1): Kennwortfeld, leer lassen behaelt das
     * Token, der Haken loescht es; ein neues Token UND der Haken zugleich sind
     * ein Widerspruch. Das Token reist nie zurueck (X-2). */
    foreach (array('alexa' => 'ANSAGE.L_ALEXA_TOKEN', 'google' => 'ANSAGE.L_GOOGLE_TOKEN') as $ww_art => $ww_lk) {
        $ww_tok = ww_post_text('tts_' . $ww_art . '_token');
        $ww_weg = isset($_POST['tts_' . $ww_art . '_token_loeschen']);
        if ($ww_tok === null && isset($_POST['tts_' . $ww_art . '_token'])) {
            $ww_fehler[] = sprintf(ww_t('ANSAGE.FEHLER_TOKEN'), ww_t($ww_lk));
            $ww_falsch[] = 'tts_' . $ww_art . '_token';
            continue;
        }
        if ($ww_tok !== null && $ww_tok !== '') {
            if ($ww_weg) {
                $ww_fehler[] = sprintf(ww_t('ANSAGE.FEHLER_TOKEN_HAKEN'), ww_t($ww_lk));
                $ww_falsch[] = 'tts_' . $ww_art . '_token';
            } elseif (!ww_ng_token_ok($ww_tok)) {
                $ww_fehler[] = sprintf(ww_t('ANSAGE.FEHLER_TOKEN'), ww_t($ww_lk));
                $ww_falsch[] = 'tts_' . $ww_art . '_token';
            } else {
                $ww_tts[$ww_art . '_token'] = $ww_tok;
            }
        } elseif ($ww_weg) {
            $ww_tts[$ww_art . '_token'] = '';
        }
    }
    if ($ww_tts['mode'] === 'alexang' && !ww_ng_token_ok($ww_tts['alexa_token'])) {
        $ww_fehler[] = ww_t('ANSAGE.FEHLER_ALEXA_OHNE_TOKEN');
        $ww_falsch[] = 'tts_alexa_token';
    }
    if ($ww_tts['mode'] === 'cc4lox' && !ww_ng_token_ok($ww_tts['google_token'])) {
        $ww_fehler[] = ww_t('ANSAGE.FEHLER_GOOGLE_OHNE_TOKEN');
        $ww_falsch[] = 'tts_google_token';
    }
    $ww_cfg['tts'] = $ww_tts;

    $ww_spr = (string) ww_post_text('sprache');
    if (!preg_match('/^[a-z]{2}-[A-Z]{2}$/', $ww_spr)) {
        $ww_fehler[] = ww_t('EINST.FEHLER_SPRACHE');
        $ww_falsch[] = 'sprache';
    } else {
        $ww_cfg['sprache'] = $ww_spr;
    }

    /* Zugangsdaten: eigene Datei mit Rechten 0600. Leere Felder loeschen
     * nichts - sonst stuende irgendwann ein leeres Geheimnis in der Datei,
     * ohne dass es jemand merkt. Nichts wird herausgeschnitten (Bauliste O3):
     * ein Geheimnis mit Steuerzeichen wird beanstandet, eines mit
     * Anfuehrungszeichen so gespeichert, wie es eingetippt ist. */
    $ww_zalt = ww_json_lesen($ww_p['zugang']);
    $ww_neu = array();
    foreach (array('hc_client_id' => 'EINST.N_HC_ID', 'hc_client_secret' => 'EINST.N_HC_SECRET',
                   'miele_client_id' => 'EINST.N_MIELE_ID', 'miele_client_secret' => 'EINST.N_MIELE_SECRET',
                   'st_token' => 'EINST.N_ST_TOKEN') as $ww_f2 => $ww_n2) {
        $ww_w2 = ww_post_text($ww_f2);
        if ($ww_w2 === null) {
            if (isset($_POST[$ww_f2])) {
                $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_ZEICHEN'), ww_t($ww_n2));
                $ww_falsch[] = $ww_f2;
            }
            $ww_neu[$ww_f2] = '';
            continue;
        }
        if ($ww_w2 !== '' && (strlen($ww_w2) > 512 || preg_match('//u', $ww_w2) !== 1
                              || preg_match('/[\x00-\x1F\x7F]/', $ww_w2) === 1)) {
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_ZEICHEN'), ww_t($ww_n2));
            $ww_falsch[] = $ww_f2;
            $ww_neu[$ww_f2] = '';
            continue;
        }
        $ww_neu[$ww_f2] = $ww_w2;
    }
    /* Bauliste O4 (Nr. 16): die Anbieterpruefung VOR dem Schreiben, gegen den
     * Stand, der danach gaelte - neuer Wert oder der gespeicherte. */
    $ww_eff = function ($k) use ($ww_neu, $ww_zalt) {
        if (isset($ww_neu[$k]) && $ww_neu[$k] !== '') { return $ww_neu[$k]; }
        return (isset($ww_zalt[$k]) && is_scalar($ww_zalt[$k])) ? (string) $ww_zalt[$k] : '';
    };
    if (!empty($ww_cfg['hc_ein']) && $ww_eff('hc_client_id') === '') {
        $ww_fehler[] = ww_t('EINST.WARN_HC_OHNE_ID');
        $ww_falsch[] = 'hc_client_id';
    }
    if (!empty($ww_cfg['miele_ein']) && $ww_eff('miele_client_id') === '') {
        $ww_fehler[] = ww_t('EINST.WARN_MIELE_OHNE_ID');
        $ww_falsch[] = 'miele_client_id';
    }
    if (!empty($ww_cfg['st_ein']) && $ww_eff('st_token') === '') {
        $ww_fehler[] = ww_t('EINST.WARN_ST_OHNE_TOKEN');
        $ww_falsch[] = 'st_token';
    }

    if (!$ww_fehler) {
        if (!ww_zugang_speichern($ww_neu)) {
            $ww_fehler[] = ww_t('EINST.FEHLER_ZUGANG_SPEICHERN');
        } elseif (ww_config_speichern($ww_cfg)) {
            $ww_meldungen[] = ww_t('EINST.GESPEICHERT');
        } else {
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_SPEICHERN'), ww_e($ww_p['config']));
        }
    } else {
        $ww_eingaben = ww_eingaben_sammeln('settings',
            array('takt_ruhe', 'takt_betrieb', 'wartezeit', 'sprache', 'ansage_ruhe_von', 'ansage_ruhe_bis',
                  'tts_mode', 'tts_ip', 'tts_port', 'tts_zones', 'tts_volume', 'tts_lang', 'tts_template',
                  'tts_alexa_geraet', 'tts_alexa_laut', 'tts_google_geraet', 'tts_google_laut',
                  'hc_client_id', 'miele_client_id'),
            $ww_haken_liste, $ww_falsch);
    }
    $ww_tab = 'tab-settings';

    /* mqtt_ein und mqtt_topic werden hier bewusst NICHT angefasst: sie wohnen im
     * Reiter MQTT und haben dort ein eigenes Formular. */
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Der Handler laedt
 * den Bestand und ruehrt ausschliesslich die MQTT-Werte an.
 *
 * Bauliste O3/O5/I6 (02.10.2026): das Thema wird geprueft und abgewiesen,
 * nicht zurechtgebogen (bis 0.9.36 wurden Anfuehrungszeichen und Steuerzeichen
 * still entfernt und Schraegstriche am Rand abgeschnitten, MQTT Befund 9);
 * ein gescheitertes Speichern wird gemeldet (Befund 5: weder Erfolg noch
 * Fehler); das bisherige Praefix wird bei einem Wechsel vorgemerkt, damit
 * Dienst und Deinstallation es abraeumen (MQTT Befunde 6/7). */
if ($ww_post && isset($_POST['save_mqtt'])) {
    $ww_mcfg = ww_config();
    $ww_falsch = array();
    $ww_alt_topic = (string) $ww_mcfg['mqtt_topic'];
    $ww_alt_ein = !empty($ww_mcfg['mqtt_ein']);
    $ww_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $ww_mtopic = (string) ww_post_text('mqtt_topic');
    if (!ww_praefix_ok($ww_mtopic)) {
        $ww_fehler[] = ww_t('EINST.FEHLER_TOPIC');
        $ww_falsch[] = 'mqtt_topic';
    } else {
        $ww_mcfg['mqtt_topic'] = $ww_mtopic;
    }
    if (!$ww_fehler) {
        $ww_altliste = ww_praefixe_alt($ww_mcfg);
        $ww_wechsel = ($ww_mtopic !== $ww_alt_topic && ww_praefix_ok($ww_alt_topic));
        if ($ww_wechsel && !in_array($ww_alt_topic, $ww_altliste, true)) {
            $ww_altliste[] = $ww_alt_topic;
        }
        $ww_altliste = array_values(array_diff($ww_altliste, array($ww_mtopic)));
        if (count($ww_altliste) > 20) {
            $ww_altliste = array_slice($ww_altliste, -20);
        }
        $ww_mcfg['mqtt_topic_alt'] = $ww_altliste;
        if (ww_config_speichern($ww_mcfg)) {
            $ww_meldungen[] = ww_t('EINST.GESPEICHERT');
            if ($ww_wechsel) {
                $ww_hinweise[] = sprintf(ww_t('MQTT.PRAEFIX_VORGEMERKT'), ww_e($ww_alt_topic));
            }
            if ($ww_alt_ein && empty($ww_mcfg['mqtt_ein'])) {
                $ww_hinweise[] = sprintf(ww_t('MQTT.AUS_RAEUMEN'), ww_e($ww_mcfg['mqtt_topic']));
            }
        } else {
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_SPEICHERN'), ww_e($ww_p['config']));
        }
    } else {
        $ww_eingaben = ww_eingaben_sammeln('mqtt', array('mqtt_topic'), array('mqtt_ein'), $ww_falsch);
    }
    $ww_tab = 'tab-mqtt';
}

/* ---------------- Dienst starten, anhalten, neu starten ---------------- */
if ($ww_post && isset($_POST['dienst'])) {
    $ww_befehl = (string) ww_post_text('dienst');
    /* Der haeufigste Grund, aus dem der Start scheitert, ist der einzige, den
     * die Oberflaeche VORHER kennt: es ist kein Anbieter eingeschaltet. */
    $ww_cfg0 = ww_config();
    if (in_array($ww_befehl, array('start', 'restart'), true)
        && empty($ww_cfg0['hc_ein']) && empty($ww_cfg0['miele_ein']) && empty($ww_cfg0['st_ein'])) {
        $ww_fehler[] = ww_t('EINST.FEHLER_KEIN_ANBIETER');
        $ww_tab = 'tab-settings';
        $ww_befehl = '';
    }
    if ($ww_befehl !== '') {
        list($ww_ok, $ww_ausgabe) = ww_dienst($ww_befehl);
        if ($ww_ok) {
            $ww_meldungen[] = ww_t('EINST.DIENST_' . strtoupper($ww_befehl)) . ' ' . ww_e($ww_ausgabe);
        } else {
            $ww_fehler[] = ww_e($ww_ausgabe);
        }
        $ww_tab = 'tab-settings';
    }
}

/* ---------------- Anmeldung der Anbieter ----------------
 * Home Connect: Device Flow - der Dienst holt einen Benutzercode, der Mensch
 * gibt ihn in einem beliebigen Browser ein, dann wird das Token abgeholt.
 * Miele: Authorization Code Flow - es gibt keinen Device Flow. Der Mensch
 * oeffnet die Anmeldeadresse, meldet sich an und kopiert den Code aus der
 * Adresszeile zurueck. */
if ($ww_post && isset($_POST['anmelden'])) {
    $ww_was = (string) ww_post_text('anmelden');
    if ($ww_was === 'hc_start') {
        list($ww_c, $ww_a) = ww_dienst_schalter('--hc-anmelden');
        if ($ww_c === 0) {
            $ww_meldungen[] = ww_t('EINST.HC_BEGONNEN');
        } else {
            $ww_fehler[] = ww_e($ww_a);
        }
    } elseif ($ww_was === 'hc_fertig') {
        list($ww_c, $ww_a) = ww_dienst_schalter('--hc-fertig');
        if ($ww_c === 0) {
            $ww_meldungen[] = ww_t('EINST.HC_FERTIG');
        } elseif ($ww_c === 2) {
            $ww_fehler[] = ww_t('EINST.HC_NOCH_NICHT');
        } else {
            $ww_fehler[] = ww_e($ww_a);
        }
    } elseif ($ww_was === 'miele_code') {
        $ww_code = (string) ww_post_text('miele_code');
        // Manche Browser geben die ganze Adresse zurueck - daraus wird der
        // Code herausgeholt, statt den Benutzer zum Ausschneiden zu zwingen.
        if (preg_match('/[?&]code=([^&\s]+)/', $ww_code, $ww_m)) {
            $ww_code = urldecode($ww_m[1]);
        }
        if (!preg_match('/^[A-Za-z0-9._~+\/-]{4,512}=*$/', $ww_code)) {
            $ww_fehler[] = ww_t('EINST.FEHLER_MIELE_CODE');
        } elseif (!ww_miele_code_ablegen($ww_code)) {
            /* Bauliste C14: der Code geht ueber eine 0600-Datei an den
             * Dienst, nie ueber die Befehlszeile. */
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_MIELE_ABLAGE'), ww_e($ww_p['datadir']));
        } else {
            list($ww_c, $ww_a) = ww_dienst_schalter('--miele-code');
            if ($ww_c === 0) {
                $ww_meldungen[] = ww_t('EINST.MIELE_FERTIG');
            } else {
                $ww_fehler[] = ww_e($ww_a);
            }
            @unlink($ww_p['datadir'] . '/miele_code');
        }
    } elseif ($ww_was === 'verwerfen') {
        /* Bauliste O8 (02.10.2026): ein loeschender Knopf braucht den
         * Bestaetigungshaken (Regeln/04), und die Meldung nennt den
         * wirklichen Weg. Bis 0.9.36 loeschte ein Klick ohne Rueckfrage beide
         * Anmeldungen und versprach eine Anmeldung "mit Benutzername und
         * Passwort", die der Dienst gar nicht kennt (oberflaeche Befund 10).
         *
         * Auch die halb fertige Home-Connect-Anmeldung wird weggeraeumt:
         * bleibt hc_anmeldung.json liegen, versucht der naechste
         * Anmeldeversuch, die alte - laengst abgelaufene - Sitzung
         * abzuschliessen. */
        if (!isset($_POST['verwerfen_ok'])) {
            $ww_fehler[] = ww_t('EINST.SITZUNG_OHNE_HAKEN');
        } else {
            @unlink($ww_p['datadir'] . '/hc_anmeldung.json');
            $ww_datei = $ww_p['datadir'] . '/token.json';
            if (is_file($ww_datei) && @unlink($ww_datei)) {
                $ww_meldungen[] = ww_t('EINST.SITZUNG_VERWORFEN');
            } elseif (is_file($ww_datei)) {
                $ww_fehler[] = sprintf(ww_t('EINST.SITZUNG_FEHLER'), ww_e($ww_datei));
            } else {
                $ww_meldungen[] = ww_t('EINST.SITZUNG_KEINE');
            }
        }
    }
    $ww_tab = 'tab-settings';
}

/* ---------------- Neues Token ----------------
 *
 * Der Knopf ist eine bewusste Entscheidung des Bedieners und tauscht das
 * Token auch dann, wenn alles heil ist. Er faellt aber geschlossen aus,
 * solange die Konfiguration unlesbar ist und eine Zweitschrift MIT
 * Aktionstoken danebenliegt. Mit der Umleitung (PRG) wuerfelt ein Neuladen
 * der Seite nicht noch einmal (Bauliste O1). */
if ($ww_post && isset($_POST['token_neu']) && ww_token_gesperrt()) {
    $ww_fehler[] = ww_t('WACHE.KEIN_TOKEN');
    $ww_tab = 'tab-loxone';
} elseif ($ww_post && isset($_POST['token_neu'])) {
    $ww_cfg = ww_config();
    $ww_cfg['aktionstoken'] = ww_token_erzeugen();
    if (ww_config_speichern($ww_cfg)) {
        $ww_meldungen[] = ww_t('LOX.TOKEN_NEU');
    } else {
        $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_SPEICHERN'), ww_e($ww_p['config']));
    }
    $ww_tab = 'tab-loxone';
}

/* ---------------- Log leeren ----------------
 * Bauliste O5: Erfolg nur bei wirklicher Wirkung. Bis 0.9.36 meldete die
 * Seite "Logdatei geleert", auch wenn an der Stelle ein Verzeichnis lag
 * (oberflaeche Befund 6). */
if ($ww_post && isset($_POST['log_leeren'])) {
    if (!is_dir(dirname($ww_p['log']))) {
        @mkdir(dirname($ww_p['log']), 0775, true);
    }
    $ww_lz = '[' . date('Y-m-d H:i:s') . '] ' . ww_t('LOG.GELEERT') . "\n";
    if (@file_put_contents($ww_p['log'], $ww_lz) === strlen($ww_lz)) {
        $ww_meldungen[] = ww_t('LOG.GELEERT');
    } else {
        $ww_fehler[] = sprintf(ww_t('LOG.LEEREN_FEHLER'), ww_e($ww_p['log']));
    }
    $ww_tab = 'tab-log';
}

/* ---------------- Aktionen des Reiters Test ---------------- */
if ($ww_post && isset($_POST['test'])) {
    list($ww_stand, $ww_text) = ww_test_aktion((string) ww_post_text('test'));
    if ($ww_stand === 1) {
        $ww_meldungen[] = ww_e($ww_text);
    } else {
        $ww_fehler[] = ww_e($ww_text);
    }
    $ww_tab = 'tab-test';
}
/* Trockenlauf: zeigt, was der Befehl taete. Loest nichts aus - deshalb grau
 * und nicht orange, und deshalb auch dann brauchbar, wenn der Dienst steht. */
if ($ww_post && isset($_POST['trockenlauf'])) {
    $ww_tnr = isset($_POST['test_geraet']) ? (string) ww_post_text('test_geraet') : '1';
    $ww_tprog = (string) ww_post_text('test_programm');
    if (!preg_match('/^[0-9]{1,3}$/', $ww_tnr)) {
        $ww_fehler[] = ww_t('TEST.M_GERAET_UNGUELTIG');
    } elseif ($ww_tprog !== '' && !preg_match('/^[A-Za-z0-9](?!.*\.\.)[A-Za-z0-9._-]{0,79}$/', $ww_tprog)) {
        $ww_fehler[] = ww_t('TEST.M_PROGRAMM_UNGUELTIG');
    } else {
        $ww_takt = (string) ww_post_text('trockenlauf');
        if (!in_array($ww_takt, array('start', 'stop', 'pause', 'fortsetzen', 'ein', 'aus'), true)) {
            $ww_fehler[] = ww_t('TEST.M_UNBEKANNT');
        } else {
            list($ww_c, $ww_testausgabe) = ww_trockenlauf($ww_takt, $ww_tnr, $ww_tprog);
        }
    }
    $ww_tab = 'tab-test';
}
/* Mitschnitt: eine Frist, kein Schalter - er laeuft von selbst ab. */
if ($ww_post && isset($_POST['mitschnitt'])) {
    $ww_sek = preg_match('/^[0-9]{1,4}$/', (string) ww_post_text('mitschnitt'))
        ? (int) ww_post_text('mitschnitt') : 0;
    $ww_erg = ww_mitschnitt_schalten($ww_sek);
    if ($ww_erg < 0) {
        $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_SPEICHERN'), ww_e($ww_p['config']));
    } elseif ($ww_erg > 0) {
        $ww_meldungen[] = sprintf(ww_t('TEST.M_MITSCHNITT_EIN'), $ww_erg);
    } else {
        $ww_meldungen[] = ww_t('TEST.M_MITSCHNITT_AUS');
    }
    $ww_tab = 'tab-test';
}
if ($ww_post && isset($_POST['selbsttest'])) {
    $ww_testausgabe = ww_selbsttest();
    $ww_tab = 'tab-test';
}
/* Testansage: spricht sofort ueber die eingestellte Ausgabeart. Bei Alexa-NG
 * und Chromecast 4 Lox NG steht die Antwortzeile der Gegenstelle mit in der
 * Meldung (Bauliste A1); fuer die uebrigen Ausgabearten bleibt die Meldung,
 * wie sie war. */
if ($ww_post && isset($_POST['ansage_test'])) {
    list($ww_aok, $ww_agrund, $ww_aantw) = ww_sagen(ww_t('ANSAGE.TESTTEXT'));
    $ww_amode = ww_tts()['mode'];
    if ($ww_amode === 'alexang' || $ww_amode === 'cc4lox') {
        if ($ww_aok) {
            $ww_meldungen[] = ww_e(sprintf(ww_t('ANSAGE.TEST_NG_OK'), $ww_aantw));
        } else {
            $ww_fehler[] = ww_e(sprintf(ww_t('ANSAGE.TEST_NG_FEHLER'), $ww_agrund))
                . ($ww_aantw !== '' ? ' ' . ww_e(sprintf(ww_t('ANSAGE.NG_ANTWORT'), $ww_aantw)) : '');
        }
    } elseif ($ww_aok) {
        $ww_meldungen[] = ww_t('ANSAGE.TEST_OK');
    } else {
        $ww_fehler[] = ww_t('ANSAGE.TEST_FEHLER');
    }
    $ww_tab = 'tab-test';
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken UND
 * Zugangsdaten, ohne die Sprechtoken (Bauliste A1). Was beim Zurueckspielen
 * abgewiesen wuerde, nennt die Datei unter _warnung mit Namen (X-3,
 * Bauliste O6); die Seite zeigt denselben Hinweis gelb am Knopf. Ein Download
 * bleibt ohne Umleitung. */
if ($ww_post && isset($_POST['ww_sichern'])) {
    $ww_js = json_encode(ww_sicherung_bauen(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ww_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="weissware_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $ww_js;
        exit;
    }
    $ww_fehler[] = ww_t('EINST.SICH_SCHREIBFEHLER');
    $ww_tab = 'tab-settings';
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze. Bauliste O7: ein leeres
 * Aktionstoken in der Datei laesst das geltende stehen (mit Hinweis), ein
 * Token in fremder Form wird abgewiesen; die Zweitschrift wird mit dem neuen
 * Stand neu geschrieben (ww_config_speichern()). */
if ($ww_post && isset($_POST['ww_zurueck'])) {
    if (!isset($_FILES['ww_sicherung']) || !is_array($_FILES['ww_sicherung'])
        || !isset($_FILES['ww_sicherung']['tmp_name']) || !is_string($_FILES['ww_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['ww_sicherung']['tmp_name'])) {
        $ww_fehler[] = ww_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['ww_sicherung']['size'] > 262144) {
        $ww_fehler[] = ww_t('EINST.SICH_ZU_GROSS');
    } else {
        list($ww_neu, $ww_zneu, $ww_mangel, $ww_n, $ww_shinw) = ww_sicherung_lesen(
            (string) @file_get_contents($_FILES['ww_sicherung']['tmp_name']));
        if ($ww_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $ww_fehler[] = ww_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $ww_mangel);
        } elseif (ww_config_speichern($ww_neu)) {
            $ww_meldungen[] = sprintf(ww_t('EINST.SICH_UEBERNOMMEN'), $ww_n);
            foreach ($ww_shinw as $ww_sh) {
                $ww_hinweise[] = $ww_sh;
            }
            if (is_array($ww_zneu) && $ww_zneu && !ww_zugang_speichern($ww_zneu)) {
                $ww_fehler[] = ww_t('EINST.SICH_ZUGANG_FEHL');
            }
            /* Den Dienst nachziehen und SAGEN, was mit ihm geschah. */
            if (ww_dienst_pid() > 0) {
                list($ww_dok, $ww_daus) = ww_dienst('restart');
                $ww_meldungen[] = $ww_dok
                    ? ww_t('EINST.SICH_DIENST_NEU')
                    : ww_t('EINST.SICH_DIENST_FEHL');
            } else {
                $ww_meldungen[] = ww_t('EINST.SICH_DIENST_AUS');
            }
        } else {
            $ww_fehler[] = ww_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
    $ww_tab = 'tab-settings';
}

/* ---------------- Umleitung nach jedem POST (PRG, Bauliste O1) ----------------
 * Ergebnis, Testausgabe und - nach einer Beanstandung - die Eingaben reisen
 * in der Einmalmeldung; die Seite selbst kommt mit dem GET. Laesst sich die
 * Einmalmeldung nicht ablegen, wird die Seite unmittelbar gezeigt (mit einem
 * Satz, warum) - eine Meldung, die verloren geht, ist schlimmer als ein F5,
 * das wiederholt. */
if ($ww_post) {
    $ww_ziel = in_array($ww_tab, $ww_reiter_ids, true) ? $ww_tab : 'tab-settings';
    if (ww_einmal_schreiben(array('meldungen' => $ww_meldungen, 'fehler' => $ww_fehler,
                                  'hinweise' => $ww_hinweise, 'testausgabe' => $ww_testausgabe,
                                  'eingaben' => $ww_eingaben))) {
        header('Location: index.php?form=' . substr($ww_ziel, 4), true, 303);
        exit;
    }
    $ww_fehler[] = sprintf(ww_t('ALLG.EINMAL_FEHLER'), ww_e($ww_p['datadir']));
}

/* ---------------- Laden ---------------- */
$ww_cfg = ww_config();
$ww_token = ww_token();
/* Leer heisst: die Konfiguration ist unlesbar, die Selbstheilung kam nicht
 * durch, und es wurde bewusst KEIN neues Token gewuerfelt (ww_token_gesperrt()).
 * Das gehoert dem Bediener gesagt - der Reiter "Einbindung in Loxone" zeigte
 * sonst Adressen mit leerem token= an, die niemand gebrauchen kann. */
if ($ww_token === '') {
    $ww_fehler[] = ww_t('WACHE.KEIN_TOKEN');
}
$ww_zg = ww_zugang();
$ww_geraete = ww_geraete();
$ww_zustand = ww_zustand();
$ww_alter = ww_alter();
$ww_pid = ww_dienst_pid();
$ww_mqtt = ww_mqtt_zustand();
$ww_pyv = ww_python_fassung();
$ww_hc_an = ww_hc_anmeldung();
$ww_ang = array('homeconnect' => ww_angemeldet('homeconnect'),
                'miele' => ww_angemeldet('miele'));
$ww_miele_adresse = 'https://api.mcs3.miele.com/thirdparty/login'
    . '?response_type=code&client_id=' . rawurlencode($ww_zg['miele_client_id'])
    . '&redirect_uri=' . rawurlencode('http://localhost')
    . '&scope=' . rawurlencode('openid mcs_thirdparty_read mcs_thirdparty_write')
    . '&state=loxberry';
$ww_libv = ww_bibliothek_fassung();
$ww_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : (gethostname() ?: 'loxberry');
$ww_basis = 'http://' . $ww_host . '/plugins/' . $ww_p['plugin'] . '/index.php';
$ww_logzeilen = array();
if (is_file($ww_p['log'])) {
    $ww_logzeilen = array_slice(
        ww_log_ende($ww_p['log'], 400),
        0, 400);
}

$ww_rahmen = class_exists('LBWeb', false);


if ($ww_rahmen) {
    LBWeb::lbheader('Weissware Cloud', 'https://wiki.loxberry.de/', 'help.html');
}

?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-row { display: flex; gap: 12px; }
.sm-row > div { flex: 1; }

/* Der Ansage- und der MQTT-Abschnitt stehen in .sm-row, nicht in .sm-feld.
   Ohne eigene Regeln bekommen ihre Beschriftungen kein display:block und
   ihre Eingabefelder keine Breite: die drei Spalten wurden unterschiedlich
   breit und standen nicht untereinander. AWM-Abfuhr und Abfahrtsassistent
   stylen dafuer global unter .sm-wrap; hier ist es auf .sm-row begrenzt,
   damit die uebrigen Reiter unveraendert bleiben. */
.sm-row > div > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-row > div input[type=text], .sm-row > div input[type=number],
.sm-row > div select, .sm-row > div textarea,
#tts_template_row textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
  font-size: 0.95em; box-sizing: border-box; }
#tts_template_row > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 10px 0 4px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select, .sm-row > div select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }

/* Markierung eines beanstandeten Feldes (X-2, Regeln/04; Bauliste O2) -
   Bauform Spotpreis Tibber 0.9.25. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-wrap input[type=checkbox].sm-beanstandet { outline: 2px solid #c62828; outline-offset: 2px; }

</style>
<div class="sm-wrap">

<?php foreach ($ww_meldungen as $ww_m) { ?>
<div class="sm-hinweis"><?= $ww_m ?></div>
<?php } ?>
<?php foreach ($ww_hinweise as $ww_h) { ?>
<div class="sm-warnung"><?= $ww_h ?></div>
<?php } ?>
<?php if ($ww_fehler) { ?>
<div class="sm-fehler"><b><?= ww_e(ww_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($ww_fehler as $ww_f) { ?><li><?= $ww_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<!-- ================= Statuskacheln ================= -->
<div class="sm-kacheln">
  <div class="sm-kachel"><?= ww_e(ww_t('ALLG.DIENST')) ?>
    <b class="<?= $ww_pid ? 'sm-an' : 'sm-aus' ?>"><?= $ww_pid ? ww_e(ww_t('ALLG.LAEUFT')) : ww_e(ww_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $ww_pid ? 'PID ' . (int) $ww_pid : ww_e(ww_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= ww_e(ww_t('ALLG.LETZTER_ABRUF')) ?>
    <b><?= $ww_alter < 0 ? '&ndash;' : (int) $ww_alter . ' s' ?></b>
    <span class="sm-hilfe"><?= $ww_alter < 0 ? ww_e(ww_t('ALLG.NIE')) : ww_e(date('d.m.Y H:i:s', time() - $ww_alter)) ?></span>
  </div>
  <div class="sm-kachel"><?= ww_e(ww_t('ALLG.GERAETE')) ?>
    <b><?= count($ww_geraete) ?></b>
    <span class="sm-hilfe"><?= (int) count(array_filter($ww_geraete, function ($g) { return !empty($g['laeuft']); })) ?> <?= ww_e(ww_t('ALLG.IN_BETRIEB')) ?></span>
  </div>
  <!-- Der grosse Wert ist die MQTT-Veroeffentlichung DIESES Plugins (mqtt_ein),
       der Autostart des Gateways steht klein darunter. Bis 0.9.29 stand hier
       der Autostart des Gateways; "MQTT ein" las sich, als sende das Plugin,
       auch wenn es gar nicht veroeffentlichte.
       Vorbild ZendureSolarFlow 0.9.21 und BatterieBMS 0.9.22. Ohne
       MQTT-Abschnitt in general.json heisst der Autostart "nicht feststellbar"
       statt "aus". -->
  <div class="sm-kachel">MQTT
    <b class="<?= !empty($ww_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($ww_cfg['mqtt_ein']) ? ww_e(ww_t('ALLG.EIN')) : ww_e(ww_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= ww_e(sprintf(ww_t('ALLG.KACHEL_MQTT_HILFE'),
        !$ww_mqtt['gefunden'] ? ww_t('ALLG.NICHT_FESTSTELLBAR')
        : ($ww_mqtt['autostart'] ? ww_t('ALLG.EIN') : ww_t('ALLG.AUS')))) ?></span>
  </div>
</div>

<?php
/* Eine Stoerung OHNE Zeitangabe sieht immer aus, als waere sie von eben.
 * zustand.json fuehrt den Zeitpunkt seit jeher mit; angezeigt wurde er nicht.
 * Wer den Dienst anhaelt, sieht die letzte Stoerung sonst noch tagelang so,
 * als bestuende sie fort. */
$ww_st_alter = isset($ww_zustand['ts']) ? max(0, time() - (int) $ww_zustand['ts']) : -1;
if (!empty($ww_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= ww_e(ww_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= ww_e($ww_zustand['fehler']) ?>
<?php if ($ww_st_alter >= 0) { ?>
<span class="sm-hilfe"><?= ww_e(sprintf(ww_t('ALLG.STOERUNG_ALTER'), $ww_st_alter)) ?></span>
<?php } ?>
</div>
<?php } ?>

<?php foreach ($ww_geraete as $ww_nr => $ww_fz) { ?>
<div class="sm-hinweis">
<b><?= ww_e($ww_fz['name'] ? $ww_fz['name'] : ww_t('ALLG.OHNE_NAMEN')) ?></b>
(<?= ww_e($ww_nr) ?>, <?= ww_e($ww_fz['anbieter']) ?><?= !empty($ww_fz['typ']) ? ', ' . ww_e($ww_fz['typ']) : '' ?>)
&middot; <?= ww_e(ww_t('ALLG.ZUSTAND')) ?>
<?php if ($ww_fz['zustand'] === null) { ?><span class="sm-aus">&ndash;</span><?php
      } elseif (!empty($ww_fz['laeuft'])) { ?><span class="sm-an"><?= ww_e($ww_fz['zustand_text']) ?></span><?php
      } else { ?><?= ww_e($ww_fz['zustand_text']) ?><?php } ?>
<?php if ($ww_fz['restzeit_min'] !== null) { ?>
&middot; <?= ww_e(ww_t('ALLG.RESTZEIT')) ?> <b><?= (int) $ww_fz['restzeit_min'] ?> min</b>
<?php } ?>
<?php if ($ww_fz['fortschritt'] !== null) { ?>
&middot; <?= (int) $ww_fz['fortschritt'] ?> %
<?php } ?>
<?php if ($ww_fz['fernstart_frei'] === 0) { ?>
&middot; <span class="sm-aus"><?= ww_e(ww_t('ALLG.KEIN_FERNSTART')) ?></span>
<?php } ?>
</div>
<?php } ?>
<?php if ($ww_zustand && !empty($ww_zustand['ausfaelle'])) { ?>
<div class="sm-warnung"><b><?= ww_e(ww_t('ALLG.AUSFAELLE')) ?></b>
<?php foreach ($ww_zustand['ausfaelle'] as $ww_a => $ww_g2) { ?>
<br><span class="sm-mono"><?= ww_e($ww_a) ?></span>: <?= ww_e($ww_g2) ?>
<?php } ?>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar, Eingaben in anderen Reitern gehen nicht verloren, und
     faellt das Skript aus, ist die Seite weiterhin bedienbar. -->
<?php
/* Die Leiste steht AUSGESCHRIEBEN da, nicht als Schleife.
 *
 * hausstandard_pruefen.py sucht die Reiter woertlich am Ziel-Attribut der
 * Verweise. Aus einer Schleife erzeugt findet es keinen einzigen und meldet
 * "nicht gemessen" - ein Strich in der Spalte, der leicht wie ein Haken
 * aussieht.
 *
 * Dieser Kommentar nennt die gesuchte Form deshalb NICHT im Wortlaut: ein
 * Beispiel in derselben Schreibweise wird mitgezaehlt, und die Pruefung
 * meldet dann einen Reiter zu viel. Genau das ist hier beim Schreiben
 * passiert.
 * Bis 0.9.6 war das hier so, und die Spalte stand deshalb dauerhaft auf "-".
 * Genau dieser Fehler steht in REGELN_1 zweimal in der Liste eigener Fehler.
 *
 * Der Preis ist eine zweite Stelle, die zu $ww_reiter_ids passen muss. Die
 * Uebereinstimmung prueft der Reiter Test nach - ausgeschrieben UND
 * nachgemessen, nicht ausgeschrieben und gehofft. */
?>
<div class="sm-tabs">
	<a class="sm-tab<?= $ww_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= ww_e(ww_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $ww_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $ww_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?= ww_e(ww_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $ww_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?form=test"><?= ww_e(ww_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $ww_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?form=log"><?= ww_e(ww_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $ww_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<?php if ($ww_pyv !== '' && version_compare($ww_pyv, '3.9.0', '<')) { ?>
<div class="sm-fehler"><?= ww_t('EINST.PYTHON_ZU_ALT') ?></div>
<?php } ?>

<h2><?= ww_e(ww_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= ww_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ww_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ww_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= ww_e(ww_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= ww_e(ww_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= ww_e(ww_t('EINST.K_STOPP')) ?></button>
  </form>
  <form action="index.php" method="post" style="flex-direction:column;align-items:flex-start;gap:4px;">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="anmelden" value="verwerfen"><?= ww_e(ww_t('EINST.K_SITZUNG')) ?></button>
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.85em;">
      <input data-role="none" type="checkbox" name="verwerfen_ok" value="1"> <?= ww_e(ww_t('EINST.L_SITZUNG_OK')) ?>
    </label>
  </form>
</div>
<p class="sm-hilfe"><?= ww_t('EINST.SITZUNG_ERKLAERUNG') ?></p>

<form action="index.php" method="post" autocomplete="off">
  <?php echo ww_fmt(); ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= ww_e(ww_t('EINST.H_ANBIETER')) ?></h2>
<div class="sm-hinweis"><?= ww_t('EINST.ANBIETER_ERKLAERUNG') ?></div>

<h3>Home Connect &mdash; Bosch, Siemens, Neff, Gaggenau, Constructa</h3>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="hc_ein" value="1" <?= ww_fh('settings', 'hc_ein', $ww_cfg['hc_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_HC_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="hc_client_id"><?= ww_e(ww_t('EINST.L_HC_ID')) ?></label>
  <input data-role="none" type="text" id="hc_client_id" name="hc_client_id" value="<?= ww_e(ww_fw('settings', 'hc_client_id', $ww_zg['hc_client_id'])) ?>"<?= ww_fm('hc_client_id') ?>>
  <div class="sm-hilfe"><?= ww_t('EINST.H_HC_ID') ?></div>
</div>
<div class="sm-feld">
  <label for="hc_client_secret"><?= ww_e(ww_t('EINST.L_HC_SECRET')) ?></label>
  <input data-role="none" type="password" id="hc_client_secret" name="hc_client_secret" value=""<?= ww_fm('hc_client_secret') ?> placeholder="<?= $ww_zg['hc_secret_laenge'] > 0 ? ww_e(sprintf(ww_t('EINST.GESETZT'), $ww_zg['hc_secret_laenge'])) : ww_e(ww_t('EINST.LEER')) ?>">
  <div class="sm-hilfe"><?= ww_t('EINST.H_HC_SECRET') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="hc_simulator" value="1" <?= ww_fh('settings', 'hc_simulator', $ww_cfg['hc_simulator']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_HC_SIMULATOR')) ?>
  </label>
  <div class="sm-hilfe"><?= ww_t('EINST.H_HC_SIMULATOR') ?></div>
</div>

<h3>Miele</h3>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="miele_ein" value="1" <?= ww_fh('settings', 'miele_ein', $ww_cfg['miele_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_MIELE_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="miele_client_id"><?= ww_e(ww_t('EINST.L_MIELE_ID')) ?></label>
  <input data-role="none" type="text" id="miele_client_id" name="miele_client_id" value="<?= ww_e(ww_fw('settings', 'miele_client_id', $ww_zg['miele_client_id'])) ?>"<?= ww_fm('miele_client_id') ?>>
  <div class="sm-hilfe"><?= ww_t('EINST.H_MIELE_ID') ?></div>
</div>
<div class="sm-feld">
  <label for="miele_client_secret"><?= ww_e(ww_t('EINST.L_MIELE_SECRET')) ?></label>
  <input data-role="none" type="password" id="miele_client_secret" name="miele_client_secret" value=""<?= ww_fm('miele_client_secret') ?> placeholder="<?= $ww_zg['miele_secret_laenge'] > 0 ? ww_e(sprintf(ww_t('EINST.GESETZT'), $ww_zg['miele_secret_laenge'])) : ww_e(ww_t('EINST.LEER')) ?>">
</div>

<h3>SmartThings &mdash; Samsung</h3>
<div class="sm-warnung"><?= ww_t('EINST.ST_WARNUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="st_ein" value="1" <?= ww_fh('settings', 'st_ein', $ww_cfg['st_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_ST_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="st_token"><?= ww_e(ww_t('EINST.L_ST_TOKEN')) ?></label>
  <input data-role="none" type="password" id="st_token" name="st_token" value=""<?= ww_fm('st_token') ?> placeholder="<?= $ww_zg['st_laenge'] > 0 ? ww_e(sprintf(ww_t('EINST.GESETZT'), $ww_zg['st_laenge'])) : ww_e(ww_t('EINST.LEER')) ?>">
  <div class="sm-hilfe"><?= ww_t('EINST.H_ST_TOKEN') ?></div>
</div>

<h2><?= ww_e(ww_t('EINST.H_TAKT')) ?></h2>
<div class="sm-hinweis"><?= ww_t('EINST.TAKT_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="takt_ruhe"><?= ww_e(ww_t('EINST.L_TAKT_RUHE')) ?></label>
  <input data-role="none" type="<?= ww_ftyp('takt_ruhe') ?>" id="takt_ruhe" name="takt_ruhe" value="<?= ww_e(ww_fw('settings', 'takt_ruhe', $ww_cfg['takt_ruhe'])) ?>" min="60" max="7200"<?= ww_fm('takt_ruhe') ?>>
  <div class="sm-hilfe"><?= ww_t('EINST.H_TAKT_RUHE') ?></div>
</div>
<div class="sm-feld">
  <label for="takt_betrieb"><?= ww_e(ww_t('EINST.L_TAKT_BETRIEB')) ?></label>
  <input data-role="none" type="<?= ww_ftyp('takt_betrieb') ?>" id="takt_betrieb" name="takt_betrieb" value="<?= ww_e(ww_fw('settings', 'takt_betrieb', $ww_cfg['takt_betrieb'])) ?>" min="60" max="3600"<?= ww_fm('takt_betrieb') ?>>
  <div class="sm-hilfe"><?= ww_t('EINST.H_TAKT_BETRIEB') ?></div>
</div>
<div class="sm-feld">
  <label for="sprache"><?= ww_e(ww_t('EINST.L_SPRACHE')) ?></label>
  <?php $ww_spr_w = ww_fw('settings', 'sprache', $ww_cfg['sprache']); ?>
  <select data-role="none" id="sprache" name="sprache"<?= ww_fm('sprache') ?>>
    <option value="de-DE" <?= $ww_spr_w === 'de-DE' ? 'selected' : '' ?>>de-DE</option>
    <option value="en-GB" <?= $ww_spr_w === 'en-GB' ? 'selected' : '' ?>>en-GB</option>
    <option value="en-US" <?= $ww_spr_w === 'en-US' ? 'selected' : '' ?>>en-US</option>
  </select>
  <div class="sm-hilfe"><?= ww_t('EINST.H_SPRACHE') ?></div>
</div>

<h2><?= ww_e(ww_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= ww_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= ww_fh('settings', 'steuerung_ein', $ww_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= ww_e(ww_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="<?= ww_ftyp('wartezeit') ?>" id="wartezeit" name="wartezeit" value="<?= ww_e(ww_fw('settings', 'wartezeit', $ww_cfg['wartezeit'])) ?>" min="0" max="<?= (int) WW_WARTEN_WEB ?>"<?= ww_fm('wartezeit') ?>>
  <div class="sm-hilfe"><?= ww_t('EINST.H_WARTEZEIT') ?></div>
</div>

<h2><?= ww_e(ww_t('ANSAGE.H')) ?></h2>
<?php $ww_tts = ww_tts();
/* X-2: nach einer Beanstandung stehen die eingetippten Werte in den Feldern
 * (ohne Sprechtoken - die reisen nie zurueck). */
foreach (array('mode', 'ip', 'port', 'zones', 'volume', 'lang', 'template',
               'alexa_geraet', 'alexa_laut', 'google_geraet', 'google_laut') as $ww_tf) {
    $ww_tts[$ww_tf] = ww_fw('settings', 'tts_' . $ww_tf, $ww_tts[$ww_tf]);
} ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ansage_ein" value="1" <?= ww_fh('settings', 'ansage_ein', $ww_cfg['ansage_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('ANSAGE.L_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= ww_t('ANSAGE.H_EIN') ?></div>
</div>
<?php /* Je Ereignis ein eigener Haken, alle ab Werk aus. */ ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ansage_stoerung" value="1" <?= ww_fh('settings', 'ansage_stoerung', $ww_cfg['ansage_stoerung']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('ANSAGE.L_STOERUNG')) ?>
  </label>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ansage_fernstart" value="1" <?= ww_fh('settings', 'ansage_fernstart', $ww_cfg['ansage_fernstart']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('ANSAGE.L_FERNSTART')) ?>
  </label>
  <div class="sm-hilfe"><?= ww_t('ANSAGE.H_FERNSTART') ?></div>
</div>
<div class="sm-row">
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_RUHE_VON')) ?></label>
        <input data-role="none" type="text" name="ansage_ruhe_von" value="<?= ww_e(ww_fw('settings', 'ansage_ruhe_von', $ww_cfg['ansage_ruhe_von'])) ?>" placeholder="22:00"<?= ww_fm('ansage_ruhe_von') ?>>
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_RUHE_BIS')) ?></label>
        <input data-role="none" type="text" name="ansage_ruhe_bis" value="<?= ww_e(ww_fw('settings', 'ansage_ruhe_bis', $ww_cfg['ansage_ruhe_bis'])) ?>" placeholder="07:00"<?= ww_fm('ansage_ruhe_bis') ?>>
    </div>
    <div>
        <label>&nbsp;</label>
        <div class="sm-hilfe"><?= ww_t('ANSAGE.H_RUHE') ?></div>
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_AUSGABE')) ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="wwTtsMode()"<?= ww_fm('tts_mode') ?>>
            <option value="musicserver"<?= $ww_tts['mode'] === 'musicserver' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_MUSICSERVER')) ?></option>
            <option value="ms4h"<?= $ww_tts['mode'] === 'ms4h' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_MS4H')) ?></option>
            <option value="audioserver"<?= $ww_tts['mode'] === 'audioserver' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_AUDIOSERVER')) ?></option>
            <option value="custom"<?= $ww_tts['mode'] === 'custom' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_CUSTOM')) ?></option>
            <option value="alexang"<?= $ww_tts['mode'] === 'alexang' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_ALEXANG')) ?></option>
            <option value="cc4lox"<?= $ww_tts['mode'] === 'cc4lox' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_CC4LOX')) ?></option>
        </select>
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_IP')) ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= ww_e($ww_tts['ip']) ?>" placeholder="z. B. 192.168.1.50"<?= ww_fm('tts_ip') ?>>
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_PORT')) ?></label>
        <input data-role="none" type="<?= ww_ftyp('tts_port') ?>" name="tts_port" value="<?= ww_e($ww_tts['port']) ?>" min="1" max="65535"<?= ww_fm('tts_port') ?>>
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_ZONEN')) ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= ww_e($ww_tts['zones']) ?>" placeholder="z. B. 2,4,6"<?= ww_fm('tts_zones') ?>>
        <div class="sm-hilfe"><?= ww_t('ANSAGE.H_ZONEN') ?></div>
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_LAUTSTAERKE')) ?></label>
        <input data-role="none" type="<?= ww_ftyp('tts_volume') ?>" name="tts_volume" value="<?= ww_e($ww_tts['volume']) ?>" min="1" max="100"<?= ww_fm('tts_volume') ?>>
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_SPRACHE')) ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= ww_e($ww_tts['lang']) ?>" maxlength="2"<?= ww_fm('tts_lang') ?>>
    </div>
</div>
<div id="tts_template_row">
    <label><?= ww_e(ww_t('ANSAGE.L_VORLAGE')) ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2"<?= ww_fm('tts_template') ?> placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= ww_e($ww_tts['template']) ?></textarea>
    <div class="sm-hilfe"><?= ww_t('ANSAGE.H_VORLAGE') ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-warnung" style="display:none;">
    <?= ww_t('ANSAGE.H_AUDIOSERVER') ?>
</div>
<?php
/* Ausgabearten Alexa-NG und Google-Lautsprecher (Bauliste A1), ab Werk nicht
 * gewaehlt. Das Sprechtoken ist ein Kennwortfeld mit value="" - der Platzhalter
 * nennt nur die Laenge; leer lassen behaelt es, der Haken loescht es. */
$ww_ng_reihen = array(
    'alexa'  => array('tts_alexa_row', 'ANSAGE.H_ALEXA', 'ANSAGE.L_ALEXA_TOKEN'),
    'google' => array('tts_google_row', 'ANSAGE.H_GOOGLE', 'ANSAGE.L_GOOGLE_TOKEN'),
);
foreach ($ww_ng_reihen as $ww_na => $ww_nr_) {
    $ww_ntok = ww_tts()[$ww_na . '_token'];
    $ww_nlaut = (string) $ww_tts[$ww_na . '_laut'] === '-1' ? '' : (string) $ww_tts[$ww_na . '_laut'];
?>
<div id="<?= $ww_nr_[0] ?>" style="display:none;">
<div class="sm-hinweis"><?= ww_t($ww_nr_[1]) ?></div>
<div class="sm-row">
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_NG_GERAET')) ?></label>
        <input data-role="none" type="text" name="tts_<?= $ww_na ?>_geraet" value="<?= ww_e($ww_tts[$ww_na . '_geraet']) ?>" maxlength="200"<?= ww_fm('tts_' . $ww_na . '_geraet') ?>>
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_NG_LAUT')) ?></label>
        <input data-role="none" type="text" name="tts_<?= $ww_na ?>_laut" value="<?= ww_e($ww_nlaut) ?>" maxlength="3"<?= ww_fm('tts_' . $ww_na . '_laut') ?>>
    </div>
    <div>
        <label><?= ww_e(ww_t($ww_nr_[2])) ?></label>
        <input data-role="none" type="password" name="tts_<?= $ww_na ?>_token" value="" autocomplete="new-password"<?= ww_fm('tts_' . $ww_na . '_token') ?> placeholder="<?= is_string($ww_ntok) && $ww_ntok !== '' ? ww_e(sprintf(ww_t('EINST.GESETZT'), strlen($ww_ntok))) : ww_e(ww_t('EINST.LEER')) ?>">
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:normal;">
            <input data-role="none" type="checkbox" name="tts_<?= $ww_na ?>_token_loeschen" value="1"> <?= ww_e(ww_t('ANSAGE.L_NG_TOKEN_LOESCHEN')) ?>
        </label>
    </div>
</div>
</div>
<?php } ?>

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= ww_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ww_e(ww_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<?php /* Das Einstellungsformular endet HIER. Bis zu dieser Fassung fehlte
         das schliessende </form>: alle folgenden Formulare der Seite lagen
         dadurch verschachtelt darin. HTML verbietet das, der Browser wirft
         die inneren weg - ihre Knoepfe sendeten also an das Einstellungs-
         formular. Ein Klick auf "Anmeldung verwerfen" oder auf einen
         Testknopf loeste damit ein Speichern der Einstellungen aus. */ ?>

<h2><?= ww_e(ww_t('EINST.H_ANMELDUNG')) ?></h2>
<p class="sm-hilfe"><?= ww_t('EINST.ANMELDUNG_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ww_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ww_t('LEGENDE.AKTION') ?></span>
</div>

<div class="sm-step"><b>Home Connect</b><br>
<?php if ($ww_ang['homeconnect']) { ?>
<span class="sm-an"><?= ww_e(ww_t('EINST.ANGEMELDET')) ?></span>
<?php } elseif ($ww_hc_an) { ?>
<?= ww_t('EINST.HC_SCHRITT2') ?>
<table class="sm-tbl">
<tr><td><?= ww_e(ww_t('EINST.HC_CODE')) ?></td><td><b class="sm-mono" style="font-size:1.3em;"><?= ww_e($ww_hc_an['user_code']) ?></b></td></tr>
<tr><td><?= ww_e(ww_t('EINST.HC_ADRESSE')) ?></td><td><span class="sm-mono"><?= ww_e($ww_hc_an['verification_uri']) ?></span></td></tr>
<?php
/*
 * Die FERTIGE Adresse, wenn Home Connect eine mitgeschickt hat - sie traegt
 * den Benutzercode bereits in sich.
 *
 * Bis 0.9.21 stand sie nur in hc_anmeldung.json und wurde nie angezeigt; wer
 * sich anmelden wollte, musste den Code aus der Zelle darueber von Hand
 * herausklauben. Markiert man dabei ueber die Zellgrenze, kopiert der Browser
 * einen TABULATOR mit, und Home Connect weist die Adresse ab:
 * "invalid_request: Illegal URI reference ... user_code=\tXXXX-9999".
 * Genau so ist es am 15.09.2026 passiert.
 *
 * Kein Kopieren mehr noetig: ein Klick genuegt. Angezeigt wird sie nur, wenn
 * sie mit http:// oder https:// beginnt - eine Adresse aus fremder Hand
 * gehoert geprueft, bevor sie in ein href geht.
 */
$ww_hc_fertig = isset($ww_hc_an['verification_uri_complete'])
    ? trim((string) $ww_hc_an['verification_uri_complete']) : '';
if ($ww_hc_fertig !== '' && preg_match('#^https?://#', $ww_hc_fertig)) { ?>
<tr><td><?= ww_e(ww_t('EINST.HC_ADRESSE_DIREKT')) ?></td><td><a class="sm-mono" href="<?= ww_e($ww_hc_fertig) ?>" target="_blank" rel="noopener noreferrer"><?= ww_e($ww_hc_fertig) ?></a></td></tr>
<?php } ?>
<tr><td><?= ww_e(ww_t('EINST.HC_GUELTIG')) ?></td><td><?= max(0, (int) $ww_hc_an['laeuft_ab'] - time()) ?> <?= ww_e(ww_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?php } else { ?>
<?= ww_t('EINST.HC_SCHRITT1') ?>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="anmelden" value="hc_start"><?= ww_e(ww_t('EINST.K_HC_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="anmelden" value="hc_fertig"><?= ww_e(ww_t('EINST.K_HC_FERTIG')) ?></button>
  </form>
</div>
</div>

<div class="sm-step"><b>Miele</b><br>
<?php
/* Bis 0.9.7 stand hier das Wort "angemeldet" als kurze Einblendung, und
 * unmittelbar dahinter - ohne Punkt, ohne Absatz - die vollstaendige
 * Anleitung zum Anmelden. Wer angemeldet war, las trotzdem drei Zeilen
 * darueber, wie man sich anmeldet, und der eine entscheidende Hinweis ging
 * darin unter. Home Connect macht es zwei Kaesten weiter oben richtig: eine
 * Verzweigung, nicht eine Einblendung vor unveraendertem Text. */
if ($ww_ang['miele']) { ?>
<p><span class="sm-an"><?= ww_e(ww_t('EINST.ANGEMELDET')) ?></span>
&middot; <?= ww_t('EINST.MIELE_SCHON_ANGEMELDET') ?></p>
<?php } else { ?>
<?= ww_t('EINST.MIELE_SCHRITTE') ?>
<?php } ?>
<?php /* Die Anmeldeadresse steht in beiden Faellen da: auch wer angemeldet
         ist, braucht sie zum erneuten Anmelden. */ ?>
<?php if ($ww_zg['miele_client_id'] !== '') { ?>
<p><span class="sm-mono"><?= ww_e($ww_miele_adresse) ?></span></p>
<?php } else { ?>
<div class="sm-warnung"><?= ww_t('EINST.MIELE_OHNE_ID') ?></div>
<?php } ?>
<form action="index.php" method="post">
  <?php echo ww_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-feld">
  <label for="miele_code"><?= ww_e(ww_t('EINST.L_MIELE_CODE')) ?></label>
  <input data-role="none" type="text" id="miele_code" name="miele_code" value="">
  <div class="sm-hilfe"><?= ww_t('EINST.H_MIELE_CODE') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="anmelden" value="miele_code"><?= ww_e(ww_t('EINST.K_MIELE_CODE')) ?></button>
</div>
</form>
</div>

<div class="sm-step"><b>SmartThings</b><br>
<?= ww_t('EINST.ST_SCHRITTE') ?>
</div>

<h2><?= ww_e(ww_t('EINST.H_ERKANNT')) ?></h2>
<?php if (!$ww_geraete) { ?>
<div class="sm-warnung"><?= ww_t('EINST.KEINE_GERAETE') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('EINST.T_NR')) ?></th><th><?= ww_e(ww_t('EINST.T_ANBIETER')) ?></th>
    <th><?= ww_e(ww_t('EINST.T_NAME')) ?></th><th><?= ww_e(ww_t('EINST.T_TYP')) ?></th>
    <th><?= ww_e(ww_t('EINST.T_MARKE')) ?></th><th><?= ww_e(ww_t('EINST.T_ZUSTAND')) ?></th>
    <th><?= ww_e(ww_t('EINST.T_FERNSTART')) ?></th><th><?= ww_e(ww_t('EINST.T_KENNUNG')) ?></th></tr>
<?php foreach ($ww_geraete as $ww_nr => $ww_fz) { ?>
<tr><td><?= ww_e($ww_nr) ?></td><td><?= ww_e($ww_fz['anbieter']) ?></td>
    <td><?= ww_e($ww_fz['name']) ?></td><td><?= ww_e($ww_fz['typ']) ?></td>
    <td><?= ww_e($ww_fz['marke']) ?></td>
    <td><?= $ww_fz['zustand'] === null ? '&ndash;' : ww_e($ww_fz['zustand_text']) ?></td>
    <td class="<?= !empty($ww_fz['fernstart_frei']) ? 'sm-an' : 'sm-aus' ?>"><?php
        if ($ww_fz['fernstart_frei'] === null) { echo '&ndash;'; }
        else { echo $ww_fz['fernstart_frei'] ? ww_e(ww_t('ALLG.JA')) : ww_e(ww_t('ALLG.NEIN')); }
    ?></td>
    <td><span class="sm-mono"><?= ww_e($ww_fz['id']) ?></span></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= ww_t('EINST.KENNUNG_HINWEIS') ?></p>
<?php } ?>

<h2><?= ww_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= ww_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= ww_t('EINST.SICH_WARNUNG') ?></div>
<?php $ww_sich_namen = ww_sicherung_warnung();
if ($ww_sich_namen) { ?>
<div class="sm-warnung"><?= ww_e(sprintf(ww_t('EINST.SICH_WARN_STAND'), implode(', ', $ww_sich_namen))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ww_sichern" value="1"><?= ww_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="ww_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ww_zurueck" value="1"><?= ww_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $ww_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<form action="index.php" method="post">
  <?php echo ww_fmt(); ?>
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= ww_fh('mqtt', 'mqtt_ein', $ww_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= ww_e(ww_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= ww_e(ww_fw('mqtt', 'mqtt_topic', $ww_cfg['mqtt_topic'])) ?>" placeholder="weissware"<?= ww_fm('mqtt_topic') ?>>
  <div class="sm-hilfe"><?= ww_t('EINST.H_MQTT_TOPIC') ?></div>
<?php $ww_palt = ww_praefixe_alt($ww_cfg); if ($ww_palt) { ?>
  <div class="sm-hilfe"><?= ww_e(sprintf(ww_t('MQTT.ALT_LISTE'), implode(', ', $ww_palt))) ?></div>
<?php } ?>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= ww_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ww_e(ww_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= ww_e(ww_t('MQTT.H_ZUSTAND')) ?></h2>
<p class="sm-hilfe"><?= ww_t('MQTT.GATEWAY_ERKLAERUNG') ?></p>

<?php if (!$ww_mqtt['gefunden']) { ?>
<div class="sm-fehler"><?= ww_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$ww_mqtt['autostart']) { ?>
<div class="sm-fehler"><?= ww_t('MQTT.AUTOSTART_AUS') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= ww_t('MQTT.AUTOSTART_EIN') ?></div>
<?php } ?>

<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ww_e(ww_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ww_e(ww_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $ww_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $ww_mqtt['autostart'] ? ww_e(ww_t('ALLG.EIN')) : ww_e(ww_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= ww_e(ww_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= ww_e($ww_mqtt['broker']) ?>:<?= ww_e($ww_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= ww_e(ww_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $ww_mqtt['udpport'] ?></span></td></tr>
<tr><td><?= ww_e(ww_t('MQTT.T_PLUGIN')) ?></td><td class="<?= !empty($ww_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($ww_cfg['mqtt_ein']) ? ww_e(ww_t('ALLG.EIN')) : ww_e(ww_t('ALLG.AUS')) ?></td></tr>
</table>

<h2><?= ww_e(ww_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= ww_abo_text() ?></div>
<div class="sm-step">
<?= ww_t('MQTT.ABO_SCHRITTE') ?>
<p><span class="sm-mono"><?= ww_e($ww_cfg['mqtt_topic']) ?>/#</span></p>
</div>

<h2><?= ww_e(ww_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= ww_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('MQTT.T_THEMA')) ?></th><th><?= ww_e(ww_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= ww_e(ww_t('MQTT.T_RETAIN')) ?></th></tr>
<?php $ww_rt = ww_mqtt_retain();
foreach (ww_mqtt_themen() as $ww_thema => $ww_schluessel) { ?>
<tr><td><span class="sm-mono"><?= ww_e($ww_cfg['mqtt_topic'] . '/' . $ww_thema) ?></span></td>
    <td><?= ww_t($ww_schluessel) ?></td>
    <td><?= ww_e(ww_t(!empty($ww_rt[$ww_thema]) ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN')) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= ww_t('MQTT.RETAIN_ERKLAERUNG') ?></p>
<p class="sm-hilfe"><?= ww_t('MQTT.PLATZHALTER') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $ww_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= ww_e(ww_t('LOX.H_TITEL')) ?></h2>
<p><?= ww_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S1_TITEL')) ?></b><br>
<?= ww_t('LOX.S1_TEXT') ?>
</div>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S2_TITEL')) ?></b><br>
<?= ww_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= ww_e($ww_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= ww_abo_text() ?></div>
</div>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S3_TITEL')) ?></b><br>
<?= ww_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ww_e(ww_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ww_e(ww_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=status&amp;geraet=1</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_ZYKLUS')) ?></td><td>300 <?= ww_e(ww_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?= ww_t('LOX.S3_BEFEHLE') ?>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('LOX.T_TITEL')) ?></th><th><?= ww_e(ww_t('LOX.T_BEFEHL')) ?></th>
    <th><?= ww_e(ww_t('LOX.T_EINHEIT')) ?></th><th><?= ww_e(ww_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ww_status_felder() as $ww_feld => $ww_info) { ?>
<tr><td><span class="sm-mono"><?= ww_e(ww_titel(1, $ww_feld)) ?></span></td>
    <td><span class="sm-mono">\i<?= ww_e($ww_feld) ?>=\i\v</span></td>
    <td><?= $ww_info[0] ?></td><td><?= ww_t($ww_info[1]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= ww_t('LOX.S3_STRICH') ?></div>
<?php if (count($ww_geraete) > 1) { ?>
<p><b><?= ww_e(ww_t('LOX.MEHRERE_GERAETE')) ?></b></p>
<table class="sm-tbl">
<?php /* Spalte 2 fuehrte bis 0.9.6 das Feld 'modell'. Das gibt es im
         gemeinsamen Geraetemodell nicht - dort heissen die Felder 'name',
         'typ' und 'marke' (bin/weissware.py, hc_abbilden/miele_abbilden/
         st_abbilden). Die Spalte blieb deshalb leer, und PHP 8 schrieb je
         Geraet eine Warnung in die Zelle. Sichtbar war das nur mit mehr als
         einem Geraet. Gezeigt wird jetzt der Name, denn diese Tabelle
         beantwortet: welche Adresse gehoert zu welchem Geraet. */ ?>
<tr><th><?= ww_e(ww_t('ALLG.GERAET')) ?></th><th><?= ww_e(ww_t('EINST.T_NAME')) ?></th><th><?= ww_e(ww_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach ($ww_geraete as $ww_nr => $ww_fz) { ?>
<tr><td><?= ww_e($ww_nr) ?></td><td><?= ww_e(isset($ww_fz['name']) ? $ww_fz['name'] : '') ?></td>
    <td><span class="sm-mono"><?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=status&amp;geraet=<?= ww_e($ww_nr) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>
<?php
/* Je erkanntem Geraet eine Knopfreihe. Ist noch keines erkannt, wird die Reihe
 * fuer Geraet 1 angeboten - dann enthaelt die Datei alle Felder, und der
 * Hinweis in der Datei sagt, sie nach dem ersten Abruf erneut zu holen. */
$ww_vnrn = $ww_geraete ? array_keys($ww_geraete) : array('1');
foreach ($ww_vnrn as $ww_vnr) {
    $ww_vname = isset($ww_geraete[$ww_vnr]['name']) ? $ww_geraete[$ww_vnr]['name'] : '';
?>
<p><b><?= ww_e(sprintf(ww_t('LOX.VORLAGEN_FUER'), $ww_vnr)) ?></b><?= $ww_vname !== '' ? ' &middot; ' . ww_e($ww_vname) : '' ?></p>
<div class="sm-knopfreihe">
<?php /* Ausgeschrieben, nicht als Schleife ueber Schluesselnamen: der
         Sprachpruefer sucht woertliche Aufrufe und meldete die drei sonst als
         "definiert, aber nie benutzt" - dieselbe Blindstelle wie bei der
         Reiterleiste. */ ?>
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= ww_e($ww_vnr) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="status"><?= ww_e(ww_t('LOX.K_VORLAGE_STATUS')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= ww_e($ww_vnr) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="verbrauch"><?= ww_e(ww_t('LOX.K_VORLAGE_VERBRAUCH')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= ww_e($ww_vnr) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="ausgang"><?= ww_e(ww_t('LOX.K_VORLAGE_BEFEHLE')) ?></button>
  </form>
</div>
<?php } ?>
<p><b><?= ww_e(ww_t('LOX.VORLAGEN_MQTT')) ?></b></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="mqtt"><?= ww_e(ww_t('LOX.K_VORLAGE_MQTT')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= ww_t('LOX.VORLAGEN_HINWEIS') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ww_t('LEGENDE.LESEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S4_TITEL')) ?></b><br>
<?= ww_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ww_e(ww_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ww_e(ww_t('LOX.T_ADRESSE')) ?></td><td><span class="sm-mono"><?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=verbrauch&amp;geraet=1</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_ZYKLUS')) ?></td><td>300 <?= ww_e(ww_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('LOX.T_BEFEHL')) ?></th><th><?= ww_e(ww_t('LOX.T_EINHEIT')) ?></th><th><?= ww_e(ww_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ww_verbrauch_felder() as $ww_feld => $ww_info) { ?>
<tr><td><span class="sm-mono">\i<?= ww_e($ww_feld) ?>=\i\v</span></td>
    <td><?= $ww_info[0] ?></td><td><?= ww_t($ww_info[1]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= ww_t('LOX.S4_LEER') ?></div>
</div>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S5_TITEL')) ?></b><br>
<?= ww_t('LOX.S5_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ww_e(ww_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= ww_e($ww_host) ?></span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_START')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ww_e($ww_p['plugin']) ?>/index.php?token=<?= ww_e($ww_token) ?>&amp;aktion=start&amp;geraet=1</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_START_PROG')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ww_e($ww_p['plugin']) ?>/index.php?token=<?= ww_e($ww_token) ?>&amp;aktion=start&amp;geraet=1&amp;programm=LaundryCare.Washer.Program.Cotton</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_STOP')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ww_e($ww_p['plugin']) ?>/index.php?token=<?= ww_e($ww_token) ?>&amp;aktion=stop&amp;geraet=1</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_PAUSE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ww_e($ww_p['plugin']) ?>/index.php?token=<?= ww_e($ww_token) ?>&amp;aktion=pause&amp;geraet=1</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_FORTSETZEN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ww_e($ww_p['plugin']) ?>/index.php?token=<?= ww_e($ww_token) ?>&amp;aktion=fortsetzen&amp;geraet=1</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_EIN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ww_e($ww_p['plugin']) ?>/index.php?token=<?= ww_e($ww_token) ?>&amp;aktion=ein&amp;geraet=1</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_AUS')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ww_e($ww_p['plugin']) ?>/index.php?token=<?= ww_e($ww_token) ?>&amp;aktion=aus&amp;geraet=1</span></td></tr>
<tr><td><?= ww_e(ww_t('LOX.T_VA_ABRUF')) ?></td>
    <td><span class="sm-mono">/plugins/<?= ww_e($ww_p['plugin']) ?>/index.php?token=<?= ww_e($ww_token) ?>&amp;aktion=abruf</span></td></tr>
</table>
<div class="sm-warnung"><?= ww_t('LOX.S5_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S6_TITEL')) ?></b><br>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('ALLG.EIGENSCHAFT')) ?></th><th><?= ww_e(ww_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= ww_e(ww_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= ww_e($ww_token) ?></span></td></tr>
</table>
<?= ww_t('LOX.S6_TEXT') ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= ww_e(ww_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ww_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S7_TITEL')) ?></b><br>
<?= ww_t('LOX.S7_TEXT') ?>
</div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Anspruch: Wer die Tabelle von oben nach unten abarbeitet, hat die Funktion
 * nachgebaut, ohne nachzudenken. Loxone Config fuehrt alle Bausteine in der
 * Baustein-Suche (F5).
 *
 * Je Zeile: Nummer, Typ, Name, Parameter, woran die Eingaenge kommen.
 * Typ, Name und Parameter stehen als Sprachschluessel drin, die Eingangsspalte
 * ist symbolisch und damit sprachfrei.
 */
function ww_bausteine()
{
    return array(
        array(1 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N01', 'BAUSTEIN.P01', '&mdash;'),
        array(2 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N02', 'BAUSTEIN.P02', '&mdash;'),
        array(3 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N03', 'BAUSTEIN.P03', '&mdash;'),
        array(4 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N04', 'BAUSTEIN.P04', '&mdash;'),
        array(5 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N05', 'BAUSTEIN.P05', '&mdash;'),
        array(6 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N06', 'BAUSTEIN.P06', '&mdash;'),
        array(7 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N07', 'BAUSTEIN.P07', '&mdash;'),
        array(8 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N08', 'BAUSTEIN.P08', '&mdash;'),
        array(9 , 'BAUSTEIN.T_VE',      'BAUSTEIN.N09', 'BAUSTEIN.P09', '&mdash;'),
        array(10, 'BAUSTEIN.T_VE',      'BAUSTEIN.N10', 'BAUSTEIN.P10', '&mdash;'),
        array(11, 'BAUSTEIN.T_VE',      'BAUSTEIN.N11', 'BAUSTEIN.P11', '&mdash;'),
        array(12, 'BAUSTEIN.T_VE',      'BAUSTEIN.N12', 'BAUSTEIN.P12', '&mdash;'),
        /* Zeilen 13-34 neu gelegt. Bis 0.9.6 stammten Typ- und Eingangsspalte
         * wortgleich aus AudiConnect 0.9.6, waehrend Name und Parameter fuer
         * Weissware neu geschrieben worden waren - um eine Zeile versetzt.
         * Dadurch trug etwa #21 den Typ "Virtueller Ausgang Befehl" und den
         * Parameter "Ein > 0,5, Aus < 0,5", #23 eine Benachrichtigung mit
         * Schwellwerten. Dieselbe Altlastquelle wie das AUDI_-Praefix, das in
         * 0.9.1 behoben wurde.
         *
         * Zwei Bedingungen bestimmen den Neuaufbau:
         *   - UND und ODER haben ZWEI Eingaenge (REGELN_3, A4: I1=00, I2=01,
         *     Q=02). Drei Bedingungen brauchen zwei Bausteine, keine dritte
         *     Klemme. Die alte Zeile #15 fuehrte vier Eingaenge.
         *   - Kein Vorwaertsverweis: jede Zeile bezieht sich nur auf kleinere
         *     Nummern. Wer die Tabelle von oben nach unten abarbeitet, hat nie
         *     einen Eingang ohne Quelle.
         *
         * Die Namen sind unveraendert die 22 aus den Sprachdateien - keiner
         * ist hinzugekommen, keiner entfallen; sie stehen nur in Baureihenfolge.
         */
        array(13, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N13', 'BAUSTEIN.P13', 'I &larr; ' . ww_t('BAUSTEIN.PVUEBERSCHUSS')),
        array(14, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N14', 'BAUSTEIN.P14', 'I &larr; ' . ww_t('BAUSTEIN.HAUSAKKU')),
        array(15, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N15', 'BAUSTEIN.P15', 'I &larr; ' . ww_t('BAUSTEIN.SPOTPREIS')),
        array(16, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N16', '',             'I1 &larr; #14, I2 &larr; #15'),
        array(17, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N17', '',             'I1 &larr; #13, I2 &larr; #16'),
        array(18, 'BAUSTEIN.T_EVZ',     'BAUSTEIN.N18', 'BAUSTEIN.P18', 'I &larr; #17'),
        array(19, 'BAUSTEIN.T_WOCHE',   'BAUSTEIN.N19', 'BAUSTEIN.P19', '&mdash;'),
        array(20, 'BAUSTEIN.T_UND',     'BAUSTEIN.N20', 'BAUSTEIN.P20', 'I1 &larr; #18, I2 &larr; #19'),
        array(21, 'BAUSTEIN.T_TASTER',  'BAUSTEIN.N21', 'BAUSTEIN.P21', '&mdash;'),
        array(22, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N22', '',             'I1 &larr; #20, I2 &larr; #21'),
        array(23, 'BAUSTEIN.T_UND',     'BAUSTEIN.N23', 'BAUSTEIN.P23', 'I1 &larr; #22, I2 &larr; #6'),
        array(24, 'BAUSTEIN.T_IMPULS',  'BAUSTEIN.N24', 'BAUSTEIN.P24', 'I &larr; #23'),
        array(25, 'BAUSTEIN.T_VA',      'BAUSTEIN.N25', 'BAUSTEIN.P25', 'I &larr; #24'),
        array(26, 'BAUSTEIN.T_VA',      'BAUSTEIN.N26', 'BAUSTEIN.P26', ww_t('BAUSTEIN.MANUELL')),
        array(27, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N27', 'BAUSTEIN.P27', 'I &larr; #5'),
        array(28, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N28', 'BAUSTEIN.P28', 'I &larr; #27'),
        array(29, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N29', 'BAUSTEIN.P29', 'I &larr; #7'),
        array(30, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N30', 'BAUSTEIN.P30', 'I &larr; #29'),
        array(31, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N31', 'BAUSTEIN.P31', 'I &larr; #11'),
        array(32, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N32', 'BAUSTEIN.P32', 'I &larr; #31'),
        array(33, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N33', 'BAUSTEIN.P33', 'I1 &larr; #1, I2 &larr; #3, I3 &larr; #5'),
        array(34, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N34', 'BAUSTEIN.P34', 'I &larr; #24'),
    );
}
?>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S8_TITEL')) ?></b><br>
<?= ww_t('LOX.S8_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= ww_e(ww_t('LOX.T_BAUSTEIN')) ?></th><th><?= ww_e(ww_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= ww_e(ww_t('LOX.T_PARAMETER')) ?></th><th><?= ww_e(ww_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (ww_bausteine() as $ww_b) { ?>
<tr><td><?= (int) $ww_b[0] ?></td><td><?= ww_t($ww_b[1]) ?></td><td><?= ww_t($ww_b[2]) ?></td>
    <td><?= $ww_b[3] !== '' ? ww_t($ww_b[3]) : '&mdash;' ?></td><td><?= $ww_b[4] ?></td></tr>
<?php } ?>
</table>
<?= ww_t('LOX.S8_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= ww_e(ww_t('LOX.S9_TITEL')) ?></b><br>
<?= ww_t('LOX.S9_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('LOX.T_PRUEFUNG')) ?></th><th><?= ww_e(ww_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=status</span></td>
    <?php /* Hier stand bis 0.9.6 "WEISSWARE;OK=1;SOC=..." - SOC ist ein
             Batteriewert und kommt in diesem Plugin nirgends vor. Eine
             Altlast wie das AUDI_-Praefix. Der Anwender haette gegen eine
             Erwartung geprueft, die es nie gab. */ ?>
    <td><span class="sm-mono">WEISSWARE;OK=1;ZUSTAND=...</span></td></tr>
<tr><td><span class="sm-mono"><?= ww_e($ww_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $ww_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= ww_e(ww_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= ww_t('TEST.EINLEITUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= ww_e(ww_t('TEST.T_FRAGE')) ?></th><th><?= ww_e(ww_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach (ww_pruefungen($ww_tab === 'tab-test') as $ww_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($ww_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($ww_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $ww_z['frage'] ?></td><td><?= $ww_z['antwort'] ?></td></tr>
<?php } ?>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ww_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= ww_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ww_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= ww_e(ww_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a class="sm-btn sm-b-lesen" href="<?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=status&amp;geraet=1" target="_blank"><?= ww_e(ww_t('TEST.K_STATUS')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=verbrauch&amp;geraet=1" target="_blank"><?= ww_e(ww_t('TEST.K_VERBRAUCH')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=geraete" target="_blank"><?= ww_e(ww_t('TEST.K_GERAETE')) ?></a>
</div>

<h3><?= ww_e(ww_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= ww_e(ww_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=roh" target="_blank"><?= ww_e(ww_t('TEST.K_ROH')) ?></a>
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="300"><?= ww_e(ww_t('TEST.K_MITSCHNITT_EIN')) ?></button>
  </form>
<?php if (ww_mitschnitt_rest() > 0) { ?>
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="0"><?= ww_e(ww_t('TEST.K_MITSCHNITT_AUS')) ?></button>
  </form>
<?php } ?>
</div>
<div class="sm-hilfe"><?= ww_t('TEST.H_MITSCHNITT') ?></div>
<?php $ww_mz = ww_mitschnitt_zeilen(120); if ($ww_mz) { ?>
<div class="sm-log"><?= ww_e(implode("\n", $ww_mz)) ?></div>
<?php } ?>

<h3><?= ww_e(ww_t('ANSAGE.H_TEST')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1"><?= ww_e(ww_t('ANSAGE.K_TEST')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= ww_t('ANSAGE.H_TEST_TEXT') ?></div>
<?php if ($ww_testausgabe !== '') { ?>
<div class="sm-pre"><?= ww_e($ww_testausgabe) ?></div>
<?php } ?>

<h3><?= ww_e(ww_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= ww_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($ww_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= ww_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
  <?php echo ww_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="test_geraet"><?= ww_e(ww_t('TEST.L_GERAET')) ?></label>
  <input data-role="none" type="number" id="test_geraet" name="test_geraet" value="1" min="1" max="99">
</div>
<div class="sm-feld">
  <label for="test_programm"><?= ww_e(ww_t('TEST.L_PROGRAMM')) ?></label>
  <input data-role="none" type="text" id="test_programm" name="test_programm" value="">
  <div class="sm-hilfe"><?= ww_t('TEST.H_PROGRAMM') ?></div>
</div>
<?php /* Der Trockenlauf steht in einer EIGENEN Reihe ueber den orangen Knoepfen:
         lesende und schaltende Knoepfe werden nie gemischt. Er benutzt
         dieselben beiden Felder darueber, deshalb steht er im selben
         Formular. */ ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= ww_t('LEGENDE.TECHNIK') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="trockenlauf" value="start"><?= ww_e(ww_t('TEST.K_TROCKEN')) ?></button>
</div>
<div class="sm-hilfe"><?= ww_t('TEST.H_TROCKEN') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= ww_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= ww_e(ww_t('TEST.K_ABRUF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="start"><?= ww_e(ww_t('TEST.K_START')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?= ww_e(ww_t('TEST.K_STOP')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="pause"><?= ww_e(ww_t('TEST.K_PAUSE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="fortsetzen"><?= ww_e(ww_t('TEST.K_FORTSETZEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ein"><?= ww_e(ww_t('TEST.K_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="aus"><?= ww_e(ww_t('TEST.K_AUS')) ?></button>
</div>
</form>

<h3><?= ww_e(ww_t('LAUF.H')) ?></h3>
<p class="sm-hilfe"><?= ww_t('LAUF.ERKLAERUNG') ?></p>
<?php $ww_laeufe = ww_laeufe(20); if (!$ww_laeufe) { ?>
<div class="sm-hinweis"><?= ww_t('LAUF.LEER') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= ww_e(ww_t('LAUF.T_ENDE')) ?></th><th><?= ww_e(ww_t('ALLG.GERAET')) ?></th>
    <th><?= ww_e(ww_t('LAUF.T_PROGRAMM')) ?></th><th><?= ww_e(ww_t('LAUF.T_DAUER')) ?></th>
    <th><?= ww_e(ww_t('LAUF.T_ENERGIE')) ?></th><th><?= ww_e(ww_t('LAUF.T_WASSER')) ?></th></tr>
<?php foreach ($ww_laeufe as $ww_l) {
    // Ein Strich heisst: dieser Wert lag nicht vor. Nie eine 0.
    $ww_w = function ($v) { return ($v === null || $v === '') ? '&ndash;' : ww_e($v); }; ?>
<tr><td><?= ww_e(date('d.m.Y H:i', (int) $ww_l['ts'])) ?></td>
    <td><?= ww_e($ww_l['name'] !== '' ? $ww_l['name'] : $ww_l['geraet']) ?></td>
    <td><?= $ww_w(isset($ww_l['programm']) ? $ww_l['programm'] : null) ?></td>
    <td><?= $ww_w(isset($ww_l['laufmin']) ? $ww_l['laufmin'] : null) ?></td>
    <td><?= $ww_w(isset($ww_l['energie_kwh']) ? $ww_l['energie_kwh'] : null) ?></td>
    <td><?= $ww_w(isset($ww_l['wasser_l']) ? $ww_l['wasser_l'] : null) ?></td></tr>
<?php } ?>
</table>
<?php } ?>

<div class="sm-warnung"><b><?= ww_e(ww_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= ww_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $ww_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= ww_e(ww_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= ww_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= ww_e($ww_p['log']) ?></span></p>
<?php if ($ww_logzeilen) { ?>
<div class="sm-log"><?= ww_e(implode("\n", $ww_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= ww_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ww_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo ww_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= ww_e(ww_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($ww_tab) ?>);
})();
function wwTtsMode() {
	var m = document.getElementById('tts_mode').value;
	document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
	document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
	document.getElementById('tts_alexa_row').style.display = (m === 'alexang') ? 'block' : 'none';
	document.getElementById('tts_google_row').style.display = (m === 'cc4lox') ? 'block' : 'none';
}
wwTtsMode();
</script>
<?php
if ($ww_rahmen) {
    LBWeb::lbfooter();
}
