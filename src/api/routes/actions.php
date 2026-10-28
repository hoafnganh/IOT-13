<?php
/* GET /actions: lich su thao tac phan trang + loc theo search/device/action/status/time/from/to
   GET /actions/{id}: chi tiet mot lenh */

if ($route === 'actions' && $method === 'GET') {
    require_login();

    [$page, $limit] = pagination_params();

    $deviceParam = trim((string) ($_GET['device'] ?? ''));
    $actionParam = strtoupper(trim((string) ($_GET['action'] ?? '')));
    $statusParam = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $searchParam = trim((string) ($_GET['search'] ?? ''));
    $timeParam   = trim((string) ($_GET['time'] ?? ''));
    $fromParam   = trim((string) ($_GET['from'] ?? ''));
    $toParam     = trim((string) ($_GET['to'] ?? ''));

    $where  = [];
    $params = [];

    if ($searchParam !== '') {
        // Tim chuoi tren moi cot (request ID, #ID, ten thiet bi, action, status, do tre, message)
        $where[]  = "(CONCAT_WS('|', a.id, CONCAT('#', LPAD(a.id, 4, '0')),"
            . " a.request_id, d.device_code, d.name, a.action, a.status,"
            . " a.response_time_ms, a.message) LIKE ?)";
        $params[] = '%' . $searchParam . '%';
    }

    if ($deviceParam !== '') {
        if (ctype_digit($deviceParam)) {
            $where[]  = 'a.device_id = ?';
            $params[] = (int) $deviceParam;
        } else {
            $like = '%' . $deviceParam . '%';
            $where[]  = '(d.device_code LIKE ? OR d.name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
    }

    if ($actionParam !== '') {
        $where[]  = 'a.action LIKE ?';
        $params[] = '%' . $actionParam . '%';
    }

    if ($statusParam !== '') {
        $where[]  = 'a.status LIKE ?';
        $params[] = '%' . $statusParam . '%';
    }

    // Loai "Thời gian": tim chuoi con tren thoi gian gui hoac thoi gian phan hoi
    if ($timeParam !== '') {
        $like = '%' . $timeParam . '%';
        $where[]  = "(DATE_FORMAT(a.requested_at, '%Y-%m-%d %H:%i:%s') LIKE ?"
            . " OR DATE_FORMAT(a.responded_at, '%Y-%m-%d %H:%i:%s') LIKE ?)";
        $params[] = $like;
        $params[] = $like;
    }

    if ($fromParam !== '') {
        $from = parse_datetime_param($fromParam);
        if ($from === null) {
            json_fail('Thoi gian bat dau khong hop le', 422);
        }
        $where[]  = 'a.requested_at >= ?';
        $params[] = $from;
    }

    if ($toParam !== '') {
        $to = parse_datetime_param($toParam);
        if ($to === null) {
            json_fail('Thoi gian ket thuc khong hop le', 422);
        }
        $where[]  = 'a.requested_at <= ?';
        $params[] = $to;
    }

    $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM actions a JOIN devices d ON d.id = a.device_id {$whereSql}"
    );
    // COUNT khong can JOIN users (chi dem dong)
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetch()['total'];

    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare(
        "SELECT a.*, d.device_code, d.name AS device_name, u.username AS user_name
         FROM actions a
         JOIN devices d ON d.id = a.device_id
         JOIN users u ON u.id = a.user_id
         {$whereSql}
         ORDER BY a.requested_at DESC, a.id DESC
         LIMIT " . (int) $limit . " OFFSET " . (int) $offset
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = format_action($row);
    }

    json_ok($rows, extra: [
        'pagination' => [
            'page'       => $page,
            'limit'      => $limit,
            'totalItems' => $totalItems,
            'totalPages' => (int) max(1, ceil($totalItems / $limit)),
        ],
    ]);
}

/* ---- Chi tiet mot lenh ---- */
require_login();

if (!ctype_digit($segments[1])) {
    json_fail('Action id khong hop le', 422);
}

$stmt = $pdo->prepare(
    'SELECT a.*, d.device_code, d.name AS device_name, u.username AS user_name
     FROM actions a
     JOIN devices d ON d.id = a.device_id
     JOIN users u ON u.id = a.user_id
     WHERE a.id = ? LIMIT 1'
);
$stmt->execute([(int) $segments[1]]);
$row = $stmt->fetch();

if ($row === false) {
    json_fail('Khong tim thay action', 404);
}

json_ok(format_action($row));
