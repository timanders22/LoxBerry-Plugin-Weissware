<?php
/**
 * Weissware - Ansage-Pruefung, minuetlich vom Cron aufgerufen.
 *
 * Nur ueber die PHP-Kommandozeile gedacht (siehe cron/cron.01min); im
 * HTML-Verzeichnis laege der Aufruf fuer jeden im Heimnetz frei.
 * Die eigentliche Arbeit steht in ww_ansage_check() (ww_lib.php).
 *
 * REPLACELBPHTMLDIR ersetzt LoxBerry bei der Installation durch den
 * Plugin-HTML-Pfad; laeuft dieses Skript aus dem ausgepackten Archiv
 * heraus, steht die Marke noch da, und der Pfad wird relativ gebildet
 * (dasselbe Muster wie bin/cron.php bei FerienFeiertage).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$ww_htmldir = 'REPLACELBPHTMLDIR';
if (strpos($ww_htmldir, 'REPLACE') === 0 || !is_file($ww_htmldir . '/ww_lib.php')) {
    $ww_htmldir = dirname(__DIR__) . '/webfrontend/html';
}
if (!is_file($ww_htmldir . '/ww_lib.php')) {
    fwrite(STDERR, "ww_lib.php nicht gefunden (gesucht in $ww_htmldir)\n");
    exit(1);
}
require_once $ww_htmldir . '/ww_lib.php';
/* Nur ein Lauf gleichzeitig (Muster FerienFeiertage): der TTS-Aufruf wartet
 * bis zu 10 s - der naechste Minutenlauf soll nicht hineinlaufen und die
 * Ansage doppelt sprechen. */
$ww_lock = ww_sperre('ansage');
if ($ww_lock === false) {
    exit(0);
}
/* 0.9.53 (Regeln/05): fehlende Einstellungen einmal mit ihrer Vorgabe in weissware.json eintragen - nach der Sperre,
 * vor dem ersten Lesen der Konfiguration (ww_ansage_check()); nicht waehrend einer Aktualisierung (Marke, siehe
 * ww_config_vervollstaendigen()). Schreibt nur, wenn etwas fehlt. Ein Fehler hier haelt die Ansage nicht auf. */
try {
    ww_config_vervollstaendigen();
} catch (Throwable $ww_vv) {
    ww_log_wenn_neu('vervollstaendigen', 'Konfiguration vervollstaendigen abgebrochen (' . get_class($ww_vv) . ').');
}
ww_ansage_check();
