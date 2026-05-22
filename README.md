# Ticket Scraper

Herramienta web en PHP para consultar entradas disponibles en eventos de VividSeats y SeatGeek, clasificadas por sector, fila y precio.

## Requisitos

| Herramienta | Versión mínima |
| :--- | :--- |
| **PHP** | 8.1 |
| **Node.js** | 18.x |
| **Extensiones PHP** | `curl`, `dom`, `json` |

```bash
php --version
node --version
```

## Instalación

```bash
# 1. Clona o descomprime el proyecto
cd ticket-scraper

# 2. Instala las dependencias de Node (Playwright)
npm install
npx playwright install chromium

# 3. Copia el fichero de configuración
cp env.example .env
```

## Configuración

Edita el fichero `.env`:

```env
# API key de ZenRows (proxy anti-bot) — opcional pero recomendado
# Plan gratuito en https://www.zenrows.com (1.000 créditos/mes)
ZENROWS_API_KEY=tu_key_aqui

# Credenciales de la API de SeatGeek — opcional
# Regístrate gratis en https://seatgeek.com/account/develop
SEATGEEK_CLIENT_ID=tu_client_id
SEATGEEK_CLIENT_SECRET=tu_secret
```

## Uso

Arranca el servidor PHP:

```bash
php -S localhost:8000
```

O con Apache/XAMPP accede directamente a:

```http
http://localhost/ticket-scraper/index.php
```

Pega la URL de un evento de VividSeats o SeatGeek en el campo de búsqueda y pulsa **Buscar entradas**.

### URLs de ejemplo

- `https://www.vividseats.com/hamilton-tickets-new-york-richard-rodgers-theatre-new-york-6-23-2026/production/6204797`
- `https://seatgeek.com/aladdin-tickets/theater/2026-07-15-2-pm/18119434`

---

## Estructura del proyecto

```text
ticket-scraper/
├── index.php                    ← Punto de entrada web (sirve el HTML)
├── api.php                      ← Endpoint AJAX — recibe URL, devuelve JSON
├── scraper.js                   ← Script Node.js con Playwright (renderizado real)
├── scraper.php                  ← Punto de entrada CLI alternativo
├── autoload.php                 ← Autoloader PSR-4 sin Composer
├── config.php                   ← Lee configuración del .env
├── .env                         ← Variables de entorno (no subir a git)
├── .env.example                 ← Plantilla de configuración
├── .gitignore                   ← Excluye .env y node_modules
├── package.json                 ← Dependencias Node (Playwright)
├── views/
│   ├── index.html               ← Estructura HTML de la interfaz
│   ├── app.css                  ← Estilos (diseño oscuro, responsive)
│   └── app.js                   ← Lógica frontend (fetch, render, filtros)
└── src/
    ├── Http/
    │   └── HttpClient.php       ← Cliente cURL con soporte ZenRows
    ├── Models/
    │   └── Ticket.php           ← Modelo de dato: sector, fila, precio
    ├── Output/
    │   └── ConsoleFormatter.php ← Formateador de tabla para CLI
    └── Scrapers/
        ├── ScraperInterface.php ← Contrato común para todos los scrapers
        ├── AbstractScraper.php  ← Utilidades compartidas (DOM, regex, precios)
        ├── ScraperRegistry.php  ← Fábrica: detecta plataforma por URL
        ├── VividSeatsScraper.php
        └── SeatGeekScraper.php
```

---

## Arquitectura

### Flujo de una petición

```text
Usuario introduce URL
        ↓
    api.php (POST AJAX)
        ↓
ScraperRegistry::resolve($url)
        ↓
    VividSeatsScraper / SeatGeekScraper
        ↓
    [ estrategia de scraping ]
        ↓
    array de Ticket[]
        ↓
    JSON agrupado por sector → fila → precio
        ↓
    app.js renderiza la tabla
```

### Estrategia de scraping por plataforma

#### VividSeats
- Descarga el HTML via ZenRows con `js_render=true` y `premium_proxy=true` para sortear la protección Akamai.
- Extrae el bloque `__NEXT_DATA__` inyectado por Next.js — contiene `initialListingsData` con los listings completos.
- Si `initialListingsData` está vacío, lanza `scraper.js` con Playwright que abre un Chrome real, acepta las cookies automáticamente e intercepta la llamada XHR a `/hermes/api/v1/listings`.
- Como fallback usa `initialTopDealListingsData` del SSR (top 5 mejores ofertas).

#### SeatGeek
- Intenta Playwright para interceptar la llamada a `/api/event_listings_v2`.
- Si falla, llama a la API oficial REST con el `client_id` registrado.
- Como fallback parsea el JSON embebido en el HTML (SSR).

### Por qué Playwright
VividSeats y SeatGeek cargan los listings de forma dinámica mediante XHR después del render inicial. Playwright lanza un Chromium real que ejecuta el JavaScript de la página, lo que permite interceptar las peticiones de red internas y sortear protecciones anti-bot.

### Por qué ZenRows
VividSeats usa Akamai Bot Manager que bloquea IPs de servidores. ZenRows enruta las peticiones a través de proxies residenciales evitando el bloqueo 403.

---

## Estado de las integraciones

| Plataforma | Estado | Notas |
| :--- | :--- | :--- |
| **VividSeats** | ✅ Funcional | Playwright intercepta `/hermes/api/v1/listings`. Devuelve sector, fila, precio y cantidad. |
| **SeatGeek** | ⚠️ Parcial | DataDome bloquea Playwright. La API oficial requiere credenciales de vendedor para listings. |

---

## Limitaciones conocidas

- **VividSeats — Akamai Bot Manager:** Los servidores de producción son bloqueados con 403. La solución es ZenRows con `premium_proxy=true`. Sin ZenRows, funciona correctamente desde IPs domésticas.
- **SeatGeek — DataDome:** Detecta Playwright aunque se eliminen las firmas de webdriver. El endpoint `/api/event_listings_v2` devuelve 403. La API REST oficial requiere credenciales de vendedor no disponibles en el plan gratuito. Para producción se necesitaría ScrapingBee o 2Captcha.

---

## Añadir nuevas plataformas

1. Crea `src/Scrapers/MiPlataformaScraper.php` extendiendo `AbstractScraper`.
2. Implementa `supports()`, `scrape()` y `platformName()`.
3. Registra en `ScraperRegistry::__construct()`:

```php
$this->scrapers = [
    new VividSeatsScraper($http),
    new SeatGeekScraper($http),
    new MiPlataformaScraper($http),
];
