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
$ww_testausgabe = '';
$ww_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

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
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* ---------------- Vorlage herunterladen ---------------- */
if ($ww_post && isset($_POST['vorlage'])) {
    /* Bis 0.9.6 gab es genau einen Knopf mit fest verdrahteter 1 - bei zwei
     * Geraeten musste die zweite Datei von Hand gebaut werden. Jetzt Art und
     * Nummer getrennt; was nicht ins Muster passt, wird abgewiesen. */
    $ww_art = (string) $_POST['vorlage'];
    if (!in_array($ww_art, array('status', 'verbrauch', 'ausgang', 'mqtt'), true)) {
        $ww_fehler[] = ww_t('LOX.FEHLER_VORLAGE');
    } else {
        $ww_rohnr = isset($_POST['vorlage_nr']) ? (string) $_POST['vorlage_nr'] : '1';
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

/* ---------------- Einstellungen speichern ---------------- */
if ($ww_post && isset($_POST['speichern'])) {
    $ww_cfg = ww_config();

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
        $ww_wert = isset($_POST[$ww_feld]) ? trim((string) $_POST[$ww_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $ww_wert)) {
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_ZAHL'), ww_t('EINST.L_' . strtoupper($ww_feld)));
            continue;
        }
        $ww_zahl = (int) $ww_wert;
        if ($ww_zahl < $ww_grenzen[0] || $ww_zahl > $ww_grenzen[1]) {
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_BEREICH'),
                ww_t('EINST.L_' . strtoupper($ww_feld)), $ww_grenzen[0], $ww_grenzen[1]);
            continue;
        }
        $ww_cfg[$ww_feld] = $ww_zahl;
    }
    if (isset($ww_cfg['takt_ruhe'], $ww_cfg['takt_betrieb'])
        && $ww_cfg['takt_ruhe'] < $ww_cfg['takt_betrieb']) {
        $ww_fehler[] = ww_t('EINST.FEHLER_TAKT_TAUSCH');
    }

    /* 'mqtt_ein' steht hier NICHT mehr: der Haken wohnt im Reiter MQTT.
     * Bliebe er in dieser Liste, schaltete jedes Speichern der Einstellungen
     * MQTT stillschweigend ab. */
    foreach (array('steuerung_ein', 'hc_ein', 'hc_simulator',
                   'miele_ein', 'st_ein', 'ansage_ein',
                   'ansage_stoerung', 'ansage_fernstart') as $ww_haken) {
        $ww_cfg[$ww_haken] = isset($_POST[$ww_haken]) ? 1 : 0;
    }

    /* Ruhezeit der Ansage. Was nicht ins Muster passt, wird gemeldet - eine
     * stillschweigend auf 00:00 zurechtgebogene Zeit hiesse: es spricht doch
     * nachts. */
    /* Der Sprachschluessel steht AUSGESCHRIEBEN neben dem Feld, er wird nicht
     * aus dem Feldnamen gerechnet. Ein geratener Schluessel ergibt eine
     * Meldung, in der der Schluessel selbst steht - und faellt erst auf, wenn
     * jemand den Fehlerfall ausloest. */
    foreach (array('ansage_ruhe_von' => 'ANSAGE.L_RUHE_VON',
                   'ansage_ruhe_bis' => 'ANSAGE.L_RUHE_BIS') as $ww_zf => $ww_zk) {
        $ww_zw = isset($_POST[$ww_zf]) ? trim((string) $_POST[$ww_zf]) : '';
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $ww_zw)) {
            $ww_fehler[] = sprintf(ww_t('ANSAGE.FEHLER_ZEIT'), ww_t($ww_zk));
        } else {
            $ww_cfg[$ww_zf] = $ww_zw;
        }
    }

    /* Sprachausgabe - Felder und Grenzen wortgleich zu AWM-Abfuhr */
    $ww_mode = (string) (isset($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver');
    $ww_cfg['tts'] = array(
        'mode' => in_array($ww_mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true) ? $ww_mode : 'musicserver',
        'ip' => trim((string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : '')),
        'port' => max(1, min(65535, (int) (isset($_POST['tts_port']) ? $_POST['tts_port'] : 7091))),
        'zones' => trim((string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '1')),
        'volume' => max(1, min(100, (int) (isset($_POST['tts_volume']) ? $_POST['tts_volume'] : 8))),
        'lang' => preg_replace('/[^a-z]/', '', strtolower((string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : 'de'))) ?: 'de',
        'template' => trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : '')),
    );

    $ww_spr = isset($_POST['sprache']) ? (string) $_POST['sprache'] : 'de-DE';
    if (!preg_match('/^[a-z]{2}-[A-Z]{2}$/', $ww_spr)) {
        $ww_fehler[] = ww_t('EINST.FEHLER_SPRACHE');
    } else {
        $ww_cfg['sprache'] = $ww_spr;
    }


    /* Zugangsdaten: eigene Datei mit Rechten 0600. Leere Felder loeschen
     * nichts - sonst stuende irgendwann ein leeres Geheimnis in der Datei,
     * ohne dass es jemand merkt. */
    $ww_neu = array();
    foreach (array('hc_client_id', 'hc_client_secret', 'miele_client_id',
                   'miele_client_secret', 'st_token') as $ww_f2) {
        $ww_neu[$ww_f2] = isset($_POST[$ww_f2])
            ? trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST[$ww_f2])) : '';
    }
    if (!ww_zugang_speichern($ww_neu)) {
        $ww_fehler[] = ww_t('EINST.FEHLER_ZUGANG_SPEICHERN');
    }
    $ww_zg = ww_zugang();
    if (!empty($ww_cfg['hc_ein']) && $ww_zg['hc_client_id'] === '') {
        $ww_fehler[] = ww_t('EINST.WARN_HC_OHNE_ID');
    }
    if (!empty($ww_cfg['miele_ein']) && $ww_zg['miele_client_id'] === '') {
        $ww_fehler[] = ww_t('EINST.WARN_MIELE_OHNE_ID');
    }
    if (!empty($ww_cfg['st_ein']) && $ww_zg['st_laenge'] === 0) {
        $ww_fehler[] = ww_t('EINST.WARN_ST_OHNE_TOKEN');
    }

    if (!$ww_fehler) {
        if (ww_config_speichern($ww_cfg)) {
            $ww_meldungen[] = ww_t('EINST.GESPEICHERT');
        } else {
            $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_SPEICHERN'), $ww_p['config']);
        }
    }
    $ww_tab = 'tab-settings';

    /* mqtt_ein und mqtt_topic werden hier bewusst NICHT angefasst: sie wohnen im
     * Reiter MQTT und haben dort ein eigenes Formular. Die Konfiguration
     * kommt aus ww_config(), die Werte ueberleben also unveraendert. Stuende
     * hier weiter "isset($_POST['mqtt_ein']) ? 1 : 0", wuerde jedes Speichern
     * der Einstellungen MQTT stillschweigend abschalten. */
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verloere
 * Werte, die er nie gesehen hat. Der Handler laedt darum den Bestand und
 * ruehrt ausschliesslich die MQTT-Werte an. */
if ($ww_post && isset($_POST['save_mqtt'])) {
    $ww_mcfg = ww_config();
    $ww_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $ww_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($ww_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $ww_mtopic)) {
        $ww_fehler[] = ww_t('EINST.FEHLER_TOPIC');
    } else {
        $ww_mcfg['mqtt_topic'] = trim($ww_mtopic, '/');
    }
    if (!$ww_fehler) {
        if (ww_config_speichern($ww_mcfg)) {
        $ww_meldungen[] = ww_t('EINST.GESPEICHERT');
        }
    }
    $ww_tab = 'tab-mqtt';
}

/* ---------------- Dienst starten, anhalten, neu starten ---------------- */
if ($ww_post && isset($_POST['dienst'])) {
    $ww_befehl = (string) $_POST['dienst'];
    /* Der haeufigste Grund, aus dem der Start scheitert, ist der einzige, den
     * die Oberflaeche VORHER kennt: es ist kein Anbieter eingeschaltet. Der
     * Dienst schreibt ihn ins Protokoll und endet; dienst.sh weiss davon
     * nichts und meldet nur "Start fehlgeschlagen - siehe weissware.log". Wer
     * das liest, sucht in einer Logdatei nach etwas, das die Seite selbst
     * weiss. Ein Haken beim Anbieter und die Anmeldung sind ZWEI Dinge - man
     * kann angemeldet und trotzdem nicht eingeschaltet sein. */
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
 * Adresszeile zurueck. Umstaendlich, aber der einzige Weg, der ohne einen von
 * aussen erreichbaren LoxBerry auskommt. */
if ($ww_post && isset($_POST['anmelden'])) {
    $ww_was = (string) $_POST['anmelden'];
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
        $ww_code = isset($_POST['miele_code']) ? trim((string) $_POST['miele_code']) : '';
        // Manche Browser geben die ganze Adresse zurueck - daraus wird der
        // Code herausgeholt, statt den Benutzer zum Ausschneiden zu zwingen.
        if (preg_match('/[?&]code=([^&\s]+)/', $ww_code, $ww_m)) {
            $ww_code = urldecode($ww_m[1]);
        }
        if (!preg_match('/^[A-Za-z0-9._~+\/-]{4,512}=*$/', $ww_code)) {
            $ww_fehler[] = ww_t('EINST.FEHLER_MIELE_CODE');
        } else {
            list($ww_c, $ww_a) = ww_dienst_schalter('--miele-code', $ww_code);
            if ($ww_c === 0) {
                $ww_meldungen[] = ww_t('EINST.MIELE_FERTIG');
            } else {
                $ww_fehler[] = ww_e($ww_a);
            }
        }
    } elseif ($ww_was === 'verwerfen') {
        /* Auch die halb fertige Home-Connect-Anmeldung wegraeumen.
         *
         * hc_anmeldung.json entsteht beim Geraeteablauf und enthaelt den
         * Geraetecode samt Ablaufzeit. Bleibt sie liegen, versucht der
         * naechste Anmeldeversuch, die alte - laengst abgelaufene - Sitzung
         * abzuschliessen, statt eine neue zu beginnen. Genau das soll der
         * Knopf "Anmeldung neu erzwingen" verhindern. */
        @unlink($ww_p['datadir'] . '/hc_anmeldung.json');
        $ww_datei = $ww_p['datadir'] . '/token.json';
        if (is_file($ww_datei) && @unlink($ww_datei)) {
            $ww_meldungen[] = ww_t('EINST.SITZUNG_VERWORFEN');
        } else {
            $ww_meldungen[] = ww_t('EINST.SITZUNG_KEINE');
        }
    }
    $ww_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($ww_post && isset($_POST['token_neu'])) {
    $ww_cfg = ww_config();
    $ww_cfg['aktionstoken'] = ww_token_erzeugen();
    if (ww_config_speichern($ww_cfg)) {
        $ww_meldungen[] = ww_t('LOX.TOKEN_NEU');
    } else {
        $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_SPEICHERN'), $ww_p['config']);
    }
    $ww_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($ww_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($ww_p['log']), 0775, true);
    @file_put_contents($ww_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . ww_t('LOG.GELEERT') . "\n");
    $ww_meldungen[] = ww_t('LOG.GELEERT');
    $ww_tab = 'tab-log';
}

/* ---------------- Aktionen des Reiters Test ---------------- */
if ($ww_post && isset($_POST['test'])) {
    list($ww_stand, $ww_text) = ww_test_aktion((string) $_POST['test']);
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
    $ww_tnr = isset($_POST['test_geraet']) ? (string) $_POST['test_geraet'] : '1';
    $ww_tprog = isset($_POST['test_programm']) ? trim((string) $_POST['test_programm']) : '';
    if (!preg_match('/^[0-9]{1,3}$/', $ww_tnr)) {
        $ww_fehler[] = ww_t('TEST.M_GERAET_UNGUELTIG');
    } elseif ($ww_tprog !== '' && !preg_match('/^[A-Za-z0-9](?!.*\.\.)[A-Za-z0-9._-]{0,79}$/', $ww_tprog)) {
        $ww_fehler[] = ww_t('TEST.M_PROGRAMM_UNGUELTIG');
    } else {
        $ww_takt = (string) $_POST['trockenlauf'];
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
    $ww_sek = preg_match('/^[0-9]{1,4}$/', (string) $_POST['mitschnitt'])
        ? (int) $_POST['mitschnitt'] : 0;
    $ww_erg = ww_mitschnitt_schalten($ww_sek);
    if ($ww_erg < 0) {
        $ww_fehler[] = sprintf(ww_t('EINST.FEHLER_SPEICHERN'), $ww_p['config']);
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
/* Testansage: spricht sofort in die konfigurierten Zonen (wie AWM-Abfuhr). */
if ($ww_post && isset($_POST['ansage_test'])) {
    if (ww_say(ww_t('ANSAGE.TESTTEXT'))) {
        $ww_meldungen[] = ww_t('ANSAGE.TEST_OK');
    } else {
        $ww_fehler[] = ww_t('ANSAGE.TEST_FEHLER');
    }
    $ww_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$ww_cfg = ww_config();
$ww_token = ww_token();
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

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($ww_post && isset($_POST['ww_sichern'])) {
    $ww_js = json_encode(ww_config(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ww_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="weissware_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $ww_js;
        exit;
    }
    $ww_fehler[] = ww_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($ww_post && isset($_POST['ww_zurueck'])) {
    if (!isset($_FILES['ww_sicherung']) || !is_array($_FILES['ww_sicherung'])
        || !isset($_FILES['ww_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['ww_sicherung']['tmp_name'])) {
        $ww_fehler[] = ww_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['ww_sicherung']['size'] > 262144) {
        $ww_fehler[] = ww_t('EINST.SICH_ZU_GROSS');
    } else {
        list($ww_neu, $ww_mangel, $ww_n) = ww_sicherung_lesen(
            (string) @file_get_contents($_FILES['ww_sicherung']['tmp_name']));
        if ($ww_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $ww_fehler[] = ww_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $ww_mangel);
        } elseif (ww_config_speichern($ww_neu)) {
            $ww_meldungen[] = sprintf(ww_t('EINST.SICH_UEBERNOMMEN'), $ww_n);
        } else {
            $ww_fehler[] = ww_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}


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
</style>
<div class="sm-wrap">

<?php foreach ($ww_meldungen as $ww_m) { ?>
<div class="sm-hinweis"><?= $ww_m ?></div>
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
  <div class="sm-kachel">MQTT
    <b class="<?= $ww_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $ww_mqtt['autostart'] ? ww_e(ww_t('ALLG.EIN')) : ww_e(ww_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= ww_e(ww_t('ALLG.GATEWAY')) ?></span>
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
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= ww_e(ww_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= ww_e(ww_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= ww_e(ww_t('EINST.K_STOPP')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="anmelden" value="verwerfen"><?= ww_e(ww_t('EINST.K_SITZUNG')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= ww_e(ww_t('EINST.H_ANBIETER')) ?></h2>
<div class="sm-hinweis"><?= ww_t('EINST.ANBIETER_ERKLAERUNG') ?></div>

<h3>Home Connect &mdash; Bosch, Siemens, Neff, Gaggenau, Constructa</h3>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="hc_ein" value="1" <?= !empty($ww_cfg['hc_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_HC_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="hc_client_id"><?= ww_e(ww_t('EINST.L_HC_ID')) ?></label>
  <input data-role="none" type="text" id="hc_client_id" name="hc_client_id" value="<?= ww_e($ww_zg['hc_client_id']) ?>">
  <div class="sm-hilfe"><?= ww_t('EINST.H_HC_ID') ?></div>
</div>
<div class="sm-feld">
  <label for="hc_client_secret"><?= ww_e(ww_t('EINST.L_HC_SECRET')) ?></label>
  <input data-role="none" type="password" id="hc_client_secret" name="hc_client_secret" value="" placeholder="<?= $ww_zg['hc_secret_laenge'] > 0 ? ww_e(sprintf(ww_t('EINST.GESETZT'), $ww_zg['hc_secret_laenge'])) : ww_e(ww_t('EINST.LEER')) ?>">
  <div class="sm-hilfe"><?= ww_t('EINST.H_HC_SECRET') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="hc_simulator" value="1" <?= !empty($ww_cfg['hc_simulator']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_HC_SIMULATOR')) ?>
  </label>
  <div class="sm-hilfe"><?= ww_t('EINST.H_HC_SIMULATOR') ?></div>
</div>

<h3>Miele</h3>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="miele_ein" value="1" <?= !empty($ww_cfg['miele_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_MIELE_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="miele_client_id"><?= ww_e(ww_t('EINST.L_MIELE_ID')) ?></label>
  <input data-role="none" type="text" id="miele_client_id" name="miele_client_id" value="<?= ww_e($ww_zg['miele_client_id']) ?>">
  <div class="sm-hilfe"><?= ww_t('EINST.H_MIELE_ID') ?></div>
</div>
<div class="sm-feld">
  <label for="miele_client_secret"><?= ww_e(ww_t('EINST.L_MIELE_SECRET')) ?></label>
  <input data-role="none" type="password" id="miele_client_secret" name="miele_client_secret" value="" placeholder="<?= $ww_zg['miele_secret_laenge'] > 0 ? ww_e(sprintf(ww_t('EINST.GESETZT'), $ww_zg['miele_secret_laenge'])) : ww_e(ww_t('EINST.LEER')) ?>">
</div>

<h3>SmartThings &mdash; Samsung</h3>
<div class="sm-warnung"><?= ww_t('EINST.ST_WARNUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="st_ein" value="1" <?= !empty($ww_cfg['st_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_ST_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="st_token"><?= ww_e(ww_t('EINST.L_ST_TOKEN')) ?></label>
  <input data-role="none" type="password" id="st_token" name="st_token" value="" placeholder="<?= $ww_zg['st_laenge'] > 0 ? ww_e(sprintf(ww_t('EINST.GESETZT'), $ww_zg['st_laenge'])) : ww_e(ww_t('EINST.LEER')) ?>">
  <div class="sm-hilfe"><?= ww_t('EINST.H_ST_TOKEN') ?></div>
</div>

<h2><?= ww_e(ww_t('EINST.H_TAKT')) ?></h2>
<div class="sm-hinweis"><?= ww_t('EINST.TAKT_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="takt_ruhe"><?= ww_e(ww_t('EINST.L_TAKT_RUHE')) ?></label>
  <input data-role="none" type="number" id="takt_ruhe" name="takt_ruhe" value="<?= (int) $ww_cfg['takt_ruhe'] ?>" min="60" max="7200">
  <div class="sm-hilfe"><?= ww_t('EINST.H_TAKT_RUHE') ?></div>
</div>
<div class="sm-feld">
  <label for="takt_betrieb"><?= ww_e(ww_t('EINST.L_TAKT_BETRIEB')) ?></label>
  <input data-role="none" type="number" id="takt_betrieb" name="takt_betrieb" value="<?= (int) $ww_cfg['takt_betrieb'] ?>" min="60" max="3600">
  <div class="sm-hilfe"><?= ww_t('EINST.H_TAKT_BETRIEB') ?></div>
</div>
<div class="sm-feld">
  <label for="sprache"><?= ww_e(ww_t('EINST.L_SPRACHE')) ?></label>
  <select data-role="none" id="sprache" name="sprache">
    <option value="de-DE" <?= $ww_cfg['sprache'] === 'de-DE' ? 'selected' : '' ?>>de-DE</option>
    <option value="en-GB" <?= $ww_cfg['sprache'] === 'en-GB' ? 'selected' : '' ?>>en-GB</option>
    <option value="en-US" <?= $ww_cfg['sprache'] === 'en-US' ? 'selected' : '' ?>>en-US</option>
  </select>
  <div class="sm-hilfe"><?= ww_t('EINST.H_SPRACHE') ?></div>
</div>

<h2><?= ww_e(ww_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= ww_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty($ww_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= ww_e(ww_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $ww_cfg['wartezeit'] ?>" min="0" max="<?= (int) WW_WARTEN_WEB ?>">
  <div class="sm-hilfe"><?= ww_t('EINST.H_WARTEZEIT') ?></div>
</div>

<h2><?= ww_e(ww_t('ANSAGE.H')) ?></h2>
<?php $ww_tts = ww_tts(); ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ansage_ein" value="1" <?= !empty($ww_cfg['ansage_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('ANSAGE.L_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= ww_t('ANSAGE.H_EIN') ?></div>
</div>
<?php /* Je Ereignis ein eigener Haken, alle ab Werk aus. */ ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ansage_stoerung" value="1" <?= !empty($ww_cfg['ansage_stoerung']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('ANSAGE.L_STOERUNG')) ?>
  </label>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ansage_fernstart" value="1" <?= !empty($ww_cfg['ansage_fernstart']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('ANSAGE.L_FERNSTART')) ?>
  </label>
  <div class="sm-hilfe"><?= ww_t('ANSAGE.H_FERNSTART') ?></div>
</div>
<div class="sm-row">
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_RUHE_VON')) ?></label>
        <input data-role="none" type="text" name="ansage_ruhe_von" value="<?= ww_e($ww_cfg['ansage_ruhe_von']) ?>" placeholder="22:00">
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_RUHE_BIS')) ?></label>
        <input data-role="none" type="text" name="ansage_ruhe_bis" value="<?= ww_e($ww_cfg['ansage_ruhe_bis']) ?>" placeholder="07:00">
    </div>
    <div>
        <label>&nbsp;</label>
        <div class="sm-hilfe"><?= ww_t('ANSAGE.H_RUHE') ?></div>
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_AUSGABE')) ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="wwTtsMode()">
            <option value="musicserver"<?= $ww_tts['mode'] === 'musicserver' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_MUSICSERVER')) ?></option>
            <option value="ms4h"<?= $ww_tts['mode'] === 'ms4h' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_MS4H')) ?></option>
            <option value="audioserver"<?= $ww_tts['mode'] === 'audioserver' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_AUDIOSERVER')) ?></option>
            <option value="custom"<?= $ww_tts['mode'] === 'custom' ? ' selected' : '' ?>><?= ww_e(ww_t('ANSAGE.O_CUSTOM')) ?></option>
        </select>
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_IP')) ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= ww_e($ww_tts['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_PORT')) ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $ww_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_ZONEN')) ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= ww_e($ww_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sm-hilfe"><?= ww_t('ANSAGE.H_ZONEN') ?></div>
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_LAUTSTAERKE')) ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $ww_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?= ww_e(ww_t('ANSAGE.L_SPRACHE')) ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= ww_e($ww_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?= ww_e(ww_t('ANSAGE.L_VORLAGE')) ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= ww_e($ww_tts['template']) ?></textarea>
    <div class="sm-hilfe"><?= ww_t('ANSAGE.H_VORLAGE') ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-warnung" style="display:none;">
    <?= ww_t('ANSAGE.H_AUDIOSERVER') ?>
</div>

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
<tr><td><?= ww_e(ww_t('EINST.HC_GUELTIG')) ?></td><td><?= max(0, (int) $ww_hc_an['laeuft_ab'] - time()) ?> <?= ww_e(ww_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?php } else { ?>
<?= ww_t('EINST.HC_SCHRITT1') ?>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="anmelden" value="hc_start"><?= ww_e(ww_t('EINST.K_HC_START')) ?></button>
  </form>
  <form action="index.php" method="post">
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
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ww_sichern" value="1"><?= ww_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
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
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($ww_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= ww_e(ww_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= ww_e(ww_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= ww_e($ww_cfg['mqtt_topic']) ?>" placeholder="weissware">
  <div class="sm-hilfe"><?= ww_t('EINST.H_MQTT_TOPIC') ?></div>
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
<tr><th><?= ww_e(ww_t('MQTT.T_THEMA')) ?></th><th><?= ww_e(ww_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (ww_mqtt_themen() as $ww_thema => $ww_schluessel) { ?>
<tr><td><span class="sm-mono"><?= ww_e($ww_cfg['mqtt_topic'] . '/' . $ww_thema) ?></span></td>
    <td><?= ww_t($ww_schluessel) ?></td></tr>
<?php } ?>
</table>
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
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= ww_e($ww_vnr) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="status"><?= ww_e(ww_t('LOX.K_VORLAGE_STATUS')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= ww_e($ww_vnr) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="verbrauch"><?= ww_e(ww_t('LOX.K_VORLAGE_VERBRAUCH')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= ww_e($ww_vnr) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="ausgang"><?= ww_e(ww_t('LOX.K_VORLAGE_BEFEHLE')) ?></button>
  </form>
</div>
<?php } ?>
<p><b><?= ww_e(ww_t('LOX.VORLAGEN_MQTT')) ?></b></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
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
<?php foreach (ww_pruefungen() as $ww_z) { ?>
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
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= ww_e(ww_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= ww_e($ww_basis) ?>?token=<?= ww_e($ww_token) ?>&amp;aktion=roh" target="_blank"><?= ww_e(ww_t('TEST.K_ROH')) ?></a>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="300"><?= ww_e(ww_t('TEST.K_MITSCHNITT_EIN')) ?></button>
  </form>
<?php if (ww_mitschnitt_rest() > 0) { ?>
  <form action="index.php" method="post">
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
}
wwTtsMode();
</script>
<?php
if ($ww_rahmen) {
    LBWeb::lbfooter();
}
