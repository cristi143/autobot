<?php
/**
 * Triunghiurile: listare, salvare, ștergere.
 *
 *   GET                 -> triunghiurile active + ultimele consumate
 *   POST   {sus, jos}   -> salvează un triunghi nou (două linii)
 *   DELETE ?id=         -> șterge moale un triunghi care încă n-a tras
 */

declare(strict_types=1);
require __DIR__ . '/_comun.php';

$metoda = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ------------------------------------------------------------------ listare */

if ($metoda === 'GET') {
    $pdo = baza();

    $triunghiuri = $pdo->query("
        SELECT id, stare, desenat_la, consumat_la, nota
        FROM triunghiuri
        WHERE stare IN ('activ','consumat')
        ORDER BY (stare = 'activ') DESC, desenat_la DESC
        LIMIT 50
    ")->fetchAll();

    if ($triunghiuri) {
        $ids = array_column($triunghiuri, 'id');
        $loc = implode(',', array_fill(0, count($ids), '?'));
        $st  = $pdo->prepare("SELECT id, triunghi_id, rol, t1, p1, t2, p2
                              FROM linii WHERE triunghi_id IN ($loc)");
        $st->execute($ids);

        $peTriunghi = [];
        foreach ($st->fetchAll() as $l) {
            $peTriunghi[(int)$l['triunghi_id']][$l['rol']] = [
                'id' => (int)$l['id'],
                't1' => (int)$l['t1'], 'p1' => (float)$l['p1'],
                't2' => (int)$l['t2'], 'p2' => (float)$l['p2'],
            ];
        }
        // Semnalul care a consumat triunghiul: fără el, un triunghi vechi
        // desenat pe grafic e doar decor. Cu el se vede unde a tras și de ce.
        $st = $pdo->prepare("SELECT triunghi_id, ora_lumanare, tip, pret_inchidere, pret_linie
                             FROM semnale WHERE triunghi_id IN ($loc)");
        $st->execute($ids);
        $semnale = [];
        foreach ($st->fetchAll() as $sm) {
            $semnale[(int)$sm['triunghi_id']] = [
                'ora'   => (int)$sm['ora_lumanare'],
                'tip'   => $sm['tip'],
                'pret'  => (float)$sm['pret_inchidere'],
                'linie' => (float)$sm['pret_linie'],
            ];
        }

        foreach ($triunghiuri as &$t) {
            $t['id']         = (int)$t['id'];
            $t['desenat_la'] = (int)$t['desenat_la'];
            $t['consumat_la']= $t['consumat_la'] === null ? null : (int)$t['consumat_la'];
            $t['linii']      = $peTriunghi[$t['id']] ?? [];
            $t['semnal']     = $semnale[$t['id']] ?? null;
        }
        unset($t);
    }

    raspunde(['ok' => true, 'triunghiuri' => $triunghiuri]);
}

/* ------------------------------------------------------------------ salvare */

if ($metoda === 'POST') {
    cere_cheia();
    $d = corp();

    // Validăm amândouă liniile înainte să atingem baza.
    $linii = [];
    foreach (['sus', 'jos'] as $rol) {
        $l = $d[$rol] ?? null;
        if (!is_array($l)) {
            eroare("Lipsește linia „$rol\".");
        }
        foreach (['t1', 'p1', 't2', 'p2'] as $camp) {
            if (!isset($l[$camp]) || !is_numeric($l[$camp])) {
                eroare("Linia „$rol\" n-are $camp valid.");
            }
        }
        $t1 = (int)$l['t1'];   $t2 = (int)$l['t2'];
        $p1 = (float)$l['p1']; $p2 = (float)$l['p2'];

        if ($t1 === $t2) {
            eroare("Linia „$rol\" e verticală — cele două puncte au același moment.");
        }
        if ($p1 <= 0 || $p2 <= 0) {
            eroare("Linia „$rol\" are prețuri nevalide.");
        }

        // Punctele se păstrează în ordine cronologică, ca panta să aibă mereu
        // același sens indiferent în ce ordine a dat utilizatorul clic.
        if ($t1 > $t2) {
            [$t1, $t2] = [$t2, $t1];
            [$p1, $p2] = [$p2, $p1];
        }

        $linii[$rol] = ['t1' => $t1, 'p1' => $p1, 't2' => $t2, 'p2' => $p2];
    }

    $nota = isset($d['nota']) ? mb_substr(trim((string)$d['nota']), 0, 255) : null;

    $pdo = baza();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO triunghiuri (stare, desenat_la, nota)
                       VALUES ('activ', ?, ?)")
            ->execute([acum_ms(), $nota !== '' ? $nota : null]);
        $id = (int)$pdo->lastInsertId();

        $st = $pdo->prepare("INSERT INTO linii (triunghi_id, rol, t1, p1, t2, p2)
                             VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($linii as $rol => $l) {
            $st->execute([$id, $rol, $l['t1'], $l['p1'], $l['t2'], $l['p2']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('autobot: salvare triunghi: ' . $e->getMessage());
        eroare('Nu am putut salva triunghiul.', 500);
    }

    raspunde(['ok' => true, 'id' => $id], 201);
}

/* ----------------------------------------------------------------- ștergere */

if ($metoda === 'DELETE') {
    cere_cheia();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) eroare('Lipsește id-ul.');

    $pdo = baza();
    $st = $pdo->prepare("SELECT stare FROM triunghiuri WHERE id = ?");
    $st->execute([$id]);
    $stare = $st->fetchColumn();

    if ($stare === false)     eroare('Triunghiul nu există.', 404);
    if ($stare === 'consumat') {
        eroare('Triunghiul a produs deja un semnal — nu poate fi șters, ' .
               'altfel tranzacția ar rămâne fără explicație.', 409);
    }

    $pdo->prepare("UPDATE triunghiuri SET stare = 'sters' WHERE id = ?")->execute([$id]);
    raspunde(['ok' => true]);
}

eroare('Metodă neacceptată.', 405);
