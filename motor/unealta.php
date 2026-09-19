<?php
/**
 * Motorul uneltei manuale — rulează din cron, la fiecare minut.
 *
 * NU ARE NICIO LEGĂTURĂ CU motor.php. Alte tabele (`u_*`), alți bani, alt
 * jurnal, alt cron. Cele două nu se pot bloca și nu se pot amesteca. Regulile
 * întregi: ../docs/plan-unealta.md.
 *
 * CE FACE, PE SCURT: utilizatorul spune unde crede că ajunge prețul. Unealta
 * așteaptă ca prețul să treacă dincolo de acel nivel și să se ÎNTOARCĂ la el.
 * Nu intră la atingere — un ordin limită la 800 s-ar executa pe drumul în jos;
 * noi vrem dovada că a fost acolo și s-a întors.
 *
 * DOUĂ RITMURI, CA ȘI LA BOTUL VECHI, DAR DIN ALT MOTIV:
 *
 *   Tot ce e decizie despre piață — armare, intrare, ieșire pe urmărire — se
 *   judecă pe ÎNCHIDEREA unei lumânări de 15 minute. ZEC se mișcă violent:
 *   ATR(14) pe 1h e 1,86%, iar oscilații de 20–30 USDC într-o oră sunt
 *   obișnuite. Un prag care se declanșează la atingere e rupt de zgomot, nu de
 *   piață.
 *
 *   STOPUL se verifică la fiecare rulare, pe mecuri, inclusiv pe lumânarea în
 *   formare. E protecție, nu afirmație: dacă prețul a fost acolo, ieșim.
 *
 * EXTREMELE (minimele și maximele care mișcă pragurile) se iau din MECURI, nu
 * din închideri. Mecurile spun unde a fost prețul; închiderile spun dacă s-a
 * rupt ceva. Fiecare regulă folosește ce știe mai bine.
 *
 * RECUPERAREA: la fiecare rulare se procesează TOATE lumânările închise
 * nevăzute, în ordine cronologică. Dacă cronul a stat oprit două ore, cele opt
 * lumânări se judecă una câte una, în ordinea în care s-au întâmplat — nu
 * comasate, pentru că o intrare pe a doua și un stop pe a cincea sunt două
 * lucruri diferite.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

require __DIR__ . '/unealta-reguli.php';

const CALE_CONFIG = '/home/marcelpa/autobot-config.php';
const SFERT_MS    = 900000;          // o lumânare de 15 minute, în milisecunde

// Câte lumânări cerem de la Binance. 300 × 15m ≈ 75 de ore: dacă cronul a stat
// oprit mai puțin de atât, nu se pierde nimic. Peste, motorul o spune în jurnal
// în loc să sară tăcut peste bucata lipsă.
const CATE_LUMANARI = 300;

$inceput = (int)(microtime(true) * 1000);
$jurnal  = [];
$prinse  = [];   // orele lumânărilor noi, ca să nu le numărăm de două ori

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
$config   = require CALE_CONFIG;
$simbol   = $config['piata']['simbol'] ?? 'ZECUSDC';
// Comisionul e comun cu botul vechi: e o proprietate a contului de Binance, nu
// a strategiei. Valoarea implicită există pentru că fișierul de configurare stă
// în afara git-ului și codul nou poate ajunge pe server înaintea lui.
$comision = (float)($config['reguli']['comision_o_parte'] ?? 0.075) / 100.0;

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
function incheie(string $rezultat, ?int $ultimaLumanare = null): never {
    global $pdo, $inceput, $jurnal, $prinse;
    try {
        $pdo->prepare("INSERT INTO u_jurnal
                         (pornit_la, durata_ms, ultima_lumanare, lumanari, rezultat, detaliu)
                       VALUES (?,?,?,?,?,?)")
            ->execute([
                $inceput,
                (int)(microtime(true) * 1000) - $inceput,
                $ultimaLumanare,
                count($prinse),
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

// Fără asta, o excepție de bază de date ar omorî scriptul înainte să scrie
// jurnalul — iar în pagină ar arăta exact ca un cron care nu mai rulează.
set_exception_handler(function (Throwable $e) {
    spune("EXCEPȚIE: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")");
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

    if ($r === false) { spune("Binance: $err");               incheie('eroare'); }
    if ($cod !== 200) { spune("Binance: HTTP $cod");          incheie('eroare'); }
    $d = json_decode($r, true);
    if (!is_array($d)) { spune("Binance: răspuns necitibil"); incheie('eroare'); }
    return $d;
}

$k = binance("https://api.binance.com/api/v3/klines?symbol=$simbol&interval=15m&limit=" . CATE_LUMANARI);
if (count($k) < 2) {
    spune("Binance a dat prea puține lumânări (" . count($k) . ").");
    incheie('fara_date');
}

// Ultima din șir e lumânarea ÎN FORMARE. Restul sunt închise.
$informare = [
    'ora'        => (int)$k[count($k) - 1][0],
    'deschidere' => (float)$k[count($k) - 1][1],
    'maxim'      => (float)$k[count($k) - 1][2],
    'minim'      => (float)$k[count($k) - 1][3],
    'inchidere'  => (float)$k[count($k) - 1][4],
];
$inchise = [];
for ($i = 0; $i < count($k) - 1; $i++) {
    $inchise[] = [
        'ora'        => (int)$k[$i][0],
        'deschidere' => (float)$k[$i][1],
        'maxim'      => (float)$k[$i][2],
        'minim'      => (float)$k[$i][3],
        'inchidere'  => (float)$k[$i][4],
        'volum'      => (float)$k[$i][5],
    ];
}
$primaAdusa = $inchise[0]['ora'];

spune(sprintf("%d lumânări închise, de la %s UTC · în formare: %.2f",
    count($inchise), gmdate('Y-m-d H:i', intdiv($primaAdusa, 1000)), $informare['inchidere']));

/* ------------------------------------------------- copia noastră de lumânări */

$ins = $pdo->prepare("INSERT INTO u_lumanari_15m
                        (ora_deschidere, deschidere, maxim, minim, inchidere, volum, adaugat_la)
                      VALUES (?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE
                        maxim = VALUES(maxim), minim = VALUES(minim),
                        inchidere = VALUES(inchidere), volum = VALUES(volum)");
// Doar ultimele câteva: restul sunt deja în bază de la rulările anterioare, iar
// 300 de scrieri pe minut ar fi risipă curată.
foreach (array_slice($inchise, -8) as $l) {
    $ins->execute([$l['ora'], $l['deschidere'], $l['maxim'], $l['minim'],
                   $l['inchidere'], $l['volum'], $inceput]);
}

/* ------------------------------------------------------------- băncile */

function citesteBanca(string $care): array {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM u_banci WHERE banca = ?");
    $st->execute([$care]);
    $b = $st->fetch();
    if (!$b) { spune("Banca „$care” lipsește din u_banci — a rulat migrația?"); incheie('eroare'); }
    return $b;
}

function scrieBanca(string $care, float $usdc, float $zec, string $fel,
                    ?int $pozitieId, ?float $pret, ?float $cantitate,
                    float $comisionPlatit, string $explicatie): void {
    global $pdo, $inceput;
    $pdo->prepare("UPDATE u_banci SET sold_usdc = ?, sold_zec = ?, actualizat_la = ?
                   WHERE banca = ?")
        ->execute([$usdc, $zec, $inceput, $care]);
    $pdo->prepare("INSERT INTO u_miscari
                     (banca, pozitie_id, moment, fel, pret, cantitate_zec, comision,
                      sold_usdc_dupa, sold_zec_dupa, explicatie)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$care, $pozitieId, $inceput, $fel, $pret, $cantitate,
                   $comisionPlatit, $usdc, $zec, $explicatie]);
}

/* ------------------------------------------------- deschiderea și închiderea */

/**
 * Deschide poziția la prețul dat și întoarce rândul proaspăt din bază.
 *
 * Pragurile se COPIAZĂ din setare în poziție. Utilizatorul poate modifica ținta
 * sau urmărirea cât e în poziție; dacă motorul ar citi setarea, o modificare de
 * mâine ar rescrie retroactiv povestea unei poziții de azi.
 */
function deschidePozitia(array $s, float $pret, int $ora): array {
    global $pdo, $comision, $inceput;

    $banca = $s['banca'];
    $b     = citesteBanca($banca);

    if ($banca === 'long') {
        $usdc = (float)$b['sold_usdc'];
        if ($usdc <= 0) { throw new RuntimeException("banca de long n-are USDC"); }
        $cant            = ($usdc / $pret) * (1 - $comision);
        $comisionIntrare = $usdc * $comision;
    } else {
        $zec = (float)$b['sold_zec'];
        if ($zec <= 0) { throw new RuntimeException("banca de short n-are ZEC"); }
        $cant            = $zec;
        $comisionIntrare = $zec * $pret * $comision;
    }

    $pdo->prepare("INSERT INTO u_pozitii
                     (setare_id, banca, stare, intrare_ora, intrare_pret, cantitate,
                      prag_iesire, urmarire, prag_stop, comision_total)
                   VALUES (?,?, 'deschisa', ?,?,?,?,?,?,?)")
        ->execute([(int)$s['id'], $banca, $ora, round($pret, 8), round($cant, 8),
                   $s['prag_iesire'], $s['urmarire'], $s['prag_stop'],
                   round($comisionIntrare, 8)]);
    $id = (int)$pdo->lastInsertId();

    if ($banca === 'long') {
        scrieBanca('long', 0.0, $cant, 'intrare', $id, $pret, $cant,
                   $comisionIntrare, 'LONG: cumpăr ZEC');
    } else {
        $incasat = $cant * $pret * (1 - $comision);
        scrieBanca('short', $incasat, 0.0, 'intrare', $id, $pret, $cant,
                   $comisionIntrare, 'SHORT: vând ZEC');
    }

    $pdo->prepare("UPDATE u_setari SET stare='in_pozitie', actualizat_la=? WHERE id=?")
        ->execute([$inceput, (int)$s['id']]);

    $st = $pdo->prepare("SELECT * FROM u_pozitii WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch();
}

function inchidePozitia(array $p, float $pret, string $motiv, int $ora): void {
    global $pdo, $comision, $inceput;

    $banca    = $p['banca'];
    $intrare  = (float)$p['intrare_pret'];
    $cant     = (float)$p['cantitate'];
    $rezultat = u_rezultatNet($banca, $intrare, $pret, $comision);
    $b        = citesteBanca($banca);

    if ($banca === 'long') {
        $comisionIesire = $cant * $pret * $comision;
        $incasat        = $cant * $pret - $comisionIesire;
        scrieBanca('long', $incasat, 0.0, 'iesire', (int)$p['id'], $pret, $cant,
                   $comisionIesire, strtoupper($motiv) . ': vând ZEC');
    } else {
        $usdc           = (float)$b['sold_usdc'];
        $comisionIesire = $usdc * $comision;
        $cumparat       = ($usdc / $pret) * (1 - $comision);
        scrieBanca('short', 0.0, $cumparat, 'iesire', (int)$p['id'], $pret, $cumparat,
                   $comisionIesire, strtoupper($motiv) . ': răscumpăr ZEC');
    }

    $pdo->prepare("UPDATE u_pozitii SET stare='inchisa', iesire_ora=?, iesire_pret=?,
                     motiv_iesire=?, rezultat_proc=?, comision_total = comision_total + ?
                   WHERE id = ?")
        ->execute([$ora, round($pret, 8), $motiv, round($rezultat, 4),
                   round($comisionIesire, 8), (int)$p['id']]);

    $pdo->prepare("UPDATE u_setari SET stare='inchisa', incheiat_la=?, actualizat_la=?
                   WHERE id=?")
        ->execute([$inceput, $inceput, (int)$p['setare_id']]);

    spune(sprintf("  → ÎNCHIS pe %s la %.2f — net %+.2f%%", strtoupper($motiv), $pret, $rezultat));
}

/**
 * Judecă o lumânare pentru o poziție deschisă. Întoarce poziția actualizată,
 * sau null dacă s-a închis.
 *
 * `$inchidere` null înseamnă lumânarea ÎN FORMARE: atunci se verifică doar
 * stopul și se actualizează extremele. Nimic altceva nu se declanșează pe o
 * lumânare neterminată — asta e chiar rostul confirmării pe 15 minute.
 *
 * ORDINEA CONTEAZĂ, pentru că pe un sfert de oră se pot întâmpla mai multe
 * lucruri deodată:
 *   1. MFE/MAE — întâi, ca să prindă și fereastra în care poziția se închide
 *   2. stopul — pe mecuri
 *   3. urmărirea — se ridică pragul
 *   4. ieșirea — doar pe închidere
 *
 * STOPUL ȘI IEȘIREA ÎN ACEEAȘI LUMÂNARE → SE IA STOPUL. Din lumânări de 15
 * minute nu se poate ști care a fost primul, iar convenția trebuie să fie
 * pesimistă: o simulare care presupune ordinea favorabilă minte exact în
 * direcția în care s-ar paria bani adevărați.
 */
function judecaPozitia(array $p, array $lum, bool $eInchisa): ?array {
    global $pdo, $inceput;

    // Momentul ieșirii: sfârșitul lumânării, dacă e închisă. Pe cea în formare,
    // sfârșitul ei e în VIITOR — acolo ieșirea se întâmplă acum.
    $oraIesire = $eInchisa ? $lum['ora'] + SFERT_MS : $inceput;

    $banca   = $p['banca'];
    $intrare = (float)$p['intrare_pret'];
    $maxim   = $lum['maxim'];
    $minim   = $lum['minim'];

    /* --- 1. cât de departe a mers, în favoare și împotrivă --- */
    if ($banca === 'long') {
        $favorabil = ($maxim - $intrare) / $intrare * 100;
        $potrivnic = ($minim - $intrare) / $intrare * 100;
    } else {
        $favorabil = ($intrare - $minim) / $intrare * 100;
        $potrivnic = ($intrare - $maxim) / $intrare * 100;
    }
    $pdo->prepare("UPDATE u_pozitii SET mfe_proc = GREATEST(mfe_proc, ?),
                                        mae_proc = LEAST(mae_proc, ?)
                   WHERE id = ?")
        ->execute([round($favorabil, 4), round($potrivnic, 4), (int)$p['id']]);

    /* --- 2. stopul, pe atingere --- */
    $stop = ($p['prag_stop'] === null) ? null : (float)$p['prag_stop'];
    if (u_atinsStopul($banca, $maxim, $minim, $stop)) {
        spune(sprintf("  stop atins (%.2f) în lumânarea %s UTC",
              $stop, gmdate('H:i', intdiv($lum['ora'], 1000))));
        inchidePozitia($p, $stop, 'stop', $oraIesire);
        return null;
    }

    /* --- 3. urmărirea --- */
    $tinta   = (float)$p['prag_iesire'];
    $atinsa  = (int)$p['tinta_atinsa'] === 1;
    $extrem  = ($p['extrem'] === null) ? null : (float)$p['extrem'];

    if (!$atinsa && u_atinsTinta($banca, $maxim, $minim, $tinta)) {
        $atinsa = true;
        spune(sprintf("  ținta %.2f atinsă în lumânarea %s UTC — urmărirea pornește",
              $tinta, gmdate('H:i', intdiv($lum['ora'], 1000))));
    }
    if ($atinsa) {
        $extrem = u_extremIesire($banca, $extrem, $maxim, $minim);
        $pdo->prepare("UPDATE u_pozitii SET tinta_atinsa=1, extrem=? WHERE id=?")
            ->execute([round($extrem, 8), (int)$p['id']]);
        $p['tinta_atinsa'] = 1;
        $p['extrem']       = $extrem;
    }

    /* --- 4. ieșirea, doar pe închidere --- */
    if ($atinsa && $eInchisa) {
        $prag = u_pragIesireEfectiv($banca, $tinta, $extrem, (float)$p['urmarire']);
        if (u_declanseazaIesirea($banca, $lum['inchidere'], $prag)) {
            spune(sprintf("  închiderea %.2f a rupt pragul de urmărire %.2f (maxim atins %.2f)",
                  $lum['inchidere'], $prag, $extrem));
            inchidePozitia($p, $lum['inchidere'], 'urmarire', $oraIesire);
            return null;
        }
    }

    return $p;
}

/* ======================= bucla peste setările active ===================== */

$setari = $pdo->query("SELECT * FROM u_setari
                       WHERE stare IN ('asteapta','armat','in_pozitie')
                       ORDER BY banca, creat_la, id")->fetchAll();

if (!$setari) {
    spune("Nicio setare activă — nu e nimic de făcut.");
    incheie('ok', $inchise[count($inchise) - 1]['ora']);
}

$vazute = [];   // o singură setare activă pe bancă; dacă apar două, o spunem

foreach ($setari as $s) {
    $banca = $s['banca'];
    if (isset($vazute[$banca])) {
        spune("ATENȚIE: a doua setare activă pe banca de $banca (#{$s['id']}) — o sar.");
        continue;
    }
    $vazute[$banca] = true;

    $id = (int)$s['id'];
    spune("Setarea #$id ($banca, {$s['stare']}):");

    $pozitie = null;
    if ($s['stare'] === 'in_pozitie') {
        $st = $pdo->prepare("SELECT * FROM u_pozitii WHERE setare_id=? AND stare='deschisa' LIMIT 1");
        $st->execute([$id]);
        $pozitie = $st->fetch() ?: null;
        if (!$pozitie) {
            spune("  setarea zice „in_pozitie” dar nu există poziție deschisă — o sar.");
            continue;
        }
    }

    // Plasa de siguranță: o setare fără `ultima_lumanare` (n-ar trebui să existe
    // — API-ul o pune la creare) ar reprocesa 75 de ore de istoric și ar intra
    // pe o revenire de acum trei zile. Mai bine pornește de acum.
    $ultima = (int)$s['ultima_lumanare'];
    if ($ultima <= 0) {
        $ultima = $inchise[count($inchise) - 1]['ora'] - SFERT_MS;
        spune("  ATENȚIE: setarea n-avea `ultima_lumanare` — pornesc de la ultima lumânare închisă.");
    }

    // Golul, dacă a existat: mai bine spus în jurnal decât sărit tăcut.
    if ($ultima > 0 && $ultima < $primaAdusa - SFERT_MS) {
        $lipsa = intdiv($primaAdusa - $ultima, SFERT_MS) - 1;
        spune("  ATENȚIE: $lipsa lumânări lipsesc (cronul a stat oprit mai mult de "
            . intdiv(CATE_LUMANARI * 15, 60) . " de ore). Pornesc de la prima adusă.");
    }

    $inchisa = false;
    foreach ($inchise as $lum) {
        if ($lum['ora'] <= $ultima) { continue; }

        $prinse[$lum['ora']] = true;

        /* --- poziție deschisă: o judecăm pe lumânarea asta --- */
        if ($pozitie) {
            $pozitie = judecaPozitia($pozitie, $lum, true);
            if ($pozitie === null) { $inchisa = true; }
        }

        /* --- fără poziție: întâi stopul, apoi armare și intrare --- */
        if (!$pozitie && !$inchisa) {
            $stareCurenta = $s['stare'];

            /* STOPUL ATINS ÎNAINTE DE INTRARE OMOARĂ SETAREA.
               Pragul de intrare urmărește extremul, deci intrarea poate ajunge
               sub un stop care e valoare absolută — și atunci „stopul” s-ar
               declanșa pe profit, ceea ce n-are niciun înțeles. Dar motivul
               adevărat e altul, și e mai simplu: stopul e nivelul la care ai
               spus că te-ai înșelat. Dacă piața ajunge acolo înainte să fi
               intrat, teza e deja infirmată. Nu se mai cumpără.
               Verificat ÎNAINTEA armării, ca o lumânare care coboară sub stop
               și închide sus să nu apuce să declanșeze o intrare. */
            $stopSetare = ($s['prag_stop'] === null) ? null : (float)$s['prag_stop'];
            if (u_atinsStopul($banca, $lum['maxim'], $lum['minim'], $stopSetare)) {
                $pdo->prepare("UPDATE u_setari SET stare='expirat', incheiat_la=?,
                                 actualizat_la=?, ultima_lumanare=? WHERE id=?")
                    ->execute([$inceput, $inceput, $lum['ora'], $id]);
                spune(sprintf("  EXPIRAT: prețul a atins stopul (%.2f) la %s UTC, înainte de intrare."
                            . " Teza a picat — nu mai intru.",
                      $stopSetare, gmdate('Y-m-d H:i', intdiv($lum['ora'], 1000))));
                $inchisa = true;
                break;
            }

            if ($stareCurenta === 'asteapta') {
                $pragArm = u_pragArmare($banca, (float)$s['prag_intrare'], (float)$s['depasire_minima']);
                if (u_sArmat($banca, $lum['maxim'], $lum['minim'], $pragArm)) {
                    $s['stare']  = $stareCurenta = 'armat';
                    $s['extrem'] = u_extremIntrare($banca, null, $lum['maxim'], $lum['minim']);
                    $pdo->prepare("UPDATE u_setari SET stare='armat', extrem=?, armat_la=?, actualizat_la=?
                                   WHERE id=?")
                        ->execute([round((float)$s['extrem'], 8), $lum['ora'], $inceput, $id]);
                    spune(sprintf("  ARMAT: prețul a trecut de %.2f (extrem %.2f) la %s UTC",
                          $pragArm, (float)$s['extrem'], gmdate('Y-m-d H:i', intdiv($lum['ora'], 1000))));
                }
            }

            if ($stareCurenta === 'armat') {
                $extrem = u_extremIntrare($banca, ($s['extrem'] === null) ? null : (float)$s['extrem'],
                                          $lum['maxim'], $lum['minim']);
                if ($s['extrem'] === null || abs($extrem - (float)$s['extrem']) > 1e-12) {
                    $pdo->prepare("UPDATE u_setari SET extrem=?, actualizat_la=? WHERE id=?")
                        ->execute([round($extrem, 8), $inceput, $id]);
                    $s['extrem'] = $extrem;
                }

                $prag = u_pragIntrareEfectiv($banca, (float)$s['prag_intrare'], $extrem,
                                             (float)$s['revenire']);
                if (u_declanseazaIntrarea($banca, $lum['inchidere'], $prag)) {
                    try {
                        $pdo->beginTransaction();
                        $pozitie = deschidePozitia($s, $lum['inchidere'], $lum['ora'] + SFERT_MS);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        spune("  nu am putut deschide poziția: " . $e->getMessage());
                        incheie('eroare', $lum['ora']);
                    }
                    $s['stare'] = 'in_pozitie';
                    spune(sprintf("  INTRARE %s la %.2f (prag %.2f, extrem %.2f) — %s UTC",
                          strtoupper($banca), $lum['inchidere'], $prag, $extrem,
                          gmdate('Y-m-d H:i', intdiv($lum['ora'], 1000))));
                }
            }
        }

        $pdo->prepare("UPDATE u_setari SET ultima_lumanare=? WHERE id=?")
            ->execute([$lum['ora'], $id]);
        $ultima = $lum['ora'];

        if ($inchisa) { break; }   // setarea și-a trăit viața
    }

    if ($inchisa) { continue; }

    /* --- lumânarea în formare: doar stopul, extremele, și butonul manual --- */
    if ($pozitie) {
        $pozitie = judecaPozitia($pozitie, $informare, false);

        if ($pozitie && (int)$pozitie['inchidere_ceruta'] === 1) {
            spune("  „ieși acum” cerut de utilizator.");
            inchidePozitia($pozitie, $informare['inchidere'], 'manual', $inceput);
            $pozitie = null;
        }

        if ($pozitie) {
            $tinta  = (float)$pozitie['prag_iesire'];
            $extrem = ($pozitie['extrem'] === null) ? null : (float)$pozitie['extrem'];
            $prag   = u_pragIesireEfectiv($banca, $tinta, $extrem, (float)$pozitie['urmarire']);
            spune(sprintf("  rămâne deschisă: intrare %.2f · %s %.2f · stop %s · acum %.2f",
                (float)$pozitie['intrare_pret'],
                (int)$pozitie['tinta_atinsa'] === 1 ? 'prag urmărire' : 'țintă',
                (int)$pozitie['tinta_atinsa'] === 1 ? $prag : $tinta,
                $pozitie['prag_stop'] === null ? '—' : sprintf('%.2f', (float)$pozitie['prag_stop']),
                $informare['inchidere']));
        }
    } elseif ($s['stare'] === 'armat') {
        $prag = u_pragIntrareEfectiv($banca, (float)$s['prag_intrare'],
                                     ($s['extrem'] === null) ? null : (float)$s['extrem'],
                                     (float)$s['revenire']);
        spune(sprintf("  armat, aștept o închidere %s %.2f (extrem %.2f, acum %.2f)",
              $banca === 'long' ? 'peste' : 'sub', $prag, (float)$s['extrem'], $informare['inchidere']));
    } else {
        $pragArm = u_pragArmare($banca, (float)$s['prag_intrare'], (float)$s['depasire_minima']);
        spune(sprintf("  aștept ca prețul să %s %.2f (acum %.2f)",
              $banca === 'long' ? 'coboare la' : 'urce la', $pragArm, $informare['inchidere']));
    }
}

incheie('ok', $inchise[count($inchise) - 1]['ora']);
