<?php
/**
 * Motorul — rulează din cron, o dată pe oră, la minutul 1.
 *
 * IEȘIRILE NU MAI ȚIN DE LINII (19.09.2026). Triunghiul dă doar semnalul de
 * intrare; TP și SL sunt două praguri fixe, așezate la intrare, la distanțe
 * proporționale cu ATR(14). Motivul, pe scurt: linia de intrare fiind
 * convergentă, ea cobora (urca) oră de oră, deci pragul de SL se îndepărta de
 * prețul de intrare cu fiecare oră petrecută în poziție — riscul creștea cu
 * timpul, în timp ce câștigul rămânea plafonat. Raționamentul complet și
 * măsurătorile care l-au susținut: docs/plan-tranzactionare.md.
 *
 * DOUĂ RITMURI DIFERITE, INTENȚIONAT:
 *
 *   TP și SL se verifică LA FIECARE RULARE, inclusiv pe lumânarea în formare.
 *   Amândouă sunt ordine la preț cunoscut: dacă maximul (minimul) l-a atins,
 *   s-ar fi executat deja. Cu cronul la un minut, poziția se închide în cel
 *   mult atâta.
 *
 *   SEMNALELE noi se evaluează O SINGURĂ DATĂ PER LUMÂNARE ÎNCHISĂ, pentru că
 *   așa sunt definite: pe închidere. O rulare care prinde aceeași lumânare a
 *   doua oară nu le mai atinge.
 *
 * Când TP și SL cad în aceeași fereastră de preț, se ia SL. Din lumânări de o
 * oră nu se poate ști care a fost primul, iar o simulare care presupune ordinea
 * favorabilă minte în favoarea strategiei.
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
$comision = (float)($config['reguli']['comision_o_parte'] ?? 0.075) / 100.0;

// Fișierul de configurare stă pe server, în afara git-ului, deci codul nou poate
// ajunge acolo înaintea cheilor noi. De asta fiecare are o valoare implicită
// rezonabilă: motorul nu se oprește dacă `autobot-config.php` n-a fost încă
// completat, doar folosește valorile de aici.
$atrPerioada = max(2, (int)($config['reguli']['atr_perioada'] ?? 14));
$tpAtr       = (float)($config['reguli']['tp_atr'] ?? 1.5);
$slAtr       = (float)($config['reguli']['sl_atr'] ?? 1.0);
// Plafoane, în procente din prețul de intrare. Cel de jos apără de ținte mai
// mici decât comisionul (0,15% dus-întors mănâncă un TP de 0,3%); cel de sus, de
// o oră sălbatică: în istoricul lui ZEC există o lumânare de 1h cu amplitudine
// de 52%, iar un SL de „1 ATR" calculat atunci ar fi fost sinucidere curată.
$distMin     = (float)($config['reguli']['distanta_minima_proc'] ?? 0.5);
$distMax     = (float)($config['reguli']['distanta_maxima_proc'] ?? 4.0);
// Stop de timp: o poziție care n-a atins nici TP, nici SL, nu trebuie să stea
// la infinit. Zero oprește regula.
$oreMaxime   = (int)($config['reguli']['ore_maxime'] ?? 48);
// Lățimea minimă a triunghiului la care mai acceptăm un semnal, în ATR-uri.
// Sub ea, spargerea nu mai spune nimic despre piață — vezi mai jos, la
// verificarea triunghiurilor active.
$latimeMinAtr = (float)($config['reguli']['latime_minima_atr'] ?? 1.0);
// capital_initial nu se citește aici: banca de long își ia soldul din tabel, iar
// cea de short se finanțează din ce are deja. Îl folosește doar stare.php, ca
// punct de referință pentru randament.

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

/**
 * ATR(n) după Wilder, în USDC, peste lumânări ÎNCHISE, în ordine cronologică.
 *
 * Prima medie e una simplă, peste primele n intervale reale; de acolo încolo,
 * netezire cu 1/n. De aceea cerem mult mai multe lumânări decât n: cu exact n+1
 * am obține doar media simplă, care sare la fiecare oră. Cu ~5n, netezirea a
 * convers și cifra e stabilă.
 */
function atr(array $inchise, int $n): ?float {
    $m = count($inchise);
    if ($m < $n + 1) return null;

    $tr = [];
    for ($i = 1; $i < $m; $i++) {
        $h  = (float)$inchise[$i][2];
        $l  = (float)$inchise[$i][3];
        $pc = (float)$inchise[$i - 1][4];
        // Intervalul adevărat include golul față de închiderea anterioară —
        // altfel o oră care se deschide cu salt ar părea liniștită.
        $tr[] = max($h - $l, abs($h - $pc), abs($l - $pc));
    }

    $a = array_sum(array_slice($tr, 0, $n)) / $n;
    for ($i = $n; $i < count($tr); $i++) {
        $a = ($a * ($n - 1) + $tr[$i]) / $n;
    }
    return $a;
}

// Ultima din șir e lumânarea ÎN FORMARE, penultima e ultima ÎNCHISĂ. Cerem
// ~5 × perioada, ca ATR-ul să fie netezit, nu doar o medie simplă.
$nevoie = $atrPerioada * 5 + 2;
$k = binance("https://api.binance.com/api/v3/klines?symbol=$simbol&interval=1h&limit=$nevoie");
if (count($k) < $atrPerioada + 2) {
    spune("Binance a dat prea puține lumânări (" . count($k) . ") pentru ATR($atrPerioada).");
    incheie('fara_date');
}

$uc = count($k) - 1;          // indicele lumânării în formare
$inchisa = [
    'ora'        => (int)$k[$uc - 1][0],
    'deschidere' => (float)$k[$uc - 1][1],
    'maxim'      => (float)$k[$uc - 1][2],
    'minim'      => (float)$k[$uc - 1][3],
    'inchidere'  => (float)$k[$uc - 1][4],
    'volum'      => (float)$k[$uc - 1][5],
];
// Lumânarea în formare: de aici luăm prețul de execuție și maximul/minimul atins
// până acum, pentru TP și SL.
$informare = [
    'deschidere' => (float)$k[$uc][1],
    'maxim'      => (float)$k[$uc][2],
    'minim'      => (float)$k[$uc][3],
    'inchidere'  => (float)$k[$uc][4],
];
$pretExecutie = $informare['deschidere'];

$atrAcum = atr(array_slice($k, 0, $uc), $atrPerioada);
if ($atrAcum === null || $atrAcum <= 0) {
    spune("ATR nedeterminabil — nu pot așeza praguri, mă opresc.");
    incheie('fara_date');
}
spune(sprintf("ATR(%d) = %.2f USDC (%.2f%% din preț)",
    $atrPerioada, $atrAcum, $atrAcum / $informare['inchidere'] * 100));

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

// ATENȚIE: condiția NU se poate deduce din solduri.
//
// „Are USDC și n-are ZEC" descrie și o bancă neinițializată, și una aflată în
// mijlocul unei poziții short — care tocmai a vândut ZEC-ul. Prima versiune
// verifica soldurile și, la rularea de după deschiderea unui short, „reinițializa"
// banca: îi convertea USDC-ul poziției în ZEC. La închidere, răscumpărarea se
// calcula din USDC-ul care nu mai era acolo, deci ieșea zero, și banca rămânea
// goală. Banii dispăreau din evidență.
//
// Singura întrebare fără echivoc e dacă inițializarea s-a făcut vreodată.
$aFostInitializata = (int)$pdo->query(
    "SELECT COUNT(*) FROM miscari WHERE banca = 'short' AND fel = 'initializare'"
)->fetchColumn() > 0;

$bancaShort = citesteBanca('short');
if (!$aFostInitializata && (float)$bancaShort['sold_usdc'] > 0) {
    // Finanțare inițială, nu tranzacție — fără comision. Banca de short e „în
    // piață" implicit: se măsoară în ZEC, deci trebuie să pornească ținând ZEC.
    $zec = (float)$bancaShort['sold_usdc'] / $pretExecutie;
    scrieBanca('short', 0.0, $zec, 'initializare', null, $pretExecutie, $zec, 0.0,
               'finanțare inițială: ' . number_format((float)$bancaShort['sold_usdc'], 2) . ' USDC → ZEC');
    spune(sprintf("Banca de short inițializată: %.6f ZEC la %.2f", $zec, $pretExecutie));
    $bancaShort = citesteBanca('short');
}

/* ============================ 1. poziția deschisă ========================= */

// Nu mai avem nevoie de geometria liniei: pragurile sunt două prețuri stocate
// pe poziție. Linia rămâne legată de poziție doar ca arhivă pentru etapa 4.
$st = $pdo->prepare("SELECT * FROM pozitii WHERE stare = 'deschisa' LIMIT 1");
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
    $tip     = $pozitie['banca'];
    $intrare = (float)$pozitie['intrare_pret'];
    $tp      = (float)$pozitie['tp_pret'];
    // Pozițiile dinaintea trecerii pe praguri fixe (19.09.2026) n-au sl_pret.
    // Nu există niciuna deschisă, dar dacă ar apărea, TP-ul și stopul de timp o
    // scot oricum — mai bine decât să inventăm un prag.
    $sl = ($pozitie['sl_pret'] === null) ? null : (float)$pozitie['sl_pret'];
    if ($sl === null) { spune("ATENȚIE: poziție fără sl_pret (dinainte de 19.09.2026)."); }

    // Fereastra de preț pe care o judecăm acum: lumânarea în formare, plus cea
    // închisă dacă n-a mai fost văzută. Altfel am reevalua o oră deja judecată.
    $maximDeVazut = $informare['maxim'];
    $minimDeVazut = $informare['minim'];
    if ($deFacutOrarul) {
        $maximDeVazut = max($maximDeVazut, $inchisa['maxim']);
        $minimDeVazut = min($minimDeVazut, $inchisa['minim']);
    }

    /* --- cât de departe a mers, în favoare și împotrivă ---
       MFE și MAE sunt materialul cu care se va răspunde, peste 20–30 de
       tranzacții, la întrebările care acum n-au răspuns: ar fi ajutat un stop
       mutat la break-even? cât las pe masă cu TP-ul așezat aici? Se scriu la
       fiecare rulare, pentru că sunt maxime pe tot drumul — retroactiv nu se
       mai pot afla. */
    if ($tip === 'long') {
        $favorabil = ($maximDeVazut - $intrare) / $intrare * 100;
        $potrivnic = ($minimDeVazut - $intrare) / $intrare * 100;
    } else {
        $favorabil = ($intrare - $minimDeVazut) / $intrare * 100;
        $potrivnic = ($intrare - $maximDeVazut) / $intrare * 100;
    }
    $pdo->prepare("UPDATE pozitii SET mfe_proc = GREATEST(mfe_proc, ?),
                                      mae_proc = LEAST(mae_proc, ?)
                   WHERE id = ?")
        ->execute([round($favorabil, 4), round($potrivnic, 4), (int)$pozitie['id']]);

    $atinsTP = ($tip === 'long') ? $maximDeVazut >= $tp : $minimDeVazut <= $tp;
    $atinsSL = $sl !== null &&
               (($tip === 'long') ? $minimDeVazut <= $sl : $maximDeVazut >= $sl);

    if ($atinsTP && $atinsSL) {
        // Amândouă în aceeași fereastră. Din lumânări de o oră nu se poate ști
        // care a fost primul, iar convenția trebuie să fie pesimistă: o
        // simulare care presupune ordinea favorabilă minte în favoarea
        // strategiei, și exact pe minciuna aia s-ar paria bani adevărați.
        spune("TP și SL atinse în aceeași fereastră — se ia SL (convenție pesimistă).");
        inchidePozitia($pozitie, $sl, 'sl');
        $pozitie = null;
    } elseif ($atinsTP) {
        inchidePozitia($pozitie, $tp, 'tp');
        $pozitie = null;
    } elseif ($atinsSL) {
        spune(sprintf("SL atins: prețul a trecut de %.2f", $sl));
        inchidePozitia($pozitie, $sl, 'sl');
        $pozitie = null;
    } else {
        $ore = ($inceput - (int)$pozitie['intrare_ora']) / 3600000.0;
        if ($oreMaxime > 0 && $ore >= $oreMaxime) {
            // Nici TP, nici SL: piața a uitat de noi. Ieșim la prețul de acum,
            // ca să nu blocăm banca la nesfârșit într-o poziție moartă.
            spune(sprintf("Stop de timp: %.0f ore în piață, fără TP sau SL.", $ore));
            inchidePozitia($pozitie, $informare['inchidere'], 'timp');
            $pozitie = null;
        } else {
            spune(sprintf("Poziția %s rămâne deschisă. TP %.2f · SL %s · %.0f h în piață"
                        . " · a fost la %+.2f%% / %+.2f%%",
                $tip, $tp, $sl === null ? '—' : sprintf('%.2f', $sl), $ore,
                $favorabil, $potrivnic));
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

    /* --- triunghiul a rămas fără loc? (20.09.2026) ---

       Regula veche expira triunghiul abia DUPĂ vârf, când „sus" ajungea sub
       „jos". Prea târziu: cu câteva ore înainte de intersecție, liniile au
       coborât deja peste preț, iar ORICE lumânare verde închide peste linia de
       sus. Semnalul acela nu spune nimic despre piață — e fabricat de geometria
       care s-a strâns. Utilizatorul a descris exact asta: „nu vreau să ne
       forțeze să intrăm doar pentru că prețul a ajuns la vârful triunghiului".

       Pragul e lățimea dintre linii, măsurată în ATR-uri, nu în procente: un
       triunghi mai îngust decât amplitudinea unei ore obișnuite e spart de
       zgomot, nu de o mișcare. Același raționament ca la pragurile de TP/SL.

       Regula veche devine un caz particular: după vârf lățimea e negativă, deci
       oricum sub prag. O singură regulă în loc de două. */
    $latime = pretLinie($linii['sus'], $inchisa['ora'])
            - pretLinie($linii['jos'], $inchisa['ora']);
    $latimeMinima = $atrAcum * $latimeMinAtr;

    if ($latime <= $latimeMinima) {
        // `nota` NU se atinge: e a utilizatorului, scrisă la desenare, și e
        // material pentru etapa 4. Starea `expirat` spune deja ce s-a întâmplat,
        // iar momentul vârfului se recalculează oricând din cele două linii.
        $pdo->prepare("UPDATE triunghiuri SET stare='expirat' WHERE id=?")
            ->execute([$t['id']]);
        $varf = varfulTriunghiului($linii['sus'], $linii['jos']);
        spune(sprintf("Triunghiul #%d a rămas fără loc: lățime %.2f USDC (%.2f ATR),"
                    . " sub pragul de %.2f. Vârful ar fi fost %s. Expirat.",
            $t['id'], $latime, $atrAcum > 0 ? $latime / $atrAcum : 0, $latimeMinima,
            $varf === null ? 'niciodată' : gmdate('Y-m-d H:i', intdiv($varf, 1000)) . ' UTC'));
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

        /* --- pragurile, așezate acum și înghețate aici ---
           Distanțele sunt multipli de ATR, nu procente fixe: 1% înseamnă altceva
           într-o săptămână liniștită decât într-una agitată, iar pragurile trebuie
           să fie în afara zgomotului orei, nu înăuntrul lui. Plafoanele apără de
           cele două capete: ATR minuscul (comisionul ar mânca ținta) și ATR
           exploziv (un stop la 50%). */
        $dTp = $atrAcum * $tpAtr;
        $dSl = $atrAcum * $slAtr;
        $minAbs = $pretExecutie * $distMin / 100;
        $maxAbs = $pretExecutie * $distMax / 100;
        $dTp = min(max($dTp, $minAbs), $maxAbs);
        $dSl = min(max($dSl, $minAbs), $maxAbs);

        if ($semnal['tip'] === 'long') {
            $usdc = (float)$banca['sold_usdc'];
            if ($usdc <= 0) { throw new RuntimeException('banca de long n-are USDC'); }
            $cant = ($usdc / $pretExecutie) * (1 - $comision);
            $tp   = $pretExecutie + $dTp;
            $sl   = $pretExecutie - $dSl;
            $comisionIntrare = $usdc * $comision;          // plătit din USDC-ul dat
        } else {
            $zec = (float)$banca['sold_zec'];
            if ($zec <= 0) { throw new RuntimeException('banca de short n-are ZEC'); }
            $cant = $zec;
            $tp   = $pretExecutie - $dTp;
            $sl   = $pretExecutie + $dSl;
            $comisionIntrare = $zec * $pretExecutie * $comision;   // din USDC-ul încasat
        }

        $pdo->prepare("INSERT INTO pozitii
                         (semnal_id, banca, stare, intrare_ora, intrare_pret, cantitate,
                          tp_pret, sl_pret, atr_intrare, linie_intrare_id, comision_total)
                       VALUES (?,?,'deschisa',?,?,?,?,?,?,?,?)")
            ->execute([$semnalId, $semnal['tip'], $inceput, $pretExecutie,
                       round($cant, 8), round($tp, 8), round($sl, 8),
                       round($atrAcum, 8), $semnal['linie']['id'],
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
                . "Intrare la %.2f · TP %.2f (%+.2f%%) · SL %.2f (%+.2f%%) · ATR %.2f",
        strtoupper($semnal['tip']), $t['id'], $inchisa['inchidere'], $semnal['prag'],
        $pretExecutie, $tp, ($tp / $pretExecutie - 1) * 100,
        $sl, ($sl / $pretExecutie - 1) * 100, $atrAcum));

    incheie('ok', $inchisa['ora']);   // o singură poziție odată
}

spune("Triunghiuri active, dar niciunul nu a fost rupt.");
incheie('ok', $inchisa['ora']);
