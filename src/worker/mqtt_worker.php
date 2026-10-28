<?php
/**
 * MQTT WORKER (CLI, chay dai trong Docker).
 * File nay: vong doi chinh - cho MySQL, ket noi broker, subscribe
 * iot/esp01/# va quet TIMEOUT. Xu ly tung ban tin nam trong handlers.php,
 * cap nhat bang devices nam trong device_state.php.
 */

require_once __DIR__ . '/../api/lib/bootstrap.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/mqtt.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/actions.php';
require_once __DIR__ . '/handlers.php';
require_once __DIR__ . '/device_state.php';

iot_cli_only();

const NODE_CODE = 'esp01';
const WORKER_LOOP_SECONDS = 20;   // moi chu ky loop nhan ban tin
const WORKER_RECONNECT_DELAY = 3; // giay nghi truoc khi thu lai ket noi

log_line('MQTT worker khoi dong');

// Cho MySQL san sang (docker-compose khoi dong song song cac service)
if (!db_wait_ready(120)) {
    log_line('Khong ket noi duoc MySQL, worker dung lai');
    exit(1);
}
log_line('MySQL da san sang');

while (true) {
    try {
        run_worker_cycle();
    } catch (Throwable $e) {
        log_line('Loi chu ky: ' . $e->getMessage());
    }
    sleep(WORKER_RECONNECT_DELAY);
}

function run_worker_cycle(): void
{
    $cfg = mqtt_config();
    $client = new MqttClient($cfg['host'], $cfg['port'], $cfg['username'], $cfg['password'], $cfg['clientId']);

    $client->connect(3);
    log_line("Da ket noi MQTT broker {$cfg['host']}:{$cfg['port']}");

    // Subscribe toan bo ban tin cua node de khong sot khi firmware doi topic
    $client->subscribe([MQTT_TOPIC_NODE_ROOT]);
    log_line('Da subscribe ' . MQTT_TOPIC_NODE_ROOT);

    $lastTimeoutSweep = 0;

    $onMessage = function (string $topic, string $payload) use (&$lastTimeoutSweep): void {
        try {
            handle_message($topic, trim($payload));
        } catch (Throwable $e) {
            log_line("Loi xu ly ban tin [{$topic}]: " . $e->getMessage());
        }

        // Quet TIMEOUT dinh ky (2 giay mot lan khi co traffic)
        if (time() - $lastTimeoutSweep >= 2) {
            $lastTimeoutSweep = time();
            $timedOut = sweep_action_timeouts(db(), action_timeout_seconds());
            if ($timedOut > 0) {
                log_line("Da danh dau {$timedOut} lenh la TIMEOUT");
            }
        }
    };

    while (true) {
        $client->loop($onMessage, WORKER_LOOP_SECONDS);

        // Quet them lan nua de bat TIMEOUT khi mach offline (khong co traffic)
        $timedOut = sweep_action_timeouts(db(), action_timeout_seconds());
        if ($timedOut > 0) {
            log_line("Da danh dau {$timedOut} lenh la TIMEOUT");
        }
    }
}

function log_line(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}
