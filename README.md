## bor_news
cd ./bor_news && npm run start-dev
cd ./bor_gpt4free && python3 my-ai-webserver.py

## crontab -e
*/2 * * * * cd /var/www/bor_terminal && /usr/bin/php bin/console app:become-rich
