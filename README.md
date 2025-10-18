## bor_news
cd ./bor_news && npm run start-dev
cd ./bor_gpt4free && python3 my-ai-webserver.py

## crontab -e
*/2 * * * * cd /var/www/bor_terminal && /usr/bin/php bin/console app:become-rich

### START THE APP IN UBUNTU , ADD 3 TERMINALS
cd /var/www/bor_terminal/bor_gpt4free
python3 my-ai-webserver.py
# then open http://localhost:5000

cd /var/www/bor_terminal/bor_news
npm run start-dev

cd /var/www/bor_terminal
php bin/console doctrine:migrations:migrate --no-interaction
git reset --hard
git pull

# DEBUG
http://borterminal/api/market-summary?debug=1 , get the question

# then post to
http://localhost:5000


## bor_gpt4free 
cd ./bor_gpt4free && git clone https://github.com/xtekky/gpt4free.git
- go to bor_gpt4free/gpt4free/g4f/Provider/PollinationsAI.py
- check what models are currently supported

