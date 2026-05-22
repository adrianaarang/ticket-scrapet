<?php

declare(strict_types=1);

namespace TicketScraper\Output;

use TicketScraper\Models\Ticket;

/**
 * Formateador de salida para consola.
 *
 * Agrupa los tickets por sector y, dentro de cada sector, por fila.
 * Imprime tablas alineadas con soporte para colores ANSI opcionales.
 */
class ConsoleFormatter
{
    /** Ancho total de la tabla de salida */
    private const TABLE_WIDTH = 72;

    /** Usar colores ANSI en la salida */
    private bool $useColors;

    public function __construct(bool $useColors = true)
    {
        // Deshabilita colores si la salida no es un terminal (ej: redirección a fichero)
        $this->useColors = $useColors && stream_isatty(STDOUT);
    }

    /**
     * Muestra por pantalla el listado completo de tickets agrupados.
     *
     * @param Ticket[] $tickets
     */
    public function render(string $platform, string $url, array $tickets): void
    {
        if (empty($tickets)) {
            $this->printLine($this->colorize('⚠  No se encontraron entradas disponibles.', 'yellow'));

            return;
        }

        $this->printHeader($platform, $url, count($tickets));

        $grouped = $this->groupBySectorAndRow($tickets);

        foreach ($grouped as $sector => $rows) {
            $this->printSectorHeader($sector);

            foreach ($rows as $row => $rowTickets) {
                $this->printRowTickets($row, $rowTickets);
            }
        }

        $this->printSummary($tickets);
    }

    // -------------------------------------------------------------------------
    // Métodos privados de presentación
    // -------------------------------------------------------------------------

    /**
     * Cabecera general del listado.
     */
    private function printHeader(string $platform, string $url, int $total): void
    {
        $separator = str_repeat('═', self::TABLE_WIDTH);

        $this->printLine($this->colorize($separator, 'cyan'));
        $this->printLine($this->colorize(
            ' ENTRADAS DISPONIBLES — ' . strtoupper($platform),
            'bold_cyan'
        ));
        $this->printLine($this->colorize(' URL: ' . $url, 'dark'));
        $this->printLine($this->colorize(
            ' Total de listings: ' . $total,
            'cyan'
        ));
        $this->printLine($this->colorize($separator, 'cyan'));
        $this->printLine('');
    }

    /**
     * Imprime la cabecera de un sector.
     */
    private function printSectorHeader(string $sector): void
    {
        $line = str_repeat('─', self::TABLE_WIDTH);
        $this->printLine($this->colorize($line, 'blue'));
        $this->printLine($this->colorize(
            sprintf('  📍 SECTOR: %s', strtoupper($sector)),
            'bold_blue'
        ));
        $this->printLine($this->colorize($line, 'blue'));

        // Cabecera de columnas
        $this->printLine(sprintf(
            '  %-12s %-10s %-10s %-8s  %s',
            'FILA',
            'PRECIO',
            'CANTIDAD',
            '',
            'NOTAS'
        ));
        $this->printLine('  ' . str_repeat('·', self::TABLE_WIDTH - 2));
    }

    /**
     * Imprime las entradas de una fila concreta.
     *
     * @param Ticket[] $tickets
     */
    private function printRowTickets(string $row, array $tickets): void
    {
        foreach ($tickets as $ticket) {
            $priceStr = $this->colorize(
                str_pad($ticket->formattedPrice(), 10),
                'green'
            );

            $line = sprintf(
                '  %-12s %s %-10s %-8s  %s',
                $row !== 'N/A' ? "Fila {$row}" : '(Sin fila)',
                $priceStr,
                $ticket->quantity . ' uds.',
                '',
                $ticket->notes ? substr($ticket->notes, 0, 28) : ''
            );

            $this->printLine($line);
        }
    }

    /**
     * Resumen estadístico al final del listado.
     *
     * @param Ticket[] $tickets
     */
    private function printSummary(array $tickets): void
    {
        $prices = array_map(fn(Ticket $t) => $t->price, $tickets);

        $min = min($prices);
        $max = max($prices);
        $avg = array_sum($prices) / count($prices);

        $separator = str_repeat('═', self::TABLE_WIDTH);

        $this->printLine('');
        $this->printLine($this->colorize($separator, 'cyan'));
        $this->printLine($this->colorize('  RESUMEN', 'bold_cyan'));
        $this->printLine(sprintf(
            '  Precio mínimo : %s',
            $this->colorize('$' . number_format($min, 2), 'green')
        ));
        $this->printLine(sprintf(
            '  Precio máximo : %s',
            $this->colorize('$' . number_format($max, 2), 'red')
        ));
        $this->printLine(sprintf(
            '  Precio medio  : %s',
            $this->colorize('$' . number_format($avg, 2), 'yellow')
        ));
        $this->printLine($this->colorize($separator, 'cyan'));
        $this->printLine('');
    }

    /**
     * Agrupa tickets por sector y, dentro de cada sector, por fila.
     * Los resultados se ordenan por sector alfabéticamente y por precio ascendente.
     *
     * @param  Ticket[]                       $tickets
     * @return array<string, array<string, Ticket[]>>
     */
    private function groupBySectorAndRow(array $tickets): array
    {
        // Ordena por sector, fila y precio
        usort($tickets, static function (Ticket $a, Ticket $b): int {
            $cmp = strnatcasecmp($a->sector, $b->sector);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strnatcasecmp($a->row, $b->row);

            return $cmp !== 0 ? $cmp : $a->price <=> $b->price;
        });

        $grouped = [];

        foreach ($tickets as $ticket) {
            $grouped[$ticket->sector][$ticket->row][] = $ticket;
        }

        return $grouped;
    }

    // -------------------------------------------------------------------------
    // Helpers ANSI
    // -------------------------------------------------------------------------

    private function printLine(string $line): void
    {
        echo $line . PHP_EOL;
    }

    /**
     * Aplica un color ANSI al texto si los colores están habilitados.
     */
    private function colorize(string $text, string $color): string
    {
        if (!$this->useColors) {
            return $text;
        }

        $codes = [
            'cyan'      => "\033[36m",
            'bold_cyan' => "\033[1;36m",
            'blue'      => "\033[34m",
            'bold_blue' => "\033[1;34m",
            'green'     => "\033[32m",
            'yellow'    => "\033[33m",
            'red'       => "\033[31m",
            'dark'      => "\033[90m",
            'bold'      => "\033[1m",
        ];

        $reset = "\033[0m";
        $code  = $codes[$color] ?? '';

        return $code . $text . $reset;
    }
}
