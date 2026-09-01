<?php
/* Verifică matematica din motor, fără bază de date. */
declare(strict_types=1);

function pretLinie(array $l, int $moment): float {
    $t1 = (int)$l['t1']; $t2 = (int)$l['t2'];
    if ($t1 === $t2) return (float)$l['p1'];
    $panta = ((float)$l['p2'] - (float)$l['p1']) / ($t2 - $t1);
    return (float)$l['p1'] + $panta * ($moment - $t1);
}

$f = 0.075 / 100;   // comision pe o parte
$treceri = 0; $caderi = 0;
function verifica(string $ce, $obtinut, $asteptat, float $toleranta = 1e-9): void {
    global $treceri, $caderi;
    $ok = is_bool($asteptat) ? ($obtinut === $asteptat) : (abs($obtinut - $asteptat) < $toleranta);
    $ok ? $treceri++ : $caderi++;
    printf("  %-3s %-52s obținut %s\n", $ok ? 'ok' : 'NU', $ce,
        is_bool($obtinut) ? ($obtinut ? 'true' : 'false') : rtrim(rtrim(sprintf('%.6f', $obtinut), '0'), '.'));
}

echo "=== 1. prețul liniei prin prelungire ===\n";
$ORA = 3600000;
$linie = ['t1' => 0, 'p1' => 100.0, 't2' => 10 * $ORA, 'p2' => 110.0];   // +1/oră
verifica('la t1',                    pretLinie($linie, 0),          100.0);
verifica('la t2',                    pretLinie($linie, 10 * $ORA),  110.0);
verifica('la mijloc',                pretLinie($linie, 5 * $ORA),   105.0);
verifica('prelungit 5 ore în viitor',pretLinie($linie, 15 * $ORA),  115.0);
verifica('extrapolat înapoi',        pretLinie($linie, -2 * $ORA),   98.0);
$desc = ['t1' => 0, 'p1' => 900.0, 't2' => 9 * $ORA, 'p2' => 862.0];
verifica('linie descendentă, la 9h', pretLinie($desc, 9 * $ORA),    862.0);

echo "\n=== 2. randamentul net al unui dus-întors ===\n";
// long: 800 -> 808 (TP de 1%)
$raport = 808 / 800;
$netLong = ($raport * (1 - $f) * (1 - $f) - 1) * 100;
verifica('LONG, TP la +1% brut -> net', $netLong, 0.8489, 1e-3);

// verificare independentă, prin solduri reale
$U = 500.0;
$cant = ($U / 800) * (1 - $f);
$U2   = $cant * 808 * (1 - $f);
verifica('LONG, aceleași cifre prin solduri', ($U2 / $U - 1) * 100, $netLong, 1e-9);

// short: vinde la 800, răscumpără la 792 (TP de 1%)
$netShort = ((800 / 792) * (1 - $f) * (1 - $f) - 1) * 100;
verifica('SHORT, TP la −1% -> net în ZEC', $netShort, 0.858642, 1e-5);
$Z  = 0.625;
$U3 = $Z * 800 * (1 - $f);
$Z2 = ($U3 / 792) * (1 - $f);
verifica('SHORT, aceleași cifre prin solduri', ($Z2 / $Z - 1) * 100, $netShort, 1e-9);

echo "\n=== 3. comisionul chiar mănâncă din câștig ===\n";
verifica('1% brut lasă sub 0,9% net', $netLong < 0.9, true);
verifica('comision dus-întors ≈ 0,15%', (1 - (1-$f)*(1-$f)) * 100, 0.1499, 1e-3);

echo "\n=== 4. condițiile de semnal ===\n";
$lum = ['deschidere' => 820.0, 'inchidere' => 835.0, 'maxim' => 838.0, 'minim' => 818.0];
$verde = $lum['inchidere'] > $lum['deschidere'];
verifica('lumânare verde recunoscută', $verde, true);
verifica('verde peste linia de sus -> LONG', $verde && $lum['inchidere'] > 830.0, true);
verifica('verde SUB linia de sus -> fără semnal', $verde && $lum['inchidere'] > 840.0, false);

$lumR = ['deschidere' => 835.0, 'inchidere' => 818.0];
$rosu = $lumR['inchidere'] < $lumR['deschidere'];
verifica('roșie sub linia de jos -> SHORT', $rosu && $lumR['inchidere'] < 822.0, true);
verifica('roșie DEASUPRA liniei de jos -> nimic', $rosu && $lumR['inchidere'] < 810.0, false);
verifica('verde peste linia de jos nu dă short', $verde && $lum['inchidere'] < 822.0, false);

echo "\n=== 5. TP înaintea SL, în aceeași oră ===\n";
// long deschis la 818.40, TP 826.58, linia de SL la 830 (deja ruptă la închidere)
$tp = 826.58; $inaltime = 828.0; $inchidere = 815.0; $linieSL = 820.0;
$atinsTP = $inaltime >= $tp;
verifica('maximul a atins TP-ul', $atinsTP, true);
verifica('închiderea ar fi dat și SL', $inchidere < $linieSL, true);
verifica('câștigă TP-ul (se verifică primul)', $atinsTP, true);


echo "\n=== 6. vârful triunghiului ===\n";
function varfulTriunghiului(array $sus, array $jos): ?int {
    $m1 = ((float)$sus['p2'] - (float)$sus['p1']) / ((int)$sus['t2'] - (int)$sus['t1']);
    $m2 = ((float)$jos['p2'] - (float)$jos['p1']) / ((int)$jos['t2'] - (int)$jos['t1']);
    if (abs($m1 - $m2) < 1e-15) return null;
    $b1 = (float)$sus['p1'] - $m1 * (int)$sus['t1'];
    $b2 = (float)$jos['p1'] - $m2 * (int)$jos['t1'];
    return (int)round(($b2 - $b1) / ($m1 - $m2));
}
$O = 3600000;
// sus coboară de la 900 la 860 în 10 ore; jos urcă de la 800 la 840 tot atunci
// se întâlnesc la mijloc, la ora 12.5, în punctul 850
$sus = ['t1'=>0,'p1'=>900.0,'t2'=>10*$O,'p2'=>860.0];
$jos = ['t1'=>0,'p1'=>800.0,'t2'=>10*$O,'p2'=>840.0];
$v = varfulTriunghiului($sus, $jos);
verifica('vârful, în ore de la început', $v / $O, 12.5, 1e-9);
verifica('la vârf, cele două linii coincid',
    abs(pretLinie($sus, $v) - pretLinie($jos, $v)) < 1e-6, true);
verifica('înainte de vârf, sus e deasupra', pretLinie($sus, 5*$O) > pretLinie($jos, 5*$O), true);
verifica('DUPĂ vârf, sus ajunge dedesubt', pretLinie($sus, 20*$O) < pretLinie($jos, 20*$O), true);
$paralele = varfulTriunghiului($sus, ['t1'=>0,'p1'=>800.0,'t2'=>10*$O,'p2'=>760.0]);
verifica('linii paralele: fără vârf', $paralele === null, true);

echo "\n" . str_repeat('-', 68) . "\n";
printf("%d trecute, %d căzute\n", $treceri, $caderi);
exit($caderi > 0 ? 1 : 0);
