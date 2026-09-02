<?php
/**
 * Motorul — rulează din cron, o dată pe oră, la minutul 1.
 *
 * DOUĂ RITMURI DIFERITE, INTENȚIONAT:
 *
 *   TP-ul se verifică LA FIECARE RULARE, inclusiv pe lumânarea în formare.
 *   Un TP e un ordin limită la un preț cunoscut: dacă maximul l-a atins, s-ar fi
 *   executat deja. Nu are rost să așteptăm închiderea orei ca s-o recunoaștem —
 *   cu cronul la 5 minute, poziția se închide în cel mult atâta.
 *
 *   SL-ul și semnalele noi se evaluează O SINGURĂ DATĂ PER LUMÂNARE ÎNCHISĂ,
 *   pentru că așa sunt definite: pe închidere. O rulare care prinde aceeași
 *   lumânare a doua oară nu le mai atinge.
 *
 * De aici și ordinea: TP înaintea SL. Nu e o preferință — SL-ul se judecă în
 * ultima clipă a orei, TP-ul oricând în timpul ei, deci TP-ul e primul prin
 * construcție.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

const CALE_CONFIG = '/home/marcelpa/autobot-config.php';
const ORA_MS      = 3600000;

$inceput = (int)(microtime(true) * 1000);
$jurnal  = [];

function spune(string $text): void {
    global $jurnal;
    $jurnal[] = $text;
    if (PHP_SAPI === 'cli') { echo $text, "\n"; }
}

/* ------------------------------------------------------------ configurarea */

if (!is_readable(CALE_CONFIG)) {
    fwrite(STDERR, "Configurarea lipsește: " . CALE_CONFIG . "\n");
    exit(1);
}
$config = require CALE_CONFIG;
$simbol   = $config['piata']['simbol'] ?? 'ZECUSDC';
$tpProc   = (float)($config['reguli']['tp_procent'] ?? 1.0);
$comision = (float)($config['reguli']['comision_o_parte'] ?? 0.075) / 100.0;
$capital  = (float)($config['reguli']['capital_initial'] ?? 500.0);

/* ------------------------------------------------------------ baza de date */

try {
    $d = $config['db'];
    $pdo = new PDO(
        "mysql:host={$d['gazda']};dbname={$d['nume']};charset=utf8mb4",
        $d['user'], $d['parola'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    fwrite(STDERR, "Conexiune eșuată: " . $e->getMessage() . "\n");
    exit(1);
}

/** Scrie în jurnal ce s-a întâmplat și iese. */
function incheie(string $rezultat, ?int $oraLumanare = null): never {
    global $pdo, $inceput, $jurnal;
    try {
        $pdo->prepare("INSERT INTO jurnal_cron (pornit_la, durata_ms, ora_lumanare, rezultat, detaliu)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([
                $inceput,
                (int)(microtime(true) * 1000) - $inceput,
                $oraLumanare,
                $rezultat,
                implode("\n", $jurnal) ?: null,
            ]);
    } catch (Throwable $e) {
        fwrite(STDERR, "Nu pot scrie jurnalul: " . $e->getMessage() . "\n");
    }
    exit($rezultat === 'eroare' ? 1 : 0);
}

set_error_handler(function ($nr, $msg, $fis, $lin) {
    spune("EROARE PHP: $msg ($fis:$lin)");
    incheie('eroare');
});

/* ---------------------------------------------------------------- Binance */

function binance(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $r   = curl_exec($ch);
    $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($r === false)  { spune("Binance: $err");        incheie('eroare'); }
    if ($cod !== 200)  { spune("Binance: HTTP $cod");    incheie('eroare'); }

    $d = json_decode($r, true);
    if (!is_array($d)) { spune("Binance: răspuns necitibil"); incheie('eroare'); }
    return $d;
}

// limit=2: prima e ultima lumânare ÎNCHISĂ, a doua e cea în formare — iar
// deschiderea ei e exact prețul la care s-ar executa un ordin dat acum.
$k = binance("https://api.binance.com/api/v3/klines?symbol=$simbol&interval=1h&limit=2");
if (count($k) < 2) { spune("Binance a dat mai puțin de două lumânări."); incheie('fara_date'); }

$inchisa = [
    'ora'        => (int)$k[0][0],
    'deschidere' => (float)$k[0][1],
    'maxim'      => (float)$k[0][2],
    'minim'      => (float)$k[0][3],
    'inchidere'  => (float)$k[0][4],
    'volum'      => (float)$k[0][5],
];
// Lumânarea în formare: de aici luăm prețul de execuție și maximul atins până
// acum, pentru TP.
$informare = [
    'deschidere' => (float)$k[1][1],
    'maxim'      => (float)$k[1][2],
    'minim'      => (float)$k[1][3],
    'inchidere'  => (float)$k[1][4],
];
$pretExecutie = $informare['deschidere'];

spune(sprintf("Lumânarea %s UTC: O %.2f H %.2f L %.2f C %.2f · în formare acum: %.2f",
    gmdate('Y-m-d H:i', intdiv($inchisa['ora'], 1000)),
    $inchisa['deschidere'], $inchisa['maxim'], $inchisa['minim'],
    $inchisa['inchidere'], $informare['inchidere']));

/* ------------------------------------- ce parte din treabă mai e de făcut? */

// Munca „pe închidere" — SL și semnale — se face o singură dată per lumânare.
// TP-ul se verifică oricum, la fiecare rulare.
$st = $pdo->prepare("SELECT COUNT(*) FROM jurnal_cron
                     WHERE ora_lumanare = ? AND rezultat = 'ok'");
$st->execute([$inchisa['ora']]);
$deFacutOrarul = ((int)$st->fetchColumn() === 0);

if (!$deFacutOrarul) {
    spune("Lumânarea închisă e deja procesată — verific doar TP-ul.");
}

/* -------------------------------------------------------- salvăm lumânarea */

$pdo->prepare("INSERT INTO lumanari_1h
                 (ora_deschidere, deschidere, maxim, minim, inchidere, volum, adaugat_la)
               VALUES (?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE
                 maxim = VALUES(maxim), minim = VALUES(minim),
                 inchidere = VALUES(inchidere), volum = VALUES(volum)")
    ->execute([$inchisa['ora'], $inchisa['deschidere'], $inchisa['maxim'],
               $inchisa['minim'], $inchisa['inchidere'], $inchisa['volum'], $inceput]);

/* ------------------------------------------------------------- ajutoare */

function pretLinie(array $l, int $moment): float {
    $t1 = (int)$l['t1']; $t2 = (int)$l['t2'];
    if ($t1 === $t2) return (float)$l['p1'];
    $panta = ((float)$l['p2'] - (float)$l['p1']) / ($t2 - $t1);
    return (float)$l['p1'] + $panta * ($moment - $t1);
}

/**
 * Momentul în care două linii convergente se intersectează, sau null dacă nu se
 * întâlnesc niciodată. După vârf, triunghiul nu mai are sens: „sus" ajunge sub
 * „jos", iar orice lumânare verde ar declanșa un long fals.
 */
function varfulTriunghiului(array $sus, array $jos): ?int {
    $m1 = ((float)$sus['p2'] - (float)$sus['p1']) / ((int)$sus['t2'] - (int)$sus['t1']);
    $m2 = ((float)$jos['p2'] - (float)$jos['p1']) / ((int)$jos['t2'] - (int)$jos['t1']);
    if (abs($m1 - $m2) < 1e-15) return null;          // paralele: canal, nu triunghi

    $b1 = (float)$sus['p1'] - $m1 * (int)$sus['t1'];
    $b2 = (float)$jos['p1'] - $m2 * (int)$jos['t1'];
    return (int)round(($b2 - $b1) / ($m1 - $m2));
}

function citesteBanca(string $care): array {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM banci WHERE banca = ?");
    $st->execute([$care]);
    return $st->fetch() ?: ['banca' => $care, 'sold_usdc' => 0, 'sold_zec' => 0];
}

function scrieBanca(string $care, float $usdc, float $zec, string $fel,
                    ?int $pozitieId, ?float $pret, ?float $cantitate,
                    float $comisionPlatit, string $explicatie): void {
    global $pdo, $inceput;
    $pdo->prepare("UPDATE banci SET sold_usdc = ?, sold_zec = ?, actualizat_la = ?
                   WHERE banca = ?")
        ->execute([$usdc, $zec, $inceput, $care]);
    $pdo->prepare("INSERT INTO miscari
                     (banca, pozitie_id, moment, fel, pret, cantitate_zec, comision,
                      sold_usdc_dupa, sold_zec_dupa, explicatie)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$care, $pozitieId, $inceput, $fel, $pret, $cantitate,
                   $comisionPlatit, $usdc, $zec, $explicatie]);
}

/* ------------------------------- banca de short pornește ținând ZEC, nu USDC */

$bancaShort = citesteBanca('short');
if ((float)$bancaShort['sold_zec'] == 0.0 && (float)$bancaShort['sold_usdc'] > 0) {
    // Finanțare inițială, nu tranzacție — fără comision. Banca de short e „în
    // piață" implicit: se măsoară în ZEC, deci trebuie să pornească ținând ZEC.
    $zec = (float)$bancaShort['sold_usdc'] / $pretExecutie;
    scrieBanca('short', 0.0, $zec, 'initializare', null, $pretExecutie, $zec, 0.0,
               'finanțare inițială: ' . number_format((float)$bancaShort['sold_usdc'], 2) . ' USDC → ZEC');
    spune(sprintf("Banca de short inițializată: %.6f ZEC la %.2f", $zec, $pretExecutie));
    $bancaShort = citesteBanca('short');
}

/* ============================ 1. poziția deschisă ========================= */

$st = $pdo->prepare("SELECT p.*, l.t1, l.p1, l.t2, l.p2
                     FROM pozitii p JOIN linii l ON l.id = p.linie_sl_id
                     WHERE p.stare = 'deschisa' LIMIT 1");
$st->execute();
$pozitie = $st->fetch();

function inchidePozitia(array $p, float $pretIesire, string $motiv): void {
    global $pdo, $comision, $inceput;

    $intrare = (float)$p['intrare_pret'];
    $cant    = (float)$p['cantitate'];
    $f       = $comision;

    // Randamentul net al unui dus-întors: raportul de preț, minus comisionul
    // plătit de două ori. Pentru short, raportul se inversează — se câștigă
    // când prețul scade.
    $raport = ($p['banca'] === 'long')
        ? $pretIesire / $intrare
        : $intrare / $pretIesire;
    $rezultat = ($raport * (1 - $f) * (1 - $f) - 1) * 100;

    $b = citesteBanca($p['banca']);

    if ($p['banca'] === 'long') {
        $incasat = $cant * $pretIesire * (1 - $f);
        scrieBanca('long', $incasat, 0.0, 'iesire', (int)$p['id'], $pretIesire, $cant,
                   $cant * $pretIesire * $f, strtoupper($motiv) . ': vând ZEC');
    } else {
        $cumparat = ((float)$b['sold_usdc'] / $pretIesire) * (1 - $f);
        scrieBanca('short', 0.0, $cumparat, 'iesire', (int)$p['id'], $pretIesire, $cumparat,
                   (float)$b['sold_usdc'] * $f, strtoupper($motiv) . ': răscumpăr ZEC');
    }

    $pdo->prepare("UPDATE pozitii SET stare='inchisa', iesire_ora=?, iesire_pret=?,
                     motiv_iesire=?, rezultat_proc=?,
                     comision_total = comision_total + ?
                   WHERE id = ?")
        ->execute([$inceput, $pretIesire, $motiv, round($rezultat, 4),
                   round($cant * $pretIesire * $f, 8), (int)$p['id']]);

    spune(sprintf("Închis pe %s la %.2f — rezultat net %+.2f%%", strtoupper($motiv), $pretIesire, $rezultat));
}

if ($pozitie) {
    $tip = $pozitie['banca'];
    $tp  = (float)$pozitie['tp_pret'];

    // --- TP: ordin limită, s-ar fi executat oricând ---
    // Se uită și la lumânarea în formare: dacă maximul de până acum a atins
    // pragul, ordinul s-a executat deja, nu are rost să așteptăm închiderea.
    // Lumânarea închisă intră în socoteală doar dacă n-a fost încă procesată,
    // altfel am reevalua o oră deja judecată.
    $maximDeVazut = $informare['maxim'];
    $minimDeVazut = $informare['minim'];
    if ($deFacutOrarul) {
        $maximDeVazut = max($maximDeVazut, $inchisa['maxim']);
        $minimDeVazut = min($minimDeVazut, $inchisa['minim']);
    }

    $atinsTP = ($tip === 'long') ? $maximDeVazut >= $tp : $minimDeVazut <= $tp;

    if ($atinsTP) {
        inchidePozitia($pozitie, $tp, 'tp');
        $pozitie = null;
    } elseif (!$deFacutOrarul) {
        spune(sprintf("Poziția %s rămâne deschisă. TP %.2f, încă neatins.", $tip, $tp));
    } else {
        // --- SL: se judecă pe închidere, față de linia care a dat intrarea ---
        $prag = pretLinie($pozitie, $inchisa['ora']);
        $rupt = ($tip === 'long')
            ? $inchisa['inchidere'] < $prag
            : $inchisa['inchidere'] > $prag;

        if ($rupt) {
            spune(sprintf("SL: închiderea %.2f a trecut înapoi de linie (%.2f)", $inchisa['inchidere'], $prag));
            inchidePozitia($pozitie, $pretExecutie, 'sl');
            $pozitie = null;
        } else {
            spune(sprintf("Poziția %s rămâne deschisă. TP %.2f, linia acum %.2f", $tip, $tp, $prag));
        }
    }
}

/* ============================ 2. semnale noi ============================= */

if (!$deFacutOrarul) {
    // Lumânarea închisă a fost deja judecată la o rulare anterioară.
    incheie('ok', null);
}

if ($pozitie) {
    spune("Poziție încă deschisă — nu caut semnale noi.");
    incheie('ok', $inchisa['ora']);
}

$verde = $inchisa['inchidere'] > $inchisa['deschidere'];
$rosu  = $inchisa['inchidere'] < $inchisa['deschidere'];

$triunghiuri = $pdo->query("SELECT id FROM triunghiuri WHERE stare = 'activ'
                            ORDER BY desenat_la ASC")->fetchAll();

if (!$triunghiuri) {
    spune("Niciun triunghi activ.");
    incheie('ok', $inchisa['ora']);
}

foreach ($triunghiuri as $t) {
    $st = $pdo->prepare("SELECT * FROM linii WHERE triunghi_id = ?");
    $st->execute([$t['id']]);
    $linii = [];
    foreach ($st->fetchAll() as $l) { $linii[$l['rol']] = $l; }

    if (!isset($linii['sus']) || !isset($linii['jos'])) {
        spune("Triunghiul #{$t['id']} n-are ambele linii — îl sar.");
        continue;
    }

    // Trecut de vârf, fără spargere: liniile s-au intersectat, rolurile n-ar mai
    // însemna nimic. Îl scoatem din joc în loc să producă un semnal fals.
    $varf = varfulTriunghiului($linii['sus'], $linii['jos']);
    if ($varf !== null && $inchisa['ora'] >= $varf) {
        $pdo->prepare("UPDATE triunghiuri SET stare='sters', nota=? WHERE id=?")
            ->execute(['expirat: liniile s-au intersectat pe ' .
                       gmdate('Y-m-d H:i', intdiv($varf, 1000)) . ' UTC fără spargere',
                       $t['id']]);
        spune("Triunghiul #{$t['id']} a trecut de vârf fără spargere — expirat.");
        continue;
    }

    $semnal = null;
    if ($verde && isset($linii['sus'])) {
        $prag = pretLinie($linii['sus'], $inchisa['ora']);
        if ($inchisa['inchidere'] > $prag) {
            $semnal = ['tip' => 'long', 'linie' => $linii['sus'], 'prag' => $prag];
        }
    }
    if (!$semnal && $rosu && isset($linii['jos'])) {
        $prag = pretLinie($linii['jos'], $inchisa['ora']);
        if ($inchisa['inchidere'] < $prag) {
            $semnal = ['tip' => 'short', 'linie' => $linii['jos'], 'prag' => $prag];
        }
    }

    if (!$semnal) { continue; }

    /* --- avem semnal: consumăm triunghiul și deschidem poziția --- */

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE triunghiuri SET stare='consumat', consumat_la=? WHERE id=?")
            ->execute([$inceput, $t['id']]);

        $pdo->prepare("INSERT INTO semnale
                         (triunghi_id, linie_id, ora_lumanare, tip, pret_inchidere, pret_linie, creat_la)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$t['id'], $semnal['linie']['id'], $inchisa['ora'], $semnal['tip'],
                       $inchisa['inchidere'], round($semnal['prag'], 8), $inceput]);
        $semnalId = (int)$pdo->lastInsertId();

        $banca = citesteBanca($semnal['tip']);

        if ($semnal['tip'] === 'long') {
            $usdc = (float)$banca['sold_usdc'];
            if ($usdc <= 0) { throw new RuntimeException('banca de long n-are USDC'); }
            $cant = ($usdc / $pretExecutie) * (1 - $comision);
            $tp   = $pretExecutie * (1 + $tpProc / 100);
            $comisionIntrare = $usdc * $comision;          // plătit din USDC-ul dat
        } else {
            $zec = (float)$banca['sold_zec'];
            if ($zec <= 0) { throw new RuntimeException('banca de short n-are ZEC'); }
            $cant = $zec;
            $tp   = $pretExecutie * (1 - $tpProc / 100);
            $comisionIntrare = $zec * $pretExecutie * $comision;   // din USDC-ul încasat
        }

        $pdo->prepare("INSERT INTO pozitii
                         (semnal_id, banca, stare, intrare_ora, intrare_pret, cantitate,
                          tp_pret, linie_sl_id, comision_total)
                       VALUES (?,?,'deschisa',?,?,?,?,?,?)")
            ->execute([$semnalId, $semnal['tip'], $inceput, $pretExecutie,
                       round($cant, 8), round($tp, 8), $semnal['linie']['id'],
                       round($pretExecutie * $cant * $comision, 8)]);
        $pozitieId = (int)$pdo->lastInsertId();

        if ($semnal['tip'] === 'long') {
            scrieBanca('long', 0.0, $cant, 'intrare', $pozitieId, $pretExecutie, $cant,
                       $comisionIntrare, 'LONG: cumpăr ZEC');
        } else {
            $incasat = $cant * $pretExecutie * (1 - $comision);
            scrieBanca('short', $incasat, 0.0, 'intrare', $pozitieId, $pretExecutie, $cant,
                       $comisionIntrare, 'SHORT: vând ZEC');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        spune("Nu am putut deschide poziția: " . $e->getMessage());
        incheie('eroare', $inchisa['ora']);
    }

    spune(sprintf("SEMNAL %s din triunghiul #%d: închiderea %.2f a rupt linia (%.2f). "
                . "Intrare la %.2f, TP %.2f",
        strtoupper($semnal['tip']), $t['id'], $inchisa['inchidere'], $semnal['prag'],
        $pretExecutie, $tp));

    incheie('ok', $inchisa['ora']);   // o singură poziție odată
}

spune("Triunghiuri active, dar niciunul nu a fost rupt.");
incheie('ok', $inchisa['ora']);
