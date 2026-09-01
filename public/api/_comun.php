<?php
/**
 * Temelia comună a tuturor punctelor de API: configurare, conexiune, răspunsuri.
 *
 * Nu produce nicio ieșire proprie — doar funcții și conexiunea.
 */

declare(strict_types=1);

// Toate momentele de piață sunt milisecunde UTC. Fixăm fusul ca nimic din PHP
// să nu strecoare ora Bucureștiului într-un calcul.
date_default_timezone_set('UTC');

const CALE_CONFIG = '/home/marcelpa/autobot-config.php';

/** Răspunde cu JSON și oprește execuția. */
function raspunde(array $date, int $cod = 200): never {
    http_response_code($cod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($date, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Răspunde cu o eroare într-o formă pe care frontend-ul o poate afișa direct. */
function eroare(string $mesaj, int $cod = 400): never {
    raspunde(['ok' => false, 'eroare' => $mesaj], $cod);
}

/** Încarcă configurarea de deasupra zonei publice. */
function config(): array {
    static $c = null;
    if ($c === null) {
        if (!is_readable(CALE_CONFIG)) {
            eroare('Configurarea lipsește de pe server (' . CALE_CONFIG . ').', 500);
        }
        $c = require CALE_CONFIG;
        if (!is_array($c) || !isset($c['db'])) {
            eroare('Configurarea de pe server e incompletă.', 500);
        }
    }
    return $c;
}

/** Conexiunea la baza de date, deschisă o singură dată. */
function baza(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $d = config()['db'];
        try {
            $pdo = new PDO(
                "mysql:host={$d['gazda']};dbname={$d['nume']};charset=utf8mb4",
                $d['user'],
                $d['parola'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            // MySQL să nu convertească nimic după fusul serverului.
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            // Mesajul brut ar conține date de conectare — nu ajunge la browser.
            error_log('autobot: conexiune eșuată: ' . $e->getMessage());
            eroare('Nu mă pot conecta la baza de date.', 500);
        }
    }
    return $pdo;
}

/** Citește corpul cererii ca JSON. */
function corp(): array {
    $brut = file_get_contents('php://input') ?: '';
    $d = json_decode($brut, true);
    return is_array($d) ? $d : [];
}

/**
 * Cere cheia pentru operațiile care schimbă ceva.
 *
 * Nu e autentificare adevărată — aceea vine la etapa 3, cu protecția prin parolă
 * din cPanel peste tot site-ul. Până atunci, împiedică scrierile venite de la
 * cineva care nimerește adresa.
 */
function cere_cheia(): void {
    $asteptata = (string)(config()['cheie_api'] ?? '');
    if ($asteptata === '' || str_contains($asteptata, 'ȘIR-LUNG')) {
        eroare('Cheia de API nu e configurată pe server.', 500);
    }
    $primita = $_SERVER['HTTP_X_AUTOBOT_CHEIE'] ?? '';
    if (!hash_equals($asteptata, (string)$primita)) {
        eroare('Cheie greșită.', 401);
    }
}

function acum_ms(): int { return (int)(microtime(true) * 1000); }

/** Prețul unei linii la un moment dat — prelungire dreaptă prin cele două puncte. */
function pret_linie(array $linie, int $moment_ms): float {
    $t1 = (int)$linie['t1']; $t2 = (int)$linie['t2'];
    $p1 = (float)$linie['p1']; $p2 = (float)$linie['p2'];
    if ($t2 === $t1) return $p1;                       // linie verticală: fără sens
    $panta = ($p2 - $p1) / ($t2 - $t1);
    return $p1 + $panta * ($moment_ms - $t1);
}
