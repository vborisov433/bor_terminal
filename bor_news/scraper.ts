import puppeteer, {Browser} from 'puppeteer';

import fs from 'fs';
import fetch from 'node-fetch';

let browser: Browser | null = null;

export interface NewsItem {
    title: string;
    link: string;
    date: string;
    index: number; // 1 = newest/top
}

function sleep(ms: number) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

const launchOptions = {
    headless: false,
    slowMo: 1,
    args: [
        "--no-sandbox",
        "--disable-setuid-sandbox",
        "--disable-dev-shm-usage",
        "--disable-gpu",
        "--no-zygote",
        "--single-process"
    ]
};

export async function restartBrowser(): Promise<Browser> {
    if (browser) {
        try {
            await browser.close();
        } catch (e) {
            console.error("Error closing browser:", e);
        }
        browser = null;
    }

    browser = await puppeteer.launch(launchOptions);

    // if connection closes → auto restart
    browser.on("disconnected", async () => {
        console.warn("Browser disconnected. Restarting...");
        browser = await puppeteer.launch(launchOptions);
    });

    console.log("Puppeteer restarted");
    return browser;
}

export async function getBrowser(retries = 2): Promise<Browser> {
    try {
        if (!browser) {
            browser = await puppeteer.launch(launchOptions);

            browser.on("disconnected", async () => {
                console.warn("Browser disconnected. Restarting...");
                browser = await puppeteer.launch(launchOptions);
            });
        }
        return browser;
    } catch (err: any) {
        console.error("Puppeteer launch failed:", err?.message || err);

        if (retries > 0) {
            console.log(`Retrying Puppeteer launch... (${retries} left)`);
            await new Promise(resolve => setTimeout(resolve, 3000));
            return getBrowser(retries - 1);
        } else {
            console.log("Final retry failed — force restart.");
            return restartBrowser();
        }
    }
}

export async function scrapeYahooFinanceNews(): Promise<NewsItem[]> {
    const browser = await getBrowser();
    const page = await browser.newPage();
    try {
        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
        );
        await page.setViewport({width: 1920, height: 1080, isMobile: false});

        await page.goto('https://finance.yahoo.com/', {
            waitUntil: 'domcontentloaded',
            timeout: 15000,
        });

        await page.setViewport({width: 1219, height: 665});

        // Scroll a bit to trigger lazy loading or scripts if needed
        await page.evaluate(() => window.scrollBy(0, 336));
        await page.evaluate(() => window.scrollBy(0, 0));

        try {
            await page.waitForSelector('.accept-all', {timeout: 3000}); // wait up to 3s
            await Promise.all([
                page.click('.accept-all'),
                page.waitForNavigation({waitUntil: 'domcontentloaded', timeout: 55000})
            ]);
        } catch {
            console.log('No consent dialog found, continuing...');
        }

        const links: { title: string; link: string; index: number }[] = await page.evaluate(() => {
            const nodes = document.querySelectorAll('.subtle-link');
            let ind = 100;
            const items: { title: string; link: string; index: number }[] = [];

            nodes.forEach(link => {
                const title = link.textContent?.trim() || '';
                const href = link.getAttribute('href') || '';

                if (title && /.+\/news\/.+/i.test(href)) {
                    items.push({
                        title,
                        link: href.startsWith('http') ? href : `https://finance.yahoo.com${href}`,
                        index: ind++
                    });
                }
            });

            return items;
        });

        // console.log(links)

        const news: NewsItem[] = [];

        for (const item of links) {
            try {
                const response = await fetch('http://15.0.1.50/api/news/check', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({link: item.link})
                });

                const result:any = await response.json();

                if (result.exists) {
                    const cleanLink = item.link.replace(/^https?:\/\//, '');
                    const shortLink = cleanLink.length > 71
                        ? cleanLink.substring(0, 71) + '…'
                        : cleanLink;

                    const now = new Date();
                    const hours = now.getHours().toString().padStart(2, '0');
                    const minutes = now.getMinutes().toString().padStart(2, '0');
                    const formattedTime = `${hours}:${minutes}`;

                    console.log(`[${formattedTime}][in.db] ${shortLink}`);
                    continue;
                }

                await page.goto(item.link, {
                    waitUntil: 'domcontentloaded',
                    timeout: 15000
                });

                const date = await page.evaluate(() => {
                    const dateElement = document.querySelector('time.byline-attr-meta-time');
                    return dateElement ? dateElement.getAttribute('datetime') || '' : '';
                });

                news.push({
                    ...item,
                    date
                });
            } catch (err) {
                await restartBrowser();
                console.error(`Failed to scrape ${item.link}:`, err);
            }
        }

        return news;

    } catch (e) {
        console.error('Error while scrapeYahooFinanceNews:', e);
        await restartBrowser();
        return [];
    } finally {
        await page.close();
    }
}


export async function scrapeLatestNews(): Promise<NewsItem[]> {
    browser = await getBrowser()
    const page = await browser.newPage();

    await page.setUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    );
    await page.setViewport({width: 1920, height: 1080, isMobile: false});

    try {

        await sleep(800 + Math.floor(Math.random() * 1200));
        await page.goto('https://www.cnbc.com/', {waitUntil: 'domcontentloaded', timeout: 15000});
        await sleep(1000 + Math.floor(Math.random() * 1600));

        await page.addScriptTag({url: 'https://code.jquery.com/jquery-3.6.0.min.js'});

        const news: NewsItem[] = await page.evaluate(() => {
            function extractDate(link: string): string {
                const match = link.match(/\/(\d{4})\/(\d{2})\/(\d{2})\//);
                if (match) {
                    return `${match[1]}-${match[2]}-${match[3]}`;
                } else {
                    return '';
                }
            }

            const items: any[] = [];

            // FeaturedCard main headline
            // @ts-ignore
            $('.FeaturedCard-packagedCardTitle a').each(function () {
                // @ts-ignore
                const title = $(this).text().trim();
                // @ts-ignore
                const link = $(this).attr('href');
                const date = extractDate(link);
                items.push({title, link, date});
            });

            // PackageItem headlines under FeaturedCard
            // @ts-ignore
            $('.PackageItem-link').each(function () {
                // @ts-ignore
                const title = $(this).text().trim();
                // @ts-ignore
                const link = $(this).attr('href');
                const date = extractDate(link);
                items.push({title, link, date});
            });

            // SecondaryCard headlines
            // @ts-ignore
            $('.SecondaryCard-headline a').each(function () {
                // @ts-ignore
                const title = $(this).text().trim();
                // @ts-ignore
                const link = $(this).attr('href');
                const date = extractDate(link);
                items.push({title, link, date});
            });

            // @ts-ignore
            $('.LatestNews-headlineWrapper a.LatestNews-headline').each(function () {
                // @ts-ignore
                const title = $(this).text().trim();
                // @ts-ignore
                const link = $(this).attr('href');
                const date = extractDate(link);
                items.push({title, link, date});
            });

            // Sort by date descending
            items.sort((a, b) => {
                if (a.date && b.date) {
                    return new Date(b.date).getTime() - new Date(a.date).getTime();
                }
                return 0;
            });

            // Add index: 1 = most recent at top
            return items.map((item, idx) => ({
                ...item,
                index: idx + 1,
            }));
        });

        // await browser.close();
        return news;

    }
    catch (e) {
        console.error('Error while scrapeYahooFinanceNews:', e);
        await restartBrowser();
        return [];
    } finally {
        await page.close();
    }

}
