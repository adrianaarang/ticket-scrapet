<?php

declare(strict_types=1);

namespace TicketScraper\Scrapers;

use RuntimeException;
use TicketScraper\Http\HttpClient;

/**
 * Registro central de scrapers disponibles.
 *
 * Actúa como fábrica: dada una URL, devuelve el scraper
 * concreto que sabe procesarla.
 */
class ScraperRegistry
{
    /** @var ScraperInterface[] */
    private array $scrapers;

    public function __construct(HttpClient $http)
    {
        // Registro de scrapers soportados
        $this->scrapers = [
            new VividSeatsScraper($http),
            new SeatGeekScraper($http),
        ];
    }

    /**
     * Devuelve el scraper apropiado para la URL dada.
     *
     * @throws RuntimeException Si ningún scraper es compatible con la URL
     */
    public function resolve(string $url): ScraperInterface
    {
        foreach ($this->scrapers as $scraper) {
            if ($scraper->supports($url)) {
                return $scraper;
            }
        }

        throw new RuntimeException(
            "No hay ningún scraper disponible para la URL: {$url}\n"
            . "Plataformas soportadas: VividSeats, SeatGeek."
        );
    }

    /**
     * Lista los nombres de las plataformas soportadas.
     *
     * @return string[]
     */
    public function supportedPlatforms(): array
    {
        return array_map(fn($s) => $s->platformName(), $this->scrapers);
    }
}
