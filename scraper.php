#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ticket-scraper — Punto de entrada CLI
 *
 * Uso:
 *   php scraper.php <URL_DEL_EVENTO>
 *
 * Ejemplos:
 *   php scraper.php "https://www.vividseats.com/hamilton-tickets.../production/6204797"
 *   php scraper.php "https://seatgeek.com/aladdin-tickets/.../18119434"
 *
 * Opciones:
 *   --no-color   Desactiva los colores ANSI en la salida
 *   --json       Emite la salida en formato JSON (útil para integraciones)
 *   --help       Muestra esta ayuda
 */

require __DIR__ . '/autoload.php';

use TicketScraper\Http\HttpClient;
use TicketScraper\Output\ConsoleFormatter;
use TicketScraper\Scrapers\ScraperRegistry;

// ---------------------------------------------------------------------------
// Parseo de argumentos
// ---------------------------------------------------------------------------

$args     = array_slice($argv, 1);
$url      = null;
$noColor  = false;
$jsonMode = false;

foreach ($args as $arg) {
    match (true) {
        $arg === '--no-color' => ($noColor  = true),
        $arg === '--json'     => ($jsonMode = true),
        $arg === '--help'     => printHelp(),
        default               => ($url = $arg),
    };
}

if ($url === null) {
    printHelp();
    exit(1);
}

// ---------------------------------------------------------------------------
// Bootstrap de dependencias
// ---------------------------------------------------------------------------

$http      = new HttpClient();
$registry  = new ScraperRegistry($http);
$formatter = new ConsoleFormatter(!$noColor && !$jsonMode);

// ---------------------------------------------------------------------------
// Ejecución principal
// ---------------------------------------------------------------------------

try {
    $scraper  = $registry->resolve($url);
    $platform = $scraper->platformName();

    if (!$jsonMode) {
        echo PHP_EOL;
        echo "🔍 Plataforma detectada: {$platform}" . PHP_EOL;
        echo "⏳ Obteniendo entradas disponibles..." . PHP_EOL . PHP_EOL;
    }

    $tickets = $scraper->scrape($url);

    if ($jsonMode) {
        // Modo JSON: emite array plano de tickets
        echo json_encode(
            array_map(fn($t) => $t->toArray(), $tickets),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        echo PHP_EOL;
    } else {
        $formatter->render($platform, $url, $tickets);
    }

    exit(0);
} catch (\RuntimeException $e) {
    $msg = "❌ Error: " . $e->getMessage();

    if ($jsonMode) {
        echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
    } else {
        fwrite(STDERR, $msg . PHP_EOL);
    }

    exit(1);
} catch (\Throwable $e) {
    $msg = "❌ Error inesperado: " . $e->getMessage()
        . " en " . $e->getFile() . ":" . $e->getLine();

    fwrite(STDERR, $msg . PHP_EOL);

    exit(2);
}

// ---------------------------------------------------------------------------
// Funciones auxiliares
// ---------------------------------------------------------------------------

/**
 * Imprime la ayuda y termina el script.
 */
function printHelp(): never
{
    $platforms = implode(', ', (new ScraperRegistry(new HttpClient()))->supportedPlatforms());

    echo <<<HELP
    
    ticket-scraper — Consulta entradas disponibles en plataformas de venta de tickets

    USO
      php scraper.php [opciones] <URL_DEL_EVENTO>

    OPCIONES
      --no-color   Desactiva los colores ANSI
      --json       Emite la salida en formato JSON
      --help       Muestra esta ayuda

    PLATAFORMAS SOPORTADAS
      {$platforms}

    EJEMPLOS
      php scraper.php "https://www.vividseats.com/.../production/6204797"
      php scraper.php "https://seatgeek.com/aladdin-tickets/.../18119434"
      php scraper.php --json "https://seatgeek.com/..." | jq '.[] | select(.price < 100)'

    HELP;

    exit(0);
}
