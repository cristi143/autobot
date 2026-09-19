<?php
/**
 * Starea pe care o afișează panoul lateral: poziție, triunghi activ, bănci,
 * ultimele tranzacții.
 *
 * Totul e real, scris de motor în baza de date.
 */

declare(strict_types=1);
require __DIR__ . '/_comun.php';

$pdo   = baza();
$acum  = acum_ms();
$reguli = config()['reguli'] ?? [];

/* ---- triunghiul activ, cu prețurile liniilor la ora curentă ---- */

$triunghi = null;
$rand = $pdo->query("SELECT id, desenat_la, nota FROM triunghiuri
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
        'nota'    => $rand['nota'],
    ];
}

/* ---- poziția deschisă ---- */

$pozitie = ['deschisa' => false];

$oreMaxime = (int)($reguli['ore_maxime'] ?? 48);

$p = $pdo->query("SELECT * FROM pozitii WHERE stare = 'deschisa' LIMIT 1")->fetch();
if ($p) {
    $pozitie = [
        'deschisa'   => true,
        'tip'        => $p['banca'],
        'banca'      => $p['banca'],
        'intrare'    => (float)$p['intrare_pret'],
        'intrare_ms' => (int)$p['intrare_ora'],
        'cantitate'  => (float)$p['cantitate'],
        // Din 19.09.2026 amândouă sunt prețuri fixe, așezate la intrare. Nu mai
        // e nimic de evaluat la fiecare cerere — și nici de desenat ca linie
        // înclinată: sunt două praguri orizontale.
        'tp'         => (float)$p['tp_pret'],
        'sl'         => $p['sl_pret'] === null ? null : (float)$p['sl_pret'],
        'atr'        => $p['atr_intrare'] === null ? null : (float)$p['atr_intrare'],
        // Cât de departe a mers prețul de când e deschisă, brut, în procente.
        'mfe'        => (float)$p['mfe_proc'],
        'mae'        => (float)$p['mae_proc'],
        'expira_ms'  => $oreMaxime > 0
            ? (int)$p['intrare_ora'] + $oreMaxime * 3600000
            : null,
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

/* ---- statistici peste TOATE tranzacțiile încheiate ----
   Nu peste ultimele zece afișate în listă: o rată de reușită calculată pe o
   fereastră mișcătoare ar spune altceva decât adevărul. */

$s = $pdo->query("
    SELECT COUNT(*)                                                AS total,
           SUM(motiv_iesire = 'tp')                                AS tp,
           SUM(motiv_iesire = 'sl')                                AS sl,
           AVG(CASE WHEN motiv_iesire = 'tp' THEN rezultat_proc END) AS medie_tp,
           AVG(CASE WHEN motiv_iesire = 'sl' THEN rezultat_proc END) AS medie_sl,
           MAX(rezultat_proc)                                      AS maxim,
           MIN(rezultat_proc)                                      AS minim
    FROM pozitii WHERE stare = 'inchisa'
")->fetch() ?: [];

$statistici = [
    'total'    => (int)($s['total'] ?? 0),
    'tp'       => (int)($s['tp'] ?? 0),
    'sl'       => (int)($s['sl'] ?? 0),
    'medie_tp' => isset($s['medie_tp']) && $s['medie_tp'] !== null ? round((float)$s['medie_tp'], 4) : null,
    'medie_sl' => isset($s['medie_sl']) && $s['medie_sl'] !== null ? round((float)$s['medie_sl'], 4) : null,
    'maxim'    => isset($s['maxim'])    && $s['maxim']    !== null ? round((float)$s['maxim'], 4) : null,
    'minim'    => isset($s['minim'])    && $s['minim']    !== null ? round((float)$s['minim'], 4) : null,
];

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
    'statistici' => $statistici,
    'reguli'   => [
        'tp_atr'           => (float)($reguli['tp_atr'] ?? 1.5),
        'sl_atr'           => (float)($reguli['sl_atr'] ?? 1.0),
        'atr_perioada'     => (int)($reguli['atr_perioada'] ?? 14),
        'ore_maxime'       => $oreMaxime,
        'comision_o_parte' => (float)($reguli['comision_o_parte'] ?? 0.075),
    ],
]);
