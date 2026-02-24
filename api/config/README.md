# API config on shared hosting

## 1) Create `config.php`

Copy `api/config/config.example.php` to `api/config/config.php` and fill every placeholder.

```bash
cp api/config/config.example.php api/config/config.php
```

`api/config/config.php` is ignored by git and must never be committed.

## 2) cPanel placement

For this repo layout, place the real file at:

`/home/gopsapp1/repositories/feedabum/api/config/config.php`

That path is in the cPanel git checkout. Deployment copy then syncs it to runtime path:

`/home/gopsapp1/fab.gops.app/api/config/config.php`

## 3) Required values

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT`
- `APP_ENV` (`dev` or `prod`)
- `APP_BASE_URL` (`https://fab.gops.app`)
- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `SESSION_COOKIE_SECURE`, `SESSION_COOKIE_SAMESITE`
- `RATE_LIMIT_*`
- `TOKEN_SIGNING_SECRET`

`STRIPE_PUBLISHABLE_KEY` is used by frontend card collection.

## 4) Test DB connection

From project root:

```bash
php -r 'require "api/bootstrap.php"; [$c,$pdo]=fab_bootstrap(false); echo "DB OK\n";'
```

## 5) Test Stripe connection

From project root (replace key if needed):

```bash
php -r '$k="sk_test_replace_me"; $ch=curl_init("https://api.stripe.com/v1/account"); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_HTTPHEADER=>["Authorization: Bearer $k"]]); $r=curl_exec($ch); $s=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); echo "HTTP $s\n"; echo $r, "\n";'
```
