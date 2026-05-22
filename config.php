<?php
declare(strict_types=1);

$envFile = __DIR__ . '/.env';

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = explode('=', $line, 2) + [1 => ''];
        $_ENV[trim($key)] = trim($value);
    }
}

define('ZENROWS_API_KEY', $_ENV['ZENROWS_API_KEY'] ?? null);
