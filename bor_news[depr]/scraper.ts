import puppeteer from 'puppeteer';

let browser: puppeteer.Browser;
import fs from 'fs';
import fetch from 'node-fetch';

const SKIP_EXISTING_CHECK = false;

export interface NewsItem {
    title: string;
    link: string;
    date: string;
    index: number; // 1 = newest/top
}

function sleep(ms: number) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function getBrowser() {
    if (!browser) {
        browser = await puppeteer.launch(
            {
                headless: false,
                slowMo: 1,
            }
        );
    }
    return browser;
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
            const articlePage = await browser.newPage();

            try {
                if (!SKIP_EXISTING_CHECK) {
                    const response = await fetch('http://15.0.1.50/api/news/check', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({link: item.link})
                    });

                    const result:any = await response.json();

                    if (result.exists) {
                        console.log(`Skipping ${item.link} — already exists in DB`);
                        continue;
                    }
                }

                await articlePage.goto(item.link, {
                    waitUntil: 'domcontentloaded',
                    timeout: 20000
                });

                const date = await page.evaluate(() => {
                    const dateElement = document.querySelector('time.byline-attr-meta-time');
                    return dateElement ? dateElement.getAttribute('datetime') || '' : '';
                });

                console.log(`Scraped: ${item.title} (${date})`);

                news.push({
                    ...item,
                    date
                });
            } catch (err) {
                console.error(`Failed to scrape ${item.link}:`, err);
            }
            finally {
                await articlePage.close();
            }
        }

        return news;

    } catch (e) {
        console.error('Error while scrapeYahooFinanceNews:', e);
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

    } finally {
        await page.close();
    }

}
