<?php
/* Ham dung chung cho API: phan quyen, phan trang, anh xa thiet bi/lenh
   dieu khien theo contract cua firmware sketch_sep21a.ino */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

const MQTT_TOPIC_TELEMETRY   = 'iot/esp01/telemetry';
const MQTT_TOPIC_STATUS      = 'iot/esp01/status';
const MQTT_TOPIC_LED_COMMAND = 'iot/esp01/led/status';   // topic firmware hien tai subscribe
const MQTT_TOPIC_LED_COMMAND_ALT = 'iot/esp01/led';      // topic ban firmware cu - publish ca hai
const MQTT_TOPIC_LED1_STATE  = 'iot/esp01/led1/state';
const MQTT_TOPIC_LED2_STATE  = 'iot/esp01/led2/state';
const MQTT_TOPIC_LED_RESULT  = 'iot/esp01/led/result';
const MQTT_TOPIC_NODE_ROOT   = 'iot/esp01/#';            // worker subscribe toan bo ban tin cua node

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    iot_session_start();
    $user = current_user();
    if ($user === null) {
        json_fail('Chua dang nhap hoac phien da het han', 401);
    }
    return $user;
}

/* Tham so phan trang: page (tu 1), limit (1..100, mac dinh 10) */
function pagination_params(): array
{
    $page  = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 10)));
    return [$page, $limit];
}

/* Doi "YYYY-MM-DD HH:mm[:ss]" sang DATETIME(3) cua MySQL; sai -> null */
function parse_datetime_param(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
        $dt = DateTime::createFromFormat($format, $value, new DateTimeZone(date_default_timezone_get()));
        if ($dt !== false) {
            $errors = DateTime::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            return $dt->format('Y-m-d H:i:s.v');
        }
    }

    return null;
}

/* Tim thiet bi theo id so hoac theo device_code */
function find_device(PDO $pdo, string $idOrCode): ?array
{
    if (ctype_digit($idOrCode)) {
        $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $idOrCode]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM devices WHERE device_code = ? LIMIT 1');
        $stmt->execute([$idOrCode]);
    }

    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/* Ma node chua thiet bi: "esp01:led1" -> "esp01" */
function node_code_of(string $deviceCode): string
{
    $pos = strpos($deviceCode, ':');
    return $pos === false ? $deviceCode : substr($deviceCode, 0, $pos);
}

/* Chuoi lenh publish cho ESP8266: "esp01:led1" + ON -> "LED1_ON"
   (trung khop callback() trong sketch_sep21a.ino) */
function command_for_device(string $deviceCode, string $action): string
{
    $ledPart = node_code_of($deviceCode) === $deviceCode
        ? ''
        : substr($deviceCode, strpos($deviceCode, ':') + 1);

    $led = ['led1' => 'LED1', 'led2' => 'LED2'][$ledPart] ?? null;
    if ($led === null) {
        throw new InvalidArgumentException("Thiet bi {$deviceCode} khong ho tro dieu khien qua MQTT");
    }

    return $led . '_' . strtoupper($action);
}

/* Dinh dang thoi gian ISO 8601 (kem miligiay) cho FE */
function iso_datetime(?string $mysqlDatetime): ?string
{
    if ($mysqlDatetime === null || $mysqlDatetime === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s.v', $mysqlDatetime, new DateTimeZone(date_default_timezone_get()));
    if ($dt === false) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $mysqlDatetime, new DateTimeZone(date_default_timezone_get()));
    }
    return $dt === false ? null : $dt->format(DateTime::ATOM);
}

function now_db(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
}
