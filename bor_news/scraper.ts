import puppeteer from 'puppeteer';

export interface NewsItem {
    title: string;
    link: string;
}

function sleep(ms: number) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

export async function scrapeLatestNews(): Promise<NewsItem[]> {
    const browser = await puppeteer.launch({ headless: true });
    const page = await browser.newPage();

    await page.setUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    );
    await page.setViewport({ width: 1920, height: 1080, isMobile: false });

    await sleep(800 + Math.floor(Math.random() * 1200));
    await page.goto('https://www.cnbc.com/', { waitUntil: 'domcontentloaded' });
    await sleep(1000 + Math.floor(Math.random() * 1600));

    await page.addScriptTag({ url: 'https://code.jquery.com/jquery-3.6.0.min.js' });

    const news = await page.evaluate(() => {
        const items: { title: string; link: string }[] = [];
        // @ts-ignore
        $('.LatestNews-headlineWrapper a.LatestNews-headline').each(function() {
            // @ts-ignore
            const title = $(this).text().trim();
            // @ts-ignore
            const link = $(this).attr('href');
            items.push({ title, link });
        });
        return items;
    });

    await browser.close();
    return news;
}
