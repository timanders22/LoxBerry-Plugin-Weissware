<?php
/**
 * Weissware Cloud - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone und ohne Weissware-Konto, ob die
 * Einrichtung traegt. Was sich nur mit Geraet pruefen liesse, wird als
 * solches benannt statt geraten.
 */

/** Eine Zeile der Selbstpruefung. $stand: 1 = ja, 0 = nein, -1 = Hinweis. */
function ww_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

function ww_pruefungen()
{
    $p = ww_paths();
    $cfg = ww_config();
    $z = ww_zugang();
    $zeilen = array();

    $venv = $p['bindir'] . '/venv/bin/python3';
    $zeilen[] = ww_pruefzeile(is_file($venv) ? 1 : 0, ww_t('TEST.F_VENV'),
        is_file($venv) ? $venv : ww_t('TEST.A_VENV_FEHLT'));

    // requests verlangt Python 3.9 oder neuer. Das ist auf jedem
    // LoxBerry erfuellt, den es heute gibt (Debian 12 liefert 3.11) - die
    // Zeile bleibt trotzdem stehen, damit man es schwarz auf weiss hat.
    $pyv = ww_python_fassung();
    $pyok = 0;
    if ($pyv !== '') {
        $teile = explode('.', $pyv);
        $pyok = ((int) $teile[0] > 3 || ((int) $teile[0] === 3 && (int) $teile[1] >= 9)) ? 1 : 0;
    }
    $zeilen[] = ww_pruefzeile($pyv === '' ? 0 : $pyok, ww_t('TEST.F_PYTHON'),
        $pyv !== '' ? ww_e($pyv) . ($pyok ? '' : ' &mdash; ' . ww_t('TEST.A_PYTHON_ZU_ALT'))
                    : ww_t('TEST.A_PYTHON_UNBEKANNT'));

    $fassung = ww_bibliothek_fassung();
    $zeilen[] = ww_pruefzeile($fassung !== '' ? 1 : 0, ww_t('TEST.F_LIB'),
        $fassung !== '' ? 'requests ' . ww_e($fassung) : ww_t('TEST.A_LIB_FEHLT'));

    $pid = ww_dienst_pid();
    $zeilen[] = ww_pruefzeile($pid > 0 ? 1 : 0, ww_t('TEST.F_DIENST'),
        $pid > 0 ? ww_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (ww_dienst_soll() ? ww_t('TEST.A_DIENST_SOLL_TOT') : ww_t('TEST.A_DIENST_GESTOPPT')));

    // Je Anbieter eine Zeile - eingeschaltet, Zugangsdaten da, angemeldet.
    $anbieter = array(
        array('hc_ein', 'homeconnect', 'Home Connect', $z['hc_client_id'] !== '', 1),
        array('miele_ein', 'miele', 'Miele', $z['miele_client_id'] !== '', 1),
        array('st_ein', 'smartthings', 'SmartThings', $z['st_laenge'] > 0, 0),
    );
    $eins = 0;
    foreach ($anbieter as $a) {
        list($schalter, $schluessel, $titel, $hat_daten, $braucht_anmeldung) = $a;
        if (empty($cfg[$schalter])) {
            continue;
        }
        $eins++;
        $zeilen[] = ww_pruefzeile($hat_daten ? 1 : 0,
            sprintf(ww_t('TEST.F_ZUGANG'), $titel),
            $hat_daten ? ww_t('TEST.A_ZUGANG_DA') : ww_t('TEST.A_ZUGANG_FEHLT'));
        if ($braucht_anmeldung) {
            $an = ww_angemeldet($schluessel);
            $zeilen[] = ww_pruefzeile($an ? 1 : 0,
                sprintf(ww_t('TEST.F_ANGEMELDET'), $titel),
                $an ? ww_t('TEST.A_ANGEMELDET') : ww_t('TEST.A_NICHT_ANGEMELDET'));
        } else {
            $zeilen[] = ww_pruefzeile(-1, sprintf(ww_t('TEST.F_ANGEMELDET'), $titel),
                ww_t('TEST.A_ST_TOKEN'));
        }
    }
    $zeilen[] = ww_pruefzeile($eins > 0 ? 1 : 0, ww_t('TEST.F_ANBIETER'),
        $eins > 0 ? sprintf(ww_t('TEST.A_ANBIETER'), $eins) : ww_t('TEST.A_KEIN_ANBIETER'));

    $rechte = is_file($p['zugang']) ? (fileperms($p['zugang']) & 0777) : -1;
    $zeilen[] = ww_pruefzeile(($rechte >= 0 && ($rechte & 0077) === 0) ? 1 : 0,
        ww_t('TEST.F_RECHTE'),
        $rechte >= 0 ? '0' . decoct($rechte) : ww_t('TEST.A_ZUGANGSDATEI_FEHLT'));

    // In der Markendatei der Bibliothek stehen Anmeldemarken. Sie darf
    // niemandem sonst lesbar sein; die Bibliothek setzt die Rechte nicht
    // selbst, der Dienst holt es nach.
    $marke = $p['datadir'] . '/token.json';
    if (is_file($marke)) {
        $mr = fileperms($marke) & 0777;
        $zeilen[] = ww_pruefzeile(($mr & 0077) === 0 ? 1 : 0, ww_t('TEST.F_MARKE'),
            '0' . decoct($mr));
    } else {
        $zeilen[] = ww_pruefzeile(-1, ww_t('TEST.F_MARKE'), ww_t('TEST.A_MARKE_FEHLT'));
    }

    $geraete = ww_geraete();
    $zeilen[] = ww_pruefzeile(count($geraete) > 0 ? 1 : 0, ww_t('TEST.F_GERAETE'),
        count($geraete) > 0 ? sprintf(ww_t('TEST.A_GERAETE'), count($geraete))
                              : ww_t('TEST.A_KEINE_GERAETE'));

    // Ausgefallene Anbieter benennen, statt sie zu verschweigen. Ein Anbieter,
    // der schweigt, ist kein leeres Ergebnis - er ist ein Befund.
    $zu = ww_zustand();
    $aus = (isset($zu['ausfaelle']) && is_array($zu['ausfaelle'])) ? $zu['ausfaelle'] : array();
    if ($aus) {
        foreach ($aus as $anb => $grund) {
            $zeilen[] = ww_pruefzeile(0, sprintf(ww_t('TEST.F_AUSFALL'), ww_e($anb)),
                ww_e($grund));
        }
    } elseif ($geraete) {
        $zeilen[] = ww_pruefzeile(1, ww_t('TEST.F_AUSFAELLE'), ww_t('TEST.A_KEINE_AUSFAELLE'));
    }
    if ($aus) {
        $zeilen[] = ww_pruefzeile(0, ww_t('TEST.F_AUSFAELLE'), ww_e(implode(', ', $aus)));
    } elseif ($geraete) {
        $zeilen[] = ww_pruefzeile(1, ww_t('TEST.F_AUSFAELLE'), ww_t('TEST.A_KEINE_AUSFAELLE'));
    }

    $alter = ww_alter();
    if ($alter < 0) {
        $zeilen[] = ww_pruefzeile(0, ww_t('TEST.F_ABRUF'), ww_t('TEST.A_NIE_ABGERUFEN'));
    } else {
        $frisch = $alter <= max(600, 3 * (int) $cfg['intervall']);
        $zeilen[] = ww_pruefzeile($frisch ? 1 : 0, ww_t('TEST.F_ABRUF'),
            sprintf(ww_t('TEST.A_ABRUF_ALTER'), $alter));
    }

    $zu = ww_zustand();
    if (!empty($zu['fehler'])) {
        $zeilen[] = ww_pruefzeile(0, ww_t('TEST.F_LETZTER_FEHLER'), ww_e($zu['fehler']));
    }

    $m = ww_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = ww_pruefzeile(0, ww_t('TEST.F_MQTT'), ww_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = ww_pruefzeile(1, ww_t('TEST.F_MQTT'),
            ww_e($m['broker']) . ':' . ww_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = ww_pruefzeile(0, ww_t('TEST.F_MQTT'), ww_t('TEST.A_MQTT_AUS'));
    }

    $zeilen[] = ww_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, ww_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? ww_t('TEST.A_STEUERUNG_EIN') : ww_t('TEST.A_STEUERUNG_AUS'));

    return $zeilen;
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung) - stand wie bei ww_befehl_absetzen.
 */
function ww_test_aktion($aktion)
{
    $nr = isset($_POST['test_geraet']) ? (string) $_POST['test_geraet'] : '1';
    if (!preg_match('/^[0-9]{1,3}$/', $nr)) {
        return array(0, ww_t('TEST.M_GERAET_UNGUELTIG'));
    }
    if ($aktion === 'abruf') {
        return ww_befehl_absetzen(array('aktion' => 'abruf'), 15);
    }
    if (!in_array($aktion, array('start', 'stop', 'pause', 'fortsetzen', 'ein', 'aus'), true)) {
        return array(0, ww_t('TEST.M_UNBEKANNT'));
    }
    $b = array('aktion' => $aktion, 'geraet' => $nr);
    if ($aktion === 'start') {
        $prog = isset($_POST['test_programm']) ? trim((string) $_POST['test_programm']) : '';
        if ($prog !== '') {
            // Der Programmschluessel wird abgewiesen, wenn er nicht ins
            // Muster passt - nicht zurechtgebogen. Bei undurchsichtigen
            // Kennungen weiss niemand, welche Zeichen bedeutungstragend sind.
            if (!preg_match('/^[A-Za-z0-9](?!.*\.\.)[A-Za-z0-9._-]{0,79}$/', $prog)) {
                return array(0, ww_t('TEST.M_PROGRAMM_UNGUELTIG'));
            }
            $b['programm'] = $prog;
        }
    }
    return ww_befehl_absetzen($b);
}

