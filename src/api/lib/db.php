<?php
/* Ket noi MySQL (PDO) - mot the duy nhat cho ca API va worker */

require_once __DIR__ . '/bootstrap.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = db_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/* Cho MySQL san sang (healthcheck, worker doi DB khoi dong cung luc) */
function db_wait_ready(int $maxSeconds = 60): bool
{
    $deadline = time() + $maxSeconds;
    while (time() < $deadline) {
        try {
            db()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            sleep(2);
        }
    }
    return false;
}
