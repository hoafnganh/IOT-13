<?php
/* GET /dashboard: gia tri moi nhat CUA MOI cam bien + trang thai node + devices.
   GET /dashboard/chart: diem bieu do trong X phut gan nhat.
   Duyet GENERIC theo bang sensors: them cam bien moi chi can INSERT 1 dong
   vao sensors (khong phai sua file nay). */

if ($route === 'dashboard' && $method === 'GET') {
    require_login();

    // Moi sensor lay dung 1 ban ghi moi nhat (subquery khop sensor_id)
    $latestStmt = $pdo->query(
        'SELECT s.sensor_code, s.name, s.type, s.unit, d.value, d.recorded_at
         FROM sensors s
         LEFT JOIN datasensor d
                ON d.id = (SELECT dd.id FROM datasensor dd
                            WHERE dd.sensor_id = s.id
                            ORDER BY dd.recorded_at DESC, dd.id DESC LIMIT 1)'
    );

    $sensors = [];
    foreach ($latestStmt->fetchAll() as $row) {
        $key = explode(':', $row['sensor_code'])[1]; // "esp01:temperature" -> "temperature"
        $sensors[$key] = [
            'sensorCode' => $row['sensor_code'],
            'name'       => $row['name'],
            'type'       => $row['type'],
            'unit'       => $row['unit'],
            'value'      => $row['value'] === null ? null : (float) $row['value'],
            'recordedAt' => iso_datetime($row['recorded_at']),
        ];
    }

    $nodeStmt = $pdo->query("SELECT * FROM devices WHERE device_code = 'esp01' LIMIT 1");
    $nodeRow  = $nodeStmt->fetch();

    $devices = [];
    foreach ($pdo->query('SELECT * FROM devices ORDER BY device_code ASC')->fetchAll() as $row) {
        $devices[] = format_device($row);
    }

    json_ok([
        'sensors' => $sensors,
        'node'    => $nodeRow === false ? null : [
            'deviceCode'       => $nodeRow['device_code'],
            'name'             => $nodeRow['name'],
            'connectionStatus' => $nodeRow['connection_status'],
            'lastSeen'         => iso_datetime($nodeRow['last_seen']),
        ],
        'devices' => $devices,
    ]);
}

/* ------------------------------ Bieu do ------------------------------
   Gioi han 5..240 phut va 20..300 diem, lay mau deu tu cuoi mang. */

$minutes = (int) ($_GET['minutes'] ?? 30);
$minutes = min(240, max(5, $minutes));
$points  = (int) ($_GET['points'] ?? 90);
$points  = min(300, max(20, $points));

$from = (new DateTimeImmutable('now'))->modify("-{$minutes} minutes")->format('Y-m-d H:i:s.v');

$stmt = $pdo->prepare(
    'SELECT s.sensor_code, d.value, d.recorded_at
     FROM datasensor d
     JOIN sensors s ON s.id = d.sensor_id
     WHERE d.recorded_at >= ?
     ORDER BY d.recorded_at ASC'
);
$stmt->execute([$from]);

$raw = [];
foreach ($stmt->fetchAll() as $row) {
    $raw[$row['sensor_code']][] = [
        't' => iso_datetime($row['recorded_at']),
        'v' => (float) $row['value'],
    ];
}

// Lay mau deu tung chuoi, danh sach chuoi = danh sach sensor trong bang sensors
$series = [];
foreach ($pdo->query('SELECT sensor_code FROM sensors')->fetchAll(PDO::FETCH_COLUMN) as $code) {
    $items   = $raw[$code] ?? [];
    $count   = count($items);
    $stride  = $count > $points ? (int) ceil($count / $points) : 1;
    $sampled = [];
    for ($i = $count - 1; $i >= 0; $i -= $stride) {
        $sampled[] = $items[$i];
    }
    $series[explode(':', $code)[1]] = array_reverse($sampled);
}

json_ok(['minutes' => $minutes, 'series' => $series]);
