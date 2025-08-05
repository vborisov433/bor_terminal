import puppeteer from 'puppeteer';

export interface NewsItem {
    title: string;
    link: string;
    date: string;
    index: number; // 1 = newest/top
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
            items.push({ title, link, date });
        });

        // PackageItem headlines under FeaturedCard
        // @ts-ignore
        $('.PackageItem-link').each(function () {
            // @ts-ignore
            const title = $(this).text().trim();
            // @ts-ignore
            const link = $(this).attr('href');
            const date = extractDate(link);
            items.push({ title, link, date });
        });

        // SecondaryCard headlines
        // @ts-ignore
        $('.SecondaryCard-headline a').each(function () {
            // @ts-ignore
            const title = $(this).text().trim();
            // @ts-ignore
            const link = $(this).attr('href');
            const date = extractDate(link);
            items.push({ title, link, date });
        });

        // @ts-ignore
        $('.LatestNews-headlineWrapper a.LatestNews-headline').each(function() {
            // @ts-ignore
            const title = $(this).text().trim();
            // @ts-ignore
            const link = $(this).attr('href');
            const date = extractDate(link);
            items.push({ title, link, date });
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

    await browser.close();
    return news;
}
