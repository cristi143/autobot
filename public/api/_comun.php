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

// Serverul are serialize_precision mare, așa că json_encode scria 0.075 ca
// 0.07499999999999999722444243843710864894092082977294921875. Valoarea era
// corectă, dar prețurile ar fi arătat la fel, iar un om care se uită la ele
// n-ar mai ști ce e real și ce e zgomot de virgulă mobilă. Cu -1, PHP scrie
// cea mai scurtă formă care se citește înapoi identic.
@ini_set('serialize_precision', '-1');

// Pentru o probă locală, calea se poate da prin AUTOBOT_CONFIG (vezi motor/bot.php).
define('CALE_CONFIG', getenv('AUTOBOT_CONFIG') ?: '/home/marcelpa/autobot-config.php');

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
                $d['dsn'] ?? "mysql:host={$d['gazda']};dbname={$d['nume']};charset=utf8mb4",
                $d['user'] ?? null,
                $d['parola'] ?? null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            // MySQL să nu convertească nimic după fusul serverului.
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $pdo->exec("SET time_zone = '+00:00'");
            }
        } catch (PDOException $e) {
            // Mesajul brut ar conține date de conectare — nu ajunge la browser.
            error_log('autobot: conexiune eșuată: ' . $e->getMessage());
            eroare('Nu mă pot conecta la baza de date.', 500);
        }
    }
    return $pdo;
}

function acum_ms(): int { return (int)(microtime(true) * 1000); }
