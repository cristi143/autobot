<?php
/**
 * Probele botului automat. Fără bază de date, fără rețea:
 *
 *     php motor/probe/bot-matematica.php
 *
 * Cazuri mici, socotite de mână. Echivalența cu simularea pe 31 744 de ore e
 * proba separată, bot-echivalenta.php.
 */

declare(strict_types=1);
require __DIR__ . '/../bot-reguli.php';

$treceri = 0; $caderi = 0;
function verifica(string $ce, $obtinut, $asteptat, float $toleranta = 1e-9): void {
    global $treceri, $caderi;
    $exact = is_bool($asteptat) || is_string($asteptat) || $asteptat === null || is_int($asteptat);
    $ok = $exact ? ($obtinut === $asteptat) : (abs($obtinut - $asteptat) < $toleranta);
    $ok ? $treceri++ : $caderi++;
    $text = is_bool($obtinut) ? ($obtinut ? 'true' : 'false')
          : ($obtinut === null ? 'null' : (is_string($obtinut) || is_int($obtinut) ? (string)$obtinut
          : rtrim(rtrim(sprintf('%.6f', $obtinut), '0'), '.')));
    printf("  %-3s %-60s obținut %s\n", $ok ? 'ok' : 'NU', $ce, $text);
}
function pret(float $x, ?float $cump = null, ?float $vinde = null): array {
    $p = [];
    foreach (B_MONEDE as $m) { $p[$m] = ['eval' => $x, 'cump' => $cump ?? $x, 'vinde' => $vinde ?? $x]; }
    return $p;
}
const C = 0.00075;
[$BTC, $ETH, $ZEC] = B_MONEDE;

/* ========================================================================= */
echo "=== 1. volatilitatea ===\n";
verifica('prețuri constante -> 0', b_volatilitate(array_fill(0, 25, 100.0)), 0.0);
$alt = [];
for ($i = 0; $i < 25; $i++) { $alt[] = $i % 2 ? 110.0 : 100.0; }
// randamente ±ln(1,1), media 0 -> abaterea = ln(1,1)
verifica('100/110 alternativ -> ln(1,1) = 0,0953102', b_volatilitate($alt), log(1.1));
try { b_volatilitate([1.0, 2.0]); verifica('2 închideri -> refuz', false, true); }
catch (InvalidArgumentException $e) { verifica('2 închideri -> refuz', true, true); }

/* ========================================================================= */
echo "\n=== 2. rangul: doar valorile STRICT mai mici ===\n";
verifica('3 față de [1,2,3,4] -> 0,5', b_rang(3.0, [1.0, 2.0, 3.0, 4.0]), 0.5);
verifica('egalitățile nu se numără: 2 față de [2,2,2] -> 0', b_rang(2.0, [2.0, 2.0, 2.0]), 0.0);
verifica('cea mai mare -> 1', b_rang(9.0, [1.0, 2.0]), 1.0);

/* ========================================================================= */
echo "\n=== 3. semnalul de panică ===\n";
verifica('rang 0,8 și preț în scădere -> semnal', b_semnal(0.8, 99, 100), true);
verifica('rang 0,79 -> nimic', b_semnal(0.79, 99, 100), false);
verifica('preț egal cu acum 24 h -> nimic (scădere strictă)', b_semnal(0.95, 100, 100), false);
verifica('agitat dar în creștere -> nimic', b_semnal(0.99, 101, 100), false);

/* ========================================================================= */
echo "\n=== 4. țintele ===\n";
verifica('A -> 1/6', b_tinta('A', false), 1 / 6);
verifica('A ignoră excepția -> 1/6', b_tinta('A', true), 1 / 6);
verifica('B fără excepție -> 1/6', b_tinta('B', false), 1 / 6);
verifica('B cu excepție -> 1/3', b_tinta('B', true), 1 / 3);

/* ========================================================================= */
echo "\n=== 5. când se tranzacționează ===\n";
verifica('abatere 4% fără eveniment -> nu', b_trebuie(40, 1000, false), false);
verifica('abatere 6% -> da', b_trebuie(-60, 1000, false), true);
verifica('abatere 0,1% la eveniment -> da', b_trebuie(1, 1000, true), true);
verifica('abatere zero la eveniment -> nu', b_trebuie(0, 1000, true), false);

/* ========================================================================= */
echo "\n=== 6. prima oră a băncii A: 1/6 din fiecare monedă ===\n";
[$A, $t] = b_pasOra('A', b_stareNoua(1000), 10, [], pret(100), C);
verifica('trei cumpărări', count($t), 3);
verifica('BTC: 1000/6/100 = 1,666667', $A['q'][$BTC], 1000 / 6 / 100);
verifica('comision pe o cumpărare: 166,67 × 0,075% = 0,125', $t[0]['comision'], 0.125);
verifica('USDC rămas: 1000 − 500 − 0,375 = 499,625', $A['usdc'], 499.625);
[$A2, $t] = b_pasOra('A', $A, 11, [$ZEC => true], pret(100), C);
verifica('A ignoră semnalele: nicio tranzacție', count($t), 0);

echo "  -- banda: ZEC dublează, abaterea depășește 5% --\n";
$p = pret(100); $p[$ZEC] = ['eval' => 200, 'cump' => 200, 'vinde' => 200];
[$A3, $t] = b_pasOra('A', $A, 12, [], $p, C);
// capital = 499,625 + 2×166,667 + 333,333 = 1166,29; ținta ZEC = 194,38, are 333,33
verifica('o singură tranzacție, pe ZEC', count($t) === 1 && $t[0]['moneda'] === $ZEC, true);
verifica('vinde ZEC (cantitate negativă)', $t[0]['cantitate'] < 0, true);
$cap = 499.625 + 2 * 1000 / 6 + 2 * 1000 / 6;
verifica('după vânzare, ZEC valorează 1/6 din capital', $A3['q'][$ZEC] * 200, $cap / 6, 1e-6);

/* ========================================================================= */
echo "\n=== 7. banca B: excepția pornește, ține 24 h, expiră ===\n";
[$B, $t] = b_pasOra('B', b_stareNoua(1000), 10, [$ZEC => true], pret(100), C);
verifica('ZEC țintit la 1/3: 1000/3/100', $B['q'][$ZEC], 1000 / 3 / 100);
verifica('BTC rămâne la 1/6', $B['q'][$BTC], 1000 / 6 / 100);
verifica('excepția expiră la ora 34', $B['exc'][$ZEC]['expira'], 34);
verifica('motivul spune semnal', str_starts_with($t[2]['motiv'], 'semnal'), true);
[$B, $t] = b_pasOra('B', $B, 20, [$ZEC => true], pret(100), C);
verifica('semnal repetat cât e activă: nu se reînnoiește', $B['exc'][$ZEC]['expira'], 34);
verifica('… și nicio tranzacție', count($t), 0);
[$B, $t] = b_pasOra('B', $B, 34, [$ZEC => true], pret(100), C);
verifica('ora 34: expiră', $B['exc'][$ZEC]['activa'], false);
verifica('semnalul din ora expirării e IGNORAT (ca în simulare)', $B['exc'][$ZEC]['expira'], 34);
verifica('ZEC vândut înapoi la ~1/6', abs($B['q'][$ZEC] * 100 / b_valoare($B, array_fill_keys(B_MONEDE, 100.0)) - 1 / 6) < 1e-3, true);
[$B, $t] = b_pasOra('B', $B, 35, [$ZEC => true], pret(100), C);
verifica('ora 35: semnal nou -> excepție nouă, expiră la 59', $B['exc'][$ZEC]['expira'], 59);

echo "  -- ore pierdute: cronul a stat oprit peste expirare --\n";
[$B, ] = b_pasOra('B', $B, 70, [$ZEC => true], pret(100), C);
verifica('ora 70: expiră (trecuse de 59) și pornește alta', $B['exc'][$ZEC]['expira'], 94);
[$B, $t] = b_pasOra('B', $B, 120, [], pret(100), C);
verifica('ora 120, fără semnal: doar expirarea', $B['exc'][$ZEC]['activa'] === false && count($t) === 1, true);

/* ========================================================================= */
echo "\n=== 8. spread-ul: cumpără la ask, vinde la bid ===\n";
[$S, $t] = b_pasOra('A', b_stareNoua(1000), 10, [], pret(100, 101, 99), C);
verifica('cantitatea se socotește la prețul de evaluare', $S['q'][$BTC], 1000 / 6 / 100);
verifica('prețul plătit e ask-ul', $t[0]['pret'], 101.0);
verifica('USDC: 1000 − 3 × 166,67 × 1,01 × 1,00075', $S['usdc'], 1000 - 3 * (1000 / 6 / 100) * 101 * (1 + C), 1e-9);

/* ========================================================================= */
echo "\n=== 9. banca C, pasivă ===\n";
[$Cb, $t] = b_pornestePasiv(1000, pret(100), C);
verifica('fără levier: comisionul iese din treime, USDC rămas 0', $Cb['usdc'], 0.0);
verifica('o treime cu tot cu comision: 1000/3/1,00075/100', $Cb['q'][$ETH], 1000 / 3 / (1 + C) / 100);
[$Cs, ] = b_pornestePasiv(1000, pret(100), C, false);
verifica('ca în simulare: 3,333333 și USDC −0,75', $Cs['q'][$ETH] === 1000 / 3 / 100 && abs($Cs['usdc'] + 0.75) < 1e-9, true);

/* ========================================================================= */
echo "\n=== 9b. fără levier: vânzările întâi, cumpărările doar din USDC existent ===\n";
// toți banii în crypto, două excepții noi: ZEC și ETH vor 1/3, BTC trebuie vândut
$S = b_stareNoua(1000);
$S['usdc'] = 0.0; foreach (B_MONEDE as $m) { $S['q'][$m] = 1000 / 3 / 100; }
[$S2, $t] = b_pasOra('B', $S, 10, [$ZEC => true, $ETH => true], pret(100), C);
verifica('prima tranzacție e o vânzare (BTC)', $t[0]['moneda'] === $BTC && $t[0]['cantitate'] < 0, true);
verifica('USDC nu coboară sub zero', $S2['usdc'] >= -1e-12, true);
[$S3, $t] = b_pasOra('B', $S, 10, [$ZEC => true, $ETH => true], pret(100), C, false);
verifica('ca în simulare (levier): același caz nu atinge ETH/ZEC, BTC vândut', count($t), 1);

/* ========================================================================= */
echo "\n=== 10. b_evalueaza cere istoric întreg ===\n";
try { b_evalueaza(array_fill(0, 100, 1.0)); verifica('100 de închideri -> refuz', false, true); }
catch (InvalidArgumentException $e) { verifica('100 de închideri -> refuz', true, true); }
$ser = [];
for ($i = 0; $i < B_INCHIDERI_NECESARE; $i++) { $ser[] = 100.0 + ($i % 2); }
$ser[B_INCHIDERI_NECESARE - 1] = 80.0;                       // o cădere bruscă la final
$e = b_evalueaza($ser);
verifica('cădere bruscă după liniște -> rang 1', $e['rang'], 1.0);
verifica('… și semnal', $e['semnal'], true);

printf("\n%d probe: %d trecute, %d picate\n", $treceri + $caderi, $treceri, $caderi);
exit($caderi === 0 ? 0 : 1);
