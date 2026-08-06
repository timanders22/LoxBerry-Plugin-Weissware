<?php
/**
 * Weissware Cloud - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesende Aktionen:
 *   status    [&geraet=N]   Hauptwerte eines Geraets
 *   verbrauch [&geraet=N]   Energie, Wasser, Temperatur, Schleuderdrehzahl
 *   geraete                 Liste aller erkannten Geraete
 *   roh                     vollstaendiges Abbild als JSON (Fehlersuche)
 *
 * Schaltende Aktionen (nur wenn im Reiter Einstellungen zugelassen):
 *   start [&programm=<Schluessel>]   stop   pause   fortsetzen
 *   ein                              aus
 *   abruf                            sofortiger Abruf statt Warten auf den Takt
 *
 * Statt der laufenden Nummer darf ueberall auch die Kennung des Anbieters
 * stehen (haId bei Home Connect, fabNumber bei Miele, deviceId bei
 * SmartThings).
 *
 * Der Endpunkt spricht NIE selbst mit einem Anbieter. Lesende Aktionen
 * beantwortet er aus dem Zwischenspeicher, schaltende legt er in einer
 * Warteschlange ab, die der Dienst abarbeitet.
 *
 * Ein Strich als Wert bedeutet: dieser Wert liegt nicht vor. Es wird bewusst
 * keine 0 gesendet - eine 0 waere eine stille Falschaussage. Bei Miele ist das
 * besonders wichtig: dort heisst -32768 ausdruecklich "gerade kein Wert", und
 * 0 Minuten Restzeit hiesse "fertig".
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/ww_lib.php';
header('Content-Type: text/plain; charset=utf-8');

$ww_cfg = ww_config();
$ww_p = ww_paths();

/* ---------------- Token ---------------- */
$ww_soll = (string) $ww_cfg['aktionstoken'];
$ww_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($ww_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($ww_soll, $ww_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$ww_lesend = array('status', 'verbrauch', 'geraete', 'roh');
$ww_schaltend = array('start', 'stop', 'pause', 'fortsetzen', 'ein', 'aus', 'abruf');
$ww_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($ww_aktion, array_merge($ww_lesend, $ww_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($ww_lesend, $ww_schaltend)) . "\n";
    exit;
}

/* ---------------- Parameter pruefen ----------------
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen - ein still veraenderter Wert fuehrt zu einem
 * Geraet, das etwas anderes tut, als die Adresse sagt.
 */
function ww_param($name, $muster, $vorgabe = '')
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $vorgabe;
    }
    $w = (string) $_GET[$name];
    if (!preg_match($muster, $w)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        exit;
    }
    return $w;
}

// Die laufende Nummer oder die Kennung des Anbieters. Die Kennungen sind je
// nach Anbieter unterschiedlich lang und enthalten Bindestriche - deshalb ein
// weites, aber immer noch enges Muster: Buchstaben, Ziffern, Bindestrich,
// Unterstrich und Punkt, hoechstens 64 Zeichen.
// Muss mit einem Buchstaben oder einer Ziffer beginnen und darf keine zwei
// Punkte hintereinander enthalten. Der Wert wird zwar nur gegen eine Liste
// verglichen und nie in einen Pfad eingesetzt - aber ein Muster, das '..'
// durchlaesst, ist eine Einladung fuer den naechsten, der den Wert anders
// verwendet.
$ww_geraet   = ww_param('geraet', '/^[A-Za-z0-9](?!.*\.\.)[A-Za-z0-9._-]{0,63}$/', '1');
// Der Programmschluessel von Home Connect sieht aus wie
// LaundryCare.Washer.Program.Cotton - Punkte sind also zwingend erlaubt.
$ww_programm = ww_param('programm', '/^[A-Za-z0-9](?!.*\.\.)[A-Za-z0-9._-]{0,79}$/', '');

/* ---------------- Hilfsausgabe ---------------- */
function ww_w($v)
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

$ww_lox = ww_loxone();
$ww_alter = ww_alter();
$ww_ok = (!empty($ww_lox['ok']) && $ww_alter >= 0) ? 1 : 0;
$ww_alle = ww_geraete();

/** Findet das Geraet zur laufenden Nummer oder zur Kennung des Anbieters. */
function ww_waehlen($alle, $schluessel)
{
    if (isset($alle[$schluessel])) {
        return $alle[$schluessel];
    }
    foreach ($alle as $g) {
        if (isset($g['id']) && strcasecmp((string) $g['id'], (string) $schluessel) === 0) {
            return $g;
        }
    }
    return null;
}

/* ================= Lesende Aktionen ================= */

if ($ww_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($ww_lox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($ww_aktion === 'geraete') {
    echo 'GERAETE;OK=' . $ww_ok . ';N=' . count($ww_alle) . ';ALTER=' . $ww_alter . "\n";
    foreach ($ww_alle as $ww_nr => $ww_g) {
        echo $ww_nr . ';' . (isset($ww_g['anbieter']) ? $ww_g['anbieter'] : '') . ';'
           . (isset($ww_g['name']) ? $ww_g['name'] : '') . ';'
           . (isset($ww_g['typ']) ? $ww_g['typ'] : '') . ';'
           . (isset($ww_g['id']) ? $ww_g['id'] : '') . "\n";
    }
    exit;
}

$ww_f = ww_waehlen($ww_alle, $ww_geraet);

if (in_array($ww_aktion, array('status', 'verbrauch'), true) && $ww_f === null) {
    printf("%s;OK=0;GRUND=GERAET_UNBEKANNT;N=%d;ALTER=%d\n",
        strtoupper($ww_aktion), count($ww_alle), $ww_alter);
    exit;
}

/** Ein Wert aus dem Abbild, oder null. */
function ww_v($f, $name)
{
    return isset($f[$name]) ? $f[$name] : null;
}

if ($ww_aktion === 'status') {
    printf("WEISSWARE;OK=%d;ZUSTAND=%s;LAEUFT=%s;FERTIG=%s;VERBUNDEN=%s;TUER=%s;"
         . "FORTSCHR=%s;RESTMIN=%s;STARTMIN=%s;LAUFMIN=%s;FERNSTART=%s;FERNBED=%s;"
         . "NETZ=%s;ALTER=%d\n",
        $ww_ok,
        ww_w(ww_v($ww_f, 'zustand')), ww_w(ww_v($ww_f, 'laeuft')),
        ww_w(ww_v($ww_f, 'fertig')), ww_w(ww_v($ww_f, 'verbunden')),
        ww_w(ww_v($ww_f, 'tuer_offen')), ww_w(ww_v($ww_f, 'fortschritt')),
        ww_w(ww_v($ww_f, 'restzeit_min')), ww_w(ww_v($ww_f, 'startzeit_min')),
        ww_w(ww_v($ww_f, 'laufzeit_min')), ww_w(ww_v($ww_f, 'fernstart_frei')),
        ww_w(ww_v($ww_f, 'fernbedienung_frei')), ww_w(ww_v($ww_f, 'netz_ein')),
        $ww_alter);
    // Name, Zustandstext und Programm stehen in einer zweiten Zeile, damit die
    // erste fuer Loxone rein aus Zahlen besteht.
    echo 'TEXT;' . str_replace(array("\r", "\n", ';'), ' ',
        (string) ww_v($ww_f, 'name') . ' | ' . (string) ww_v($ww_f, 'zustand_text')
        . ' | ' . (string) ww_v($ww_f, 'programm_text')) . "\n";
    exit;
}

if ($ww_aktion === 'verbrauch') {
    printf("VERBRAUCH;OK=%d;ENERGIE=%s;WASSER=%s;TEMP=%s;SCHLEUDER=%s;ALTER=%d\n",
        $ww_ok,
        ww_w(ww_v($ww_f, 'energie_kwh')), ww_w(ww_v($ww_f, 'wasser_l')),
        ww_w(ww_v($ww_f, 'temperatur')), ww_w(ww_v($ww_f, 'schleuderdrehzahl')),
        $ww_alter);
    exit;
}

/* ================= Schaltende Aktionen ================= */

if ($ww_aktion !== 'abruf' && empty($ww_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n";
    exit;
}
if (ww_dienst_pid() === 0) {
    // Nicht stillschweigend einreihen: ohne laufenden Dienst passiert nichts,
    // und der Befehl laege bis zum naechsten Start in der Warteschlange.
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

$ww_befehl = array('aktion' => $ww_aktion, 'geraet' => $ww_geraet);
if ($ww_aktion === 'start' && $ww_programm !== '') {
    $ww_befehl['programm'] = $ww_programm;
}

list($ww_erg, $ww_meldung) = ww_befehl_absetzen($ww_befehl);
if ($ww_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $ww_erg, $ww_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $ww_meldung));
