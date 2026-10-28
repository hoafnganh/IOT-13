<?php
/* GET /profile: thong tin nguoi dung hien tai + du an */

$user = require_login();

$stmt = $pdo->prepare('SELECT id, username, full_name, role, email, student_code, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

if ($row === false) {
    json_fail('Khong tim thay nguoi dung', 404);
}

json_ok([
    'user' => [
        'id'          => (int) $row['id'],
        'username'    => $row['username'],
        'fullName'    => $row['full_name'],
        'role'        => $row['role'],
        'email'       => $row['email'],
        'studentCode' => $row['student_code'],
        'createdAt'   => iso_datetime($row['created_at']),
    ],
    'project' => [
        'name'       => 'Hệ thống đo cảm biến và điều khiển LED bằng MQTT',
        'course'     => 'INT14149 - IoT và Ứng dụng',
        'instructor' => 'TS. Nguyễn Quốc Uy',
        'hardware'   => 'ESP8266 NodeMCU, DHT11, quang trở, 2 LED',
    ],
]);
