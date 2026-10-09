# Webuzo release notes (Bangla)

প্রস্তুত archive: `release/webuzo-marremove-existing-site.zip`। এটি `shop.aveen.xyz`-এর আগে থেকেই তৈরি Webuzo installation আপডেট করার জন্য; ZIP-টি `/home/aveenxyz/shop.aveen.xyz`-এ extract করুন। এটি কোনো live deployment করে না। Archive-এ `.env`, `vendor/`, `storage/`-এর কোনো runtime data/log বা server-এর Composer lock নেই (শুধু `.gitignore` placeholder আছে)—extract করার সময় server-এর এগুলো অক্ষত রাখুন। phpMyAdmin-এ import করার SQL: `docs/webuzo_mysql_import.sql`।

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
SESSION_SECURE_COOKIE=true
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
MODERATION_ADMIN_EMAILS=<your trusted administrator email>
GEMINI_ENABLED=false
```

Existing `APP_KEY` থাকলে তা বদলাবেন না। APP_KEY ফাঁকা হলে, Page token সংরক্ষণের আগে একবারই তৈরি করুন। `.env` বদলানোর পরে:

```bash
/usr/local/apps/php83/bin/php artisan config:clear
/usr/local/apps/php83/bin/php artisan marremove:admin-create admin@example.com --name="Shop Admin"
/usr/local/apps/php83/bin/php artisan config:cache
/usr/local/apps/php83/bin/php artisan route:cache
```

`marremove:admin-create` চালালে password দুইবার চাইবে; টাইপ করা password terminal-এ দেখা যাবে না। অন্তত 12 অক্ষরের শক্তিশালী password দিন—command line বা chat-এ password লিখবেন না। একই allowlisted email দিয়ে পরে command চালালে password reset-এর আগে confirmation চাইবে। Public registration/password-reset page নেই। Login page-এ এই email/password ব্যবহার করুন।

## 4) Composer/source files

- Archive `vendor/` বাদ দেয়; বিদ্যমান Webuzo install-এ আগে থেকে থাকা `vendor/` মুছবেন না।
- বর্তমান repository-তে `backend/composer.lock` নেই; server-এ থাকা lockfile এই archive-এ নেই এবং extract করলে replace হবে না। নতুন server-এ production dependency install করার আগে reviewed/tested `composer.lock` source release-এ যোগ করা দরকার। Production-এ `composer update` চালাবেন না।
- Archive extract করার সময় `backend/.env`, `backend/vendor`, `backend/storage` বা server-এর `backend/composer.lock` overwrite/delete করবেন না।

## 5) গুরুত্বপূর্ণ go-live blocker

এই release-এ local email/password sign-in আছে; শুধু `MODERATION_ADMIN_EMAILS`-এ থাকা account-ই login করতে পারে। Public registration বা password reset নেই, SSO-ও নেই। Strong password দিয়ে CLI command-এ admin তৈরি না করা পর্যন্ত dashboard ব্যবহারযোগ্য হবে না। API authorization সরাবেন না। নতুন login flow চালু করার আগে ZIP-এর latest source deploy করুন।

## 6) Facebook Page সংযোগ: একাধিক Page বেছে নিন

1. Admin হিসেবে `https://shop.aveen.xyz/facebook/pages/connect` খুলুন। **Choose Pages from one User Access Token** অংশে একটি Facebook User Access Token দিন এবং **Find Pages** চাপুন। এই token browser থেকে backend-এ HTTPS POST-এ যাবে; browser DevTools-এর Network panel-এ request body দেখা সম্ভব, তাই শুধু বিশ্বস্ত admin browser ব্যবহার করুন এবং request-body logging বন্ধ রাখুন। Token Marremove-এ সংরক্ষণ করা হয় না।
2. Meta যদি Pages ফেরত দেয়, নাম/ID-সহ তালিকা দেখাবে। যে Page-গুলো যুক্ত করতে চান শুধু সেগুলোর checkbox দিন, তারপর **Connect selected Pages** চাপুন। কোনো Page স্বয়ংক্রিয়ভাবে connect হয় না। নির্বাচনটি পাঁচ মিনিটের মধ্যে শেষ করুন; মেয়াদ শেষ হলে আবার **Find Pages** চালান। Page token browser-এ ফেরত আসে না; server-side encrypted cache-এ সাময়িক থাকে এবং import attempt-এর পর মুছে যায়।
3. Page তালিকা দেখাতে Meta-র `pages_show_list` permission এবং Facebook account-এর ওই Pages-এ access প্রয়োজন। Bulk flow এই permission বা Meta App Review এড়িয়ে যায় না। Page connect হলেও `pages_read_user_content` (এবং প্রযোজ্য ক্ষেত্রে `pages_read_engagement`) অনুমোদিত না থাকলে comment sync কাজ নাও করতে পারে—এটি bypass করা যাবে না।
4. এই bulk feature-এ নতুন database table/migration নেই; আগের SQL import file বদলানোর দরকার নেই। তবে feature পেতে latest release source ও rebuilt frontend files deploy করতে হবে।

Queue job চালাতে persistent worker দরকার। Webuzo-তে Supervisor/Process Manager থাকলে সেখানে run করুন। না থাকলে Webuzo **Cron Jobs**-এ প্রতি মিনিটে schedule (`* * * * *`) দিয়ে নিচের command ব্যবহার করা যায়; এটি queue খালি হলে নিজে exit করে এবং `flock` overlap আটকায়:

```bash
cd /home/aveenxyz/shop.aveen.xyz/backend && /bin/flock -n /home/aveenxyz/shop.aveen.xyz/backend/storage/queue-worker.lock /usr/local/apps/php83/bin/php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=900 --stop-when-empty >> /home/aveenxyz/shop.aveen.xyz/backend/storage/logs/queue-worker.log 2>&1
```

Hosting provider যদি cron process-এ ছোট runtime limit দেয় বা দীর্ঘ job মেরে ফেলে, Supervisor চালুর জন্য provider-কে বলুন। Queue worker চালু হলে queued job সত্যিই execute হয়—test-এর জন্য real Facebook/Gemini কাজ queue করবেন না।

প্রথম go-live smoke test-এ `MODERATION_TEST_MODE=true` ও automatic actions off রাখুন। SQL/health checks Meta বা Gemini-র live action চালায় না।
