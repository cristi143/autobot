<?php
/**
 * Regulile botului automat — funcții pure, fără bază de date și fără rețea.
 *
 * Sursa de adevăr: ../docs/plan-bot.md. Regulile REPRODUC simularea din
 * 12.11.2026 (testul preînregistrat „vol mare după cădere” și testul „trei
 * straturi”). Orice abatere de la simulare e o greșeală, nu o îmbunătățire:
 * botul există ca rezultatele lui să se poată pune lângă cele istorice.
 *
 * DE CE STAU SINGURE, ca la unealta manuală: le folosesc motorul, probele și
 * proba de echivalență cu simularea. Scrise de mai multe ori, s-ar
 * desincroniza tăcut.
 *
 * Unități: timpul în ORE UNIX (ms / 3 600 000), prețurile în USDC.
 */

declare(strict_types=1);

const B_MONEDE         = ['BTCUSDC', 'ETHUSDC', 'ZECUSDC'];   // și ordinea de execuție
const B_FEREASTRA_VOL  = 24;     // randamente orare din care se socotește volatilitatea
const B_FEREASTRA_RANG = 720;    // ore anterioare față de care se socotește rangul
const B_PRAG_RANG      = 0.8;    // cincimea de sus
const B_DURATA_EXC     = 24;     // ore
const B_BANDA          = 0.05;   // abaterea care declanșează reechilibrarea, din capital
const B_PROPORTIE      = 0.5;    // partea crypto în băncile A și B (decizia utilizatorului)
const B_PONDERE_EXC    = 1 / 3;  // ponderea unei monede în timpul excepției (banca B)
const B_PRAG_ZERO      = 1e-9;   // sub atât, o diferență nu e tranzacție

/** Câte închideri orare sunt necesare ca să se poată judeca semnalul. */
const B_INCHIDERI_NECESARE = B_FEREASTRA_RANG + B_FEREASTRA_VOL + 1;   // 745

/* ============================ semnalul =================================== */

/**
 * Volatilitatea: abaterea standard (pe populație, împărțit la 24) a celor 24 de
 * randamente log dintre 25 de închideri consecutive. Socotită în două treceri —
 * întâi media, apoi abaterile — ca să nu depindă de istoric.
 */
function b_volatilitate(array $inchideri): float {
    $n = count($inchideri);
    if ($n !== B_FEREASTRA_VOL + 1) {
        throw new InvalidArgumentException("b_volatilitate cere " . (B_FEREASTRA_VOL + 1) . " închideri, a primit $n");
    }
    $r = [];
    for ($i = 1; $i < $n; $i++) { $r[] = log($inchideri[$i] / $inchideri[$i - 1]); }
    $m = 0.0;
    foreach ($r as $x) { $m += $x; }
    $m /= B_FEREASTRA_VOL;
    $v = 0.0;
    foreach ($r as $x) { $v += ($x - $m) * ($x - $m); }
    return sqrt($v / B_FEREASTRA_VOL);
}

/**
 * Rangul: ce parte din volatilitățile anterioare e STRICT mai mică decât cea de
 * acum (0 = cea mai liniștită oră din fereastră, aproape 1 = cea mai agitată).
 * Egalitățile nu se numără, exact ca bisect_left din simulare.
 */
function b_rang(float $vol, array $anterioare): float {
    $n = count($anterioare);
    if ($n === 0) { throw new InvalidArgumentException('b_rang fără valori anterioare'); }
    $sub = 0;
    foreach ($anterioare as $x) { if ($x < $vol) { $sub++; } }
    return $sub / $n;
}

/** Semnalul de panică: agitație în cincimea de sus ȘI preț mai mic decât acum 24 h. */
function b_semnal(float $rang, float $pretAcum, float $pret24): bool {
    return $rang >= B_PRAG_RANG && $pretAcum < $pret24;
}

/**
 * Tot semnalul, din șirul de închideri orare (cea mai veche prima, ultima = ora
 * judecată). Cere cel puțin B_INCHIDERI_NECESARE valori, consecutive, fără găuri.
 */
function b_evalueaza(array $inchideri): array {
    $n = count($inchideri);
    if ($n < B_INCHIDERI_NECESARE) {
        throw new InvalidArgumentException("b_evalueaza cere " . B_INCHIDERI_NECESARE . " închideri, a primit $n");
    }
    $c = array_slice($inchideri, $n - B_INCHIDERI_NECESARE);   // 745 de valori
    $vol = [];
    for ($i = B_FEREASTRA_VOL; $i < B_INCHIDERI_NECESARE; $i++) {
        $vol[] = b_volatilitate(array_slice($c, $i - B_FEREASTRA_VOL, B_FEREASTRA_VOL + 1));
    }
    $acum = array_pop($vol);                       // ultimele 720 rămân „anterioare”
    $rang = b_rang($acum, $vol);
    $pret = $c[B_INCHIDERI_NECESARE - 1];
    $p24  = $c[B_INCHIDERI_NECESARE - 1 - 24];
    return [
        'vol'    => $acum,
        'rang'   => $rang,
        'r24'    => $pret / $p24 - 1,
        'semnal' => b_semnal($rang, $pret, $p24),
    ];
}

/* ============================ băncile ==================================== */

/**
 * Starea unei bănci: ['usdc' => float, 'q' => [moneda => cantitate],
 *                      'exc' => [moneda => ['expira' => ?int ora, 'activa' => bool]]]
 */
function b_stareNoua(float $usdc): array {
    $s = ['usdc' => $usdc, 'q' => [], 'exc' => []];
    foreach (B_MONEDE as $m) {
        $s['q'][$m] = 0.0;
        $s['exc'][$m] = ['expira' => null, 'activa' => false];
    }
    return $s;
}

/** Ponderea-țintă a unei monede: A și B pornesc de la aceeași bază. */
function b_tinta(string $banca, bool $exceptieActiva): float {
    $baza = B_PROPORTIE / count(B_MONEDE);
    if ($banca === 'B' && $exceptieActiva) { return max($baza, B_PONDERE_EXC); }
    return $baza;
}

/** Valoarea băncii la prețurile date. */
function b_valoare(array $s, array $pret): float {
    $v = $s['usdc'];
    foreach (B_MONEDE as $m) { $v += $s['q'][$m] * $pret[$m]; }
    return $v;
}

/**
 * Se tranzacționează? La un eveniment (pornirea sau expirarea unei excepții),
 * da, până la țintă. Altfel doar dacă abaterea depășește banda.
 */
function b_trebuie(float $diferenta, float $capital, bool $eveniment): bool {
    if (abs($diferenta) > B_BANDA * $capital) { return true; }
    return $eveniment && abs($diferenta) > B_PRAG_ZERO;
}

/**
 * O oră din viața unei bănci A sau B. Funcția pe care o rulează motorul.
 *
 *  $ora      ora unix judecată
 *  $semnale  [moneda => bool]  — semnalul de panică al orei (doar B îl folosește)
 *  $pret     [moneda => ['eval' => preț de evaluare, 'cump' => ask, 'vinde' => bid]]
 *            (în simulare toate trei sunt close-ul orei)
 *  $comision pe o parte, ca fracție (0.00075)
 *  $farLevier true = cum rulează motorul: întâi vânzările, apoi cumpărările, iar o
 *            cumpărare nu cheltuie mai mult USDC decât există. false = exact ca
 *            simularea (BTC, ETH, ZEC în ordine, USDC poate coborî sub zero) —
 *            folosit DOAR de proba de echivalență. Simularea băncii B a avut
 *            așa, fără intenție, USDC negativ în 4% din ore (până la −6% din
 *            capital): un levier mic pe care o bursă spot nu-l permite.
 *
 * Întoarce [starea nouă, lista tranzacțiilor]. O tranzacție:
 *  ['moneda','cantitate' (+ cumpărare / − vânzare),'pret','comision','motiv','tinta']
 */
function b_pasOra(string $banca, array $s, int $ora, array $semnale, array $pret, float $comision,
                  bool $farLevier = true): array {
    $eval = [];
    foreach (B_MONEDE as $m) { $eval[$m] = $pret[$m]['eval']; }
    $capital = b_valoare($s, $eval);       // capitalul de la începutul orei
    $tranz = [];

    // 1. excepțiile: întâi expirarea, apoi pornirea — un semnal în chiar ora
    //    expirării e ignorat, ca în simulare (expira < ora, strict).
    $eveniment = [];
    $motivEv = [];
    foreach (B_MONEDE as $m) {
        $eveniment[$m] = false;
        if ($banca !== 'B') { continue; }
        $e = &$s['exc'][$m];
        if ($e['activa'] && $e['expira'] <= $ora) {
            $e['activa'] = false;
            $eveniment[$m] = true;
            $motivEv[$m] = 'excepția a expirat, înapoi la bază';
        }
        if (!$e['activa'] && ($e['expira'] === null || $e['expira'] < $ora) && !empty($semnale[$m])) {
            $e['activa'] = true;
            $e['expira'] = $ora + B_DURATA_EXC;
            $eveniment[$m] = true;
            $motivEv[$m] = 'semnal de panică, excepție pe ' . B_DURATA_EXC . ' h';
        }
        unset($e);
    }

    // 2. ținte; ce trebuie tranzacționat se hotărăște pe capitalul de la începutul orei
    $deFacut = [];
    foreach (B_MONEDE as $m) {
        $activa = $banca === 'B' && $s['exc'][$m]['activa'];
        $tinta = b_tinta($banca, $activa);
        $dif = $tinta * $capital - $s['q'][$m] * $eval[$m];
        if (b_trebuie($dif, $capital, $eveniment[$m])) { $deFacut[] = [$m, $tinta, $dif]; }
    }
    // fără levier: vânzările întâi, ca banii lor să poată fi folosiți la cumpărări
    if ($farLevier) {
        usort($deFacut, fn($a, $b) => ($a[2] > 0) <=> ($b[2] > 0));
    }

    // 3. execuția
    foreach ($deFacut as [$m, $tinta, $dif]) {
        $dq = $dif / $eval[$m];
        $limitat = false;
        if ($farLevier && $dq > 0) {
            $max = max(0.0, $s['usdc']) / ($pret[$m]['cump'] * (1 + $comision));
            if ($dq > $max) { $dq = $max; $limitat = true; }
            if ($dq * $eval[$m] <= B_PRAG_ZERO) { continue; }
        }
        if ($dq > 0) {
            $px = $pret[$m]['cump'];
            $fee = $dq * $px * $comision;
            $s['usdc'] -= $dq * $px + $fee;
        } else {
            $px = $pret[$m]['vinde'];
            $fee = -$dq * $px * $comision;
            $s['usdc'] += -$dq * $px - $fee;
        }
        $s['q'][$m] += $dq;
        $tranz[] = [
            'moneda'    => $m,
            'cantitate' => $dq,
            'pret'      => $px,
            'comision'  => $fee,
            'tinta'     => $tinta,
            'motiv'     => ($motivEv[$m] ?? 'reechilibrare: abatere peste ' . (B_BANDA * 100) . '% din capital')
                         . ($limitat ? ' (limitată la USDC disponibil)' : ''),
        ];
    }
    return [$s, $tranz];
}

/**
 * Banca C, pasivă: cumpără 1/3 din fiecare monedă o singură dată, apoi nimic.
 * Aceeași socoteală a comisionului ca la celelalte.
 */
function b_pornestePasiv(float $usdc, array $pret, float $comision, bool $farLevier = true): array {
    $s = b_stareNoua($usdc);
    $tranz = [];
    // fără levier, comisionul iese din aceeași treime; simularea îl punea peste
    $parte = $usdc / count(B_MONEDE) / ($farLevier ? 1 + $comision : 1);
    foreach (B_MONEDE as $m) {
        $px = $pret[$m]['cump'];
        // fără levier, cantitatea se socotește la prețul plătit (ask), ca USDC să
        // ajungă fix la zero; simularea o socotea la prețul de evaluare
        $dq = $parte / ($farLevier ? $px : $pret[$m]['eval']);
        $fee = $dq * $px * $comision;
        $s['usdc'] -= $dq * $px + $fee;
        $s['q'][$m] = $dq;
        $tranz[] = ['moneda' => $m, 'cantitate' => $dq, 'pret' => $px, 'comision' => $fee,
                    'tinta' => 1 / count(B_MONEDE), 'motiv' => 'pornire: o treime în fiecare monedă'];
    }
    return [$s, $tranz];
}
