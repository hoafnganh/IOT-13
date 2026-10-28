<?php
/**
 * ESP8266 SIMULATOR (CLI) - gia lap mach that khi chua co phan cung.
 *
 * Ban tin gia lap trung khop 100% voi sketch_sep21a.ino:
 *   - publish "online" (retained) tren iot/esp01/status
 *   - publish telemetry JSON moi 3 giay tren iot/esp01/telemetry
 *   - nhan lenh text (LED1_ON, LED2_OFF, BOTH_ON...) tren iot/esp01/led
 *   - sau khi thuc hien: publish trang thai LED + ket qua SUCCESS
 *
 * Chay: php tools/esp8266_simulator.php
 * Dung trong docker: docker compose run --rm simulator
 */

require_once __DIR__ . '/../api/lib/bootstrap.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/mqtt.php';
require_once __DIR__ . '/../api/lib/helpers.php';

iot_cli_only();

const NODE_CODE = 'esp01'; // deviceId trong telemetry cua sketch_sep21a.ino

$led1 = 'OFF';
$led2 = 'OFF';

$cfg    = mqtt_config();
$client = new MqttClient($cfg['host'], $cfg['port'], $cfg['username'], $cfg['password'], 'esp01-sim-' . getmypid());

$client->connect(5);
log_line("Simulator da ket noi MQTT broker {$cfg['host']}:{$cfg['port']}");

$client->publish(MQTT_TOPIC_STATUS, 'online', 0, true);
log_line('Da publish status = online (retained)');

// Firmware hien tai nghe lenh tren iot/esp01/led/status (ban cu: iot/esp01/led)
$client->subscribe([MQTT_TOPIC_LED_COMMAND, MQTT_TOPIC_LED_COMMAND_ALT]);
log_line('Dang cho lenh tren ' . MQTT_TOPIC_LED_COMMAND);

function simulate_command(string $payload, string &$led1, string &$led2, MqttClient $client): void
{
    $cmd = strtoupper(trim($payload));
    log_line('Nhan lenh: ' . $cmd);

    switch ($cmd) {
        case 'LED1_ON':
            $led1 = 'ON';
            $client->publish(MQTT_TOPIC_LED1_STATE, $led1);
            break;
        case 'LED1_OFF':
            $led1 = 'OFF';
            $client->publish(MQTT_TOPIC_LED1_STATE, $led1);
            break;
        case 'LED2_ON':
            $led2 = 'ON';
            $client->publish(MQTT_TOPIC_LED2_STATE, $led2);
            break;
        case 'LED2_OFF':
            $led2 = 'OFF';
            $client->publish(MQTT_TOPIC_LED2_STATE, $led2);
            break;
        case 'BOTH_ON':
            $led1 = 'ON';
            $led2 = 'ON';
            $client->publish(MQTT_TOPIC_LED1_STATE, $led1);
            $client->publish(MQTT_TOPIC_LED2_STATE, $led2);
            break;
        case 'BOTH_OFF':
            $led1 = 'OFF';
            $led2 = 'OFF';
            $client->publish(MQTT_TOPIC_LED1_STATE, $led1);
            $client->publish(MQTT_TOPIC_LED2_STATE, $led2);
            break;
        default:
            $client->publish(MQTT_TOPIC_LED_RESULT, 'FAILED');
            log_line('Lenh khong hop le -> FAILED');
            return;
    }

    $client->publish(MQTT_TOPIC_LED_RESULT, 'SUCCESS');
    log_line("Trang thai moi: LED1={$led1} LED2={$led2} -> SUCCESS");
}

$onMessage = function (string $topic, string $payload) use (&$led1, &$led2, $client): void {
    if ($topic === MQTT_TOPIC_LED_COMMAND) {
        simulate_command($payload, $led1, $led2, $client);
    }
};

while (true) {
    // Xu ly lenh trong 3 giay (trung khop chu ky telemetry cua firmware)
    $client->loop($onMessage, 3);

    $temperature = round(random_int(270, 330) / 10, 1);
    $humidity    = round(random_int(550, 800) / 10, 1);
    $light       = random_int(200, 950); // lux

    $telemetry = sprintf(
        '{"deviceId":"%s","temperature":%s,"humidity":%s,"light":%d,"led1":"%s","led2":"%s"}',
        NODE_CODE,
        number_format($temperature, 1, '.', ''),
        number_format($humidity, 1, '.', ''),
        $light,
        $led1,
        $led2
    );

    $client->publish(MQTT_TOPIC_TELEMETRY, $telemetry);
    log_line('Telemetry: ' . $telemetry);
}

function log_line(string $message): void
{
    echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
}
