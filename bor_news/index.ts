import express from 'express';
import type { Request, Response } from 'express';
import {scrapeLatestNews, scrapeYahooFinanceNews} from './scraper.js';
import type { NewsItem } from './scraper.js';

const app = express();
const PORT = 3000;
let isScraping = false;

async function withTimeout<T>(promise: Promise<T>, ms: number, fallback: T): Promise<T> {
    return Promise.race([
        promise,
        new Promise<T>((resolve) =>
            setTimeout(() => resolve(fallback), ms)
        ),
    ]);
}

app.get('/api/latest-news', async (_req: Request, res: Response) => {
    if (isScraping) {
        // If scraping is already in progress, return empty array
        return res.json([]);
    }
    isScraping = true;

    try {
        // const news: NewsItem[] = await scrapeLatestNews().catch(err => {
        //     console.error('scrapeLatestNews failed:', err);
        //     return [];
        // });
        // const yahooNews: NewsItem[] = await scrapeYahooFinanceNews().catch(err => {
        //     console.error('scrapeYahooFinanceNews failed:', err);
        //     return [];
        // });

        const news: NewsItem[] = await withTimeout(
            scrapeLatestNews().catch(err => {
                console.error('scrapeLatestNews failed:', err);
                return [];
            }),
            60000,
            []
        );

        const yahooNews: NewsItem[] = await withTimeout(
            scrapeYahooFinanceNews().catch(err => {
                console.error('scrapeYahooFinanceNews failed:', err);
                return [];
            }),
            120000,
            []
        );


        const result = [...yahooNews, ...news];
        res.json(result);
    } catch (error) {
        console.error('Unexpected error in /api/latest-news:', error);
        res.status(500).json({ error: 'Failed to fetch news', details: (error as Error).message });
    } finally {
        isScraping = false; // release the flag
    }
});

app.listen(PORT, () => {
    console.log(`Server is running at http://localhost:${PORT}`);
});
