# Feed a Bum MVP

Production-ready scaffold for hyperlocal micro-giving with:

- Frontend: Vue 3 + Vite + TypeScript + Pinia + Vue Router + PWA
- Backend: PHP 8.1+ JSON API (no framework)
- Database: MySQL 8
- Deployment target: cPanel Git Version Control (no server-side Node build)

## Repository Structure

- `web/` Vue source
- `api/` PHP JSON API
- `db/` MySQL schema + seed
- `dist/` built frontend output committed for cPanel deploy
- `.cpanel.yml` deployment copy tasks only

## Features in this MVP

Donor flow:
- QR scan (`getUserMedia` + `jsQR`) and code fallback
- Recipient profile by token/code
- Verification + transparency stats
- One-time donation via Stripe PaymentIntent
- Weekly/monthly recurring via Stripe Checkout subscription mode
- Offline banner + last viewed recipient cache

Partner admin flow:
- Session login/logout
- Recipient CRUD fields (story/needs/zone/status/verified)
- Token + short code generation and token rotation
- Recipient stats view

Backend security:
- PDO prepared statements
- HttpOnly cookie session auth
- Token hash storage (`recipient_tokens.token_hash` only)
- DB-backed rate limiting (`throttle` table)
- Stripe webhook signature verification
- Wallet ledger credit only on confirmed webhook events

## Local Setup

### 1) Database

Create database + user, then run:

```bash
mysql -u <user> -p <database> < db/schema.sql
mysql -u <user> -p <database> < db/seed.sql
```

Seeded dev login:
- email: `admin@feedabum.local`
- password: `DevPass!234`

Change this password immediately outside dev.

Seeded recipient test values:
- short code: `FAB1234`
- raw token: `demo-recipient-token-abc123`

The seeded token hash expects `TOKEN_SIGNING_SECRET=dev_token_signing_secret_change_me`.

### 2) API config

```bash
cp api/config/config.example.php api/config/config.php
```

Fill all values in `api/config/config.php`.

Important:
- `api/config/config.php` is gitignored and must not be committed.
- Runtime config load order is:
1. environment variables (`getenv`)
2. `api/config/config.php`
3. if missing: API fails (detailed JSON only when `APP_ENV=dev`)

### 3) Run API locally

```bash
php -S 127.0.0.1:8000 -t api
```

### 4) Run frontend locally

```bash
npm install --prefix web
cp web/.env.example web/.env.local
```

For local cross-origin dev, set `web/.env.local`:

```ini
VITE_API_BASE=http://127.0.0.1:8000
VITE_APP_BASE=http://127.0.0.1:5173
VITE_STRIPE_PUBLISHABLE_KEY=pk_test_replace_me
```

Then run:

```bash
npm run dev:web
```

## Build Frontend to Root `/dist`

Build command from repo root:

```bash
npm run build
```

This runs Vite in `web/` and writes output into repo-root `dist/`.

For cPanel Git deployment, commit `dist/` changes before pushing.

## cPanel Deployment (exact target)

Remote and server:
- Git remote: `git@github.com:agillhock7/feedabum.git`
- cPanel checkout path: `/home/gopsapp1/repositories/feedabum`
- site URL: `https://fab.gops.app`
- docroot: `/home/gopsapp1/fab.gops.app`

`.cpanel.yml` behavior:
- no `npm install` and no `npm run build`
- ensures destination folders exist
- copies `dist/* -> /home/gopsapp1/fab.gops.app/`
- copies `api/* -> /home/gopsapp1/fab.gops.app/api/`
- prefers `rsync` if available, otherwise `cp -R`
- no destructive delete pass (keeps folders such as `.well-known`)

Deployment checklist:
1. Build locally: `npm run build`
2. Commit source + `dist/`
3. Push to `main`
4. Trigger deploy in cPanel Git Version Control
5. Verify `https://fab.gops.app` and API endpoint `https://fab.gops.app/api/`

## Stripe Webhook

Endpoint:
- `POST /api/webhook/stripe`
- file: `api/webhook/stripe.php`

Set webhook secret in API config: `STRIPE_WEBHOOK_SECRET`.

Local webhook forwarding example with Stripe CLI:

```bash
stripe listen --forward-to http://127.0.0.1:8000/webhook/stripe
```

Use test events:
- `payment_intent.succeeded`
- `checkout.session.completed`
- `invoice.paid`
- `customer.subscription.updated`
- `customer.subscription.deleted`

## Frontend Env Template

`web/.env.example` includes:
- `VITE_API_BASE=/api`
- `VITE_APP_BASE=https://fab.gops.app`
- `VITE_STRIPE_PUBLISHABLE_KEY=...`

Frontend does not hold DB credentials. Database keys (`DB_*`) belong only in `api/config/config.php` (or server env vars).

Default `/api` makes production work on same domain without CORS setup.

## API Endpoints

- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/recipient/by-token?token=...`
- `GET /api/recipient/by-code?code=...`
- `POST /api/donation/create-intent`
- `POST /api/subscription/create`
- `POST /api/webhook/stripe`
- `GET /api/admin/recipients`
- `POST /api/admin/recipient/create`
- `POST /api/admin/recipient/update`
- `POST /api/admin/recipient/rotate-token`
