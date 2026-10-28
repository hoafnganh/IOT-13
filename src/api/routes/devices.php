<?php
/* GET  /devices: danh sach thiet bi
   GET  /devices/{id}: chi tiet (id so hoac device_code)
   POST /devices/{id}/actions (body {"action":"ON"}) va /commands (body {"state":"ON"})
        la hai ten goi cung mot chuc nang: gui lenh ON/OFF cho LED. */

if ($route === 'devices' && $method === 'GET') {
    require_login();
    $rows = $pdo->query('SELECT * FROM devices ORDER BY type DESC, device_code ASC')->fetchAll();
    json_ok(array_map('format_device', $rows));
}

if (count($segments) === 2 && $method === 'GET') {
    require_login();
    $device = find_device($pdo, $segments[1]);
    if ($device === null) {
        json_fail('Khong tim thay thiet bi', 404);
    }
    json_ok(format_device($device));
}

/* ---- Gui lenh dieu khien ---- */
$user   = require_login();
$device = find_device($pdo, $segments[1]);

if ($device === null) {
    json_fail('Khong tim thay thiet bi', 404);
}

if (($device['type'] ?? '') !== 'ACTUATOR') {
    json_fail('Thiet bi nay khong ho tro dieu khien ON/OFF', 400);
}

$body   = body_json();
$action = strtoupper(trim((string) ($body['action'] ?? $body['state'] ?? ($_POST['action'] ?? ''))));

if (!in_array($action, ['ON', 'OFF'], true)) {
    json_fail('Action phai la ON hoac OFF', 422);
}

// Node phai online moi cho gui lenh
$node = find_device($pdo, node_code_of($device['device_code']));
$nodeOnline = $node !== null && $node['connection_status'] === 'online';

if (!$nodeOnline) {
    $failed = create_pending_action($pdo, (int) $user['id'], $device, $action);
    mark_action($pdo, (int) $failed['id'], 'FAILED', 'Thiết bị đang offline, không gửi được lệnh');
    json_fail('ESP8266 đang offline, không thể điều khiển. Hãy kiểm tra nguồn và Wi-Fi của mạch.', 409);
}

$pending = create_pending_action($pdo, (int) $user['id'], $device, $action);

try {
    $cfg    = mqtt_config();
    $client = new MqttClient($cfg['host'], $cfg['port'], $cfg['username'], $cfg['password'], $cfg['clientId'] . '-pub');
    $client->connect(2);

    $command = command_for_device($device['device_code'], $action);

    // Firmware hien tai nghe iot/esp01/led/status; ban cu nghe iot/esp01/led
    // -> publish ca hai topic de luon den dung mach.
    $client->publish(MQTT_TOPIC_LED_COMMAND, $command);
    $client->publish(MQTT_TOPIC_LED_COMMAND_ALT, $command);

    $client->disconnect();
} catch (Throwable $e) {
    mark_action($pdo, (int) $pending['id'], 'FAILED', 'Khong publish duoc lenh len MQTT broker: ' . $e->getMessage());
    json_fail('Khong ket noi duoc MQTT broker: ' . $e->getMessage(), 502);
}

json_out([
    'success' => true,
    'message' => 'Đã nhận lệnh ' . $action . ', đang chờ ESP8266 phản hồi',
    'data'    => [
        'actionId'   => $pending['id'],
        'requestId'  => $pending['request_id'],
        'deviceCode' => $device['device_code'],
        'action'     => $action,
        'status'     => 'PENDING',
    ],
], 202);
