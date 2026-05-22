<?php

declare(strict_types=1);

namespace TicketScraper\Models;

/**
 * Representa una entrada disponible para un evento.
 */
class Ticket
{
    public function __construct(
        public readonly string $sector,
        public readonly string $row,
        public readonly float  $price,
        public readonly string $currency = 'USD',
        public readonly int    $quantity  = 1,
        public readonly string $notes     = '',
    ) {}

    /**
     * Devuelve el precio formateado con símbolo de moneda.
     */
    public function formattedPrice(): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol  = $symbols[$this->currency] ?? $this->currency . ' ';

        return $symbol . number_format($this->price, 2);
    }

    /**
     * Serializa el ticket a array asociativo.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sector'   => $this->sector,
            'row'      => $this->row,
            'price'    => $this->price,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'notes'    => $this->notes,
        ];
    }
}
