<?php

declare(strict_types=1);

namespace TicketScraper\Http;

use RuntimeException;

/**
 * Cliente HTTP basado en cURL.
 *
 * Cuando hay API key de ZenRows, enruta las peticiones por su proxy
 * con js_render y premium_proxy activados para sortear Akamai/Cloudflare.
 */
class HttpClient
{
    private const CONNECT_TIMEOUT  = 20;
    private const REQUEST_TIMEOUT  = 90; // JS render puede tardar
    private const ZENROWS_ENDPOINT = 'https://api.zenrows.com/v1/';
    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/124.0.0.0 Safari/537.36';

    private string  $cookieJar;
    private ?string $zenRowsKey;

    public function __construct(?string $zenRowsKey = null)
    {
        $this->zenRowsKey = $zenRowsKey ?: null;
        $this->cookieJar  = tempnam(sys_get_temp_dir(), 'ticket_cookies_');
    }

    public function __destruct()
    {
        if (file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    /**
     * GET — con ZenRows usa js_render + premium_proxy para páginas protegidas.
     *
     * @param  string               $url
     * @param  array<string,string> $headers
     * @throws RuntimeException
     */
    public function get(string $url, array $headers = []): string
    {
        $targetUrl = $this->zenRowsKey
            ? $this->buildZenRowsUrl($url)
            : $url;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $targetUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'DNT: 1',
                'Connection: keep-alive',
            ], array_values($headers)),
            CURLOPT_ENCODING       => '',
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("cURL error: {$error}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("HTTP {$httpCode} para {$url}");
        }

        // ZenRows con json_response devuelve {"html":"..."}
        $decoded = json_decode((string) $body, true);
        if (isset($decoded['html'])) {
            return $decoded['html'];
        }

        return (string) $body;
    }

    /**
     * GET que decodifica la respuesta como JSON.
     *
     * @return array<mixed>
     * @throws RuntimeException
     */
    public function getJson(string $url, array $headers = []): array
    {
        // Las llamadas a APIs internas no pasan por ZenRows
        $targetUrl = $url;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $targetUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: application/json, text/plain, */*',
                'X-Requested-With: XMLHttpRequest',
            ], array_values($headers)),
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("cURL error: {$error}");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("HTTP {$httpCode}");
        }

        $data = json_decode((string) $body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JSON decode error: ' . json_last_error_msg());
        }

        return (array) $data;
    }

    public function hasProxy(): bool
    {
        return $this->zenRowsKey !== null;
    }

    // -------------------------------------------------------------------------

    private function buildZenRowsUrl(string $originalUrl): string
    {
        $instructions = json_encode([
            ['wait' => 2000],
            ['click' => "button[id*='accept'], #onetrust-accept-btn-handler, button[class*='accept']"],
            ['wait' => 3000],
        ]);

        return self::ZENROWS_ENDPOINT . '?' . http_build_query([
            'apikey'          => $this->zenRowsKey,
            'url'             => $originalUrl,
            'js_render'       => 'true',
            'premium_proxy'   => 'true',
            'json_response'   => 'true',
            'js_instructions' => $instructions,
            'wait'            => '5000',
        ]);
    }
}
