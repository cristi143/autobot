<?php
/**
 * Motorul botului automat — rulează din cron, o dată pe oră, la minutul 2.
 *
 * Regulile: ../docs/plan-bot.md. Deciziile le iau funcțiile din bot-reguli.php,
 * aceleași pe care le-a verificat proba de echivalență cu simularea. Aici nu se
 * hotărăște nimic: se aduc date, se cheamă regulile, se scrie ce au zis.
 *
 * O RULARE:
 *   1. aduce ultimele 1000 de lumânări de 1h închise, pentru fiecare monedă
 *   2. dacă ora cea mai nouă a fost deja judecată → „nimic_nou”, gata
 *   3. orele sărite de cron se judecă și se scriu ca PIERDUTE — fără tranzacții:
 *      a cumpăra acum la prețul de acum 3 ore ar fi o ficțiune (plan §5)
 *   4. judecă ora nouă, ia prețurile de acum (bid/ask), rulează băncile A și B
 *   5. scrie totul într-o singură tranzacție de bază de date
 *
 * Banca C (pasivă) cumpără o singură dată, la prima rulare, apoi doar se evaluează.
 *
 * Pentru o probă locală, calea configurării se poate da prin AUTOBOT_CONFIG, iar
 * configurarea poate avea `db.dsn` (de exemplu sqlite:...). De aceea SQL-ul de
 * aici evită ce e specific MySQL (REPLACE INTO în loc de ON DUPLICATE KEY).
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

require __DIR__ . '/bot-reguli.php';

const ORA_MS       = 3600000;
const CAPITAL      = 1000.0;          // USDC fictivi pe bancă
const LUMANARI     = 1000;            // maximul Binance pe o cerere
const MAX_PIERDUTE = 200;             // peste atât, nu mai reconstituim orele pierdute

$inceput = (int)(microtime(true) * 1000);
$jurnal  = [];

function spune(string $text): void {
    global $jurnal;
    $jurnal[] = $text;
    if (PHP_SAPI === 'cli') { echo $text, "\n"; }
}

/* ------------------------------------------------------------ configurarea */

$caleConfig = getenv('AUTOBOT_CONFIG') ?: '/home/marcelpa/autobot-config.php';
if (!is_readable($caleConfig)) {
    fwrite(STDERR, "Configurarea lipsește: $caleConfig\n");
    exit(1);
}
$config   = require $caleConfig;
$comision = (float)($config['reguli']['comision_o_parte'] ?? 0.075) / 100.0;

/* ------------------------------------------------------------ baza de date */

try {
    $d = $config['db'];
    $dsn = $d['dsn'] ?? "mysql:host={$d['gazda']};dbname={$d['nume']};charset=utf8mb4";
    $pdo = new PDO($dsn, $d['user'] ?? null, $d['parola'] ?? null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $eMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($eMysql) { $pdo->exec("SET time_zone = '+00:00'"); }
} catch (Throwable $e) {
    fwrite(STDERR, "Conexiune eșuată: " . $e->getMessage() . "\n");
    exit(1);
}

/** Scrie rularea în jurnal și iese. */
function incheie(string $rezultat, ?int $ora = null, int $pierdute = 0): never {
    global $pdo, $inceput, $jurnal;
    try {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $pdo->prepare("INSERT INTO b_jurnal (pornit_la, durata_ms, ora_judecata, ore_pierdute, rezultat, detaliu)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$inceput, (int)(microtime(true) * 1000) - $inceput, $ora, $pierdute,
                       $rezultat, implode("\n", $jurnal) ?: null]);
    } catch (Throwable $e) {
        fwrite(STDERR, "Nu pot scrie jurnalul: " . $e->getMessage() . "\n");
    }
    exit($rezultat === 'eroare' ? 1 : 0);
}

set_error_handler(function ($nr, $msg, $fis, $lin) {
    spune("EROARE PHP: $msg ($fis:$lin)");
    incheie('eroare');
});
// Fără asta, o excepție ar omorî scriptul înainte de jurnal, iar în pagină ar
// arăta exact ca un cron care nu mai rulează.
set_exception_handler(function (Throwable $e) {
    spune("EXCEPȚIE: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")");
    incheie('eroare');
});

// Două rulări suprapuse ar putea tranzacționa aceeași oră de două ori.
if ($eMysql && (int)$pdo->query("SELECT GET_LOCK('autobot_bot', 0)")->fetchColumn() !== 1) {
    spune('O altă rulare e încă în curs.');
    incheie('ocupat');
}

/** Numerele merg în DECIMAL ca text: fără zgomot de virgulă mobilă în coloane de bani. */
function dec(float $x, int $zecimale = 10): string { return sprintf("%.{$zecimale}F", $x); }
function oraText(int $ms): string { return gmdate('d.m.Y H:i', intdiv($ms, 1000)) . ' UTC'; }

/* ---------------------------------------------------------------- Binance */

function binance(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_SSL_VERIFYPEER => true]);
    $r   = curl_exec($ch);
    $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($r === false) { spune("Binance: $err");                 incheie('eroare'); }
    if ($cod !== 200) { spune("Binance: HTTP $cod pe $url");    incheie('eroare'); }
    $d = json_decode($r, true);
    if (!is_array($d)) { spune('Binance: răspuns necitibil');   incheie('eroare'); }
    return $d;
}

$acumMs = (int)(microtime(true) * 1000);
$inch = [];          // [simbol][ora_ms] => închidere, doar lumânări ÎNCHISE
foreach (B_MONEDE as $m) {
    $k = binance("https://api.binance.com/api/v3/klines?symbol=$m&interval=1h&limit=" . LUMANARI);
    foreach ($k as $l) {
        if ((int)$l[6] < $acumMs) { $inch[$m][(int)$l[0]] = (float)$l[4]; }   // [6] = ora închiderii
    }
    if (count($inch[$m] ?? []) < B_INCHIDERI_NECESARE) {
        spune("$m: doar " . count($inch[$m] ?? []) . ' lumânări închise, trebuie ' . B_INCHIDERI_NECESARE);
        incheie('eroare');
    }
}
// Ora judecată = cea mai nouă oră închisă la TOATE trei monedele.
$H = min(array_map(fn($m) => max(array_keys($inch[$m])), B_MONEDE));

/** Ultimele 745 de închideri până la $ora inclusiv; null dacă lipsește vreuna. */
function inchideriPana(array $serie, int $ora): ?array {
    $c = [];
    for ($t = $ora - (B_INCHIDERI_NECESARE - 1) * ORA_MS; $t <= $ora; $t += ORA_MS) {
        if (!isset($serie[$t])) { return null; }
        $c[] = $serie[$t];
    }
    return $c;
}

/* ------------------------------------------------- copia noastră de lumânări */

$insL = $pdo->prepare("REPLACE INTO b_lumanari_1h (simbol, ora, inchidere, adaugat_la) VALUES (?,?,?,?)");
$pdo->beginTransaction();
foreach (B_MONEDE as $m) {
    $are = (int)$pdo->query("SELECT COUNT(*) FROM b_lumanari_1h WHERE simbol = " . $pdo->quote($m))->fetchColumn();
    // prima dată tot istoricul adus; apoi doar ultimele două zile (corecturi Binance incluse)
    $deScris = $are === 0 ? $inch[$m] : array_slice($inch[$m], -48, null, true);
    foreach ($deScris as $ora => $c) { $insL->execute([$m, $ora, dec($c, 8), $inceput]); }
}
$pdo->commit();

/* ------------------------------------------------------------ ce e nou */

$ultima = $pdo->query("SELECT MAX(ora) FROM b_evaluari WHERE pierduta = 0")->fetchColumn();
$ultima = $ultima === null || $ultima === false ? null : (int)$ultima;
if ($ultima !== null && $ultima >= $H) {
    spune('Ora ' . oraText($H) . ' e deja judecată.');
    incheie('nimic_nou', $H);
}

$insE = $pdo->prepare("REPLACE INTO b_evaluari (ora, simbol, inchidere, vol, rang, r24, semnal, pierduta)
                       VALUES (?,?,?,?,?,?,?,?)");

/** Judecă o oră pe o monedă și o scrie. */
function evalueaza(string $m, int $ora, bool $pierduta): ?array {
    global $inch, $insE;
    $c = inchideriPana($inch[$m], $ora);
    if ($c === null) { return null; }
    $e = b_evalueaza($c);
    $insE->execute([$ora, $m, dec($inch[$m][$ora], 8), $e['vol'], $e['rang'], $e['r24'],
                    $e['semnal'] ? 1 : 0, $pierduta ? 1 : 0]);
    return $e;
}

$pierdute = 0;
$pdo->beginTransaction();
if ($ultima !== null && $H - $ultima > ORA_MS) {
    $pierdute = intdiv($H - $ultima, ORA_MS) - 1;
    spune("Cronul a sărit $pierdute or" . ($pierdute === 1 ? 'ă' : 'e') . ', de la ' . oraText($ultima + ORA_MS)
          . '. Se scriu ca pierdute, fără tranzacții.');
    $de = max($ultima + ORA_MS, $H - MAX_PIERDUTE * ORA_MS);
    for ($t = $de; $t < $H; $t += ORA_MS) {
        foreach (B_MONEDE as $m) {
            $e = evalueaza($m, $t, true);
            if ($e && $e['semnal']) { spune("  semnal ratat: $m la " . oraText($t)); }
        }
    }
}

$semnale = []; $ev = [];
foreach (B_MONEDE as $m) {
    $ev[$m] = evalueaza($m, $H, false);
    if ($ev[$m] === null) {
        spune("$m: istoricul are o gaură înainte de " . oraText($H) . '; ora nu se poate judeca.');
        incheie('eroare', $H, $pierdute);
    }
    $semnale[$m] = $ev[$m]['semnal'];
}
spune('Ora ' . oraText($H) . ': ' . implode(' · ', array_map(fn($m) => sprintf(
    '%s rang %.2f, %+.1f%% în 24h%s', substr($m, 0, 3), $ev[$m]['rang'], $ev[$m]['r24'] * 100,
    $ev[$m]['semnal'] ? ' → SEMNAL' : ''), B_MONEDE)));

/* ------------------------------------------------------------ prețurile de acum */

$bt = binance('https://api.binance.com/api/v3/ticker/bookTicker?symbols='
              . rawurlencode(json_encode(B_MONEDE)));
$pret = [];
foreach ($bt as $x) {
    $bid = (float)$x['bidPrice']; $ask = (float)$x['askPrice'];
    if ($bid <= 0 || $ask < $bid) { spune("{$x['symbol']}: bid/ask fără sens ($bid / $ask)"); incheie('eroare', $H, $pierdute); }
    $pret[$x['symbol']] = ['eval' => ($bid + $ask) / 2, 'cump' => $ask, 'vinde' => $bid];
}
foreach (B_MONEDE as $m) {
    if (!isset($pret[$m])) { spune("Binance n-a dat prețul pentru $m"); incheie('eroare', $H, $pierdute); }
}
$eval = array_map(fn($p) => $p['eval'], $pret);

/* ------------------------------------------------------------ băncile */

function citesteBanca(string $b): ?array {
    global $pdo;
    $r = $pdo->query("SELECT * FROM b_banci WHERE banca = " . $pdo->quote($b))->fetch();
    if (!$r) { return null; }
    $s = b_stareNoua(0.0);
    $s['usdc'] = (float)$r['usdc'];
    foreach ($pdo->query("SELECT simbol, cantitate FROM b_detineri WHERE banca = " . $pdo->quote($b)) as $d) {
        $s['q'][$d['simbol']] = (float)$d['cantitate'];
    }
    if ($b === 'B') {
        foreach (B_MONEDE as $m) {
            $e = $pdo->query("SELECT * FROM b_exceptii WHERE simbol = " . $pdo->quote($m)
                             . " ORDER BY pornita_ora DESC, id DESC LIMIT 1")->fetch();
            if ($e) {
                $s['exc'][$m] = ['expira' => intdiv((int)$e['expira_ora'], ORA_MS),
                                 'activa' => $e['inchisa_ora'] === null];
            }
        }
    }
    return $s;
}

$insT = $pdo->prepare("INSERT INTO b_tranzactii (banca, ora, executat_la, simbol, cantitate, pret, pret_simulare,
                                                 comision, tinta, usdc_dupa, motiv)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)");

/** Scrie tranzacțiile unei bănci, cu USDC-ul de după fiecare. */
function scrieTranzactii(string $b, float $usdcInainte, array $tranz): void {
    global $insT, $H, $inch, $pdo;
    $usdc = $usdcInainte;
    foreach ($tranz as $t) {
        $q = $t['cantitate'];
        $usdc += $q > 0 ? -($q * $t['pret'] + $t['comision']) : (-$q * $t['pret'] - $t['comision']);
        $motiv = "$b · " . ($q > 0 ? 'cumpărat ' : 'vândut ') . substr($t['moneda'], 0, 3)
               . ' până la ' . str_replace('.', ',', (string)round($t['tinta'] * 100, 1)) . '%: ' . $t['motiv'];
        $insT->execute([$b, $H, (int)(microtime(true) * 1000), $t['moneda'], dec($q, 12), dec($t['pret'], 8),
                        dec($inch[$t['moneda']][$H], 8), dec($t['comision']), $t['tinta'], dec($usdc),
                        mb_substr($motiv, 0, 255)]);
        spune('  ' . $motiv);
    }
}

function scrieBanca(string $b, array $s, bool $noua): void {
    global $pdo, $inceput;
    if ($noua) {
        $pdo->prepare("INSERT INTO b_banci (banca, usdc, capital_initial, pornita_la, actualizat_la) VALUES (?,?,?,?,?)")
            ->execute([$b, dec($s['usdc']), dec(CAPITAL), $inceput, $inceput]);
    } else {
        $pdo->prepare("UPDATE b_banci SET usdc = ?, actualizat_la = ? WHERE banca = ?")
            ->execute([dec($s['usdc']), $inceput, $b]);
    }
    $up = $pdo->prepare("REPLACE INTO b_detineri (banca, simbol, cantitate) VALUES (?,?,?)");
    foreach (B_MONEDE as $m) { $up->execute([$b, $m, dec($s['q'][$m], 12)]); }
}

$Hh = intdiv($H, ORA_MS);
$prima = $pdo->query("SELECT COUNT(*) FROM b_banci")->fetchColumn() == 0;
if ($prima) {
    spune('Prima rulare: trei bănci a câte ' . CAPITAL . ' USDC.');
    [$C, $tC] = b_pornestePasiv(CAPITAL, $pret, $comision);
    scrieTranzactii('C', CAPITAL, $tC);
    scrieBanca('C', $C, true);
    $A = b_stareNoua(CAPITAL);
    $B = b_stareNoua(CAPITAL);
} else {
    $A = citesteBanca('A'); $B = citesteBanca('B'); $C = citesteBanca('C');
    if (!$A || !$B || !$C) { spune('O bancă lipsește din b_banci.'); incheie('eroare', $H, $pierdute); }
}

[$A2, $tA] = b_pasOra('A', $A, $Hh, [], $pret, $comision);
[$B2, $tB] = b_pasOra('B', $B, $Hh, $semnale, $pret, $comision);
if ($prima) {   // la pornire nu e o reechilibrare, e prima cumpărare
    foreach ([&$tA, &$tB] as &$lista) {
        foreach ($lista as &$t) {
            if (str_starts_with($t['motiv'], 'reechilibrare')) { $t['motiv'] = 'pornire: 1/6 din capital în fiecare monedă'; }
        }
        unset($t);
    }
    unset($lista);
}

/* ---- excepțiile băncii B: întâi închiderile, apoi pornirile ---- */
foreach (B_MONEDE as $m) {
    $inainte = $B['exc'][$m]; $dupa = $B2['exc'][$m];
    $sAInchis = $inainte['activa'] && (!$dupa['activa'] || $dupa['expira'] !== $inainte['expira']);
    $aPornit  = $dupa['activa'] && (!$inainte['activa'] || $dupa['expira'] !== $inainte['expira']);
    if ($sAInchis) {
        $pdo->prepare("UPDATE b_exceptii SET inchisa_ora = ?, pret_inchidere = ?
                       WHERE simbol = ? AND inchisa_ora IS NULL")
            ->execute([$H, dec($inch[$m][$H], 8), $m]);
    }
    if ($aPornit) {
        $pdo->prepare("INSERT INTO b_exceptii (simbol, pornita_ora, expira_ora, pret_pornire) VALUES (?,?,?,?)")
            ->execute([$m, $H, $dupa['expira'] * ORA_MS, dec($inch[$m][$H], 8)]);
        spune("  excepție pe $m până la " . oraText($dupa['expira'] * ORA_MS));
    }
}

scrieTranzactii('A', $A['usdc'], $tA);
scrieTranzactii('B', $B['usdc'], $tB);
scrieBanca('A', $A2, $prima);
scrieBanca('B', $B2, $prima);

$insV = $pdo->prepare("REPLACE INTO b_evolutie (ora, banca, valoare, usdc) VALUES (?,?,?,?)");
foreach (['A' => $A2, 'B' => $B2, 'C' => $C] as $b => $s) {
    $v = b_valoare($s, $eval);
    $insV->execute([$H, $b, dec($v), dec($s['usdc'])]);
    spune(sprintf('  banca %s: %.2f USDC (%+.2f%%)', $b, $v, ($v / CAPITAL - 1) * 100));
}
if ($A2['usdc'] < -1e-6 || $B2['usdc'] < -1e-6) {
    spune('USDC negativ după tranzacții — regula „fără levier” n-a ținut.');
    incheie('eroare', $H, $pierdute);
}

$pdo->commit();
incheie('ok', $H, $pierdute);
