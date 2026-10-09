# Webuzo release notes (Bangla)

প্রস্তুত archive: `release/webuzo-marremove-existing-site.zip`। এটি `shop.aveen.xyz`-এর আগে থেকেই তৈরি Webuzo installation আপডেট করার জন্য; ZIP-টি `/home/aveenxyz/shop.aveen.xyz`-এ extract করুন। এটি কোনো live deployment করে না। Archive-এ `.env`, `vendor/`, `storage/` বা server-এর Composer lock রাখা হবে না—extract করার সময় server-এর এগুলো অক্ষত রাখুন। phpMyAdmin-এ import করার SQL: `docs/webuzo_mysql_import.sql`।

## 1) Domain/PHP

- Webuzo-তে `shop.aveen.xyz`-এর PHP-FPM/handler **8.3** রাখুন। CLI ও web PHP একই major version হওয়া উচিত।
- Document Root: `/home/aveenxyz/shop.aveen.xyz/backend/public`
- একই origin-এ `/api/*` ও `/up` Laravel-এ যায়, আর React SPA `index.html` থেকে serve হয়। Updated `.htaccess` এবং root redirect এই source-এ রাখা হয়েছে।

## 2) Database

1. Webuzo-তে একটি নতুন, খালি MySQL database এবং dedicated user তৈরি করুন।
2. phpMyAdmin-এ database select করে `webuzo_mysql_import.sql` import করুন। SQL ফাইলে current Laravel migrations-এর final tables এবং `migrations` ledger আছে; এটি কেবল empty DB-তে import করার জন্য। এতে `DROP TABLE` নেই।
3. `backend/.env`-এ Webuzo-র সঠিক MySQL host, database, username/password বসান। Secret shell transcript/chat-এ দেবেন না।
4. এরপর:

```bash
cd ~/shop.aveen.xyz/backend
/usr/local/apps/php83/bin/php artisan config:clear
/usr/local/apps/php83/bin/php artisan migrate:status
```

সব migration `Ran` দেখালে SQL import সম্পূর্ণ। `migrate:fresh` চালাবেন না; SQL file import করার পর `migrate` চালানোর দরকার নেই।

## 3) নিরাপদ app configuration

`.env`-এ অন্তত এগুলো মিলিয়ে নিন:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://shop.aveen.xyz
DB_CONNECTION=mysql
DB_HOST=<Webuzo database host>
DB_PORT=3306
DB_DATABASE=<database name>
DB_USERNAME=<database user>
DB_PASSWORD=<private database password>
MODERATION_TEST_MODE=true
MODERATION_AUTO_EXECUTE_ACTIONS=false
MODERATION_AUTO_HIDE_ENABLED=false
MODERATION_AUTO_DELETE_ENABLED=false
MODERATION_ALLOW_AI_HIDE=false
MODERATION_ALLOW_AI_DELETE=false
GEMINI_ENABLED=false
```

Existing `APP_KEY` থাকলে তা বদলাবেন না। APP_KEY ফাঁকা হলে, Page token সংরক্ষণের আগে একবারই তৈরি করুন। `.env` বদলানোর পরে:

```bash
/usr/local/apps/php83/bin/php artisan config:clear
/usr/local/apps/php83/bin/php artisan config:cache
/usr/local/apps/php83/bin/php artisan route:cache
```

## 4) Composer/source files

- Archive `vendor/` বাদ দেয়; বিদ্যমান Webuzo install-এ আগে থেকে থাকা `vendor/` মুছবেন না।
- বর্তমান repository-তে `backend/composer.lock` নেই; server-এ থাকা lockfile এই archive-এ নেই এবং extract করলে replace হবে না। নতুন server-এ production dependency install করার আগে reviewed/tested `composer.lock` source release-এ যোগ করা দরকার। Production-এ `composer update` চালাবেন না।
- Archive extract করার সময় `backend/.env`, `backend/vendor`, `backend/storage` বা server-এর `backend/composer.lock` overwrite/delete করবেন না।

## 5) গুরুত্বপূর্ণ go-live blocker

এই codebase-এ sign-in/SSO flow নেই। API ও moderation routes authenticated admin session চায়; authorization সরিয়ে dashboard খোলা নিরাপদ নয়। Public admin dashboard live করার আগে আপনার trusted login/SSO পদ্ধতি integrate করতে হবে।

একটি persistent queue worker-ও দরকার; Webuzo-র process manager/Supervisor-এ সেট করুন (terminal session-এ foreground-এ চালাবেন না):

```bash
cd /home/aveenxyz/shop.aveen.xyz/backend && /usr/local/apps/php83/bin/php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=900 --max-time=3600
```

প্রথম go-live smoke test-এ `MODERATION_TEST_MODE=true` ও automatic actions off রাখুন। SQL/health checks Meta বা Gemini-র live action চালায় না।
