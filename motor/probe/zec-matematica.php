<?php
/**
 * Probele băncilor pe ZEC (D, E, F). Fără bază de date, fără rețea:
 *
 *     php motor/probe/zec-matematica.php
 *
 * Cazuri mici, socotite de mână. Echivalența cu simularea e proba separată,
 * zec-echivalenta.php.
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
    printf("  %-3s %-62s obținut %s\n", $ok ? 'ok' : 'NU', $ce, $text);
}
function pret(float $x, ?float $cump = null, ?float $vinde = null): array {
    return ['eval' => $x, 'cump' => $cump ?? $x, 'vinde' => $vinde ?? $x];
}
const C = 0.00075;

echo "=== 1. pornirea: prima oră cumpără până la țintă ===\n";
[$d, $t] = z_pasOra('D', z_stareNoua(1000), 10, false, pret(100), C);
verifica('D: o tranzacție', count($t), 1);
verifica('D: 5 ZEC (500 USDC la 100)', $d['q'], 5.0);
verifica('D: USDC = 1000 − 500 − 0,375 comision', $d['usdc'], 499.625);
[$e, $t] = z_pasOra('E', z_stareNoua(1000), 10, false, pret(100), C);
verifica('E: 3 ZEC', $e['q'], 3.0);
verifica('E: ținta scrisă 0,30', $t[0]['tinta'], 0.30);
[$f, $t] = z_pasOra('F', z_stareNoua(1000), 10, false, pret(100), C);
verifica('F fără semnal: tot 3 ZEC', $f['q'], 3.0);

echo "\n=== 2. banda de 5 pp ===\n";
// D: 5 ZEC + 500 USDC. La 120: ZEC 600, capital 1100, țintă 550, abatere 50 < 55 -> nimic
$s = ['usdc' => 500.0, 'q' => 5.0, 'exc' => ['expira' => null, 'activa' => false]];
[$s2, $t] = z_pasOra('D', $s, 11, false, pret(120), C);
verifica('abatere 50 din 1100 (4,5%) -> nimic', count($t), 0);
// La 125: ZEC 625, capital 1125, țintă 562,5, abatere 62,5 > 56,25 -> vinde 0,5 ZEC
[$s2, $t] = z_pasOra('D', $s, 11, false, pret(125), C);
verifica('abatere 62,5 din 1125 (5,6%) -> vinde', count($t), 1);
verifica('… 0,5 ZEC', $t[0]['cantitate'], -0.5);
verifica('… USDC = 500 + 62,5 − 0,046875', $s2['usdc'], 562.453125);
// La 80: ZEC 400, capital 900, țintă 450, abatere 50 > 45 -> cumpără 0,625 ZEC
[$s2, $t] = z_pasOra('D', $s, 11, false, pret(80), C);
verifica('la scădere cumpără 0,625 ZEC', $t[0]['cantitate'], 0.625);

echo "\n=== 3. D și E ignoră semnalul ===\n";
[$s2, $t] = z_pasOra('D', $s, 11, true, pret(100), C);
verifica('D cu semnal: nimic', count($t), 0);
verifica('D: nicio excepție', $s2['exc']['activa'], false);
$sE = ['usdc' => 700.0, 'q' => 3.0, 'exc' => ['expira' => null, 'activa' => false]];
[$s2, $t] = z_pasOra('E', $sE, 11, true, pret(100), C);
verifica('E cu semnal: nimic', count($t), 0);

echo "\n=== 4. excepția băncii F ===\n";
[$f1, $t] = z_pasOra('F', $sE, 100, true, pret(100), C);
verifica('semnal -> excepție activă', $f1['exc']['activa'], true);
verifica('… expiră la ora 124', $f1['exc']['expira'], 124);
verifica('… ținta 0,55', $t[0]['tinta'], 0.55);
verifica('… cumpără 2,5 ZEC (de la 300 la 550)', $t[0]['cantitate'], 2.5);
verifica('… USDC = 700 − 250 − 0,1875', $f1['usdc'], 449.8125);
[$f2, $t] = z_pasOra('F', $f1, 110, true, pret(100), C);
verifica('semnal în timpul excepției: ignorat', count($t), 0);
verifica('… expirarea rămâne 124', $f2['exc']['expira'], 124);
[$f3, $t] = z_pasOra('F', $f1, 124, true, pret(100), C);
verifica('ora expirării, cu semnal: se închide, nu repornește', $f3['exc']['activa'], false);
verifica('… vinde înapoi la 30%', $t[0]['cantitate'] < 0 && abs($t[0]['tinta'] - 0.30) < 1e-12, true);
[$f4, $t] = z_pasOra('F', $f3, 125, true, pret(100), C);
verifica('ora de după, cu semnal: excepție nouă', $f4['exc']['activa'], true);
verifica('… expiră la 149', $f4['exc']['expira'], 149);

echo "\n=== 5. la eveniment se tranzacționează oricât de puțin ===\n";
// F la expirare, cu abatere mică față de 30%: tot tranzacționează
$sF = ['usdc' => 690.0, 'q' => 3.1, 'exc' => ['expira' => 50, 'activa' => true]];
[$s2, $t] = z_pasOra('F', $sF, 50, false, pret(100), C);
verifica('abatere de 1% la expirare -> tranzacție', count($t), 1);
$sF['exc'] = ['expira' => 50, 'activa' => false];
[$s2, $t] = z_pasOra('F', $sF, 60, false, pret(100), C);
verifica('aceeași abatere fără eveniment -> nimic', count($t), 0);

echo "\n=== 6. fără levier, bid/ask ===\n";
// 10 USDC, ținta 55% = 0,055 ZEC; la un ask de 300 ar costa 16,5 -> se oprește la cât are
$sS = ['usdc' => 10.0, 'q' => 0.0, 'exc' => ['expira' => null, 'activa' => false]];
[$s2, $t] = z_pasOra('F', $sS, 100, true, pret(100, 300, 100), C);
verifica('cumpărarea se oprește la USDC disponibil', $s2['usdc'] >= -1e-12 && $s2['usdc'] < 1e-9, true);
verifica('… motivul o spune', str_contains($t[0]['motiv'], 'limitată'), true);
[$s2, $t] = z_pasOra('D', z_stareNoua(1000), 10, false, pret(100, 101, 99), C);
verifica('cumpără la ask (101)', $t[0]['pret'], 101.0);
verifica('… cantitatea socotită la prețul de evaluare: 5 ZEC', $t[0]['cantitate'], 5.0);
[$s2, $t] = z_pasOra('D', $s, 11, false, pret(125, 126, 124), C);
verifica('vinde la bid (124)', $t[0]['pret'], 124.0);
try { z_pasOra('A', z_stareNoua(1000), 1, false, pret(100), C); verifica('bancă necunoscută -> refuz', false, true); }
catch (InvalidArgumentException $e) { verifica('bancă necunoscută -> refuz', true, true); }

printf("\n%d probe: %d trecute, %d picate\n", $treceri + $caderi, $treceri, $caderi);
exit($caderi === 0 ? 0 : 1);
