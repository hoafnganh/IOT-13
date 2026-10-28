<?php
/**
 * REST API duy nhat cua he thong - moi request /api/v1/* duoc rewrite vao day.
 * File nay chi lam 2 viec: giai ma route + yeu cau file xu ly tuong ung
 * trong api/routes/. Moi nhom endpoint nam trong dung mot file routes.
 *
 *   POST /api/v1/auth/login | logout          GET /api/v1/auth/me   -> routes/auth.php
 *   GET  /api/v1/profile                                            -> routes/profile.php
 *   GET  /api/v1/dashboard | dashboard/chart                        -> routes/dashboard.php
 *   GET  /api/v1/sensor-data                                        -> routes/sensor-data.php
 *   GET  /api/v1/devices | devices/{id}                             -> routes/devices.php
 *   POST /api/v1/devices/{id}/actions (alias /commands)             -> routes/devices.php
 *   GET  /api/v1/actions | actions/{id}                             -> routes/actions.php
 *   GET  /api/v1/health                                             -> routes/health.php
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/mqtt.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/actions.php';

// Route lay tu .htaccess (?_route=...) hoac tu REQUEST_URI truc tiep
$route = $_GET['_route'] ?? '';
if ($route === '' && isset($_SERVER['REQUEST_URI'])) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    if (preg_match('#/api(?:/v1)?/(.+)$#', $path, $m)) {
        $route = trim($m[1], '/');
    }
}

$route    = trim($route, '/');
$segments = $route === '' ? [] : explode('/', $route);
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    $pdo = db();
} catch (Throwable $e) {
    json_fail('Khong ket noi duoc co so du lieu: ' . $e->getMessage(), 500);
}

// Dinh tuyen: file route duoc require se tu exit sau khi tra JSON
if ($route === 'health') {
    require __DIR__ . '/routes/health.php';
}
if (str_starts_with($route, 'auth/')) {
    require __DIR__ . '/routes/auth.php';
}
if ($route === 'profile') {
    require __DIR__ . '/routes/profile.php';
}
if (str_starts_with($route, 'dashboard')) {
    require __DIR__ . '/routes/dashboard.php';
}
if ($route === 'sensor-data') {
    require __DIR__ . '/routes/sensor-data.php';
}
if (($segments[0] ?? '') === 'devices') {
    require __DIR__ . '/routes/devices.php';
}
if (($segments[0] ?? '') === 'actions') {
    require __DIR__ . '/routes/actions.php';
}

// Khong khop route nao
json_fail('Endpoint khong ton tai: ' . $method . ' /api/v1/' . $route, 404);

// ---------------------------------------------------------------------
// Dinh dang du lieu tra ve (dung chung boi cac file routes)
// ---------------------------------------------------------------------

function format_device(array $row): array
{
    return [
        'id'               => (int) $row['id'],
        'deviceCode'       => $row['device_code'],
        'name'             => $row['name'],
        'type'             => $row['type'],
        'gpio'             => $row['gpio_pin'],
        'currentState'     => strtoupper((string) ($row['current_state'] ?? 'UNKNOWN')),
        'connectionStatus' => $row['connection_status'],
        'lastSeen'         => iso_datetime($row['last_seen']),
    ];
}

function format_action(array $row): array
{
    return [
        'id'          => (int) $row['id'],
        'requestId'   => $row['request_id'],
        'deviceId'    => $row['device_code'],
        'deviceName'  => $row['device_name'],
        'action'      => strtoupper($row['action']),
        'status'      => strtoupper($row['status']),
        'user'        => $row['user_name'] ?? null,
        'requestedAt' => iso_datetime($row['requested_at']),
        'respondedAt' => iso_datetime($row['responded_at']),
        'latencyMs'   => $row['response_time_ms'] === null ? null : (int) $row['response_time_ms'],
        'message'     => $row['message'],
    ];
}
