# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A chauffeur/limousine booking system: a CodeIgniter 3 (PHP) REST API + admin portal in `backend/`, and a
drop-in, dependency-light (jQuery only) embed widget in `embed/` that any HTML site can paste in. No build
step anywhere in this repo — PHP is served as-is, and the widget JS/CSS is loaded directly by the browser.
`test3.html` (+ `test-map.js`, `test-config.js`) is the local manual test harness that exercises the widget
against a real backend and Google Maps; there is no automated test suite.

Payments are PayPal Orders v2 REST, redirect checkout, **authorize-then-capture**: the customer approves on
paypal.com (funds are held, not charged), and an admin explicitly **accepts** (captures) or **rejects**
(voids) the hold from the admin portal — see `Paypal.php` and `Admin_api::reservations_accept/reject`.

## Commands

Local setup (no package manager, no build):
```bash
# Backend: point backend/application/config/database.php (or backend/.env) at a MySQL db named `kafeh`
mysql -u root -p kafeh < backend/sql/kfb_schema.sql
# then apply every backend/sql/kfb_migration_v*.sql IN NUMERIC ORDER (v3, v3_1, v3_2, v4, v5, ... vN)
mysql -u root -p kafeh < backend/sql/kfb_migration_v3.sql   # ...repeat through the highest vN present

# Serve backend/ with PHP (e.g. XAMPP) and confirm it's up:
curl http://localhost/booking/backend/api/health

# First admin account (create_admin.php is broken under CI3's CLI bootstrap — see Known gaps):
# open /admin/setup once in a browser instead.

# Widget: open test3.html directly, or via a local static server if Google Maps refuses file://:
python -m http.server 8000   # then http://localhost:8000/test3.html
```
Secrets (PayPal client id/secret, DB creds) live in `backend/.env` (gitignored, copy `.env.example`) —
`backend/index.php` loads it into `getenv()` before CodeIgniter boots. Files under
`backend/application/config/` are tracked by git and only ever read `getenv('KEY') ?: 'placeholder'`; never
put real credentials directly in them.

There's no linter/formatter/test-runner configured for this app (the `phpunit`/`vfsstream` entries in
`backend/composer.json` belong to the vendored CodeIgniter framework itself, not this application). Verify
PHP changes with `php -l <file>`; verify JS changes with `node --check <file>`.

## Architecture

**Two independent front-ends share one backend.** `embed/booking.js` (the production widget) and
`test-map.js` (Google Maps: autocomplete, route line, distance calc) are both loaded by `test3.html` and
talk to the CI3 API in `backend/`. `embed/booking.js` never touches Google Maps directly — it reads
`window.kfbRoute` (published by `test-map.js` on every route change, plus a `kfb:route-updated` DOM event)
for distance/duration/pickup/dropoff coordinates.

**Two separate auth mechanisms, deliberately not shared:**
- Admin portal (`Auth.php`, `Admin.php`, `Admin_api.php`) — CI session cookies. `Admin_api`'s constructor
  hard-blocks every request unless `session->userdata('logged_in')`.
- Customer accounts (`Api.php`'s `customer_*` methods, `Customer_model.php`) — bearer tokens
  (`Authorization: Bearer <token>`), hashed at rest, because the widget is embedded cross-origin on
  third-party sites where cookie sessions don't reliably survive. Registration requires email OTP
  verification before the account is loginable (`kfb_customer_email_otps`).

**Routing** is split into `routes_api.php` (public, `/api/...`) and `routes_admin.php` (admin, `/admin/...`
pages + `/admin/api/...` JSON), both pulled into `routes.php`. Order matters for `(:any)` wildcards — a
specific sub-route (e.g. `.../accept`) must be declared before the generic `.../(:any)` catch-all beneath
it, or it never gets reached.

**Pricing is a server-side rate engine (v16), not a client calculator.** This is the part most likely to
need cross-file reading:
- `Pricing_engine.php` (a library, not a model — it composes `Vehicle_model` + `Settings_model` +
  `Surcharge_model`) is the **single implementation** of every pricing formula. It's called from two
  places that must never drift apart: `POST /api/pricing/quote` (the live estimate `embed/booking.js`
  polls as the customer fills the form) and `Api::reservation_create()`/`reservation_update()` (which
  recomputes and **overwrites** whatever `amount` the client submitted — the client's number is only ever
  a preview, never trusted).
- Each vehicle (`kfb_vehicles`) carries one local `$/mile` rate, one local `$/hour` rate, and a
  point-to-point minimum. Everything else (regional travel fee, long-distance/worldwide multipliers,
  hourly minimums per zone, default gratuity %, the garage's own coordinates) is a **global** setting in
  `kfb_settings`, read via `Settings_model::pricing_settings()` — never hard-coded.
  `Pricing_engine::classify_zone()` measures straight-line (Haversine) distance from the garage to
  pickup/dropoff to decide `local` vs `regional` (same state) vs `long_distance` (different US state) vs
  `worldwide`; the pickup↔dropoff route mileage used for the actual `$/mile` math still comes from
  Google's road distance, computed client-side in `test-map.js` and submitted as `distanceMiles`/`hours`.
- `kfb_surcharges` is an admin-editable catalog (Airport fee, Meet & Greet, tolls, etc.) — most are manual
  add-ons, but a couple carry an `auto_trigger` (`airport`, `airport_meet_greet`) that `Pricing_engine`
  matches against the trip's pickup/dropoff type before applying them automatically.
- Add-ons (`kfb_addons`) and promo codes (`kfb_promo_codes`) are **separate systems** `Pricing_engine`
  doesn't know about — they're folded onto the *one-way* total (before round-trip doubling) by both
  `Api::_apply_quote_to_payload()` (server) and `embed/booking.js`'s `mergeAddonsAndDiscount()` (client
  preview), which must stay in sync with each other since they're two independent implementations of the
  same fold-in step.
- `kfb_bookings` stores the resulting breakdown (`pricing_zone`, `travel_fee_amount`,
  `surcharges_total_amount`, `gratuity_pct`, `gratuity_amount`) alongside the final `amount` — rows from
  before this model (no `pricing_zone`) are "legacy pricing" and only have the final amount.

**Round trips are two linked `kfb_bookings` rows**, not one row with a flag: `_create_return_leg()`
inserts a second row (pickup/dropoff swapped) with `return_booking_id` pointing at the outbound leg. Only
the outbound leg carries a nonzero `amount`/payment/promo/add-ons — the whole round trip is authorized and
captured as a single PayPal transaction against it, and the return leg's status is kept in sync whenever
the outbound leg's status changes.

**Migrations** (`backend/sql/kfb_migration_vN.sql`) are numbered sequentially and must be applied in order
against `kfb_schema.sql`. Every one is idempotent (guarded `ADD COLUMN`/`DROP COLUMN` via
`information_schema` checks wrapped in a throwaway stored procedure, `CREATE TABLE IF NOT EXISTS`, or
`INSERT IGNORE`) — safe to re-run, and `kfb_schema.sql` itself is kept in sync with the latest migration so
a fresh install doesn't need to replay the whole history. When a migration destructively drops columns with
real data in them, it copies the table to a `_backup_vN` table first (see `kfb_migration_v16.sql` for the
pattern) rather than silently discarding admin-entered data.

## Known gaps (still true as of this writing)

- `backend/create_admin.php` is broken — CI3's CLI bootstrap treats `argv` as a pseudo-URI and rejects
  spaces/punctuation in the username/password. Use `/admin/setup` instead (works once, before any admin
  exists).
- "Charge additional amount" on a reservation always errors — PayPal Orders v2 redirect checkout doesn't
  retain a chargeable payment method after checkout (no Vault). Extra charges have to be handled manually.
- No PayPal webhook listener — accept/reject only happen via the admin's synchronous request; a chargeback
  raised later won't update a booking's status automatically.
