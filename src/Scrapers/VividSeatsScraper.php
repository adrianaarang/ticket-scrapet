<?php

declare(strict_types=1);

namespace TicketScraper\Scrapers;

use RuntimeException;
use TicketScraper\Models\Ticket;

class VividSeatsScraper extends AbstractScraper
{
    private const URL_PATTERN           = '#vividseats\.com#i';
    private const PRODUCTION_ID_PATTERN = '#/production/(\d+)#';
    private const NEXT_DATA_PATTERN     = '#<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#s';

    public function platformName(): string { return 'VividSeats'; }

    public function supports(string $url): bool
    {
        return (bool) preg_match(self::URL_PATTERN, $url);
    }

    public function scrape(string $url): array
    {
        $html = $this->http->get($url);
        $data = $this->extractJsonFromScript($html, self::NEXT_DATA_PATTERN);
        $pp   = $data['props']['pageProps'] ?? [];

        $listings = $pp['initialListingsData']['listings'] ?? [];
        if (!empty($listings)) {
            return $this->mapListings($listings);
        }

        try {
            return $this->fetchWithPlaywright($url);
        } catch (\Throwable) {}

        $topDeals = $pp['initialTopDealListingsData']['data']['topDeals'] ?? [];
        if (!empty($topDeals)) {
            return $this->mapTopDeals($topDeals);
        }

        $minPrice = $pp['initialProductionDetailsData']['data']['minPrice'] ?? null;
        throw new RuntimeException($minPrice !== null
            ? "VividSeats tiene entradas desde \${$minPrice} pero no fue posible cargar el listado completo."
            : 'No se encontraron listings en la pagina de VividSeats.'
        );
    }

private function fetchWithPlaywright(string $url): array
{
    $scriptPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scraper.js';

    if (!file_exists($scriptPath)) {
        throw new RuntimeException('scraper.js no encontrado.');
    }

    $cmd = '"C:\\Program Files\\nodejs\\node.exe" ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($url);

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

    $source = $json['source'] ?? 'tickets';
    return $source === 'topDeals'
        ? $this->mapTopDeals($items)
        : $this->mapTickets($items);
}
    private function mapTickets(array $tickets): array
    {
        $result = [];

        foreach ($tickets as $t) {
            if (!is_array($t)) continue;

            $sector   = (string) ($t['sectionName'] ?? $t['s'] ?? $t['l'] ?? 'N/A');
            $row      = (string) ($t['row']         ?? $t['r'] ?? 'N/A');
            $rawPrice = $t['allInPricePerTicket']   ?? $t['p'] ?? 0;
            $quantity = (int)   ($t['quantity']     ?? $t['q'] ?? 1);
            $notes    = (string) ($t['n']           ?? '');

            if ($row === '--') $row = 'N/A';

            $price = is_string($rawPrice) ? $this->parsePrice($rawPrice) : (float) $rawPrice;
            if ($price <= 0) continue;

            $result[] = new Ticket(
                sector:   $this->normalizeSector($sector),
                row:      trim($row),
                price:    $price,
                currency: 'USD',
                quantity: $quantity,
                notes:    trim($notes),
            );
        }

        return $result;
    }

    private function mapListings(array $listings): array
    {
        $tickets = [];

        foreach ($listings as $l) {
            if (!is_array($l)) continue;

            $sector   = (string) ($l['section'] ?? $l['sectionName'] ?? 'N/A');
            $row      = (string) ($l['row']     ?? 'N/A');
            $rawPrice = $l['currentPrice'] ?? $l['price'] ?? $l['pricePerTicket'] ?? 0;
            $quantity = (int) ($l['quantity'] ?? 1);
            $notes    = (string) ($l['notes'] ?? '');
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

    private function mapTopDeals(array $topDeals): array
    {
        $tickets = [];

        foreach ($topDeals as $deal) {
            if (!is_array($deal)) continue;

            $price = (float) ($deal['price'] ?? 0);
            if ($price <= 0) continue;

            $tickets[] = new Ticket(
                sector:   $this->normalizeSector((string) ($deal['section'] ?? 'N/A')),
                row:      trim((string) ($deal['row'] ?? 'N/A')),
                price:    $price,
                currency: 'USD',
                quantity: 1,
                notes:    'Top Deal',
            );
        }

        return $tickets;
    }
}