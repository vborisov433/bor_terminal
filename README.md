## bor_news
cd ./bor_news && npm run start-dev
cd ./bor_gpt4free && python3 my-ai-webserver.py

## crontab -e
*/2 * * * * cd /var/www/bor_terminal && /usr/bin/php bin/console app:become-rich

### START THE APP IN UBUNTU , ADD 3 TERMINALS
cd /var/www/bor_terminal/bor_gpt4free
python3 my-ai-webserver.py

cd /var/www/bor_terminal/bor_news
npm run start-dev

cd /var/www/bor_terminal
php bin/console doctrine:migrations:migrate --no-interaction
git reset --hard
git pull


