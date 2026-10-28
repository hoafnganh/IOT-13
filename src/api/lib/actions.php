<?php
/**
 * Vong doi mot lenh dieu khien (bang actions): PENDING -> SUCCESS | FAILED | TIMEOUT.
 * ESP8266 khong tra ve requestId (firmware chi gui "SUCCESS"/"FAILED" tren topic
 * iot/esp01/led/result va trang thai tung LED), nen worker doi chieu bang:
 * lenh PENDING moi nhat cung trang thai muc tieu cua dung thiet bi do.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

function create_pending_action(PDO $pdo, int $userId, array $device, string $action): array
{
    $requestId = 'req-' . bin2hex(random_bytes(6));
    $now       = now_db();

    $stmt = $pdo->prepare(
        'INSERT INTO actions (request_id, user_id, device_id, action, status, requested_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$requestId, $userId, (int) $device['id'], strtoupper($action), 'PENDING', $now]);

    return [
        'id'          => (int) $pdo->lastInsertId(),
        'requestId'   => $requestId,
        'deviceId'    => (int) $device['id'],
        'deviceCode'  => $device['device_code'],
        'deviceName'  => $device['name'],
        'action'      => strtoupper($action),
        'status'      => 'PENDING',
        'requestedAt' => $now,
        'respondedAt' => null,
        'responseTimeMs' => null,
        'message'     => null,
    ];
}

/* Lenh PENDING moi nhat cua thiet bi (co the loc theo trang thai muc tieu ON/OFF) */
function latest_pending_action(PDO $pdo, int $deviceId, ?string $action = null): ?array
{
    $sql    = 'SELECT * FROM actions WHERE device_id = ? AND status = ?';
    $params = [$deviceId, 'PENDING'];

    if ($action !== null) {
        $sql .= ' AND action = ?';
        $params[] = strtoupper($action);
    }

    $sql .= ' ORDER BY requested_at DESC, id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/* Danh dau ket qua + tinh do tre = TIMESTAMPDIFF(requested_at, responded_at) */
function mark_action(PDO $pdo, int $actionId, string $status, string $message, ?string $respondedAt = null): void
{
    $respondedAt = $respondedAt ?? now_db();

    $stmt = $pdo->prepare(
        'UPDATE actions
         SET status = ?, responded_at = ?, response_time_ms = ?, message = ?
         WHERE id = ? AND status = ?'
    );
    $stmt->execute([$status, $respondedAt, null, $message, $actionId, 'PENDING']);

    if ($stmt->rowCount() === 0) {
        return;
    }

    $upd = $pdo->prepare(
        'UPDATE actions
         SET response_time_ms = TIMESTAMPDIFF(MICROSECOND, requested_at, responded_at) DIV 1000
         WHERE id = ?'
    );
    $upd->execute([$actionId]);
}

/* Lenh PENDING qua lau khong co phan hoi -> TIMEOUT */
function sweep_action_timeouts(PDO $pdo, int $timeoutSeconds): int
{
    $cutoff = (new DateTimeImmutable('now'))
        ->modify("-{$timeoutSeconds} seconds")
        ->format('Y-m-d H:i:s.v');

    $stmt = $pdo->prepare(
        'UPDATE actions
         SET status = ?,
             responded_at = DATE_ADD(requested_at, INTERVAL ? SECOND),
             response_time_ms = ? * 1000,
             message = ?
         WHERE status = ? AND requested_at < ?'
    );
    $stmt->execute([
        'TIMEOUT',
        $timeoutSeconds,
        $timeoutSeconds,
        'Khong nhan duoc phan hoi tu ESP8266 trong ' . $timeoutSeconds . ' giay',
        'PENDING',
        $cutoff,
    ]);

    return $stmt->rowCount();
}
