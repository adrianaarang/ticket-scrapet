<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 minimalista para el namespace TicketScraper.
 *
 * Mapea  TicketScraper\Foo\Bar  →  src/Foo/Bar.php
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'TicketScraper\\';
    $base   = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
