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
- Location context (zone + city + coordinates)
- One-time donation via Stripe PaymentIntent
- Weekly/monthly recurring via Stripe Checkout subscription mode
- Offline banner + last viewed recipient cache

Partner admin flow:
- Session login/logout
- Dynamic Tucson operations dashboard
- Recipient search/filter pipeline (self-signups, onboarding stage, status)
- Recipient CRUD fields (story/needs/zone/city/coordinates/status/verified)
- Token + short code generation and token rotation
- Map-enabled zone pin management
- Configurable demo admin login toggle (read-only demo sessions)
- Email-based password recovery (request + secure token reset)

Recipient self onboarding:
- Public self-signup page (`/signup`)
- Free signup with immediate token + short code + QR image
- Initial routing through default outreach backer (`Dark Horses USA`)
- Onboarding status (`new`, `reviewed`, `verified`) for staged verification rollout

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

If you already have an existing DB from earlier versions, run this migration once:

```bash
mysql -u <user> -p <database> < db/migrations/2026-02-24-tucson-onboarding.sql
mysql -u <user> -p <database> < db/migrations/2026-02-24-user-roles-hierarchy.sql
mysql -u <user> -p <database> < db/migrations/2026-02-24-password-reset.sql
```

Seeded dev accounts:
- owner admin: `owner@feedabum.local` / `OwnerPass!234`
- outreach admin: `admin@feedabum.local` / `OutreachPass!234`
- demo admin (read-only when enabled): `demo@feedabum.local` / `DemoPass!234`
- member user (donor/panhandler): `member@feedabum.local` / `MemberPass!234`

Change all seeded passwords immediately outside dev.

Seeded recipient test values:
- short code: `FAB1234`
- raw token: `demo-recipient-token-abc123`

The seeded token hash expects `TOKEN_SIGNING_SECRET=dev_token_signing_secret_change_me`.

Seeded partner/backer:
- `Dark Horses USA` (partner id `1`)

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

Demo toggle keys:
- `DEMO_LOGIN_ENABLED=true|false`
- `DEMO_LOGIN_EMAIL=demo@feedabum.local`

When demo login is enabled, that demo account is read-only in admin endpoints.

Password recovery keys:
- `MAIL_FROM_EMAIL`
- `MAIL_FROM_NAME`
- `PASSWORD_RESET_TTL_MINUTES`
- `RATE_LIMIT_PASSWORD_RESET_MAX`
- `RATE_LIMIT_PASSWORD_RESET_WINDOW`

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

## Social Share Metadata and Card

The frontend ships with Open Graph + Twitter metadata for social previews on X, Facebook, Discord, and similar platforms.

Social card assets:
- `web/public/social/fab-social-card.svg`
- `web/public/social/fab-social-card.png` (1200x630)

Regenerate the PNG from SVG:

```bash
npm run social:card
```

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

Default `/api` makes production work on same domain without CORS setup. For Tucson launch, keep `VITE_APP_BASE=https://fab.gops.app`.

## API Endpoints

- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/password/forgot`
- `POST /api/auth/password/reset`
- `POST /api/user/register`
- `POST /api/user/login`
- `POST /api/user/logout`
- `GET /api/user/me`
- `GET /api/recipient/by-token?token=...`
- `GET /api/recipient/by-code?code=...`
- `POST /api/recipient/signup`
- `POST /api/donation/create-intent`
- `POST /api/subscription/create`
- `POST /api/webhook/stripe`
- `GET /api/admin/recipients`
- `POST /api/admin/recipient/create`
- `POST /api/admin/recipient/update`
- `POST /api/admin/recipient/rotate-token`
- `GET /api/admin/users` (owner)
- `POST /api/admin/user/create` (owner)
- `POST /api/admin/user/update-status` (owner)
