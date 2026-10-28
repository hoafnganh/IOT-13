<?php
/* Cap nhat bang devices: trang thai ket noi cua ca node + cac LED con,
   trang thai ON/OFF hien tai cua tung LED. */

/* Cap nhat connection_status + last_seen cho node va moi thiet bi con cua no */
function set_node_status(PDO $pdo, string $status, ?string $at = null): void
{
    $stmt = $pdo->prepare(
        "UPDATE devices
         SET connection_status = ?, last_seen = ?
         WHERE device_code = ? OR device_code LIKE CONCAT(?, ':%')"
    );
    $stmt->execute([$status, $at ?? now_db(), NODE_CODE, NODE_CODE]);
}

function set_device_state(PDO $pdo, string $deviceCode, string $state, string $at): void
{
    $stmt = $pdo->prepare(
        'UPDATE devices
         SET current_state = ?, last_seen = ?
         WHERE device_code = ?'
    );
    $stmt->execute([$state, $at, $deviceCode]);

    // Dam bao thiet bi ton tai (neu DB bi seed lai trong luc worker chay)
    if ($stmt->rowCount() === 0) {
        $name = $deviceCode === 'esp01:led1' ? 'LED 1' : 'LED 2';
        $gpio = $deviceCode === 'esp01:led1' ? 'D1' : 'D4';
        $ins  = $pdo->prepare(
            "INSERT IGNORE INTO devices (device_code, name, type, gpio_pin, current_state, connection_status, last_seen)
             VALUES (?, ?, 'ACTUATOR', ?, ?, 'online', ?)"
        );
        $ins->execute([$deviceCode, $name, $gpio, $state, $at]);
    }
}
