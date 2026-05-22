# Ticket Scraper


<img width="1527" height="805" alt="Captura de pantalla 2026-05-22 160417" src="https://github.com/user-attachments/assets/1753e631-0602-4541-b05e-c3f498d8231e" />
<img width="1409" height="900" alt="Captura de pantalla 2026-05-22 160541" src="https://github.com/user-attachments/assets/dac9fcfd-d653-4e9c-8992-ed645a1693e4" />


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

```
http://localhost/ticket-scraper/index.php
```

Pega la URL de un evento de VividSeats o SeatGeek en el campo de búsqueda y pulsa **Buscar entradas**.

### URLs de ejemplo

- `https://www.vividseats.com/hamilton-tickets-new-york-richard-rodgers-theatre-new-york-6-23-2026/production/6204797`
- `https://seatgeek.com/aladdin-tickets/theater/2026-07-15-2-pm/18119434`

### Uso desde CLI

```bash
# VividSeats
php scraper.php "https://www.vividseats.com/.../production/6204797"

# SeatGeek
php scraper.php "https://seatgeek.com/aladdin-tickets/.../18119434"

# Salida JSON (filtrable con jq)
php scraper.php --json "https://..." | jq '.[] | select(.price < 100)'

# Sin colores (para redirigir a fichero)
php scraper.php --no-color "https://..." > entradas.txt
```

---

## Estructura del proyecto

```
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

```
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
- **SeatGeek — DataDome:** Detecta Playwright aunque se eliminen las firmas de webdriver. Además muestra un slider de verificación en el primer acceso que requiere interacción humana real. El endpoint `/api/event_listings_v2` devuelve 403. La API REST oficial requiere credenciales de vendedor no disponibles en el plan gratuito. Para producción se necesitaría ScrapingBee o 2Captcha.

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
```

---

## Seguridad

- Credenciales almacenadas en `.env`, excluido de git mediante `.gitignore`.
- `api.php` solo acepta peticiones con cabecera `X-Requested-With: XMLHttpRequest`.
- URLs validadas con `filter_var($url, FILTER_VALIDATE_URL)` antes de procesarse.
- Salidas HTML escapadas con `htmlspecialchars` para prevenir XSS.

---

## Mejoras futuras

### Resolución de CAPTCHA en SeatGeek
SeatGeek usa DataDome con un slider de verificación que bloquea Playwright. Las posibles soluciones son:

- **Cookies de sesión reales:** el usuario navega manualmente a SeatGeek una vez, acepta el slider, y exporta las cookies. Playwright las reutiliza en peticiones posteriores saltándose el CAPTCHA.
- **2Captcha / CapSolver:** servicios de resolución de CAPTCHA por humanos o IA (~$2 por 1.000 resoluciones).
- **ScrapingBee:** proxy especializado con bypass nativo de DataDome.

### Sistema de caché
Las entradas de un evento no cambian en segundos. Implementar una caché en fichero o Redis reduciría los tiempos de respuesta de ~15s (Playwright) a menos de 1s para consultas repetidas del mismo evento.

```php
// Ejemplo: caché en fichero con TTL de 5 minutos
$cacheKey = md5($url);
$cachePath = sys_get_temp_dir() . "/tickets_{$cacheKey}.json";
if (file_exists($cachePath) && time() - filemtime($cachePath) < 300) {
    return json_decode(file_get_contents($cachePath), true);
}
```

### Paginación de resultados
Actualmente se muestran todos los listings a la vez. Para eventos con cientos de entradas, implementar paginación o scroll infinito mejoraría el rendimiento de la interfaz.

### Exportación de datos
Añadir botones para exportar los listings en CSV o JSON directamente desde la interfaz web, útil para análisis de precios.

### Alertas de precio
Permitir al usuario definir un precio máximo y recibir una notificación (email o webhook) cuando aparezca una entrada por debajo de ese umbral.

### Soporte para más plataformas
La arquitectura basada en `ScraperInterface` permite añadir nuevas plataformas fácilmente. Candidatas naturales:

- **StubHub** — mayor marketplace de entradas del mundo
- **Ticketmaster** — venta primaria
- **Viagogo** — popular en Europa

### Tests automatizados
Añadir tests unitarios con PHPUnit para los scrapers usando fixtures HTML pregrabados, y tests de integración con Playwright que verifiquen el flujo completo end-to-end.

---

## Tecnologías utilizadas

| Tecnología | Uso |
| :--- | :--- |
| PHP 8.3 | Backend, scraping HTTP, API endpoint |
| Node.js + Playwright | Renderizado JS, intercepción XHR |
| ZenRows | Proxy anti-bot para VividSeats |
| HTML / CSS / JS | Interfaz web sin frameworks |
| cURL | Peticiones HTTP en PHP |
| DOMDocument + DOMXPath | Parsing HTML |
