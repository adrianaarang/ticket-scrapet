<?php

declare(strict_types=1);

namespace TicketScraper\Scrapers;

use TicketScraper\Models\Ticket;

/**
 * Contrato que deben implementar todos los scrapers de plataformas de tickets.
 */
interface ScraperInterface
{
    /**
     * Indica si este scraper es capaz de procesar la URL dada.
     */
    public function supports(string $url): bool;

    /**
     * Extrae y devuelve la lista de entradas disponibles para la URL del evento.
     *
     * @return Ticket[]
     */
    public function scrape(string $url): array;

    /**
     * Nombre legible de la plataforma que gestiona este scraper.
     */
    public function platformName(): string;
}
