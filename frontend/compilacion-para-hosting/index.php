<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
$index = __DIR__ . DIRECTORY_SEPARATOR . 'index.html';
if (! is_readable($index)) {
    http_response_code(500);
    echo 'No se encuentra index.html.';
    exit(1);
}
readfile($index);
