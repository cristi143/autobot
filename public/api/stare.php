<?php
/**
 * Starea pe care o afișează panoul lateral: poziție, triunghi activ, bănci,
 * ultimele tranzacții.
 *
 * Deocamdată pozițiile și tranzacțiile sunt mereu goale — motorul, care le
 * produce, vine la etapa 2. Băncile și triunghiurile sunt însă reale, citite
 * din baza de date.
 */

declare(strict_types=1);
require __DIR__ . '/_comun.php';

$pdo   = baza();
$acum  = acum_ms();
$reguli = config()['reguli'] ?? [];

/* ---- triunghiul activ, cu prețurile liniilor la ora curentă ---- */

$triunghi = null;
$rand = $pdo->query("SELECT id, desenat_la FROM triunghiuri
                     WHERE stare = 'activ' ORDER BY desenat_la DESC LIMIT 1")->fetch();
if ($rand) {
    $st = $pdo->prepare("SELECT rol, t1, p1, t2, p2 FROM linii WHERE triunghi_id = ?");
    $st->execute([$rand['id']]);

    $preturi = [];
    foreach ($st->fetchAll() as $l) {
        $preturi[$l['rol']] = round(pret_linie($l, $acum), 8);
    }

    $triunghi = [
        'exista'  => true,
        'id'      => (int)$rand['id'],
        'sus'     => $preturi['sus'] ?? null,
        'jos'     => $preturi['jos'] ?? null,
        'desenat' => (int)$rand['desenat_la'],
    ];
}

/* ---- poziția deschisă (nu există până la etapa 2) ---- */

$pozitie = ['deschisa' => false];

$p = $pdo->query("SELECT p.*, l.t1, l.p1, l.t2, l.p2
                  FROM pozitii p
                  JOIN linii l ON l.id = p.linie_sl_id
                  WHERE p.stare = 'deschisa' LIMIT 1")->fetch();
if ($p) {
    $pozitie = [
        'deschisa'   => true,
        'tip'        => $p['banca'],
        'banca'      => $p['banca'],
        'intrare'    => (float)$p['intrare_pret'],
        'intrare_ms' => (int)$p['intrare_ora'],
        'cantitate'  => (float)$p['cantitate'],
        'tp'         => (float)$p['tp_pret'],
        // SL-ul nu e un preț stocat: e linia, evaluată acum și cu o oră în urmă,
        // ca panoul să poată arăta încotro se mișcă.
        'sl_acum'          => round(pret_linie($p, $acum), 8),
        'sl_ora_trecuta'   => round(pret_linie($p, $acum - 3600000), 8),
        // Geometria liniei, ca graficul s-o poată desena. Cât timp poziția e
        // deschisă, linia asta E stop loss-ul — trebuie văzută, nu doar citită
        // ca număr. Triunghiul e deja consumat, deci nu se mai desenează singur.
        'sl_linie' => [
            't1' => (int)$p['t1'], 'p1' => (float)$p['p1'],
            't2' => (int)$p['t2'], 'p2' => (float)$p['p2'],
        ],
    ];
}

/* ---- băncile ----
   Fiecare bancă are o monedă în care SE MĂSOARĂ, alta decât cea pe care o ține
   în clipa asta: cea de long se judecă în USDC, cea de short în ZEC. Serverul
   trimite ambele solduri și punctele de pornire; conversia o face pagina, cu
   prețul live din grafic, care e mai proaspăt decât ultima lumânare închisă. */

$capital = (float)($reguli['capital_initial'] ?? 500.0);

// Prețul la care a fost finanțată banca de short — reperul pentru „cumpăr și țin".
$init = $pdo->query("SELECT pret, cantitate_zec FROM miscari
                     WHERE fel = 'initializare' AND banca = 'short'
                     ORDER BY moment ASC LIMIT 1")->fetch() ?: null;

$banci = [];
foreach ($pdo->query("SELECT * FROM banci") as $b) {
    $banci[$b['banca']] = [
        'tine'      => (float)$b['sold_zec'] > 0 ? 'ZEC' : 'USDC',
        'sold_usdc' => (float)$b['sold_usdc'],
        'sold_zec'  => (float)$b['sold_zec'],
    ];
}
$banci['long']['masurata_in']  = 'USDC';
$banci['short']['masurata_in'] = 'ZEC';
$banci['long']['pornire']  = $capital;                                    // USDC
$banci['short']['pornire'] = $init ? (float)$init['cantitate_zec'] : null; // ZEC

$pretInitial = $init ? (float)$init['pret'] : null;

/* ---- ultimele tranzacții închise ---- */

$istoric = [];
foreach ($pdo->query("SELECT banca, iesire_ora, motiv_iesire, rezultat_proc
                      FROM pozitii WHERE stare = 'inchisa'
                      ORDER BY iesire_ora DESC LIMIT 10") as $t) {
    $istoric[] = [
        'cand'     => (int)$t['iesire_ora'],
        'banca'    => $t['banca'],
        'motiv'    => strtoupper((string)$t['motiv_iesire']),
        'rezultat' => $t['rezultat_proc'] === null ? null : (float)$t['rezultat_proc'],
    ];
}

/* ---- ultima rulare a cronului ----
   Un cron mort arată exact ca unul care n-a avut ce face. Singura deosebire e
   cât timp a trecut de la ultima rulare: motorul scrie în jurnal de fiecare
   dată, chiar și când nu face nimic. Peste două ore fără nicio urmă, ceva e
   stricat. */

$c = $pdo->query("SELECT pornit_la, rezultat FROM jurnal_cron
                  ORDER BY pornit_la DESC LIMIT 1")->fetch() ?: null;

$motor = ['implementat' => true, 'ultima_rulare' => null, 'intarziat' => false];
if ($c) {
    $motor['ultima_rulare'] = ['pornit_la' => (int)$c['pornit_la'], 'rezultat' => $c['rezultat']];
    $motor['intarziat'] = ($acum - (int)$c['pornit_la']) > 2 * 3600000;
} else {
    $motor['intarziat'] = true;   // n-a rulat niciodată
}

/* ---- ce versiune de cod are serverul ----
   Browserul o compară cu versiunea fișierului pe care chiar l-a încărcat.
   Diferite = rulează cod vechi din cache, iar panoul o spune. */

$versiune = null;
$caleDesen = __DIR__ . '/../desen.js';
if (is_readable($caleDesen)) {
    $cap = (string)file_get_contents($caleDesen, false, null, 0, 2048);
    if (preg_match('/VERSIUNE\s*=\s*"([^"]+)"/', $cap, $m)) {
        $versiune = $m[1];
    }
}

raspunde([
    'ok'       => true,
    'versiune' => $versiune,
    'acum'     => $acum,
    'motor'    => $motor,
    'pozitie'  => $pozitie,
    'triunghi' => $triunghi ?? ['exista' => false],
    'banci'    => $banci,
    'pret_initial' => $pretInitial,
    'istoric'  => $istoric,
    'reguli'   => [
        'tp_procent'       => (float)($reguli['tp_procent'] ?? 1.0),
        'comision_o_parte' => (float)($reguli['comision_o_parte'] ?? 0.075),
    ],
]);
