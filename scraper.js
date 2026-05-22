/**
 * scraper.js — Extrae listings de VividSeats y SeatGeek usando Playwright.
 */
const { chromium } = require('playwright');

const url = process.argv[2];

if (!url) {
    console.log(JSON.stringify({ error: 'URL no proporcionada.' }));
    process.exit(1);
}

const isVividSeats = url.includes('vividseats.com');
const isSeatGeek   = url.includes('seatgeek.com');

(async () => {
    let browser;
    try {
        browser = await chromium.launch({
            headless: true,
            args: [
                '--disable-blink-features=AutomationControlled',
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-infobars',
                '--window-size=1280,800',
            ],
        });

        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            locale: 'en-US',
            viewport: { width: 1280, height: 800 },
            extraHTTPHeaders: {
                'Accept-Language': 'en-US,en;q=0.9',
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            },
        });

        // Elimina la firma de webdriver
        await context.addInitScript(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3] });
            Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });
            window.chrome = { runtime: {} };
        });

        const page     = await context.newPage();
        const listings = [];

        page.on('response', async (response) => {
            const respUrl = response.url();
            const status  = response.status();
            if (status !== 200) return;

            try {
                if (isVividSeats && respUrl.includes('/hermes/api/v1/listings')) {
                    const json  = await response.json();
                    const items = json.tickets ?? json.listings ?? json.data?.listings ?? [];
                    if (Array.isArray(items) && items.length > 0) {
                        listings.push(...items);
                    }
                }

                if (isSeatGeek && respUrl.includes('event_listings_v2')) {
                    const json  = await response.json();
                    const items = json.listings ?? json.data?.listings ?? [];
                    if (Array.isArray(items) && items.length > 0) {
                        listings.push(...items);
                    }
                }
            } catch (_) {}
        });

        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });

        try {
            await page.click('#onetrust-accept-btn-handler', { timeout: 4000 });
        } catch (_) {
            try {
                await page.click('button:has-text("Accept")', { timeout: 2000 });
            } catch (_) {}
        }

        await page.waitForTimeout(8000);

        if (listings.length > 0) {
            const seen   = new Set();
            const unique = listings.filter(l => {
                const key = `${l.sectionName ?? l.section ?? l.s}-${l.row ?? l.r}-${l.allInPricePerTicket ?? l.price ?? l.p}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
            console.log(JSON.stringify({ listings: unique }));
        } else {
            const nextData = await page.evaluate(() => {
                const el = document.getElementById('__NEXT_DATA__');
                return el ? JSON.parse(el.textContent) : null;
            });

            if (nextData && isVividSeats) {
                const pp       = nextData?.props?.pageProps ?? {};
                const topDeals = pp?.initialTopDealListingsData?.data?.topDeals ?? [];
                const minPrice = pp?.initialProductionDetailsData?.data?.minPrice ?? null;

                if (topDeals.length > 0) {
                    console.log(JSON.stringify({ listings: topDeals, source: 'topDeals' }));
                } else {
                    console.log(JSON.stringify({
                        error: minPrice
                            ? `VividSeats tiene entradas desde $${minPrice} pero no fue posible cargar el listado completo.`
                            : 'No se encontraron listings.'
                    }));
                }
            } else {
                console.log(JSON.stringify({ error: 'No se encontraron listings.' }));
            }
        }

    } catch (err) {
        console.log(JSON.stringify({ error: err.message }));
    } finally {
        if (browser) await browser.close();
    }
})();