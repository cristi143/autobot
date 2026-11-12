<?php
/**
 * Proba de echivalență: regulile botului (bot-reguli.php) față de simularea în
 * Python din 12.11.2026, pe aceleași ore (2023-01 → 2026-11, date USDT).
 *
 *     php motor/probe/bot-echivalenta.php <dosar-cu-datele-de-referință>
 *
 * Dosarul trebuie să conțină, generate de simulare (gen_echiv.py):
 *   echiv_inchideri.csv   ora, închiderile orare ale celor trei monede
 *   echiv_semnale.csv     semnalul simulării, pe fiecare oră și monedă
 *   echiv_referinta.json  rezultatele finale ale băncilor A, B, C
 *
 * Datele nu stau în git (sunt derivate din historical_data/, 7.5 GB).
 * Trei verificări, separate ca o cădere să spună unde e greșeala:
 *   1. semnalele PHP = semnalele simulării, oră cu oră
 *   2. b_evalueaza (calea folosită de motor) = calculul pas cu pas, pe 300 de ore
 *   3. băncile PHP, hrănite cu semnalele simulării = rezultatele simulării
 */

declare(strict_types=1);
require __DIR__ . '/../bot-reguli.php';

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '' || !is_dir($dir)) { fwrite(STDERR, "Dă dosarul cu datele de referință.\n"); exit(2); }

$caderi = 0;
function rezultat(string $ce, bool $ok, string $detalii): void {
    global $caderi;
    if (!$ok) { $caderi++; }
    printf("  %-3s %-58s %s\n", $ok ? 'ok' : 'NU', $ce, $detalii);
}

/* ---------------------------------------------------------------- datele */
$inch = [];   // [moneda][ora] => close
$f = fopen("$dir/echiv_inchideri.csv", 'r');
$cap = fgetcsv($f, null, ',', '"', '');
while (($r = fgetcsv($f, null, ',', '"', '')) !== false) {
    $h = (int)$r[0];
    foreach (B_MONEDE as $k => $m) { $inch[$m][$h] = (float)$r[$k + 1]; }
}
fclose($f);
$semSim = [];  // [ora][moneda] => bool
$f = fopen("$dir/echiv_semnale.csv", 'r');
fgetcsv($f, null, ',', '"', '');
while (($r = fgetcsv($f, null, ',', '"', '')) !== false) {
    foreach (B_MONEDE as $k => $m) { $semSim[(int)$r[0]][$m] = $r[1 + 2 * $k] === '1'; }
}
fclose($f);
$ref = json_decode(file_get_contents("$dir/echiv_referinta.json"), true);
$ore = array_keys($semSim);
$h0 = $ore[0]; $h1 = end($ore);
printf("ore: %d (%s → %s UTC)\n", count($ore), gmdate('Y-m-d H:i', $h0 * 3600), gmdate('Y-m-d H:i', $h1 * 3600));

/* ---------------------------------------------------- 1. semnalele */
echo "\n=== 1. semnalele, oră cu oră ===\n";
$vol = [];
foreach (B_MONEDE as $m) {
    for ($h = $h0 - B_FEREASTRA_RANG; $h <= $h1; $h++) {
        $c = [];
        for ($k = $h - B_FEREASTRA_VOL; $k <= $h; $k++) { $c[] = $inch[$m][$k]; }
        $vol[$m][$h] = b_volatilitate($c);
    }
}
$semPhp = [];
foreach (B_MONEDE as $m) {
    $dif = 0; $nr = 0; $prima = null;
    foreach ($ore as $h) {
        $ant = [];
        for ($k = $h - B_FEREASTRA_RANG; $k < $h; $k++) { $ant[] = $vol[$m][$k]; }
        $s = b_semnal(b_rang($vol[$m][$h], $ant), $inch[$m][$h], $inch[$m][$h - 24]);
        $semPhp[$h][$m] = $s;
        if ($s) { $nr++; }
        if ($s !== $semSim[$h][$m]) { $dif++; $prima ??= $h; }
    }
    rezultat("$m: semnale identice", $dif === 0,
             "$nr ore-semnal, $dif diferențe" . ($prima ? ', prima la ' . gmdate('Y-m-d H:i', $prima * 3600) : ''));
}

/* ---------------------------------------------------- 2. b_evalueaza */
echo "\n=== 2. b_evalueaza (calea motorului) pe 300 de ore alese la întâmplare ===\n";
mt_srand(12112026);
foreach (B_MONEDE as $m) {
    $dif = 0;
    for ($i = 0; $i < 100; $i++) {
        $h = $ore[mt_rand(0, count($ore) - 1)];
        $c = [];
        for ($k = $h - B_INCHIDERI_NECESARE + 1; $k <= $h; $k++) { $c[] = $inch[$m][$k]; }
        $e = b_evalueaza($c);
        if ($e['semnal'] !== $semPhp[$h][$m] || abs($e['vol'] - $vol[$m][$h]) > 0) { $dif++; }
    }
    rezultat("$m: b_evalueaza = calculul pas cu pas", $dif === 0, "$dif diferențe din 100");
}

/* ---------------------------------------------------- 3. băncile */
echo "\n=== 3. băncile, hrănite cu semnalele simulării ===\n";
$com = 0.00075;
$A = b_stareNoua(1.0); $B = b_stareNoua(1.0);
$feeA = 0.0; $feeB = 0.0; $evB = 0; $nA = 0; $nB = 0;
$pretOra = function (int $h) use ($inch): array {
    $p = [];
    foreach (B_MONEDE as $m) { $x = $inch[$m][$h]; $p[$m] = ['eval' => $x, 'cump' => $x, 'vinde' => $x]; }
    return $p;
};
[$C, $tc] = b_pornestePasiv(1.0, $pretOra($h0), $com, false);
$feeC = array_sum(array_column($tc, 'comision'));
$hFinal = $ref['ora_final'] - 1;
for ($h = $h0; $h <= $hFinal; $h++) {
    $p = $pretOra($h);
    [$A, $t] = b_pasOra('A', $A, $h, [], $p, $com, false);
    foreach ($t as $x) { $feeA += $x['comision']; $nA++; }
    [$B, $t] = b_pasOra('B', $B, $h, $semSim[$h], $p, $com, false);
    foreach ($t as $x) {
        $feeB += $x['comision']; $nB++;
        if (str_starts_with($x['motiv'], 'semnal')) { $evB++; }
    }
}
$eval = [];
foreach (B_MONEDE as $m) { $eval[$m] = $inch[$m][$hFinal]; }
foreach ([['A', $A, $feeA], ['B', $B, $feeB], ['C', $C, $feeC]] as [$nume, $s, $fee]) {
    $tot = b_valoare($s, $eval) - 1;
    $r = $ref[$nume];
    $ok = abs($tot - $r['tot']) < 1e-9 * (1 + abs($r['tot'])) && abs($fee - $r['fees']) < 1e-9;
    rezultat("banca $nume: randament și comisioane = simularea", $ok,
             sprintf('PHP %+.6f%% / %.6f   simulare %+.6f%% / %.6f',
                     $tot * 100, $fee, $r['tot'] * 100, $r['fees']));
}
rezultat('banca B: număr de excepții = simularea', $evB === $ref['evenimente_B'],
         "PHP $evB, simulare {$ref['evenimente_B']}");
printf("  (tranzacții: A %d, B %d)\n", $nA, $nB);

/* ---------------------------------------------------- 4. cât schimbă „fără levier” */
echo "\n=== 4. aceleași bănci, cum rulează motorul (fără levier) — informativ ===\n";
$A2 = b_stareNoua(1.0); $B2 = b_stareNoua(1.0); $lim = 0; $neg = 0;
[$C2, ] = b_pornestePasiv(1.0, $pretOra($h0), $com);
for ($h = $h0; $h <= $hFinal; $h++) {
    $p = $pretOra($h);
    [$A2, ] = b_pasOra('A', $A2, $h, [], $p, $com);
    [$B2, $t] = b_pasOra('B', $B2, $h, $semSim[$h], $p, $com);
    foreach ($t as $x) { if (str_contains($x['motiv'], 'limitată')) { $lim++; } }
    if ($B2['usdc'] < -1e-12 || $A2['usdc'] < -1e-12) { $neg++; }
}
foreach ([['A', $A2, $A], ['B', $B2, $B], ['C', $C2, $C]] as [$nume, $s2, $s1]) {
    printf("  banca %s: %+.2f%% fără levier, față de %+.2f%% ca în simulare\n",
           $nume, (b_valoare($s2, $eval) - 1) * 100, (b_valoare($s1, $eval) - 1) * 100);
}
rezultat('fără levier: USDC nu coboară niciodată sub zero', $neg === 0, "$neg ore negative; $lim cumpărări limitate");

echo $caderi === 0 ? "\nTOTUL IDENTIC.\n" : "\n$caderi VERIFICĂRI PICATE.\n";
exit($caderi === 0 ? 0 : 1);
