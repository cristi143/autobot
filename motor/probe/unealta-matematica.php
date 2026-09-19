<?php
/**
 * Probele uneltei manuale. Fără bază de date, fără rețea — se rulează oriunde:
 *
 *     php motor/probe/unealta-matematica.php
 *
 * Regulile sunt CERUTE din `unealta-reguli.php`, nu rescrise aici. Botul vechi
 * își copiază funcțiile în probe; acolo sunt două, aici ar fi unsprezece, iar
 * simetria long/short e exact felul de lucru care se desincronizează tăcut.
 *
 * Ce se verifică aici e altceva decât la botul vechi: nu formule de randament,
 * ci o MAȘINĂ DE STĂRI. Greșelile care dor sunt „pragul a coborât când n-avea
 * voie” și „long-ul s-a comportat ca un short”, nu virgula a șasea.
 */

declare(strict_types=1);

require __DIR__ . '/../unealta-reguli.php';

$f = 0.075 / 100;          // comision pe o parte
$treceri = 0; $caderi = 0;

function verifica(string $ce, $obtinut, $asteptat, float $toleranta = 1e-9): void {
    global $treceri, $caderi;
    $exact = is_bool($asteptat) || is_string($asteptat) || $asteptat === null;
    $ok = $exact ? ($obtinut === $asteptat) : (abs($obtinut - $asteptat) < $toleranta);
    $ok ? $treceri++ : $caderi++;

    if (is_bool($obtinut))       { $text = $obtinut ? 'true' : 'false'; }
    elseif ($obtinut === null)   { $text = 'null'; }
    elseif (is_string($obtinut)) { $text = $obtinut; }
    else                         { $text = rtrim(rtrim(sprintf('%.6f', $obtinut), '0'), '.'); }

    printf("  %-3s %-56s obținut %s\n", $ok ? 'ok' : 'NU', $ce, $text);
}

/* ========================================================================= */
echo "=== 1. armarea: prețul trebuie să treacă DINCOLO de nivel ===\n";

verifica('long 800, coborâre 20 -> prag de armare 780', u_pragArmare('long', 800, 20), 780.0);
verifica('short 1600, urcare 20 -> prag de armare 1620', u_pragArmare('short', 1600, 20), 1620.0);

verifica('long: minim 785 nu armează',      u_sArmat('long', 800, 785, 780), false);
verifica('long: minim 780 armează (egal)',  u_sArmat('long', 800, 780, 780), true);
verifica('long: minim 700 armează',         u_sArmat('long', 800, 700, 780), true);
verifica('short: maxim 1615 nu armează',    u_sArmat('short', 1615, 1600, 1620), false);
verifica('short: maxim 1620 armează (egal)',u_sArmat('short', 1620, 1600, 1620), true);
// Mecurile contează, nu închiderile: asta e tot rostul separării.
verifica('long: armează dintr-o înțepătură, oricât de scurtă',
         u_sArmat('long', 810, 779, 780), true);

/* ========================================================================= */
echo "\n=== 2. pragul de intrare urmărește extremul, dar nu urcă peste nivel ===\n";

verifica('fără extrem -> nivelul ales',      u_pragIntrareEfectiv('long', 800, null, 20), 800.0);
verifica('extrem 780 -> 800 (fix la nivel)', u_pragIntrareEfectiv('long', 800, 780, 20), 800.0);
verifica('extrem 700 -> 720 (coboară)',      u_pragIntrareEfectiv('long', 800, 700, 20), 720.0);
verifica('extrem 500 -> 520',                u_pragIntrareEfectiv('long', 800, 500, 20), 520.0);
// Plafonul: pragul n-are voie să urce peste nivelul ales, altfel am cumpăra mai
// scump decât am spus că vrem.
verifica('extrem 790 -> tot 800 (plafon)',   u_pragIntrareEfectiv('long', 800, 790, 20), 800.0);

verifica('short, extrem 1625 -> 1605',       u_pragIntrareEfectiv('short', 1600, 1625, 20), 1605.0);
verifica('short, extrem 1700 -> 1680',       u_pragIntrareEfectiv('short', 1600, 1700, 20), 1680.0);
verifica('short, extrem 1605 -> tot 1600 (plafon)',
         u_pragIntrareEfectiv('short', 1600, 1605, 20), 1600.0);

echo "  -- pragul doar se depărtează de nivel, niciodată înapoi --\n";
$extrem = null; $anterior = null; $monoton = true;
foreach ([790, 770, 700, 705, 650, 660] as $minim) {
    $extrem = u_extremIntrare('long', $extrem, $minim + 5, $minim);
    $prag   = u_pragIntrareEfectiv('long', 800, $extrem, 20);
    if ($anterior !== null && $prag > $anterior + 1e-12) { $monoton = false; }
    $anterior = $prag;
}
verifica('după 790,770,700,705,650,660 pragul n-a urcat niciodată', $monoton, true);
verifica('și a ajuns la 670', $anterior, 670.0);

/* ========================================================================= */
echo "\n=== 3. intrarea se declanșează pe ÎNCHIDERE, nu pe atingere ===\n";

verifica('long: maxim 805 dar închidere 795 sub prag 798', u_declanseazaIntrarea('long', 795, 798), false);
verifica('long: închidere 798 (egal) intră',               u_declanseazaIntrarea('long', 798, 798), true);
verifica('long: închidere 801 intră',                      u_declanseazaIntrarea('long', 801, 798), true);
verifica('short: închidere 1610 peste prag 1605 nu intră', u_declanseazaIntrarea('short', 1610, 1605), false);
verifica('short: închidere 1600 intră',                    u_declanseazaIntrarea('short', 1600, 1605), true);

/* ========================================================================= */
echo "\n=== 4. ținta și urmărirea ===\n";

verifica('long: maxim 874 nu atinge ținta 875', u_atinsTinta('long', 874, 860, 875), false);
verifica('long: maxim 875 atinge (egal)',       u_atinsTinta('long', 875, 860, 875), true);
verifica('short: minim 1401 nu atinge 1400',    u_atinsTinta('short', 1450, 1401, 1400), false);
verifica('short: minim 1400 atinge',            u_atinsTinta('short', 1450, 1400, 1400), true);

verifica('long, extrem 890, urmărire 45 -> prag 875 (plafon la țintă)',
         u_pragIesireEfectiv('long', 875, 890, 45), 875.0);
verifica('long, extrem 920 -> 875 (exact la limită)',
         u_pragIesireEfectiv('long', 875, 920, 45), 875.0);
verifica('long, extrem 935 -> 890',  u_pragIesireEfectiv('long', 875, 935, 45), 890.0);
verifica('long, extrem 1000 -> 955', u_pragIesireEfectiv('long', 875, 1000, 45), 955.0);
verifica('short, extrem 1390 -> 1400 (plafon)', u_pragIesireEfectiv('short', 1400, 1390, 45), 1400.0);
verifica('short, extrem 1300 -> 1345',          u_pragIesireEfectiv('short', 1400, 1300, 45), 1345.0);

echo "  -- pragul de ieșire DOAR urcă --\n";
$ex = null; $ant = null; $monoton = true;
foreach ([880, 935, 900, 1000, 950, 1010] as $maxim) {
    $ex   = u_extremIesire('long', $ex, $maxim, $maxim - 10);
    $prag = u_pragIesireEfectiv('long', 875, $ex, 45);
    if ($ant !== null && $prag < $ant - 1e-12) { $monoton = false; }
    $ant = $prag;
}
verifica('după 880,935,900,1000,950,1010 pragul n-a coborât niciodată', $monoton, true);
verifica('și a ajuns la 965', $ant, 965.0);

/* ========================================================================= */
echo "\n=== 5. stopul, singurul evaluat pe atingere ===\n";

verifica('fără stop, nimic nu se întâmplă', u_atinsStopul('long', 900, 100, null), false);
verifica('long: minim 781 nu atinge 780',   u_atinsStopul('long', 800, 781, 780), false);
verifica('long: minim 780 atinge',          u_atinsStopul('long', 800, 780, 780), true);
verifica('long: o înțepătură la 775 atinge, oricât de scurtă',
         u_atinsStopul('long', 820, 775, 780), true);
verifica('short: maxim 1650 atinge stopul 1650', u_atinsStopul('short', 1650, 1600, 1650), true);

/* ========================================================================= */
echo "\n=== 6. banii: randamentul net al unui dus-întors ===\n";

$netLong = u_rezultatNet('long', 801.0, 888.0, $f);
verifica('long 801 -> 888, net', $netLong, 10.6951, 1e-3);
// Verificare independentă, prin solduri reale: exact ce face motorul cu banca.
$U    = 1000.0;
$cant = ($U / 801.0) * (1 - $f);
$U2   = $cant * 888.0 * (1 - $f);
verifica('aceleași cifre prin soldurile băncii', ($U2 / $U - 1) * 100, $netLong, 1e-9);

$netShort = u_rezultatNet('short', 1600.0, 1350.0, $f);
verifica('short 1600 -> 1350, net în ZEC', $netShort, 18.3408, 1e-3);
$Z  = 1.0;
$U3 = $Z * 1600.0 * (1 - $f);          // vinde ZEC
$Z2 = ($U3 / 1350.0) * (1 - $f);       // răscumpără mai mult ZEC
verifica('aceleași cifre prin soldurile băncii', ($Z2 / $Z - 1) * 100, $netShort, 1e-9);

verifica('o ieșire fix la prețul de intrare pierde exact comisionul',
         u_rezultatNet('long', 800.0, 800.0, $f), ((1 - $f) * (1 - $f) - 1) * 100, 1e-12);
verifica('și e o pierdere, nu zero', u_rezultatNet('long', 800.0, 800.0, $f) < 0, true);

/* ========================================================================= */
echo "\n=== 7. mașina de stări, pe scenarii întregi ===\n";

/**
 * Rulează setarea peste un șir de lumânări [deschidere, maxim, minim, închidere]
 * și întoarce ce s-a întâmplat.
 *
 * ORDINEA e cea din motor — și e singurul lucru repetat aici, pentru că ea nu
 * stă într-o funcție pură: MFE/MAE, stop, urmărire, ieșire, apoi intrare.
 */
function simuleaza(array $s, array $lumanari): array {
    $banca = $s['banca'];
    $stare = 'asteapta';
    $extremIn = null;
    $poz = null;
    $istoric = [];

    foreach ($lumanari as $i => [$o, $h, $lo, $c]) {
        if ($poz !== null) {
            if (u_atinsStopul($banca, $h, $lo, $poz['stop'])) {
                $istoric[] = ['iesire', $i, 'stop', $poz['stop']];
                return ['istoric' => $istoric, 'stare' => 'inchisa',
                        'intrare' => $poz['intrare'], 'iesire' => $poz['stop'], 'motiv' => 'stop'];
            }
            if (!$poz['atinsa'] && u_atinsTinta($banca, $h, $lo, $poz['tinta'])) {
                $poz['atinsa'] = true;
                $istoric[] = ['tinta', $i];
            }
            if ($poz['atinsa']) {
                $poz['extrem'] = u_extremIesire($banca, $poz['extrem'], $h, $lo);
                $prag = u_pragIesireEfectiv($banca, $poz['tinta'], $poz['extrem'], $poz['urmarire']);
                if (u_declanseazaIesirea($banca, $c, $prag)) {
                    $istoric[] = ['iesire', $i, 'urmarire', $c];
                    return ['istoric' => $istoric, 'stare' => 'inchisa',
                            'intrare' => $poz['intrare'], 'iesire' => $c, 'motiv' => 'urmarire'];
                }
            }
            continue;
        }

        // Stopul atins înainte de intrare omoară setarea. Verificat ÎNAINTEA
        // armării: o lumânare care coboară sub stop și închide sus n-are voie
        // să apuce să declanșeze o intrare.
        if (u_atinsStopul($banca, $h, $lo, $s['prag_stop'])) {
            $istoric[] = ['expirat', $i];
            return ['istoric' => $istoric, 'stare' => 'expirat',
                    'intrare' => null, 'iesire' => null, 'motiv' => null];
        }

        if ($stare === 'asteapta') {
            $pragArm = u_pragArmare($banca, $s['prag_intrare'], $s['depasire_minima']);
            if (u_sArmat($banca, $h, $lo, $pragArm)) {
                $stare = 'armat';
                $extremIn = u_extremIntrare($banca, null, $h, $lo);
                $istoric[] = ['armat', $i];
            }
        }
        if ($stare === 'armat') {
            $extremIn = u_extremIntrare($banca, $extremIn, $h, $lo);
            $prag = u_pragIntrareEfectiv($banca, $s['prag_intrare'], $extremIn, $s['revenire']);
            if (u_declanseazaIntrarea($banca, $c, $prag)) {
                $poz = ['intrare' => $c, 'tinta' => $s['prag_iesire'],
                        'urmarire' => $s['urmarire'], 'stop' => $s['prag_stop'],
                        'atinsa' => false, 'extrem' => null];
                $istoric[] = ['intrare', $i, $c, $prag];
                $stare = 'in_pozitie';
            }
        }
    }

    return ['istoric' => $istoric, 'stare' => $stare,
            'intrare' => $poz['intrare'] ?? null, 'iesire' => null, 'motiv' => null,
            'extrem_intrare' => $extremIn, 'pozitie' => $poz];
}

$setareLong = ['banca' => 'long', 'prag_intrare' => 800.0, 'depasire_minima' => 20.0,
               'revenire' => 20.0, 'prag_iesire' => 875.0, 'urmarire' => 45.0,
               'prag_stop' => 760.0];

echo "  -- long, de la cap la coadă --\n";
$r = simuleaza($setareLong, [
    [810, 812, 805, 808],   // 0: nimic, n-a coborât
    [808, 809, 778, 795],   // 1: armează (778), dar închide sub prag (798)
    [795, 802, 790, 801],   // 2: închide 801 peste 798 -> INTRARE
    [801, 830, 799, 825],   // 3: urcă, ținta încă departe
    [825, 890, 820, 885],   // 4: atinge ținta 875, închide peste -> rămâne
    [885, 935, 880, 930],   // 5: maxim 935 -> pragul urcă la 890
    [930, 932, 885, 888],   // 6: închide 888 sub 890 -> IEȘIRE
]);
verifica('s-a închis',            $r['stare'], 'inchisa');
verifica('a intrat la 801',       $r['intrare'], 801.0);
verifica('a ieșit la 888',        $r['iesire'], 888.0);
verifica('motivul: urmărire',     $r['motiv'], 'urmarire');
verifica('rezultat net pozitiv',  u_rezultatNet('long', 801.0, 888.0, $f) > 0, true);

echo "  -- scenariul utilizatorului: oscilații violente nu trebuie să scoată --\n";
// „ajunge la 875, urcă la 890, scade repede la 880, iar repede 910, iar 890”
$r = simuleaza($setareLong, [
    [808, 809, 778, 795],
    [795, 802, 790, 801],   // intrare la 801
    [801, 880, 800, 878],   // atinge 875, închide 878
    [878, 890, 876, 881],   // urcă la 890, coboară la 876 — închide 881
    [881, 883, 878, 880],   // scade repede la 880
    [880, 910, 879, 905],   // iar repede 910
    [905, 908, 888, 890],   // iar repede 890
]);
verifica('cu urmărire 45, poziția e ÎNCĂ DESCHISĂ', $r['stare'], 'in_pozitie');
verifica('pragul a rămas la țintă (875), nu l-a scos',
         u_pragIesireEfectiv('long', 875.0, $r['pozitie']['extrem'], 45.0), 875.0);
verifica('maximul reținut e 910 (din mec)', $r['pozitie']['extrem'], 910.0);

// Aceleași lumânări, urmărire strânsă: îl scoate. Cifra contează, nu regula.
$stramt = $setareLong; $stramt['urmarire'] = 10.0;
$r2 = simuleaza($stramt, [
    [808, 809, 778, 795],
    [795, 802, 790, 801],
    [801, 880, 800, 878],
    [878, 890, 876, 881],
    [881, 883, 878, 880],   // maxim 890 -> prag 880; închiderea 880 rupe pragul
]);
verifica('cu urmărire 10, l-a scos',      $r2['stare'], 'inchisa');
verifica('exact pe lumânarea cu 880',     $r2['iesire'], 880.0);

echo "  -- pragul de intrare coboară după minim --\n";
$r = simuleaza($setareLong, [
    [810, 812, 778, 800],   // armează la 778; prag 798; închide 800 -> ar intra!
]);
verifica('armare și intrare în aceeași lumânare sunt posibile', $r['stare'], 'in_pozitie');
verifica('a intrat la 800',                                     $r['intrare'], 800.0);

// Stopul mărginește cât de jos poate coborî pragul de intrare: cu stop 760,
// scenariul ăsta ar expira la primul minim de 700. Ca să se vadă urmărirea
// pragului, stopul trebuie așezat sub unde ajunge prețul — sau deloc.
$adanc = $setareLong; $adanc['prag_stop'] = 650.0;
$r = simuleaza($adanc, [
    [810, 812, 778, 790],   // armează, nu intră (790 < 798)
    [790, 795, 700, 705],   // cade la 700; prag devine 720; 705 < 720, nu intră
    [705, 725, 702, 724],   // închide 724 peste 720 -> INTRARE mult sub 800
]);
verifica('a intrat la 724, nu a așteptat 800', $r['intrare'], 724.0);
verifica('ținta a rămas 875, absolută',        $r['pozitie']['tinta'], 875.0);

// Aceleași lumânări, cu stopul de 760: teza pică înainte de intrare.
$r = simuleaza($setareLong, [
    [810, 812, 778, 790],
    [790, 795, 700, 705],
]);
verifica('cu stop 760, aceeași cădere o face să expire', $r['stare'], 'expirat');

echo "  -- stopul --\n";
$r = simuleaza($setareLong, [
    [808, 809, 778, 795],
    [795, 802, 790, 801],   // intrare 801
    [801, 805, 759, 762],   // minim 759 <= stop 760
]);
verifica('a ieșit pe stop',        $r['motiv'], 'stop');
verifica('exact la prag, nu la închidere', $r['iesire'], 760.0);
verifica('și e pierdere',          u_rezultatNet('long', 801.0, 760.0, $f) < 0, true);

echo "  -- stop și urmărire în aceeași lumânare: se ia STOPUL --\n";
$r = simuleaza($setareLong, [
    [808, 809, 778, 795],
    [795, 802, 790, 801],   // intrare 801
    [801, 935, 800, 930],   // ținta atinsă, maxim 935, prag 890
    [930, 931, 755, 880],   // minim 755 atinge stopul; închiderea 880 ar rupe și pragul
]);
verifica('motivul e stop, nu urmărire', $r['motiv'], 'stop');
verifica('ieșirea e la 760, nu la 880', $r['iesire'], 760.0);

echo "  -- short, oglinda exactă --\n";
$setareShort = ['banca' => 'short', 'prag_intrare' => 1600.0, 'depasire_minima' => 20.0,
                'revenire' => 20.0, 'prag_iesire' => 1400.0, 'urmarire' => 45.0,
                'prag_stop' => 1650.0];
$r = simuleaza($setareShort, [
    [1590, 1595, 1580, 1585],   // 0: n-a urcat destul
    [1585, 1625, 1580, 1610],   // 1: armează (1625); prag 1605; închide 1610 peste -> nu intră
    [1610, 1615, 1595, 1600],   // 2: închide 1600 sub 1605 -> INTRARE (vinde ZEC)
    [1600, 1610, 1500, 1520],   // 3: coboară, ținta încă departe
    [1520, 1530, 1390, 1395],   // 4: atinge ținta 1400, închide sub -> rămâne
    [1395, 1400, 1300, 1310],   // 5: minim 1300 -> pragul coboară la 1345
    [1310, 1360, 1305, 1350],   // 6: închide 1350 peste 1345 -> IEȘIRE
]);
verifica('a intrat la 1600',   $r['intrare'], 1600.0);
verifica('a ieșit la 1350',    $r['iesire'], 1350.0);
verifica('motivul: urmărire',  $r['motiv'], 'urmarire');
verifica('câștig în ZEC',      u_rezultatNet('short', 1600.0, 1350.0, $f) > 0, true);

$r = simuleaza($setareShort, [
    [1585, 1625, 1580, 1610],
    [1610, 1615, 1595, 1600],   // intrare 1600
    [1600, 1655, 1598, 1610],   // maxim 1655 >= stop 1650
]);
verifica('short: stopul e deasupra și se atinge pe maxim', $r['motiv'], 'stop');
verifica('ieșire la 1650',                                 $r['iesire'], 1650.0);
verifica('și e pierdere în ZEC', u_rezultatNet('short', 1600.0, 1650.0, $f) < 0, true);

/* ========================================================================= */
echo "\n=== 8. validarea setărilor ===\n";

$bun = ['banca' => 'long', 'prag_intrare' => 800, 'depasire_minima' => 20,
        'revenire' => 20, 'prag_iesire' => 875, 'urmarire' => 45, 'prag_stop' => 760];
verifica('o setare bună nu are obiecții', count(u_validareSetare($bun)), 0);

$fara = $bun; unset($fara['prag_stop']);
verifica('stopul e opțional', count(u_validareSetare($fara)), 0);

$rau = $bun; $rau['prag_iesire'] = 700;
verifica('long cu ținta sub intrare e respins', count(u_validareSetare($rau)) > 0, true);

$rau = $bun; $rau['prag_stop'] = 850;
verifica('long cu stopul deasupra intrării e respins', count(u_validareSetare($rau)) > 0, true);

$rau = $bun; $rau['prag_stop'] = 780;
verifica('long cu stopul FIX pe pragul de armare e respins',
         count(u_validareSetare($rau)) > 0, true);

$rau = $bun; $rau['prag_stop'] = 785;
verifica('long cu stopul între armare și nivel e respins',
         count(u_validareSetare($rau)) > 0, true);

$rau = $bun; $rau['revenire'] = 40;
verifica('revenire mai mare decât coborârea e respinsă', count(u_validareSetare($rau)) > 0, true);

$rau = $bun; $rau['urmarire'] = 100;
verifica('urmărire mai mare decât drumul până la țintă e respinsă',
         count(u_validareSetare($rau)) > 0, true);

$rau = $bun; $rau['prag_intrare'] = -5;
verifica('preț negativ e respins', count(u_validareSetare($rau)) > 0, true);

$rau = $bun; $rau['urmarire'] = 'mult';
verifica('text în loc de număr e respins', count(u_validareSetare($rau)) > 0, true);

$bunS = ['banca' => 'short', 'prag_intrare' => 1600, 'depasire_minima' => 20,
         'revenire' => 20, 'prag_iesire' => 1400, 'urmarire' => 45, 'prag_stop' => 1650];
verifica('short cu stopul fix pe pragul de armare (1620) e respins',
         count(u_validareSetare(array_merge($bunS, ['prag_stop' => 1620]))) > 0, true);
verifica('o setare de short bună nu are obiecții', count(u_validareSetare($bunS)), 0);

$rauS = $bunS; $rauS['prag_iesire'] = 1700;
verifica('short cu ținta peste intrare e respins', count(u_validareSetare($rauS)) > 0, true);

$rauS = $bunS; $rauS['prag_stop'] = 1500;
verifica('short cu stopul sub intrare e respins', count(u_validareSetare($rauS)) > 0, true);

verifica('bancă inexistentă e respinsă',
         count(u_validareSetare(['banca' => 'altceva'])) > 0, true);

/* ========================================================================= */
echo "\n" . str_repeat('-', 72) . "\n";
printf("%d trecute, %d căzute\n", $treceri, $caderi);
exit($caderi > 0 ? 1 : 0);
