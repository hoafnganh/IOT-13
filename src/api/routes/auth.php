<?php
/* POST /auth/login, POST /auth/logout, GET /auth/me */

if ($route === 'auth/login' && $method === 'POST') {
    iot_session_start();

    $body     = body_json();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        json_fail('Vui long nhap day du ten dang nhap va mat khau', 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user === false || !password_verify($password, $user['password_hash'])) {
        json_fail('Sai ten dang nhap hoac mat khau', 401);
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'          => (int) $user['id'],
        'username'    => $user['username'],
        'fullName'    => $user['full_name'],
        'role'        => $user['role'],
        'email'       => $user['email'],
        'studentCode' => $user['student_code'],
    ];

    json_ok(['user' => $_SESSION['user']], extra: ['message' => 'Đăng nhập thành công']);
}

if ($route === 'auth/logout' && $method === 'POST') {
    iot_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
    json_ok(['loggedOut' => true], extra: ['message' => 'Đăng xuất thành công']);
}

/* Doc LAI tu DB thay vi ban sao trong session: session cu tu cap nhat
   ten/mssv moi sau khi sua bang users, khong can dang nhap lai. */
if ($route === 'auth/me' && $method === 'GET') {
    iot_session_start();
    $user = current_user();
    if ($user === null) {
        json_fail('Chua dang nhap', 401);
    }

    $stmt = $pdo->prepare('SELECT id, username, full_name, role, email, student_code FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if ($row === false) {
        json_fail('Chua dang nhap', 401);
    }

    $_SESSION['user'] = [
        'id'          => (int) $row['id'],
        'username'    => $row['username'],
        'fullName'    => $row['full_name'],
        'role'        => $row['role'],
        'email'       => $row['email'],
        'studentCode' => $row['student_code'],
    ];

    json_ok(['user' => $_SESSION['user']]);
}
