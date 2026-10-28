<?php
/* Ham tra JSON cho API: json_ok (thanh cong), json_fail (loi) - deu exit
   sau khi tra header HTTP + body; body_json doc body request JSON. */

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $data, array $extra = []): never
{
    json_out(array_merge(['success' => true, 'data' => $data], $extra));
}

function json_fail(string $message, int $status = 400): never
{
    json_out(['success' => false, 'message' => $message], $status);
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}
