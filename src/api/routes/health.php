<?php
/* GET /health: kiem tra MySQL + MQTT broker (khong can dang nhap) */

$dbOk = true;
try {
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    $dbOk = false;
}

$mqttOk = false;
$mqttError = null;
try {
    $cfg = mqtt_config();
    $client = new MqttClient($cfg['host'], $cfg['port'], $cfg['username'], $cfg['password'], $cfg['clientId']);
    $client->connect(1);
    $client->disconnect();
    $mqttOk = true;
} catch (Throwable $e) {
    $mqttError = $e->getMessage();
}

json_ok(['database' => $dbOk ? 'up' : 'down', 'mqtt' => $mqttOk ? 'up' : 'down', 'mqttError' => $mqttError]);
