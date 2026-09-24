<?php

declare(strict_types=1);

// Router for `php -S` used by CurlTransportTest. Echoes what it received.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with((string) $path, '/sleep')) {
    sleep(3);
}

$status = (int) ($_GET['status'] ?? 202);
$headers = [];

foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_X_INNLOGGER_') || $name === 'CONTENT_TYPE') {
        $headers[$name] = $value;
    }
}

http_response_code($status);

if ($status === 429) {
    header('Retry-After: 7');
}
header('Content-Type: application/json');

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
]);
