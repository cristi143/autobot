<?php
/**
 * API-ul botului automat — DOAR CITIRE.
 *
 *   GET -> băncile, deținerile, semnalul pe fiecare monedă, excepțiile, deciziile,
 *          starea motorului
 *
 * Două grupuri de bănci: „cos” (A, B, C — BTC+ETH+ZEC, docs/plan-bot.md) și
 * „zec” (D, E, F — doar ZEC, docs/plan-bot-zec.md). Excepții au B și F.
 *
 * Nu există nicio scriere: botul nu primește ordine de la pagină, altfel n-ar
 * mai fi testul unei reguli fixe (docs/plan-bot.md §7). Tot ce se schimbă,
 * schimbă motorul, din cron.
 */

declare(strict_types=1);
require __DIR__ . '/_comun.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { eroare('Botul nu primește comenzi.', 405); }

const MONEDE = ['BTCUSDC', 'ETHUSDC', 'ZECUSDC'];
$pdo = baza();

/* ---- ultima oră judecată și închiderile ei ---- */
$ultimaOra = $pdo->query("SELECT MAX(ora) FROM b_evaluari WHERE pierduta = 0")->fetchColumn();
$ultimaOra = $ultimaOra ? (int)$ultimaOra : null;

$semnal = [];
$inchidere = [];
if ($ultimaOra) {
    $st = $pdo->prepare("SELECT * FROM b_evaluari WHERE ora = ?");
    $st->execute([$ultimaOra]);
    foreach ($st as $e) {
        $inchidere[$e['simbol']] = (float)$e['inchidere'];
        $semnal[$e['simbol']] = [
            'pret'   => (float)$e['inchidere'],
            'vol'    => (float)$e['vol'],
            'rang'   => (float)$e['rang'],
            'r24'    => (float)$e['r24'],
            'semnal' => (bool)$e['semnal'],
        ];
    }
}

/* ---- excepțiile ---- */
$active = []; $inchise = [];
foreach ($pdo->query("SELECT * FROM b_exceptii ORDER BY pornita_ora DESC, id DESC LIMIT 300") as $e) {
    $x = [
        'banca'   => $e['banca'],
        'simbol'  => $e['simbol'],
        'pornita' => (int)$e['pornita_ora'],
        'expira'  => (int)$e['expira_ora'],
        'inchisa' => $e['inchisa_ora'] === null ? null : (int)$e['inchisa_ora'],
        'pret_pornire'   => (float)$e['pret_pornire'],
        'pret_inchidere' => $e['pret_inchidere'] === null ? null : (float)$e['pret_inchidere'],
    ];
    if ($x['inchisa'] === null) {
        $active[] = $x;
        // `exceptie_pana` rămâne a băncii B, ca până acum; a băncii F e în lista `active`
        if ($x['banca'] === 'B' && isset($semnal[$x['simbol']])) { $semnal[$x['simbol']]['exceptie_pana'] = $x['expira']; }
    } else {
        $x['rezultat_brut'] = $x['pret_inchidere'] / $x['pret_pornire'] - 1;
        $inchise[] = $x;
    }
}
$stat = [];
foreach ($pdo->query("SELECT banca, COUNT(*) AS n, AVG(pret_inchidere / pret_pornire - 1) AS medie
                      FROM b_exceptii WHERE inchisa_ora IS NOT NULL GROUP BY banca") as $r) {
    $stat[$r['banca']] = ['numar' => (int)$r['n'], 'medie_bruta' => $r['medie'] === null ? null : (float)$r['medie']];
}

/* ---- băncile ---- */
$banci = [];
foreach ($pdo->query("SELECT * FROM b_banci ORDER BY banca") as $b) {
    $id = $b['banca'];
    $det = [];
    $st = $pdo->prepare("SELECT simbol, cantitate FROM b_detineri WHERE banca = ?");
    $st->execute([$id]);
    foreach ($st as $d) { $det[$d['simbol']] = (float)$d['cantitate']; }

    // valoarea și cea mai mare scădere, din evoluția orară
    $st = $pdo->prepare("SELECT ora, valoare FROM b_evolutie WHERE banca = ? ORDER BY ora");
    $st->execute([$id]);
    $varf = 0.0; $dd = 0.0; $valoare = null; $ora = null; $n = 0;
    foreach ($st as $v) {
        $x = (float)$v['valoare'];
        $varf = max($varf, $x);
        if ($varf > 0) { $dd = max($dd, 1 - $x / $varf); }
        $valoare = $x; $ora = (int)$v['ora']; $n++;
    }
    $usdc = (float)$b['usdc'];
    $parti = ['USDC' => $usdc];
    foreach (MONEDE as $m) {
        $parti[$m] = ($det[$m] ?? 0) * ($inchidere[$m] ?? 0);
    }
    $total = array_sum($parti);
    $banci[] = [
        'banca'      => $id,
        'grup'       => in_array($id, ['D', 'E', 'F'], true) ? 'zec' : 'cos',
        'initial'    => (float)$b['capital_initial'],
        'pornita'    => (int)$b['pornita_la'],
        'valoare'    => $valoare,
        'ora'        => $ora,
        'ore'        => $n,
        'scadere_max'=> $dd,
        'usdc'       => $usdc,
        'detineri'   => $det,
        'ponderi'    => $total > 0 ? array_map(fn($x) => $x / $total, $parti) : null,
    ];
}

/* ---- deciziile ---- */
$tranz = [];
foreach ($pdo->query("SELECT * FROM b_tranzactii ORDER BY id DESC LIMIT 80") as $t) {
    $q = (float)$t['cantitate'];
    $tranz[] = [
        'banca'     => $t['banca'],
        'ora'       => (int)$t['ora'],
        'simbol'    => $t['simbol'],
        'cantitate' => $q,
        'pret'      => (float)$t['pret'],
        'pret_simulare' => (float)$t['pret_simulare'],
        'valoare'   => abs($q) * (float)$t['pret'],
        'comision'  => (float)$t['comision'],
        'motiv'     => $t['motiv'],
    ];
}
// Cât a costat, în medie, să execuți la prețul de acum în loc de închiderea orei.
// Pozitiv = mai prost decât simularea (cumpărat mai scump / vândut mai ieftin).
$cost = $pdo->query("SELECT COUNT(*) AS n,
                            AVG(CASE WHEN cantitate > 0 THEN pret / pret_simulare - 1
                                     ELSE 1 - pret / pret_simulare END) AS medie
                     FROM b_tranzactii")->fetch();

/* ---- motorul ---- */
$motor = null;
if ($j = $pdo->query("SELECT * FROM b_jurnal ORDER BY id DESC LIMIT 1")->fetch()) {
    $motor = [
        'ultima'    => (int)$j['pornit_la'],
        'rezultat'  => $j['rezultat'],
        'detaliu'   => $j['detaliu'],
        'ora'       => $j['ora_judecata'] === null ? null : (int)$j['ora_judecata'],
    ];
}
$pierdute = (int)$pdo->query("SELECT COUNT(DISTINCT ora) FROM b_evaluari WHERE pierduta = 1")->fetchColumn();

/* ---- versiunea codului din pagină ---- */
$versiune = null;
$js = __DIR__ . '/../bot.js';
if (is_readable($js) && preg_match('/VERSIUNE\s*=\s*"([^"]+)"/', (string)file_get_contents($js, false, null, 0, 4000), $m)) {
    $versiune = $m[1];
}

raspunde([
    'ok'         => true,
    'versiune'   => $versiune,
    'comision'   => (float)(config()['reguli']['comision_o_parte'] ?? 0.075),
    'ultima_ora' => $ultimaOra,
    'banci'      => $banci,
    'semnal'     => $semnal,
    'exceptii'   => ['active' => $active, 'inchise' => array_slice($inchise, 0, 40), 'pe_banca' => $stat],
    'tranzactii' => $tranz,
    'cost_executie' => ['n' => (int)$cost['n'], 'medie' => $cost['medie'] === null ? null : (float)$cost['medie']],
    'motor'      => $motor,
    'ore_pierdute' => $pierdute,
]);
