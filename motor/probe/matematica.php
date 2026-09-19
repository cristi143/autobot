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
    // Numerele se compară cu toleranță; tot restul — bool, șir, null — exact.
    $exact = is_bool($asteptat) || is_string($asteptat) || $asteptat === null;
    $ok = $exact ? ($obtinut === $asteptat) : (abs($obtinut - $asteptat) < $toleranta);
    $ok ? $treceri++ : $caderi++;

    if (is_bool($obtinut))      { $text = $obtinut ? 'true' : 'false'; }
    elseif ($obtinut === null)  { $text = 'null'; }
    elseif (is_string($obtinut)){ $text = $obtinut; }
    else                        { $text = rtrim(rtrim(sprintf('%.6f', $obtinut), '0'), '.'); }

    printf("  %-3s %-52s obținut %s\n", $ok ? 'ok' : 'NU', $ce, $text);
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

echo "\n=== 5. TP și SL în aceeași fereastră: câștigă SL ===\n";
/* Din 19.09.2026 amândouă sunt praguri fixe, verificate pe aceeași fereastră de
   preț. Din lumânări de o oră nu se poate ști care a fost primul, deci convenția
   e pesimistă: se ia pierderea. O simulare care presupune ordinea favorabilă
   minte exact în direcția în care s-ar paria bani adevărați. */
function iesire(string $tip, float $tp, float $sl, float $maxim, float $minim): ?string {
    $atinsTP = ($tip === 'long') ? $maxim >= $tp : $minim <= $tp;
    $atinsSL = ($tip === 'long') ? $minim <= $sl : $maxim >= $sl;
    if ($atinsTP && $atinsSL) return 'sl';
    if ($atinsTP) return 'tp';
    if ($atinsSL) return 'sl';
    return null;
}
verifica('long: doar TP atins',        iesire('long',  830.0, 810.0, 832.0, 815.0), 'tp');
verifica('long: doar SL atins',        iesire('long',  830.0, 810.0, 825.0, 808.0), 'sl');
verifica('long: amândouă -> SL',       iesire('long',  830.0, 810.0, 832.0, 808.0), 'sl');
verifica('long: niciunul -> rămâne',   iesire('long',  830.0, 810.0, 825.0, 815.0), null);
verifica('short: doar TP atins',       iesire('short', 790.0, 810.0, 805.0, 788.0), 'tp');
verifica('short: doar SL atins',       iesire('short', 790.0, 810.0, 812.0, 795.0), 'sl');
verifica('short: amândouă -> SL',      iesire('short', 790.0, 810.0, 812.0, 788.0), 'sl');
verifica('short: niciunul -> rămâne',  iesire('short', 790.0, 810.0, 805.0, 795.0), null);


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

echo "\n=== 7. cele două ritmuri: pragurile des, semnalele pe închidere ===\n";
/* Reproduce alegerea maximului de urmărit din motor. */
function maximDeVazut(array $informare, array $inchisa, bool $deFacutOrarul): float {
    return $deFacutOrarul ? max($informare['maxim'], $inchisa['maxim']) : $informare['maxim'];
}
$inchisa   = ['maxim' => 840.0, 'minim' => 810.0];
$informare = ['maxim' => 828.0, 'minim' => 820.0];

verifica('lumânarea închisă neprocesată: maximul ei contează',
    maximDeVazut($informare, $inchisa, true), 840.0);
verifica('deja procesată: doar lumânarea în formare',
    maximDeVazut($informare, $inchisa, false), 828.0);

// TP la 835: se atinge doar dacă mai punem la socoteală lumânarea închisă
$tp = 835.0;
verifica('TP prins la prima trecere peste ora închisă',
    maximDeVazut($informare, $inchisa, true) >= $tp, true);
verifica('la a doua trecere nu se reevaluează ora veche',
    maximDeVazut($informare, $inchisa, false) >= $tp, false);

// TP la 825: atins de lumânarea în formare, deci prins imediat, între ore
$tp2 = 825.0;
verifica('TP atins în ora curentă, prins fără să aștepte închiderea',
    maximDeVazut($informare, $inchisa, false) >= $tp2, true);

echo "\n=== 8. inițializarea băncii de short ===\n";
/* Reproduce cele două variante de condiție, ca să se vadă de ce cea veche
   distrugea o poziție deschisă. */
function initVeche(float $usdc, float $zec): bool { return $zec == 0.0 && $usdc > 0; }
function initNoua(bool $aFostInitializata, float $usdc): bool { return !$aFostInitializata && $usdc > 0; }

// bancă proaspătă, finanțată cu 500 USDC
verifica('veche: bancă nouă -> inițializează',      initVeche(500.0, 0.0), true);
verifica('nouă:  bancă nouă -> inițializează',      initNoua(false, 500.0), true);

// bancă în mijlocul unui short: a vândut ZEC, stă pe USDC
verifica('veche: short deschis -> GREȘIT, reinițializează', initVeche(512.4, 0.0), true);
verifica('nouă:  short deschis -> nu se atinge',            initNoua(true, 512.4), false);

// bancă în repaus, ținând ZEC
verifica('veche: stă pe ZEC -> nu se atinge', initVeche(0.0, 0.61), false);
verifica('nouă:  stă pe ZEC -> nu se atinge', initNoua(true, 0.0),  false);

echo "\n=== 9. ATR(n) după Wilder ===\n";
/* Copie fidelă a funcției din motor. O lumânare = [t, o, h, l, c, v]. */
function atr(array $inchise, int $n): ?float {
    $m = count($inchise);
    if ($m < $n + 1) return null;
    $tr = [];
    for ($i = 1; $i < $m; $i++) {
        $h  = (float)$inchise[$i][2];
        $l  = (float)$inchise[$i][3];
        $pc = (float)$inchise[$i - 1][4];
        $tr[] = max($h - $l, abs($h - $pc), abs($l - $pc));
    }
    $a = array_sum(array_slice($tr, 0, $n)) / $n;
    for ($i = $n; $i < count($tr); $i++) { $a = ($a * ($n - 1) + $tr[$i]) / $n; }
    return $a;
}

/** Lumânare cu amplitudine fixă în jurul unui preț, fără gol față de cea dinainte. */
function lum(float $c, float $amplitudine): array {
    return [0, $c, $c + $amplitudine / 2, $c - $amplitudine / 2, $c, 0];
}

$plate = [];
for ($i = 0; $i < 30; $i++) { $plate[] = lum(800.0, 10.0); }
verifica('amplitudine constantă 10 -> ATR 10', atr($plate, 14), 10.0, 1e-9);
verifica('prea puține lumânări -> null', atr(array_slice($plate, 0, 10), 14), null);
verifica('exact n+1 lumânări -> media simplă', atr(array_slice($plate, 0, 15), 14), 10.0, 1e-9);

// Golul contează: o oră îngustă, dar deschisă departe de închiderea anterioară,
// nu e o oră liniștită. Fără |h - close_anterior|, ATR-ul ar rata saltul.
$cuGol = $plate;
$cuGol[] = [0, 850.0, 852.0, 848.0, 850.0, 0];     // h-l = 4, dar golul e ~47
$faraGol = $plate;
$faraGol[] = lum(800.0, 4.0);
verifica('lumânarea cu gol ridică ATR-ul', atr($cuGol, 14) > atr($faraGol, 14), true);

echo "\n=== 10. pragurile așezate din ATR ===\n";
/* Reproduce calculul din motor, inclusiv plafoanele. */
function praguri(string $tip, float $exec, float $atr, float $tpAtr, float $slAtr,
                 float $minProc, float $maxProc): array {
    $minAbs = $exec * $minProc / 100;
    $maxAbs = $exec * $maxProc / 100;
    $dTp = min(max($atr * $tpAtr, $minAbs), $maxAbs);
    $dSl = min(max($atr * $slAtr, $minAbs), $maxAbs);
    return ($tip === 'long') ? [$exec + $dTp, $exec - $dSl] : [$exec - $dTp, $exec + $dSl];
}

// ATR 1,72% din 800 = 13,76 · TP 1,5 ATR = 20,64 · SL 1 ATR = 13,76
[$tp, $sl] = praguri('long', 800.0, 13.76, 1.5, 1.0, 0.5, 4.0);
verifica('long: TP la 1,5 ATR', $tp, 820.64, 1e-6);
verifica('long: SL la 1 ATR',   $sl, 786.24, 1e-6);
verifica('raportul câștig/risc e 1,5', ($tp - 800.0) / (800.0 - $sl), 1.5, 1e-9);

[$tpS, $slS] = praguri('short', 800.0, 13.76, 1.5, 1.0, 0.5, 4.0);
verifica('short: pragurile se oglindesc', 800.0 - $tpS, $tp - 800.0, 1e-9);
verifica('short: SL deasupra intrării', $slS > 800.0, true);

// ATR minuscul: plafonul de jos apără de o țintă mâncată de comision
[$tpMic, $slMic] = praguri('long', 800.0, 0.5, 1.5, 1.0, 0.5, 4.0);
verifica('ATR mic -> TP dus la minimul de 0,5%', $tpMic, 804.0, 1e-9);
verifica('ATR mic -> SL dus la minimul de 0,5%', $slMic, 796.0, 1e-9);
verifica('minimul bate comisionul dus-întors',
    ($tpMic / 800.0 - 1) * 100 > (1 - (1 - $f) * (1 - $f)) * 100, true);

// ATR exploziv (ora de 52% din istoricul lui ZEC): plafonul de sus taie
[$tpMare, $slMare] = praguri('long', 800.0, 100.0, 1.5, 1.0, 0.5, 4.0);
verifica('ATR uriaș -> TP tăiat la 4%', $tpMare, 832.0, 1e-9);
verifica('ATR uriaș -> SL tăiat la 4%', $slMare, 768.0, 1e-9);

echo "\n=== 11. MFE și MAE ===\n";
/* Cât de departe a mers prețul, în favoare și împotrivă. Brut, în procente. */
function excursie(string $tip, float $intrare, float $maxim, float $minim): array {
    return ($tip === 'long')
        ? [($maxim - $intrare) / $intrare * 100, ($minim - $intrare) / $intrare * 100]
        : [($intrare - $minim) / $intrare * 100, ($intrare - $maxim) / $intrare * 100];
}
[$mfe, $mae] = excursie('long', 800.0, 816.0, 792.0);
verifica('long: MFE +2%', $mfe,  2.0, 1e-9);
verifica('long: MAE −1%', $mae, -1.0, 1e-9);
[$mfeS, $maeS] = excursie('short', 800.0, 816.0, 792.0);
verifica('short: MFE +1% (prețul a scăzut)', $mfeS,  1.0, 1e-9);
verifica('short: MAE −2% (prețul a urcat)',  $maeS, -2.0, 1e-9);
verifica('MFE nu e niciodată sub MAE', $mfe >= $mae, true);

echo "\n" . str_repeat('-', 68) . "\n";
printf("%d trecute, %d căzute\n", $treceri, $caderi);
exit($caderi > 0 ? 1 : 0);
