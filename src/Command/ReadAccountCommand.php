<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Panther\Client;
use Facebook\WebDriver\Cookie;

#[AsCommand(
    name: 'app:read-account',
    description: 'Login to FXCM, save cookies, and scrape data.',
)]
class ReadAccountCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('FXCM Stealth Scraper - Diagnosis Mode');

        // --- STEP 1: INITIALIZE CLIENT ---
        $io->section('1. Initializing Chrome Client');

        // CRITICAL: This user agent must match the browser you used to get cookies.
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $client = Client::createChromeClient(null, [
            '--headless',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-blink-features=AutomationControlled', // Hides "navigator.webdriver"
            "--user-agent=$userAgent",
            '--window-size=1920,1080',
        ]);
        $io->success('Client initialized.');

        // --- STEP 2: ESTABLISH CONTEXT ---
        $io->section('2. Establishing Session Context');
        $client->request('GET', 'https://app.fxcm.com');

        // --- STEP 3: INJECT COOKIES ---
        $io->section('3. Injecting Auth Cookies');
        $this->injectCookies($client, 'app.fxcm.com_cookies.txt', $io);

        // --- STEP 4: DEEP LINK NAVIGATION ---
        $io->section('4. Navigating to Portfolio');
        $deepLink = 'https://app.fxcm.com/mobile/portfolio/open-positions';
        $client->request('GET', $deepLink);
        $io->text("Requested URL: $deepLink");

        // --- STEP 5: WAIT FOR APP TO WAKE UP ---
        $io->section('5. Waiting for Equity (Max 60s)...');

        try {
            // We ignore spinners. We wait strictly for the data we want.
            // If the app works, this element MUST appear eventually.
            $client->waitFor('[data-testid="asEquity"]', 60);

            // If we get here, WE WON.
            $rawEquity = $client->getCrawler()->filter('[data-testid="asEquity"]')->text();
            $equityValue = (int) floatval(preg_replace('/[^0-9.]/', '', $rawEquity));

            $io->success(sprintf('SUCCESS! Equity Found: %d', $equityValue));

        } catch (\Exception $e) {
            // --- DIAGNOSIS BLOCK ---
            $io->error('TIMEOUT: The App Shell loaded, but Equity never appeared.');

            // 1. Screenshot (Visual Check)
            $client->takeScreenshot('debug_crash_view.png');
            $io->note('Saved visual state to: debug_crash_view.png');

            // 2. HTML (Structure Check)
            file_put_contents('debug_crash.html', $client->getCrawler()->html());
            $io->note('Saved HTML to: debug_crash.html');

            // 3. CONSOLE LOGS (The Smoking Gun)
            // This will tell us if React crashed or if Cloudflare blocked JS resources
            $io->section('BROWSER CONSOLE LOGS (JS Errors)');
            $logs = $client->getWebDriver()->manage()->getLog('browser');

            if (empty($logs)) {
                $io->warning('No console logs found. (Chrome might be restricting them)');
            }

            foreach ($logs as $log) {
                // Only show errors/warnings to reduce noise
                if ($log['level'] === 'SEVERE' || $log['level'] === 'WARNING') {
                    $io->writeln(sprintf('<comment>[%s]</comment> %s', $log['level'], $log['message']));
                }
            }

            return Command::FAILURE;
        }

        // --- STEP 6: PORTFOLIO CHECK (Only if Step 5 passed) ---
        $io->section('6. Checking Open Positions');
        try {
            $noPosSelector = '[data-testid="PortfolioScreen.noPositions"]';
            $crawler = $client->getCrawler();

            if ($crawler->filter($noPosSelector)->count() > 0) {
                $io->success('Portfolio Status: No Open Positions.');
            } else {
                $io->warning('Portfolio Status: Active Trades Detected.');
                $client->takeScreenshot('active_portfolio.png');
            }
        } catch (\Exception $e) {
            $io->error('Error checking portfolio: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }

    /**
     * Helper to parse Netscape cookie file and inject into WebDriver
     */
    private function injectCookies(Client $client, string $filepath, SymfonyStyle $io): void
    {
        if (!file_exists($filepath)) {
            $io->warning("Cookie file $filepath not found. Proceeding as guest/unauthenticated.");
            return;
        }

        $lines = explode("\n", file_get_contents($filepath));
        $options = $client->getWebDriver()->manage();
        $count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;

            $parts = preg_split('/\s+/', $line);

            // Ensure we have enough parts for a valid cookie
            if (count($parts) >= 7) {
                try {
                    // Constructor: Name, Value
                    $cookie = new Cookie($parts[5], $parts[6]);

                    // Set Domain (from file, usually column 0)
                    $cookie->setDomain($parts[0]);
                    $cookie->setPath($parts[2]);

                    // Set Expiry if numeric
                    if (is_numeric($parts[4]) && $parts[4] > 0) {
                        $cookie->setExpiry((int)$parts[4]);
                    }

                    $options->addCookie($cookie);
                    $count++;
                } catch (\Exception $e) {
                    // Ignore specific cookie failures
                }
            }
        }
        $io->text("Injected $count cookies from file.");
    }
}