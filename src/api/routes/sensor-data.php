<?php
/* GET /sensor-data: tra cuu phan trang + loc theo sensor/value/search/time/from/to */

require_login();

[$page, $limit] = pagination_params();

$sensorParam = trim((string) ($_GET['sensor'] ?? ($_GET['type'] ?? '')));
$valueParam  = trim((string) ($_GET['value'] ?? ''));
$searchParam = trim((string) ($_GET['search'] ?? ''));
$timeParam   = trim((string) ($_GET['time'] ?? ''));
$fromParam   = trim((string) ($_GET['from'] ?? ''));
$toParam     = trim((string) ($_GET['to'] ?? ''));

$where  = [];
$params = [];

if ($sensorParam !== '') {
    $map = ['temperature' => 'TEMPERATURE', 'humidity' => 'HUMIDITY', 'light' => 'LIGHT'];
    $where[]  = 's.type = ?';
    $params[] = $map[strtolower($sensorParam)] ?? strtoupper($sensorParam);
}

// Gia tri kieu so/toan tu: 15 | =15 | >15 | <20 | >=15.5
if ($valueParam !== '') {
    if (!preg_match('/^(>=|<=|>|<|=)?\s*(-?\d+(?:\.\d+)?)$/', $valueParam, $m)) {
        json_fail('Gia tri tim kiem khong hop le (VD: 15, >15, <20, >=15.5)', 422);
    }
    $op = ($m[1] === '' || $m[1] === null) ? '=' : $m[1];
    $where[]  = "d.value {$op} ?";
    $params[] = (float) $m[2];
}

if ($searchParam !== '') {
    if (preg_match('/^(>=|<=|>|<|=)?\s*(-?\d+(?:\.\d+)?)$/', $searchParam, $m)) {
        // So/toan tu -> loc theo gia tri
        $op = ($m[1] === '' || $m[1] === null) ? '=' : $m[1];
        $where[]  = "d.value {$op} ?";
        $params[] = (float) $m[2];
    } else {
        // Chuoi -> LIKE tren moi cot. Khong dung CAST (gay loi mix collations);
        // CONCAT_WS tu chuyen so thanh chuoi.
        $where[]  = "(CONCAT_WS('|', d.id, CONCAT('#', LPAD(d.id, 4, '0')),"
            . " s.sensor_code, s.name, s.type, s.unit, d.value) LIKE ?)";
        $params[] = '%' . $searchParam . '%';
    }
}

// Loai "Thời gian": tim chuoi con trong cot thoi gian (giong dinh dang bang)
if ($timeParam !== '') {
    $where[]  = "DATE_FORMAT(d.recorded_at, '%Y-%m-%d %H:%i:%s') LIKE ?";
    $params[] = '%' . $timeParam . '%';
}

if ($fromParam !== '') {
    $from = parse_datetime_param($fromParam);
    if ($from === null) {
        json_fail('Thoi gian bat dau khong hop le (dang dung: YYYY-MM-DD HH:mm:ss)', 422);
    }
    $where[]  = 'd.recorded_at >= ?';
    $params[] = $from;
}

if ($toParam !== '') {
    $to = parse_datetime_param($toParam);
    if ($to === null) {
        json_fail('Thoi gian ket thuc khong hop le (dang dung: YYYY-MM-DD HH:mm:ss)', 422);
    }
    $where[]  = 'd.recorded_at <= ?';
    $params[] = $to;
}

$whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total FROM datasensor d JOIN sensors s ON s.id = d.sensor_id {$whereSql}"
);
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetch()['total'];

$offset = ($page - 1) * $limit;
$stmt = $pdo->prepare(
    "SELECT d.id, s.sensor_code, s.name, s.type, d.value, s.unit, d.recorded_at
     FROM datasensor d
     JOIN sensors s ON s.id = d.sensor_id
     {$whereSql}
     ORDER BY d.recorded_at DESC, d.id DESC
     LIMIT " . (int) $limit . " OFFSET " . (int) $offset
);
$stmt->execute($params);

$rows = [];
foreach ($stmt->fetchAll() as $row) {
    $rows[] = [
        'id'         => (int) $row['id'],
        'sensorId'   => $row['sensor_code'],
        'sensorName' => $row['name'],
        'type'       => $row['type'],
        'value'      => (float) $row['value'],
        'unit'       => $row['unit'],
        'recordedAt' => iso_datetime($row['recorded_at']),
    ];
}

json_ok($rows, extra: [
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'totalItems' => $totalItems,
        'totalPages' => (int) max(1, ceil($totalItems / $limit)),
    ],
]);
