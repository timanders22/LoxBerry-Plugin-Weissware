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

/**
 * Liest eine Python-Tabelle statisch aus bin/weissware.py.
 *
 * Was statisch liest, muss auch statisch vergleichen - deshalb wird hier
 * NICHT das laufende Python befragt, sondern die Datei gelesen.
 * Rueckgabe: Liste der Schluessel, oder null wenn die Datei fehlt.
 */
function ww_py_tabelle($anfang, $muster)
{
    $datei = ww_paths()['bindir'] . '/weissware.py';
    if (!is_file($datei)) {
        return null;
    }
    $q = (string) @file_get_contents($datei);
    $i = strpos($q, $anfang);
    if ($i === false) {
        return null;
    }
    $rest = substr($q, $i);
    /* Der FRUEHERE der beiden Endmarker gilt. Wer nur auf "\n)" prueft und ihn
     * nimmt, wenn er da ist, laeuft bei einem Verzeichnis (das auf "\n}"
     * endet) bis zur naechsten schliessenden Klammer irgendwo weiter unten -
     * und liest dann die halbe Datei mit. Aufgefallen an einer Prueffzeile,
     * die daraufhin Zustandsnamen als fehlende Vorgabewerte meldete: ein rotes
     * Kreuz, das nichts bedeutet. */
    $kandidaten = array();
    foreach (array("\n)", "\n}") as $marke) {
        $pos = strpos($rest, $marke);
        if ($pos !== false) {
            $kandidaten[] = $pos;
        }
    }
    if (!$kandidaten) {
        return null;
    }
    $ende = min($kandidaten);
    preg_match_all($muster, substr($rest, 0, $ende), $m);
    return $m[1];
}

function ww_pruefungen()
{
    $p = ww_paths();
    $cfg = ww_config();
    $z = ww_zugang();
    $zeilen = array();

    /* Erste Zeile: ein ECHTER Aufruf des eigenen Endpunkts. Alle uebrigen
     * Zeilen sehen sich Dateien an; nur diese spricht die Stelle an, an der
     * das Plugin bei getrennten Baeumen mit HTTP 500 stirbt, ohne dass es
     * jemand merkt. Drei Ausgaenge - "nicht feststellbar" ist ein Hinweis,
     * kein Kreuz. */
    $ep = ww_endpunkt_pruefen();
    $zeilen[] = ww_pruefzeile((int) $ep['stand'], ww_t('TEST.F_ENDPUNKT'),
        ww_e($ep['text']) . ($ep['alter'] > 0
            ? ' <span class="sm-hilfe">(' . sprintf(ww_t('TEST.A_EP_ALTER'), (int) $ep['alter']) . ')</span>'
            : ''));

    /* Oberflaeche und Dienst muessen dieselben Vorgabewerte fuehren. Ein
     * Schluessel, den nur eine Seite kennt, faellt sonst erst auf, wenn ein
     * Wert unerklaerlich auf die Werkseinstellung zurueckspringt. */
    $py_vorgaben = ww_py_tabelle('VORGABEN = {', '/"([a-z_]+)":/');
    if ($py_vorgaben === null) {
        $zeilen[] = ww_pruefzeile(-1, ww_t('TEST.F_VORGABEN'), ww_t('TEST.A_VORGABEN_UNLESBAR'));
    } else {
        $fehlen = array_diff($py_vorgaben, array_keys(ww_vorgaben()));
        $zeilen[] = ww_pruefzeile($fehlen ? 0 : 1, ww_t('TEST.F_VORGABEN'),
            $fehlen ? sprintf(ww_t('TEST.A_VORGABEN_FEHLEN'), ww_e(implode(', ', $fehlen)))
                    : sprintf(ww_t('TEST.A_VORGABEN_OK'), count($py_vorgaben)));
    }

    /* Themenliste gegen Sendecode. Bei Renault nannten Oberflaeche, Baustein-
     * Liste und Importdatei fuenf Themen, die der Sendecode nie
     * veroeffentlicht hat - der Anwender bekam virtuelle Eingaenge, die
     * dauerhaft auf 0 standen, ohne Fehlermeldung. */
    $py_felder = ww_py_tabelle('MQTT_FELDER = (', '/"([a-z_]+)"/');
    if ($py_felder === null) {
        $zeilen[] = ww_pruefzeile(-1, ww_t('TEST.F_THEMEN'), ww_t('TEST.A_THEMEN_UNLESBAR'));
    } else {
        $doku = array();
        foreach (array_keys(ww_mqtt_themen()) as $t) {
            if (strpos($t, 'geraetN/') === 0) {
                $doku[] = substr($t, strlen('geraetN/'));
            }
        }
        $ab = array_merge(array_diff($py_felder, $doku), array_diff($doku, $py_felder));
        $zeilen[] = ww_pruefzeile($ab ? 0 : 1, ww_t('TEST.F_THEMEN'),
            $ab ? sprintf(ww_t('TEST.A_THEMEN_AB'), ww_e(implode(', ', $ab)))
                : sprintf(ww_t('TEST.A_THEMEN_OK'), count($py_felder)));
    }

    /* Reiterleiste, Bereiche und Positivliste fuehren dieselben Namen.
     * Fehlt einer in der Positivliste, ist der Reiter sichtbar und anklickbar
     * - aber nach jedem Absenden springt die Seite zurueck auf Einstellungen.
     * Die Leiste steht ausgeschrieben da, damit hausstandard_pruefen.py sie
     * sieht; diese Zeile misst nach, dass die drei Stellen zusammenpassen. */
    $eig = @file_get_contents(__DIR__ . '/index.php');
    if ($eig === false) {
        $zeilen[] = ww_pruefzeile(-1, ww_t('TEST.F_REITER'), ww_t('TEST.A_REITER_UNLESBAR'));
    } else {
        preg_match_all('/data-ziel="tab-([a-z0-9]+)"/', $eig, $mL);
        preg_match_all('/class="sm-seite[^"]*"[^>]*id="tab-([a-z0-9]+)"/', $eig, $mB);
        // Die Positivliste steht als erzeugtes Muster in der Datei.
        preg_match('/\$ww_reiter_ids\s*=\s*array\(([^)]*)\)/', $eig, $mP);
        preg_match_all("/'tab-([a-z0-9]+)'/", isset($mP[1]) ? $mP[1] : '', $mPl);
        $leiste = $mL[1];
        $flaechen = $mB[1];
        $liste = $mPl[1];
        sort($leiste); sort($flaechen); sort($liste);
        $gleich = ($leiste === $flaechen && $leiste === $liste && $leiste !== array());
        $zeilen[] = ww_pruefzeile($gleich ? 1 : 0, ww_t('TEST.F_REITER'),
            $gleich ? sprintf(ww_t('TEST.A_REITER_OK'), count($leiste))
                    : sprintf(ww_t('TEST.A_REITER_AB'), count($leiste),
                              count($flaechen), count($liste)));
    }

    /* Vorlage und Statuszeile fuehren dieselben Feldnamen.
     * Die Statuszeile ist ein von Hand geschriebenes printf, die Vorlage
     * entsteht aus ww_status_felder() - zwei Stellen, die auseinanderlaufen
     * koennen. Bei der Waermepumpe legte die Vorlage 20 Eingaenge an, die
     * Zeile lieferte 17, und die drei fehlenden standen dauerhaft auf 0. */
    $ep_datei = dirname(__DIR__) . '/html/index.php';
    $ep_q = is_file($ep_datei) ? (string) @file_get_contents($ep_datei) : '';
    if ($ep_q === '') {
        $zeilen[] = ww_pruefzeile(-1, ww_t('TEST.F_FELDER'), ww_t('TEST.A_FELDER_UNLESBAR'));
    } else {
        // Nur die Formatzeichenkette der Statuszeile ansehen, nicht die Datei.
        $zeile = '';
        if (preg_match('/printf\("(WEISSWARE;.*?)\\\\n"/s', $ep_q, $mZ)) {
            $zeile = preg_replace('/"\s*\.\s*"/', '', $mZ[1]);
        }
        preg_match_all('/([A-Z]+)=%/', $zeile, $mF);
        $inZeile = $mF[1];
        $inVorlage = array_keys(ww_status_felder());
        $fehlt = array_merge(array_diff($inVorlage, $inZeile), array_diff($inZeile, $inVorlage));
        $zeilen[] = ww_pruefzeile($zeile === '' ? -1 : ($fehlt ? 0 : 1), ww_t('TEST.F_FELDER'),
            $zeile === '' ? ww_t('TEST.A_FELDER_UNLESBAR')
                : ($fehlt ? sprintf(ww_t('TEST.A_FELDER_AB'), ww_e(implode(', ', $fehlt)))
                          : sprintf(ww_t('TEST.A_FELDER_OK'), count($inVorlage))));

        /* Jedes Suchmuster ist eindeutig. Loxone sucht woertlich und nimmt den
         * ERSTEN Treffer: steckt 'ALTER=' auch in 'SOLLALTER=', bekommt der
         * Baustein den falschen Wert. Geprueft wird paarweise. */
        $doppelt = array();
        foreach ($inVorlage as $a) {
            foreach ($inVorlage as $b) {
                if ($a !== $b && strpos($b . '=', $a . '=') !== false) {
                    $doppelt[] = $a . ' in ' . $b;
                }
            }
        }
        $zeilen[] = ww_pruefzeile($doppelt ? 0 : 1, ww_t('TEST.F_MUSTER'),
            $doppelt ? sprintf(ww_t('TEST.A_MUSTER_AB'), ww_e(implode(', ', $doppelt)))
                     : sprintf(ww_t('TEST.A_MUSTER_OK'), count($inVorlage)));
    }

    /* Gibt es eine Zweitschrift? Sie liegt NEBEN dem Konfigordner, damit sie
     * ein Update und sogar eine Neuinstallation uebersteht. */
    $zw = is_file($p['sicherung']);
    $zeilen[] = ww_pruefzeile($zw ? 1 : -1, ww_t('TEST.F_ZWEITSCHRIFT'),
        $zw ? sprintf(ww_t('TEST.A_ZWEITSCHRIFT_DA'), ww_e(basename($p['sicherung'])))
            : ww_t('TEST.A_ZWEITSCHRIFT_FEHLT'));

    // Laeuft gerade ein Mitschnitt? Ein Hinweis, kein Befund - aber er darf
    // nicht unbemerkt weiterlaufen.
    $rest = ww_mitschnitt_rest();
    if ($rest > 0) {
        $zeilen[] = ww_pruefzeile(-1, ww_t('TEST.F_MITSCHNITT'),
            sprintf(ww_t('TEST.A_MITSCHNITT_LAEUFT'), $rest));
    }

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

    /* Angemeldet UND eingeschaltet sind zwei Dinge. Wer sich anmeldet und den
     * Haken vergisst, sieht in den Zeilen darueber nichts davon - die Schleife
     * oben ueberspringt ausgeschaltete Anbieter -, und der Dienst weigert sich
     * zu starten mit "kein Anbieter eingeschaltet". Diese Zeile nennt den Fall
     * beim Namen. Ein Hinweis, kein Kreuz: es kann Absicht sein. */
    $stumm = array();
    foreach (array('homeconnect' => 'hc_ein', 'miele' => 'miele_ein') as $ww_a => $ww_s) {
        if (empty($cfg[$ww_s]) && ww_angemeldet($ww_a)) {
            $stumm[] = $ww_a;
        }
    }
    if ($stumm) {
        $zeilen[] = ww_pruefzeile(-1, ww_t('TEST.F_ANGEMELDET_AUS'),
            sprintf(ww_t('TEST.A_ANGEMELDET_AUS'), ww_e(implode(', ', $stumm))));
    }

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
    /* Hier stand derselbe Block ein zweites Mal. Ohne Ausfaelle erschien die
     * Zeile "keine Ausfaelle" doppelt; mit Ausfaellen wurden sie erst einzeln
     * je Anbieter und danach noch einmal gesammelt aufgefuehrt. Die
     * Einzelaufstellung oben ist die brauchbarere - sie nennt den Grund. */

    $alter = ww_alter();
    if ($alter < 0) {
        $zeilen[] = ww_pruefzeile(0, ww_t('TEST.F_ABRUF'), ww_t('TEST.A_NIE_ABGERUFEN'));
    } else {
        /* Bis 0.9.6 stand hier $cfg['intervall'] - einen solchen Schluessel
         * kennt weder ww_vorgaben() noch VORGABEN in bin/weissware.py. Der
         * Ausdruck wurde damit zu 0, die Schwelle lag unabhaengig vom
         * eingestellten Takt immer bei 600 s. Wer den Ruhetakt hoeher stellt,
         * bekam ein rotes Kreuz, das nichts bedeutet - und sucht dann dort.
         * Massgeblich ist der Ruhetakt: laeuft nichts, wird in diesem Abstand
         * abgerufen, und ein Abbild darf entsprechend alt sein. */
        /* DIESELBE Grenze wie der Waechter in bin/dienst.sh, aus DERSELBEN
         * Funktion. Bis 0.9.11 stand hier eine zweite Formel (dreifacher
         * Takt, mindestens 600 s), waehrend der Waechter mit dem fuenffachen
         * rechnete: die Zeile konnte gruen sein, waehrend der Waechter den
         * Dienst neu startete - und umgekehrt.
         *
         * Und dieser Aufruf ist der Grund, warum ww_wache_grenze() sichtbar
         * benutzt wird. Ein Helfer, den nur ein Shell-Skript ruft, sieht fuer
         * jedes Suchwerkzeug tot aus. Genau so ist er in 0.9.11 entfernt
         * worden.
         */
        $grenze = ww_wache_grenze();
        $frisch = $alter <= $grenze;
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

