<?php
/**
 * Verificare de mediu — răspunde la întrebarea care contează:
 * poate serverul ăsta să ruleze motorul de tranzacționare?
 *
 * TEMPORAR. Șterge fișierul după ce ai citit rezultatul (vezi ultima secțiune).
 */

header('Content-Type: text/html; charset=utf-8');

function rand_rez(string $eticheta, bool $ok, string $detaliu = ''): string {
    $c = $ok ? 'da' : 'nu';
    $s = $ok ? 'DA' : 'NU';
    $d = $detaliu !== '' ? '<span class="det">' . htmlspecialchars($detaliu) . '</span>' : '';
    return "<tr><td>{$eticheta}</td><td class=\"{$c}\">{$s}</td><td>{$d}</td></tr>";
}

$randuri = [];

/* --- 1. PHP --- */
$php_ok = version_compare(PHP_VERSION, '8.0', '>=');
$randuri[] = rand_rez('Versiune PHP', $php_ok, PHP_VERSION . '  (' . PHP_SAPI . ')');

/* --- 2. Extensii --- */
$necesare = [
    'curl'     => 'cere date de la Binance',
    'openssl'  => 'conexiuni https',
    'json'     => 'citește răspunsurile Binance',
    'pdo_mysql'=> 'baza de date',
    'hash'     => 'semnătura pentru ordine reale (etapa 5)',
];
foreach ($necesare as $ext => $lascecefoloseste) {
    $randuri[] = rand_rez("Extensia <code>{$ext}</code>", extension_loaded($ext), $lascecefoloseste);
}

/* --- 3. Chiar poate ajunge la Binance? --- */
$binance_ok = false; $binance_det = 'netestat'; $lumanare = null;
if (extension_loaded('curl')) {
    $ch = curl_init('https://api.binance.com/api/v3/klines?symbol=ZECUSDC&interval=1h&limit=2');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raspuns = curl_exec($ch);
    $cod     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $eroare  = curl_error($ch);
    curl_close($ch);

    if ($raspuns !== false && $cod === 200) {
        $k = json_decode($raspuns, true);
        if (is_array($k) && count($k) >= 2) {
            $binance_ok  = true;
            $lumanare    = $k[0];   // penultima = ultima ÎNCHISĂ
            $binance_det = 'HTTP 200, ' . strlen($raspuns) . ' octeți';
        } else {
            $binance_det = 'răspuns neașteptat';
        }
    } else {
        $binance_det = $eroare !== '' ? $eroare : "HTTP {$cod}";
    }
}
$randuri[] = rand_rez('Conexiune la Binance', $binance_ok, $binance_det);

/* --- 4. Ceasul serverului (cronul depinde de el) --- */
$acum = new DateTime('now', new DateTimeZone('UTC'));
$randuri[] = rand_rez('Ceasul serverului', true,
    $acum->format('Y-m-d H:i:s') . ' UTC  ·  fus local: ' . date_default_timezone_get());

/* --- 5. IP-ul de ieșire — va trebui pe lista albă a cheii Binance --- */
$ip = 'necunoscut';
if (extension_loaded('curl')) {
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $r = curl_exec($ch);
    curl_close($ch);
    if ($r) { $ip = trim($r); }
}
$randuri[] = rand_rez('IP-ul de ieșire al serverului', $ip !== 'necunoscut', $ip);

$toate_ok = $php_ok && $binance_ok
    && extension_loaded('curl') && extension_loaded('openssl')
    && extension_loaded('json') && extension_loaded('pdo_mysql');
?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Verificare mediu — autobot</title>
<style>
  :root { --bg:#fff; --ink:#15202b; --muted:#5f6f7c; --line:#e3e9ee; --da:#17795c; --nu:#b4413a; --sunken:#f2f6f8; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#141b22; --ink:#e3eaf1; --muted:#8a9aa8; --line:#26313a; --da:#3fb98a; --nu:#e0685f; --sunken:#1b242c; }
  }
  * { box-sizing:border-box; }
  body { margin:0; padding:2rem 1.25rem 4rem; background:var(--bg); color:var(--ink);
         font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; line-height:1.6; }
  main { max-width:44rem; margin:0 auto; }
  h1 { font-size:1.4rem; margin:0 0 .35rem; }
  .sub { color:var(--muted); margin:0 0 1.75rem; font-size:.9375rem; }
  .verdict { padding:1rem 1.15rem; border:1px solid var(--line); border-left:3px solid; border-radius:3px; margin-bottom:1.75rem; }
  .verdict.bun  { border-left-color:var(--da); }
  .verdict.prost{ border-left-color:var(--nu); }
  .verdict b { display:block; margin-bottom:.25rem; }
  table { width:100%; border-collapse:collapse; font-size:.9375rem; }
  th, td { padding:.6rem .7rem; text-align:left; border-bottom:1px solid var(--line); vertical-align:top; }
  th { font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); background:var(--sunken); }
  td.da { color:var(--da); font-weight:600; } td.nu { color:var(--nu); font-weight:600; }
  .det, code { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.85em; color:var(--muted); }
  code { background:var(--sunken); padding:.1em .35em; border-radius:3px; color:inherit; }
  .lum { margin-top:1.5rem; padding:.9rem 1.1rem; background:var(--sunken); border-radius:3px; font-size:.9375rem; }
  .nota { margin-top:2rem; padding-top:1.25rem; border-top:1px solid var(--line); color:var(--muted); font-size:.875rem; }
</style>
</head>
<body>
<main>
  <h1>Verificare mediu</h1>
  <p class="sub">Poate serverul ăsta rula motorul de tranzacționare?</p>

  <?php if ($toate_ok): ?>
    <div class="verdict bun">
      <b>Totul e în regulă.</b>
      Serverul are ce-i trebuie și ajunge la Binance. Mai rămâne un singur lucru de
      verificat manual, care nu se poate testa de aici: existența secțiunii
      <strong>Cron Jobs</strong> în cPanel (secțiunea Advanced).
    </div>
  <?php else: ?>
    <div class="verdict prost">
      <b>Ceva lipsește.</b>
      Vezi rândurile marcate cu NU mai jos și spune-mi care sunt.
    </div>
  <?php endif; ?>

  <table>
    <thead><tr><th>Ce am verificat</th><th>Rezultat</th><th>Detalii</th></tr></thead>
    <tbody><?= implode("\n", $randuri) ?></tbody>
  </table>

  <?php if ($lumanare): ?>
    <div class="lum">
      <strong>Ultima lumânare închisă de 1h, luată chiar acum de server:</strong><br>
      <span class="det">
        <?= gmdate('Y-m-d H:i', (int)($lumanare[0] / 1000)) ?> UTC ·
        O <?= htmlspecialchars($lumanare[1]) ?> ·
        H <?= htmlspecialchars($lumanare[2]) ?> ·
        L <?= htmlspecialchars($lumanare[3]) ?> ·
        C <?= htmlspecialchars($lumanare[4]) ?>
      </span><br>
      Asta e exact operațiunea pe care o va face cronul în fiecare oră.
    </div>
  <?php endif; ?>

  <p class="nota">
    <strong>Șterge pagina asta după ce ai citit-o.</strong> Nu conține secrete, dar
    arată versiunea de PHP și IP-ul serverului, care n-au de ce să stea public.
    Din File Manager: șterge <code>verificare.php</code> din document root. Oricum
    dispare la următorul deploy după ce o scot din repo.
  </p>
</main>
</body>
</html>
