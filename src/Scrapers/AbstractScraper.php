<?php

declare(strict_types=1);

namespace TicketScraper\Scrapers;

use DOMDocument;
use DOMXPath;
use TicketScraper\Http\HttpClient;

/**
 * Clase base con utilidades comunes para todos los scrapers.
 *
 * Proporciona helpers para parsear HTML con DOMDocument/DOMXPath
 * y para extraer bloques JSON incrustados en el HTML de la página.
 */
abstract class AbstractScraper implements ScraperInterface
{
    public function __construct(protected HttpClient $http) {}

    /**
     * Descarga el HTML de una URL y lo convierte en un DOMXPath navegable.
     *
     * @param  string   $url
     * @param  string[] $extraHeaders
     */
    protected function fetchDom(string $url, array $extraHeaders = []): DOMXPath
    {
        $html = $this->http->get($url, $extraHeaders);

        return $this->buildXPath($html);
    }

    /**
     * Construye un DOMXPath a partir de una cadena HTML.
     */
    protected function buildXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();

        // Suprime errores de HTML mal formado (muy común en sitios reales)
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /**
     * Busca y decodifica el primer bloque JSON que coincida con el patrón dado.
     *
     * @param  string $html    Fuente HTML completa
     * @param  string $pattern Expresión regular con un grupo de captura para el JSON
     * @return array<mixed>|null
     */
    protected function extractJsonFromScript(string $html, string $pattern): ?array
    {
        if (!preg_match($pattern, $html, $matches)) {
            return null;
        }

        $raw  = $matches[1] ?? '';
        $data = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? (array) $data : null;
    }

    /**
     * Limpia y convierte a float un string de precio con símbolos y comas.
     * Ej: "$1,234.50" → 1234.50
     */
    protected function parsePrice(string $raw): float
    {
        $clean = preg_replace('/[^0-9.]/', '', $raw);

        return (float) $clean;
    }

    /**
     * Normaliza el nombre de un sector eliminando espacios extra.
     */
    protected function normalizeSector(string $raw): string
    {
        return trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    }
}
