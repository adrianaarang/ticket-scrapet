<?php

declare(strict_types=1);

namespace TicketScraper\Scrapers;

use RuntimeException;
use TicketScraper\Models\Ticket;

/**
 * Scraper para SeatGeek.
 *
 * Estrategia:
 *   1. Playwright (navegador real) para sortear Cloudflare.
 *   2. API directa con event ID.
 *   3. JSON embebido en el HTML (SSR).
 */
class SeatGeekScraper extends AbstractScraper
{
    private const URL_PATTERN      = '#seatgeek\.com#i';
    private const EVENT_ID_PATTERN = '#/(\d+)\s*$#';
    private const CLIENT_ID        = 'MjM5MDU1MjZ8MTY5MDI5OTQyNy43NjU0NzU5';
    private const PAGE_SIZE        = 500;
    private const NODE_PATH        = 'C:\\Program Files\\nodejs\\node.exe';

    public function platformName(): string
    {
        return 'SeatGeek';
    }

    public function supports(string $url): bool
    {
        return (bool) preg_match(self::URL_PATTERN, $url);
    }

    public function scrape(string $url): array
{
    // Intento 1: Playwright directamente
    try {
        return $this->fetchWithPlaywright($url);
    } catch (\Throwable $e) {}

    // Intento 2: API con client_id
    $eventId = $this->extractEventId($url);
    if ($eventId !== null) {
        try {
            return $this->fetchFromApi($eventId);
        } catch (\RuntimeException) {}
    }

    throw new RuntimeException('No se pudieron obtener los listings de SeatGeek para: ' . $url);
}

    // -------------------------------------------------------------------------

    private function fetchWithPlaywright(string $url): array
    {
        $scriptPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scraper.js';

        if (!file_exists($scriptPath)) {
            throw new RuntimeException('scraper.js no encontrado.');
        }

        $cmd = '"' . self::NODE_PATH . '" ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($url);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, dirname($scriptPath));

        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo ejecutar node.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (empty($output)) {
            throw new RuntimeException('Playwright no devolvió respuesta.');
        }

        $lines    = array_filter(explode("\n", trim($output)), fn($l) => str_starts_with(trim($l), '{'));
        $jsonLine = end($lines);
        $json     = json_decode($jsonLine ?: $output, true);

        if (isset($json['error'])) {
            throw new RuntimeException($json['error']);
        }

        $items = $json['listings'] ?? [];
        if (empty($items)) {
            throw new RuntimeException('Playwright no encontró listings.');
        }

        return $this->mapApiListings($items);
    }

    private function extractEventId(string $url): ?string
    {
        $cleanUrl = preg_replace('#[?#].*$#', '', $url) ?? $url;
        if (preg_match(self::EVENT_ID_PATTERN, $cleanUrl, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractEventIdFromHtml(string $html): ?string
    {
        if (preg_match('#"event(?:_i|I)d"\s*:\s*(\d+)#', $html, $m)) {
            return $m[1];
        }
        if (preg_match('#og:url.*?content=["\'].*?/(\d+)["\']#si', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function fetchFromApi(string $eventId): array
    {
        $apiUrl = sprintf(
            'https://seatgeek.com/api/events/%s/listings?client_id=%s&per_page=%d',
            $eventId,
            self::CLIENT_ID,
            self::PAGE_SIZE
        );

        $data     = $this->http->getJson($apiUrl, ['Referer: https://seatgeek.com/', 'Accept: application/json']);
        $listings = $data['listings'] ?? [];

        if (empty($listings) && isset($data['meta']['total']) && $data['meta']['total'] > 0) {
            throw new RuntimeException('La API devolvió listings vacíos.');
        }

        return $this->mapApiListings((array) $listings);
    }

    private function parseEmbeddedJson(string $html): array
    {
        $patterns = [
            '#window\.__listingData\s*=\s*(\{.*?\});\s*</script>#s',
            '#"listings"\s*:\s*(\[.*?\])\s*[,}]#s',
            '#<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#s',
        ];

        foreach ($patterns as $pattern) {
            $data = $this->extractJsonFromScript($html, $pattern);
            if ($data === null) continue;

            $listings = $data['listings']
                ?? $data['props']['pageProps']['listings']
                ?? $data['props']['pageProps']['event']['listings']
                ?? null;

            if (is_array($listings) && !empty($listings)) {
                return $this->mapApiListings($listings);
            }
        }

        return [];
    }

    private function mapApiListings(array $listings): array
    {
        $tickets = [];

        foreach ($listings as $listing) {
            if (!is_array($listing)) continue;

            $sector   = (string) ($listing['section'] ?? $listing['sectionName'] ?? $listing['display_section'] ?? 'N/A');
            $row      = (string) ($listing['row']     ?? $listing['display_row']  ?? 'N/A');
            $rawPrice = $listing['price'] ?? $listing['retail_price'] ?? $listing['lowest_price'] ?? 0;
            $quantity = (int) ($listing['quantity'] ?? $listing['available_quantity'] ?? 1);
            $notes    = (string) ($listing['notes'] ?? '');
            $price    = is_string($rawPrice) ? $this->parsePrice($rawPrice) : (float) $rawPrice;

            if ($price <= 0) continue;

            $tickets[] = new Ticket(
                sector:   $this->normalizeSector($sector),
                row:      trim($row),
                price:    $price,
                currency: 'USD',
                quantity: $quantity,
                notes:    trim($notes),
            );
        }

        return $tickets;
    }
}