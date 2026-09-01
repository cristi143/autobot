<?php
/**
 * Diagnostic pentru API — TEMPORAR, se șterge după ce totul merge.
 *
 * Nu include _comun.php și nu folosește nimic din restul codului: dacă acela e
 * de vină, fișierul ăsta trebuie totuși să poată vorbi. De aceea nu are
 * `declare(strict_types)`, nu are funcții cu tipuri moderne și își prinde singur
 * erorile fatale.
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$pasi = array();
function pas($eticheta, $ok, $detaliu) {
    global $pasi;
    $pasi[] = sprintf("%-3s %-38s %s", $ok ? 'OK' : '!!', $eticheta, $detaliu);
}

// Dacă tot se prăbușește, măcar spunem unde.
register_shutdown_function(function () {
    global $pasi;
    $e = error_get_last();
    echo "=== DIAGNOSTIC API AUTOBOT ===\n\n" . implode("\n", $pasi) . "\n";
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_COMPILE_ERROR), true)) {
        echo "\n!! EROARE FATALĂ:\n";
        echo "   " . $e['message'] . "\n";
        echo "   în " . $e['file'] . ", linia " . $e['line'] . "\n";
    }
});

/* --- 1. PHP --- */
pas('Versiune PHP', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION);

/* --- 2. Fișierul de configurare --- */
$cale = '/home/marcelpa/autobot-config.php';
$exista = file_exists($cale);
pas('Există autobot-config.php', $exista, $exista ? $cale : 'LIPSEȘTE la ' . $cale);

if (!$exista) { exit; }

$citibil = is_readable($cale);
pas('Se poate citi', $citibil, $citibil ? 'da' : 'permisiuni greșite — pune 0644');
if (!$citibil) { exit; }

$brut = file_get_contents($cale);
pas('Începe cu <?php', substr(ltrim($brut), 0, 5) === '<?php',
    'primele caractere: ' . substr(ltrim($brut), 0, 12));

// Ghilimelele „inteligente" din editoare strică fișierul fără să pară nimic
$curbe = preg_match('/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', $brut);
pas('Fără ghilimele curbe', !$curbe,
    $curbe ? 'GĂSITE — înlocuiește \u{201C}\u{201D}\u{2018}\u{2019} cu \' simplu' : 'da');

/* --- 3. Se parsează? --- */
$config = @include $cale;
pas('Se încarcă fără eroare', is_array($config),
    is_array($config) ? 'a întors un tablou' : 'NU a întors un tablou (vezi eroarea de mai jos)');
if (!is_array($config)) { exit; }

foreach (array('db', 'reguli', 'cheie_api') as $cheie) {
    pas("Conține „$cheie\"", isset($config[$cheie]), isset($config[$cheie]) ? 'da' : 'lipsește');
}
if (!isset($config['db'])) { exit; }

foreach (array('gazda', 'nume', 'user', 'parola') as $c) {
    $are = isset($config['db'][$c]) && $config['db'][$c] !== '';
    $val = $c === 'parola'
        ? ($are ? '(pusă, ' . strlen($config['db'][$c]) . ' caractere)' : 'GOALĂ')
        : ($are ? $config['db'][$c] : 'lipsește');
    pas("db.$c", $are, $val);
}

$cheie_ok = isset($config['cheie_api']) && strlen((string)$config['cheie_api']) >= 20
            && strpos((string)$config['cheie_api'], 'ȘIR-LUNG') === false;
pas('cheie_api completată', $cheie_ok, $cheie_ok ? 'da' : 'lipsă sau lăsată ca model');

/* --- 4. Extensia PDO --- */
pas('Extensia pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'da' : 'LIPSEȘTE');
if (!extension_loaded('pdo_mysql')) { exit; }

/* --- 5. Conexiunea --- */
$d = $config['db'];
try {
    $pdo = new PDO("mysql:host={$d['gazda']};dbname={$d['nume']};charset=utf8mb4",
                   $d['user'], $d['parola'],
                   array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    pas('Conexiune la baza de date', true, 'reușită');
} catch (Exception $e) {
    pas('Conexiune la baza de date', false, $e->getMessage());
    exit;
}

/* --- 6. Tabelele --- */
$asteptate = array('lumanari_1h','triunghiuri','linii','semnale','pozitii','banci','miscari','jurnal_cron');
$gasite = array();
foreach ($pdo->query('SHOW TABLES') as $r) { $gasite[] = array_values($r)[0]; }
$lipsa = array_diff($asteptate, $gasite);
pas('Cele 8 tabele', count($lipsa) === 0,
    count($lipsa) === 0 ? 'toate există' : 'lipsesc: ' . implode(', ', $lipsa));

/* --- 7. Băncile --- */
try {
    $b = $pdo->query('SELECT banca, sold_usdc FROM banci')->fetchAll(PDO::FETCH_ASSOC);
    $t = array();
    foreach ($b as $x) { $t[] = $x['banca'] . '=' . $x['sold_usdc']; }
    pas('Băncile inițializate', count($b) === 2, implode('  ', $t));
} catch (Exception $e) {
    pas('Băncile inițializate', false, $e->getMessage());
}

/* --- 8. Fișierele API --- */
foreach (array('_comun.php', 'triunghiuri.php', 'stare.php') as $f) {
    $c = __DIR__ . '/' . $f;
    pas("Există $f", file_exists($c), file_exists($c) ? 'da' : 'LIPSEȘTE — n-a ajuns la deploy');
}
