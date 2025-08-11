import express from 'express';
import type { Request, Response } from 'express';
import {scrapeLatestNews, scrapeYahooFinanceNews} from './scraper.js';
import type { NewsItem } from './scraper.js';

const app = express();
const PORT = 3000;

app.get('/api/latest-news', async (_req: Request, res: Response) => {
    try {
        const news: NewsItem[] = await scrapeLatestNews();
        const yahooNews: NewsItem[] = await scrapeYahooFinanceNews();

        let result = [...yahooNews, ...news];
        res.json(result);
    } catch (error) {
        res.status(500).json({ error: 'Failed to fetch news', details: (error as Error).message });
    }
});

app.listen(PORT, () => {
    console.log(`Server is running at http://localhost:${PORT}`);
});
