<?php
/**
 * Proba de echivalență pentru băncile pe ZEC (D, E, F): z_pasOra față de
 * simularea din 15.11.2026 (cercetare/zec_singur.py), pe aceleași ore
 * (ZECUSDT, 2019-08 → 2026-11).
 *
 *     php motor/probe/zec-echivalenta.php <dosar-cu-datele-de-referință>
 *
 * Dosarul trebuie să conțină, generate de simulare:
 *   zec_echiv_ore.csv         ora, închiderea, semnalul simulării
 *   zec_echiv_referinta.json  rezultatele finale ale băncilor, pe cele două perioade
 * (pypy3 cercetare/zec_singur.py ore.json fng.json ZECUSDT BTCUSDT --echiv <dosar>)
 *
 * Datele nu stau în git. Două verificări, separate ca o cădere să spună unde e:
 *   1. semnalul PHP (b_evalueaza) = semnalul simulării, oră cu oră
 *   2. băncile PHP, hrănite cu semnalele PHP = rezultatele simulării
 * Ca în simulare: cumpărarea și vânzarea la închiderea orei, cost 0,125% pe parte.
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
$ore = []; $inch = []; $semSim = [];
$f = fopen("$dir/zec_echiv_ore.csv", 'r');
fgetcsv($f, null, ',', '"', '');
while (($r = fgetcsv($f, null, ',', '"', '')) !== false) {
    $ore[] = (int)$r[0]; $inch[] = (float)$r[1];
    $semSim[] = $r[2] === '' ? null : ($r[2] === '1');
}
fclose($f);
$ref = json_decode((string)file_get_contents("$dir/zec_echiv_referinta.json"), true);
$n = count($ore);
$idx = array_flip($ore);
printf("%d ore, %s → %s\n\n", $n, gmdate('d.m.Y', $ore[0] * 3600), gmdate('d.m.Y', $ore[$n - 1] * 3600));

/* ------------------------------------------------------------ 1. semnalul */
echo "=== 1. semnalul, oră cu oră ===\n";
$semPhp = array_fill(0, $n, false);
$comparate = 0; $diferite = 0; $pozitive = 0;
for ($i = B_INCHIDERI_NECESARE - 1; $i < $n; $i++) {
    $e = b_evalueaza(array_slice($inch, $i - B_INCHIDERI_NECESARE + 1, B_INCHIDERI_NECESARE));
    $semPhp[$i] = $e['semnal'];
    if ($semSim[$i] === null) { continue; }
    $comparate++;
    if ($e['semnal']) { $pozitive++; }
    if ($e['semnal'] !== $semSim[$i]) { $diferite++; }
}
rezultat('semnal PHP = semnal simulare', $diferite === 0, "$comparate ore, $pozitive semnale, $diferite diferențe");

/* ------------------------------------------------------------ 2. băncile */
$cost = (float)$ref['cost'];
foreach ($ref['perioade'] as $nume => $p) {
    echo "\n=== 2. băncile — $nume ===\n";
    $a = $idx[$p['de_la']]; $b = isset($idx[$p['pana_la']]) ? $idx[$p['pana_la']] : $n;
    foreach ($p['banci'] as $banca => $asteptat) {
        $s = z_stareNoua(1000.0);
        $tranz = 0; $exc = 0; $varf = 0.0; $cadere = 0.0; $minUsdc = INF; $v = 1000.0;
        for ($i = $a; $i < $b; $i++) {
            $c = $inch[$i];
            $inainte = $s['exc'];
            [$s, $t] = z_pasOra($banca, $s, $ore[$i], $semPhp[$i], ['eval' => $c, 'cump' => $c, 'vinde' => $c], $cost);
            $tranz += count($t);
            if ($s['exc']['activa'] && (!$inainte['activa'] || $s['exc']['expira'] !== $inainte['expira'])) { $exc++; }
            $v = z_valoare($s, $c);
            $varf = max($varf, $v);
            $cadere = max($cadere, 1 - $v / $varf);
            $minUsdc = min($minUsdc, $s['usdc']);
        }
        $ok = abs($v - $asteptat['final']) < 1e-6 * $asteptat['final']
           && abs($cadere - $asteptat['cadere']) < 1e-9
           && $tranz === $asteptat['tranzactii'] && $exc === $asteptat['exceptii'];
        rezultat("banca $banca", $ok, sprintf('final %.6f (sim %.6f) · cădere %.4f%% · %d tranz (sim %d) · %d exc (sim %d)',
            $v, $asteptat['final'], $cadere * 100, $tranz, $asteptat['tranzactii'], $exc, $asteptat['exceptii']));
        rezultat("banca $banca: USDC niciodată sub zero", $minUsdc >= -1e-9, sprintf('minim %.4f', $minUsdc));
    }
}

printf("\n%s\n", $caderi === 0 ? 'ECHIVALENT cu simularea.' : "$caderi verificări picate.");
exit($caderi === 0 ? 0 : 1);
