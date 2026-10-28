<?php
/* Xu ly tung ban tin MQTT nhan duoc:
   telemetry -> luu datasensor; status -> online/offline;
   led{n}/state -> doi chieu lenh PENDING thanh SUCCESS;
   led/result = FAILED -> danh dau lenh moi nhat la FAILED. */

/* Dinh tuyen ban tin theo phan sau topic (bo prefix iot/esp01/) */
function handle_message(string $topic, string $payload): void
{
    $pdo = db();

    $suffix = $topic;
    if (strpos($topic, 'iot/esp01/') === 0) {
        $suffix = substr($topic, strlen('iot/esp01/'));
    }

    switch ($suffix) {
        case 'telemetry':
            handle_telemetry($pdo, $payload);
            break;

        case 'status':
            set_node_status($pdo, strtolower($payload) === 'online' ? 'online' : 'offline');
            break;

        case 'led1/state':
        case 'led1/status':
            handle_led_state($pdo, 'esp01:led1', $payload);
            break;

        case 'led2/state':
        case 'led2/status':
            handle_led_state($pdo, 'esp01:led2', $payload);
            break;

        case 'led/result':
            handle_led_result($pdo, $payload);
            break;

        case 'led':
        case 'led/status':
            // Topic lenh: bo qua echo chinh lenh da publish (LED1_ON, BOTH_OFF...)
            if (preg_match('/^(LED1|LED2|BOTH)_(ON|OFF)$/', strtoupper(trim($payload)))) {
                break;
            }
            log_line("Ban tin tren topic lenh [{$topic}]: {$payload}");
            break;

        default:
            log_line("Topic chua xu ly [{$topic}]: {$payload}");
            break;
    }
}

/* Telemetry moi 3 giay: luu 3 gia tri cam bien + trang thai 2 LED, danh dau node online */
function handle_telemetry(PDO $pdo, string $payload): void
{
    $data = json_decode($payload, true);
    if (!is_array($data)) {
        log_line('Telemetry JSON khong hop le: ' . substr($payload, 0, 120));
        return;
    }

    $now = now_db();
    set_node_status($pdo, 'online', $now);

    // Trang thai LED trong telemetry: vua cap nhat thiet bi, vua doi chieu lenh PENDING
    foreach (['esp01:led1' => 'led1', 'esp01:led2' => 'led2'] as $code => $key) {
        if (isset($data[$key]) && in_array(strtoupper((string) $data[$key]), ['ON', 'OFF'], true)) {
            $state = strtoupper((string) $data[$key]);
            set_device_state($pdo, $code, $state, $now);
            match_pending_from_state($pdo, $code, $state);
        }
    }

    // Luu 3 gia tri cam bien vao datasensor
    $values = [
        'esp01:temperature' => $data['temperature'] ?? null,
        'esp01:humidity'    => $data['humidity'] ?? null,
        'esp01:light'       => $data['light'] ?? null,
    ];

    $insert = $pdo->prepare(
        'INSERT INTO datasensor (sensor_id, value, recorded_at)
         SELECT id, ?, ? FROM sensors WHERE sensor_code = ?'
    );

    foreach ($values as $code => $value) {
        if ($value === null || !is_numeric($value)) {
            continue;
        }
        $insert->execute([(float) $value, $now, $code]);
    }

    log_line(sprintf(
        'Telemetry: temp=%.1f, hum=%.1f, light=%s',
        (float) ($data['temperature'] ?? 0),
        (float) ($data['humidity'] ?? 0),
        (string) ($data['light'] ?? '?')
    ));
}

/* Lenh PENDING khop trang thai muc tieu (tu led/state rieng hoac telemetry) -> SUCCESS */
function match_pending_from_state(PDO $pdo, string $deviceCode, string $state): void
{
    $device = find_device($pdo, $deviceCode);
    if ($device === null) {
        return;
    }

    $pending = latest_pending_action($pdo, (int) $device['id'], $state);
    if ($pending !== null) {
        mark_action($pdo, (int) $pending['id'], 'SUCCESS', 'ESP8266 da thuc hien lenh ' . $state);
        log_line("Lenh #{$pending['id']} ({$deviceCode} {$state}) -> SUCCESS");
    }
}

/* ESP8266 bao trang thai LED sau khi thuc hien lenh */
function handle_led_state(PDO $pdo, string $deviceCode, string $state): void
{
    $state = strtoupper(trim($state));
    if (!in_array($state, ['ON', 'OFF'], true)) {
        return;
    }

    set_device_state($pdo, $deviceCode, $state, now_db());
    match_pending_from_state($pdo, $deviceCode, $state);
}

/* ESP8266 bao "FAILED" -> danh dau lenh PENDING moi nhat la FAILED
   (SUCCESS da duoc xu ly qua led1/led2/state) */
function handle_led_result(PDO $pdo, string $result): void
{
    if (strtoupper(trim($result)) !== 'FAILED') {
        return;
    }

    $stmt = $pdo->query(
        "SELECT * FROM actions
         WHERE status = 'PENDING'
         ORDER BY requested_at DESC, id DESC
         LIMIT 1"
    );
    $pending = $stmt->fetch();

    if ($pending !== null) {
        mark_action($pdo, (int) $pending['id'], 'FAILED', 'ESP8266 bao lenh thuc hien that bai');
        log_line("Lenh #{$pending['id']} -> FAILED (theo led/result)");
    }
}
