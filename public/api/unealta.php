<?php
/**
 * API-ul uneltei manuale.
 *
 *   GET                              -> starea întreagă: bănci, setări, poziții, istoric
 *   POST {actiune:'creeaza', ...}    -> o setare nouă pe o bancă liberă
 *   POST {actiune:'modifica', ...}   -> schimbă praguri (și cât ești în poziție)
 *   POST {actiune:'anuleaza', id}    -> retrage o setare care încă n-a intrat
 *   POST {actiune:'inchide', id}     -> „ieși acum” pe o poziție deschisă
 *
 * NIMIC DE AICI NU ATINGE BANII. Scrierile sunt intenții: o setare, o cerere de
 * închidere. Soldurile le mișcă doar motorul, din cron. Două locuri care scriu
 * în aceeași poziție s-ar putea ciocni, iar prețul unei coliziuni e o bancă
 * golită — capcană deja plătită la botul vechi.
 */

declare(strict_types=1);
require __DIR__ . '/_comun.php';

/* Regulile stau lângă motor, ca să existe o singură copie a lor. După deploy,
   motorul e în /home/marcelpa/autobot-motor/; în repo, e alături de noi. */
$caleReguli = '/home/marcelpa/autobot-motor/unealta-reguli.php';
if (!is_readable($caleReguli)) {
    $caleReguli = __DIR__ . '/../../motor/unealta-reguli.php';
}
if (!is_readable($caleReguli)) {
    eroare('Regulile uneltei lipsesc de pe server — a rulat deploy-ul motorului?', 500);
}
require $caleReguli;

const SFERT_MS = 900000;

$metoda = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo    = baza();
$acum   = acum_ms();

/* ===================================================================== GET */

if ($metoda === 'GET') {

    /* ---- băncile ---- */
    $banci = [];
    foreach ($pdo->query("SELECT * FROM u_banci")->fetchAll() as $b) {
        $banci[$b['banca']] = [
            'usdc'         => (float)$b['sold_usdc'],
            'zec'          => (float)$b['sold_zec'],
            'actualizat'   => (int)$b['actualizat_la'],
        ];
    }

    /* ---- pornirea fiecărei bănci, ca randamentul să aibă reper ----
       Se citește din prima mișcare de inițializare, nu dintr-o constantă:
       cifra scrisă în bază e cea care s-a întâmplat cu adevărat. */
    $porniri = ['long' => null, 'short' => null];
    foreach ($pdo->query("SELECT banca, sold_usdc_dupa, sold_zec_dupa FROM u_miscari
                          WHERE fel = 'initializare'")->fetchAll() as $m) {
        $porniri[$m['banca']] = $m['banca'] === 'long'
            ? (float)$m['sold_usdc_dupa'] : (float)$m['sold_zec_dupa'];
    }

    /* ---- setările active, cu pragurile deja socotite ----
       Pragurile se calculează AICI, din aceleași funcții pe care le folosește
       motorul. Refăcute în JavaScript, s-ar putea desincroniza de ce se
       întâmplă de fapt, iar pagina ar minți fără să știe. */
    $setari = ['long' => null, 'short' => null];
    $st = $pdo->query("SELECT * FROM u_setari
                       WHERE stare IN ('asteapta','armat','in_pozitie')
                       ORDER BY banca, creat_la");
    foreach ($st->fetchAll() as $s) {
        $banca = $s['banca'];
        if ($setari[$banca] !== null) { continue; }   // una pe bancă

        $extrem = $s['extrem'] === null ? null : (float)$s['extrem'];
        $d = [
            'id'              => (int)$s['id'],
            'banca'           => $banca,
            'stare'           => $s['stare'],
            'prag_intrare'    => (float)$s['prag_intrare'],
            'depasire_minima' => (float)$s['depasire_minima'],
            'revenire'        => (float)$s['revenire'],
            'prag_iesire'     => (float)$s['prag_iesire'],
            'urmarire'        => (float)$s['urmarire'],
            'prag_stop'       => $s['prag_stop'] === null ? null : (float)$s['prag_stop'],
            'nota'            => $s['nota'],
            'extrem'          => $extrem,
            'armat_la'        => $s['armat_la'] === null ? null : (int)$s['armat_la'],
            'creat_la'        => (int)$s['creat_la'],
            'ultima_lumanare' => (int)$s['ultima_lumanare'],
            'prag_armare'     => round(u_pragArmare($banca, (float)$s['prag_intrare'],
                                                    (float)$s['depasire_minima']), 8),
            'prag_efectiv'    => round(u_pragIntrareEfectiv($banca, (float)$s['prag_intrare'],
                                                            $extrem, (float)$s['revenire']), 8),
            'pozitie'         => null,
        ];

        if ($s['stare'] === 'in_pozitie') {
            $sp = $pdo->prepare("SELECT * FROM u_pozitii WHERE setare_id=? AND stare='deschisa' LIMIT 1");
            $sp->execute([(int)$s['id']]);
            if ($p = $sp->fetch()) {
                $ext = $p['extrem'] === null ? null : (float)$p['extrem'];
                $d['pozitie'] = [
                    'id'            => (int)$p['id'],
                    'intrare_ora'   => (int)$p['intrare_ora'],
                    'intrare'       => (float)$p['intrare_pret'],
                    'cantitate'     => (float)$p['cantitate'],
                    'prag_iesire'   => (float)$p['prag_iesire'],
                    'urmarire'      => (float)$p['urmarire'],
                    'prag_stop'     => $p['prag_stop'] === null ? null : (float)$p['prag_stop'],
                    'tinta_atinsa'  => (int)$p['tinta_atinsa'] === 1,
                    'extrem'        => $ext,
                    'prag_efectiv'  => round(u_pragIesireEfectiv($banca, (float)$p['prag_iesire'],
                                                                 $ext, (float)$p['urmarire']), 8),
                    'inchidere_ceruta' => (int)$p['inchidere_ceruta'] === 1,
                    'mfe'           => (float)$p['mfe_proc'],
                    'mae'           => (float)$p['mae_proc'],
                ];
            }
        }

        $setari[$banca] = $d;
    }

    /* ---- istoricul: ce s-a închis ---- */
    $istoric = [];
    $q = $pdo->query("SELECT p.*, s.prag_intrare, s.nota
                      FROM u_pozitii p
                      JOIN u_setari s ON s.id = p.setare_id
                      WHERE p.stare = 'inchisa'
                      ORDER BY p.iesire_ora DESC LIMIT 40");
    foreach ($q->fetchAll() as $p) {
        $istoric[] = [
            'id'           => (int)$p['id'],
            'banca'        => $p['banca'],
            'nivel_gandit' => (float)$p['prag_intrare'],
            'intrare_ora'  => (int)$p['intrare_ora'],
            'intrare'      => (float)$p['intrare_pret'],
            'iesire_ora'   => $p['iesire_ora'] === null ? null : (int)$p['iesire_ora'],
            'iesire'       => $p['iesire_pret'] === null ? null : (float)$p['iesire_pret'],
            'motiv'        => $p['motiv_iesire'],
            'rezultat'     => $p['rezultat_proc'] === null ? null : (float)$p['rezultat_proc'],
            'mfe'          => (float)$p['mfe_proc'],
            'mae'          => (float)$p['mae_proc'],
            'nota'         => $p['nota'],
        ];
    }

    /* ---- setările care s-au încheiat fără să intre vreodată ----
       Două feluri, ținute separat: `anulata` e decizia utilizatorului,
       `expirat` e verdictul pieței (stopul atins înainte de intrare).
       Contopite, n-ar mai spune nimic. */
    $anulate = [];
    foreach ($pdo->query("SELECT id, banca, stare, prag_intrare, prag_iesire, prag_stop,
                                 creat_la, incheiat_la, nota
                          FROM u_setari WHERE stare IN ('anulata','expirat')
                          ORDER BY incheiat_la DESC LIMIT 15")->fetchAll() as $a) {
        $anulate[] = [
            'id' => (int)$a['id'], 'banca' => $a['banca'], 'stare' => $a['stare'],
            'prag_stop' => $a['prag_stop'] === null ? null : (float)$a['prag_stop'],
            'prag_intrare' => (float)$a['prag_intrare'],
            'prag_iesire'  => (float)$a['prag_iesire'],
            'creat_la'     => (int)$a['creat_la'],
            'incheiat_la'  => $a['incheiat_la'] === null ? null : (int)$a['incheiat_la'],
            'nota'         => $a['nota'],
        ];
    }

    /* ---- motorul mai trăiește? ----
       Fără asta, un cron mort arată exact ca o piață liniștită. */
    $motor = ['ultima' => null, 'rezultat' => null, 'detaliu' => null];
    if ($j = $pdo->query("SELECT pornit_la, rezultat, detaliu FROM u_jurnal
                          ORDER BY pornit_la DESC LIMIT 1")->fetch()) {
        $motor = [
            'ultima'   => (int)$j['pornit_la'],
            'rezultat' => $j['rezultat'],
            'detaliu'  => $j['detaliu'],
        ];
    }

    /* ---- versiunea codului, ca la botul vechi ----
       Browserul o compară cu fișierul pe care chiar l-a încărcat: diferite =
       rulează cod vechi din cache, iar pagina o spune. */
    $versiune = null;
    $cale = __DIR__ . '/../unealta/unealta.js';
    if (is_readable($cale)) {
        $cap = (string)file_get_contents($cale, false, null, 0, 2048);
        if (preg_match('/VERSIUNE\s*=\s*"([^"]+)"/', $cap, $m)) { $versiune = $m[1]; }
    }

    raspunde([
        'ok'       => true,
        'versiune' => $versiune,
        'acum'     => $acum,
        'motor'    => $motor,
        'banci'    => $banci,
        'porniri'  => $porniri,
        'setari'   => $setari,
        'istoric'  => $istoric,
        'anulate'  => $anulate,
        'comision' => (float)(config()['reguli']['comision_o_parte'] ?? 0.075),
    ]);
}

/* ==================================================================== POST */

if ($metoda !== 'POST') {
    eroare('Metodă neacceptată.', 405);
}

cere_cheia();

$d       = corp();
$actiune = (string)($d['actiune'] ?? '');

/** Citește o setare activă după id, sau se oprește cu eroare. */
function setareActiva(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM u_setari WHERE id = ?");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) { eroare('Setarea nu există.', 404); }
    return $s;
}

/* ------------------------------------------------------------------ creare */

if ($actiune === 'creeaza') {
    $banca = (string)($d['banca'] ?? '');

    $probleme = u_validareSetare([
        'banca'           => $banca,
        'prag_intrare'    => $d['prag_intrare']    ?? null,
        'depasire_minima' => $d['depasire_minima'] ?? null,
        'revenire'        => $d['revenire']        ?? null,
        'prag_iesire'     => $d['prag_iesire']     ?? null,
        'urmarire'        => $d['urmarire']        ?? null,
        'prag_stop'       => $d['prag_stop']       ?? null,
    ]);
    if ($probleme) { eroare(implode(' ', $probleme), 422); }

    // O singură setare activă pe bancă. Verificarea stă aici și în motor, nu în
    // bază: MySQL n-are indecși unici parțiali.
    $st = $pdo->prepare("SELECT COUNT(*) FROM u_setari
                         WHERE banca = ? AND stare IN ('asteapta','armat','in_pozitie')");
    $st->execute([$banca]);
    if ((int)$st->fetchColumn() > 0) {
        eroare("Banca de $banca are deja o setare activă. Anuleaz-o sau așteaptă să se închidă.", 409);
    }

    // Prima lumânare judecată e prima care se DESCHIDE după clipa asta. Cea în
    // formare acum a început înainte ca utilizatorul să decidă ceva: minimele
    // ei nu-i aparțin.
    $ultimaLumanare = intdiv($acum, SFERT_MS) * SFERT_MS;

    $stop = (isset($d['prag_stop']) && $d['prag_stop'] !== null && $d['prag_stop'] !== '')
            ? (float)$d['prag_stop'] : null;
    $nota = trim((string)($d['nota'] ?? ''));

    $pdo->prepare("INSERT INTO u_setari
                     (banca, stare, prag_intrare, depasire_minima, revenire,
                      prag_iesire, urmarire, prag_stop, nota,
                      ultima_lumanare, creat_la, actualizat_la)
                   VALUES (?, 'asteapta', ?,?,?,?,?,?,?,?,?,?)")
        ->execute([$banca,
                   (float)$d['prag_intrare'], (float)$d['depasire_minima'],
                   (float)$d['revenire'], (float)$d['prag_iesire'],
                   (float)$d['urmarire'], $stop, $nota !== '' ? $nota : null,
                   $ultimaLumanare, $acum, $acum]);

    raspunde(['ok' => true, 'id' => (int)$pdo->lastInsertId(),
              'mesaj' => 'Setarea e activă. Prima lumânare judecată e cea care se deschide la '
                       . gmdate('H:i', intdiv($ultimaLumanare + SFERT_MS, 1000)) . ' UTC.']);
}

/* --------------------------------------------------------------- modificare */

if ($actiune === 'modifica') {
    $id = (int)($d['id'] ?? 0);
    $s  = setareActiva($pdo, $id);

    /* --- în poziție: se pot schimba doar ținta, urmărirea și stopul, și se
           scriu pe POZIȚIE, nu pe setare. Setarea e ce a decis utilizatorul
           atunci; poziția e ce e valabil acum. --- */
    if ($s['stare'] === 'in_pozitie') {
        $sp = $pdo->prepare("SELECT * FROM u_pozitii WHERE setare_id=? AND stare='deschisa' LIMIT 1");
        $sp->execute([$id]);
        $p = $sp->fetch();
        if (!$p) { eroare('Setarea zice că e în poziție, dar nu există una deschisă.', 409); }

        $banca    = $p['banca'];
        $tinta    = isset($d['prag_iesire']) ? (float)$d['prag_iesire'] : (float)$p['prag_iesire'];
        $urmarire = isset($d['urmarire'])    ? (float)$d['urmarire']    : (float)$p['urmarire'];
        $stop     = array_key_exists('prag_stop', $d)
                  ? (($d['prag_stop'] === null || $d['prag_stop'] === '') ? null : (float)$d['prag_stop'])
                  : ($p['prag_stop'] === null ? null : (float)$p['prag_stop']);

        $probleme = [];
        if ($tinta <= 0)    { $probleme[] = 'Ținta trebuie să fie mai mare decât zero.'; }
        if ($urmarire <= 0) { $probleme[] = 'Urmărirea trebuie să fie mai mare decât zero.'; }
        // Singura regulă de direcție care rămâne valabilă în poziție: stopul e
        // de partea cealaltă a țintei. Restul le decide utilizatorul — mutarea
        // stopului la prețul de intrare („break-even”) e tocmai una dintre ele.
        if ($stop !== null) {
            if ($banca === 'long'  && $stop >= $tinta) { $probleme[] = 'La long, stopul trebuie să fie sub țintă.'; }
            if ($banca === 'short' && $stop <= $tinta) { $probleme[] = 'La short, stopul trebuie să fie peste țintă.'; }
        }
        if ($probleme) { eroare(implode(' ', $probleme), 422); }

        $pdo->prepare("UPDATE u_pozitii SET prag_iesire=?, urmarire=?, prag_stop=? WHERE id=?")
            ->execute([$tinta, $urmarire, $stop, (int)$p['id']]);
        // Nota rămâne a utilizatorului, în orice stare: e singurul lucru din
        // toată povestea care nu se poate deduce din cifre.
        if (array_key_exists('nota', $d)) {
            $n = trim((string)$d['nota']);
            $pdo->prepare("UPDATE u_setari SET nota=? WHERE id=?")
                ->execute([$n !== '' ? $n : null, $id]);
        }
        $pdo->prepare("UPDATE u_setari SET actualizat_la=? WHERE id=?")->execute([$acum, $id]);

        // Ținta coborâtă sub ce s-a atins deja: se va declanșa la prima
        // închidere care rupe pragul. Mai bine spus acum decât descoperit după.
        $avertisment = null;
        if ((int)$p['tinta_atinsa'] === 1) {
            $prag = u_pragIesireEfectiv($banca, $tinta,
                        $p['extrem'] === null ? null : (float)$p['extrem'], $urmarire);
            $avertisment = sprintf('Urmărirea e deja pornită — pragul de ieșire e acum %.2f.', $prag);
        }
        raspunde(['ok' => true, 'mesaj' => 'Poziția a fost actualizată.', 'atentie' => $avertisment]);
    }

    /* --- încă n-a intrat: se poate schimba tot --- */
    if ($s['stare'] !== 'asteapta' && $s['stare'] !== 'armat') {
        eroare('Setarea nu mai e activă.', 409);
    }

    $nou = [
        'banca'           => $s['banca'],
        'prag_intrare'    => $d['prag_intrare']    ?? (float)$s['prag_intrare'],
        'depasire_minima' => $d['depasire_minima'] ?? (float)$s['depasire_minima'],
        'revenire'        => $d['revenire']        ?? (float)$s['revenire'],
        'prag_iesire'     => $d['prag_iesire']     ?? (float)$s['prag_iesire'],
        'urmarire'        => $d['urmarire']        ?? (float)$s['urmarire'],
        'prag_stop'       => array_key_exists('prag_stop', $d)
                             ? $d['prag_stop']
                             : ($s['prag_stop'] === null ? null : (float)$s['prag_stop']),
    ];
    $probleme = u_validareSetare($nou);
    if ($probleme) { eroare(implode(' ', $probleme), 422); }

    $stop = ($nou['prag_stop'] === null || $nou['prag_stop'] === '') ? null : (float)$nou['prag_stop'];

    // DACĂ SE MUTĂ NIVELUL SAU DEPĂȘIREA, ARMAREA NU MAI ÎNSEAMNĂ NIMIC: ea a
    // fost dovada că prețul a trecut de un prag care acum e altul. Setarea se
    // întoarce la „așteaptă” și se rearmează pe regula nouă. Altfel am intra pe
    // o dovadă adunată pentru altceva.
    $seReseteaza = $s['stare'] === 'armat'
        && (abs((float)$nou['prag_intrare'] - (float)$s['prag_intrare']) > 1e-9
         || abs((float)$nou['depasire_minima'] - (float)$s['depasire_minima']) > 1e-9);

    $nota = array_key_exists('nota', $d) ? trim((string)$d['nota']) : null;

    $pdo->prepare("UPDATE u_setari SET prag_intrare=?, depasire_minima=?, revenire=?,
                     prag_iesire=?, urmarire=?, prag_stop=?, actualizat_la=?"
                  . (array_key_exists('nota', $d) ? ", nota=?" : "")
                  . ($seReseteaza ? ", stare='asteapta', extrem=NULL, armat_la=NULL" : "")
                  . " WHERE id=?")
        ->execute(array_merge(
            [(float)$nou['prag_intrare'], (float)$nou['depasire_minima'],
             (float)$nou['revenire'], (float)$nou['prag_iesire'],
             (float)$nou['urmarire'], $stop, $acum],
            array_key_exists('nota', $d) ? [$nota !== '' ? $nota : null] : [],
            [$id]));

    raspunde(['ok' => true, 'mesaj' => 'Setarea a fost actualizată.',
              'atentie' => $seReseteaza
                  ? 'Nivelul s-a mutat, deci armarea de dinainte nu mai e valabilă — setarea așteaptă din nou.'
                  : null]);
}

/* ---------------------------------------------------------------- anulare */

if ($actiune === 'anuleaza') {
    $id = (int)($d['id'] ?? 0);
    $s  = setareActiva($pdo, $id);

    if ($s['stare'] === 'in_pozitie') {
        eroare('Setarea e deja în poziție — folosește „ieși acum”.', 409);
    }
    if ($s['stare'] !== 'asteapta' && $s['stare'] !== 'armat') {
        eroare('Setarea nu mai e activă.', 409);
    }

    // Anulată, nu ștearsă: e o decizie a utilizatorului și rămâne în poveste,
    // separată de cele care s-au închis pe piață.
    $pdo->prepare("UPDATE u_setari SET stare='anulata', incheiat_la=?, actualizat_la=? WHERE id=?")
        ->execute([$acum, $acum, $id]);

    raspunde(['ok' => true, 'mesaj' => 'Setarea a fost retrasă.']);
}

/* ------------------------------------------------------- „ieși acum” */

if ($actiune === 'inchide') {
    $id = (int)($d['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM u_pozitii WHERE id=? AND stare='deschisa'");
    $st->execute([$id]);
    if (!$st->fetch()) { eroare('Nu există o poziție deschisă cu numărul ăsta.', 404); }

    $pdo->prepare("UPDATE u_pozitii SET inchidere_ceruta=1 WHERE id=?")->execute([$id]);

    raspunde(['ok' => true,
              'mesaj' => 'Cerere trimisă. Motorul închide poziția la următoarea rulare, '
                       . 'în cel mult un minut, la prețul de atunci.']);
}

eroare('Acțiune necunoscută.', 400);
