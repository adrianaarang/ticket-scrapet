<?php
declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

require __DIR__ . '/autoload.php';
require __DIR__ . '/config.php';

use TicketScraper\Http\HttpClient;
use TicketScraper\Models\Ticket;
use TicketScraper\Scrapers\ScraperRegistry;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$url = trim($_POST['url'] ?? '');

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL inválida o vacía.']);
    exit;
}

try {
    $http     = new HttpClient(defined('ZENROWS_API_KEY') ? ZENROWS_API_KEY : null);
    $registry = new ScraperRegistry($http);
    $scraper  = $registry->resolve($url);
    $tickets  = $scraper->scrape($url);

    usort($tickets, static fn(Ticket $a, Ticket $b) =>
        [$a->sector, $a->row, $a->price] <=> [$b->sector, $b->row, $b->price]
    );

    $grouped = [];
    foreach ($tickets as $t) {
        $grouped[$t->sector][$t->row][] = $t->toArray();
    }

    $prices = array_map(fn(Ticket $t) => $t->price, $tickets);
    $stats  = empty($prices) ? null : [
        'min' => min($prices),
        'max' => max($prices),
        'avg' => array_sum($prices) / count($prices),
    ];

    echo json_encode([
        'platform'  => $scraper->platformName(),
        'total'     => count($tickets),
        'grouped'   => $grouped,
        'stats'     => $stats,
        'has_proxy' => $http->hasProxy(),
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
