<?php
/* Khoi tao chung cho API va worker: config, timezone, session.
   Moi file trong api/lib deu nap qua day de tranh bi goi truc tiep tu URL. */

declare(strict_types=1);

define('IOT_APP', 1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set(getenv('APP_TZ') ?: 'Asia/Ho_Chi_Minh');

/* Doc cau hinh tu bien moi truong (docker-compose), co gia tri mac dinh */
function config(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function db_config(): array
{
    return [
        'host'     => config('DB_HOST', '127.0.0.1'),
        'port'     => config('DB_PORT', '3306'),
        'database' => config('DB_NAME', 'iot_db'),
        'user'     => config('DB_USER', 'iot'),
        'password' => config('DB_PASSWORD', 'iot12345'),
        'charset'  => 'utf8mb4',
    ];
}

function mqtt_config(): array
{
    return [
        'host'     => config('MQTT_HOST', '127.0.0.1'),
        'port'     => (int) config('MQTT_PORT', '1883'),
        'username' => config('MQTT_USERNAME', 'anhdh'),
        'password' => config('MQTT_PASSWORD', '2507'),
        'clientId' => config('MQTT_CLIENT_ID', 'iot-web-' . getmypid()),
    ];
}

/* So giay cho mot lenh cho phan hoi tu ESP8266 truoc khi bi danh dau TIMEOUT */
function action_timeout_seconds(): int
{
    return (int) config('ACTION_TIMEOUT_SECONDS', '8');
}

/* Session dung chung cho API (worker CLI khong can session) */
function iot_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('IOTSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* Chan truy cap truc tiep vao cac file lib/worker tu URL */
function iot_cli_only(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
