# Kafeh Booking — API + Embed Widget

A drop-in chauffeur/limousine booking widget for any HTML/jQuery website, backed by a **CodeIgniter 3** REST API, MySQL, and **Stripe** payments. Payment is an **authorize-then-approve** flow: the customer's card is authorized (held, not charged) when they submit a reservation, and an admin explicitly **accepts** (captures the funds) or **rejects** (releases the hold) it from the admin portal. The card is saved so the admin can bill it again later for extras. The backend also ships a session-based **admin portal** for managing the fleet, promo codes, add-on services, and reservations.

---

## 📁 What's inside

```
booking/
├── embed/                                 # Drop-in widget (paste into any site)
│   ├── kafeh-booking.css / .js            # v1 widget (earliest)
│   ├── kafeh-booking2.css / .js           # v2 widget
│   ├── kafeh-booking3.css / .js           # v3 widget — CURRENT / actively developed
│   └── kafeh-airports.js                  # Airport autocomplete data/helper
│
├── vendor/                                # Local copies of vendor libs (offline test)
│   └── jquery-3.7.1.min.js
│
├── test.html / test2.html / test3.html    # Local test harnesses for each widget version
│                                           #   test3.html is the current one (loads kafeh-booking3.*)
│                                           #   test.html / test2.html (v1/v2) still POST to the
│                                           #   removed /api/paypal/* endpoints — see Known gaps
├── test.css                               # Shared test harness styles
├── test-config.js                         # KAFEH_API + KAFEH_STRIPE_PUBLISHABLE_KEY globals
├── test-status.js                         # Tiny status panel filler
├── test-map.js                            # Google Maps controller (markers, route, km/min)
│
├── backend/                               # CodeIgniter 3 application
│   ├── application/
│   │   ├── controllers/
│   │   │   ├── Api.php                    # Public REST endpoints
│   │   │   ├── Auth.php                   # Admin login / logout / setup
│   │   │   ├── Admin.php                  # Admin pages (dashboard, vehicles, promos, add-ons, reservations)
│   │   │   └── Admin_api.php              # Admin CRUD JSON endpoints
│   │   ├── models/
│   │   │   ├── Booking_model.php          # Bookings / stops / payments / signatures / return legs
│   │   │   ├── Admin_model.php            # Admin login (bcrypt)
│   │   │   ├── Vehicle_model.php          # Vehicle CRUD + image upload + rates
│   │   │   ├── Promo_model.php            # Promo code CRUD + validation/discount math
│   │   │   ├── Addon_model.php            # Add-on services catalog (Red Carpet, Floral, …)
│   │   │   ├── Customer_model.php         # Guest → account customer records
│   │   │   └── Settings_model.php         # Global key/value settings (Meet & Greet fee)
│   │   ├── libraries/
│   │   │   ├── Stripe.php                 # Stripe PaymentIntents wrapper (raw cURL, no SDK installed)
│   │   │   └── Flights.php                # Aviationstack flight-lookup wrapper
│   │   ├── views/admin/                   # Admin portal UI (dashboard, vehicles, promos, addons, reservations, settings)
│   │   └── config/
│   │       ├── stripe.php                 # Stripe keys — reads getenv(), see backend/.env
│   │       ├── kafeh.php                  # Top-level config
│   │       ├── aviationstack.php          # Flight-lookup API key/config
│   │       ├── routes_kafeh.php           # Public REST routes
│   │       └── routes_admin.php           # Admin portal routes
│   ├── assets/admin/                      # Admin CSS + JS
│   ├── uploads/vehicles/                  # Uploaded vehicle images
│   ├── sql/
│   │   ├── kfb_schema.sql                 # Base MySQL schema (fresh install)
│   │   └── kfb_migration_v3*.sql … v6.sql # Incremental migrations (see below)
│   ├── create_admin.php                   # CLI: create an admin user (currently broken, see Known gaps)
│   ├── .env                               # Local secrets — gitignored, not committed. Loaded by index.php.
│   └── .env.example
│
├── docs/
│   └── VISITOR_TRACKING_RESEARCH.md       # Research notes on corporate-IP detection (not yet built)
│
├── .env.example
└── README.md
```

---

## 🚀 Quick start (local testing)

### 1. Open the test page

Open **`test3.html`** in your browser — it's the current widget version under active development (`embed/kafeh-booking3.css` / `.js`). `test.html` and `test2.html` are earlier iterations kept for reference.

- Loads **jQuery** from `vendor/jquery-3.7.1.min.js` (local copy, no internet needed for this)
- Loads **Stripe.js** from `https://js.stripe.com/v3/` (no build step, official CDN script)
- Loads **Google Maps** — replace `YOUR_GOOGLE_MAPS_KEY` with your own key
- Sets `KAFEH_API` and `KAFEH_STRIPE_PUBLISHABLE_KEY` via `test-config.js`

No build step, no server required. Works on `file://` as long as your browser allows CDN scripts (most do).

> **Heads-up:** the Google Maps API does **not** work over `file://` in some browsers. If the map doesn't initialize, run a quick local server:
>
> ```bash
> python -m http.server 8000
> # then visit http://localhost:8000/test3.html
> ```

### 2. Wire it up to the backend

Edit `test-config.js` and change `KAFEH_API` to your backend URL:

```js
window.KAFEH_API = "http://localhost/booking/backend"; // local XAMPP
window.KAFEH_API = "https://api.kafeh.com";             // production
```

### 3. Backend (CodeIgniter 3)

This repo's `backend/` folder **is** a full CI3 application (not just a set of files to copy in).

1. Serve `backend/` with PHP (XAMPP, `php -S`, etc.).
2. Set DB creds in `backend/application/config/database.php`.
3. Import the schema: `mysql -u root -p kafeh < backend/sql/kfb_schema.sql`
4. Apply migrations in order (see **Database migrations** below).
5. Create `backend/.env` (copy `.env.example`) and set `STRIPE_SECRET_KEY` / `STRIPE_PUBLISHABLE_KEY` from your [Stripe test-mode API keys](https://dashboard.stripe.com/test/apikeys). `backend/index.php` loads this file automatically — see **Secrets & `.env`** below.
6. Set the same publishable key as `window.KAFEH_STRIPE_PUBLISHABLE_KEY` in `test-config.js` (publishable keys are safe client-side; the secret key never leaves the server).
7. (Optional) Set a flight-lookup API key in `backend/application/config/aviationstack.php`.
8. Test: `curl http://localhost/booking/backend/api/health`

### Secrets & `.env`

Config files under `backend/application/config/` (e.g. `stripe.php`) are tracked by git and only ever read `getenv('SOME_KEY') ?: 'placeholder'` — real credentials never belong in them. Put real values in `backend/.env` (gitignored, copy from `.env.example`); `backend/index.php` loads it into `getenv()` on every request before CodeIgniter boots. This is the same pattern the removed PayPal integration used, now actually wired up.

---

## 🧩 Embedding the widget in a production site

All CSS lives in `embed/kafeh-booking3.css` and all wizard JS in `embed/kafeh-booking3.js` — no inline CSS or scripts. The canonical reference markup is the `<div class="kfb-widget">…</div>` block in `test3.html`.

```html
<!-- 1. Styles -->
<link rel="stylesheet" href="/path/to/embed/kafeh-booking3.css">

<!-- 2. Widget markup (copy from test3.html's <div class="kfb-widget"> block) -->
<div class="kfb-widget" id="kafehBookingWidget"> … </div>

<!-- 3. Required vendor scripts (BEFORE the widget JS) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://js.stripe.com/v3/"></script>

<!-- 4. Globals the widget reads at boot time -->
<script>
  window.KAFEH_API                     = "https://api.kafeh.com";
  window.KAFEH_STRIPE_PUBLISHABLE_KEY  = "YOUR_LIVE_STRIPE_PUBLISHABLE_KEY";
</script>

<!-- 5. Google Maps LAST -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_KEY&libraries=places&callback=kfbTestInitMap" async defer></script>

<!-- 6. Widget script (after jQuery + Google Maps) -->
<script src="/path/to/embed/kafeh-booking3.js"></script>
```

**Required load order:** jQuery → Stripe.js → widget JS → Google Maps.

---

## 🔌 REST API reference

Base URL: `https://YOUR-API-HOST/api` (routes defined in `backend/application/config/routes_kafeh.php`)

| Method | Route | Purpose |
|---|---|---|
| GET | `/health` | Health check |
| GET | `/fleet` | List available vehicles |
| GET | `/addons?region=chicago\|america\|worldwide` | List enabled add-on services, priced for the region |
| GET | `/flights/validate?flight=AA1234&date=2026-08-01` | Look up a real flight via Aviationstack (falls back to `ok:false` if unavailable) |
| POST | `/reservation` | Create a booking (returns `booking_id`, status `pending`) |
| GET | `/reservation/:id` | Fetch a booking with stops + payment history |
| POST | `/reservation/sign` | Save an e-signature for bookings ≥ $500 (`{ booking_id, signature, terms_version }`) |
| POST | `/customers/register` | Promote a guest booking to a full account (`{ email, password, first_name, last_name }`) |
| POST | `/stripe/create-intent` | Authorize (hold) the trip amount, save the card (`{ booking_id, amount }`) — does **not** charge |
| POST | `/stripe/finalize` | Confirm the authorization succeeded, persist card + status `awaiting_approval` (`{ booking_id, payment_intent_id }`) |
| GET | `/promo/validate?code=XYZ&amount=189.50` | Validate a promo code and compute the discount |

### `POST /reservation` — example body

```json
{
  "service":     "From Airport",
  "pickupDate":  "2026-07-01",
  "pickupTime":  "14:30",
  "pickup":      "JFK Airport, Terminal 4",
  "dropoff":     "Times Square, NY",
  "stops":       ["Stop 1 address"],
  "passengers":  2,
  "luggage":     2,
  "childSeats":  0,
  "notes":       "Flight AA100",
  "vehicle_id":  "suv",
  "vehicle_name":"Premium SUV",
  "distanceMiles": 18.4,
  "durationMins":  42,
  "amount":      189.50,
  "firstName":   "John",
  "lastName":    "Doe",
  "email":       "john@example.com",
  "phone":       "+1 555 000 0000"
}
```

### Payment flow: `POST /stripe/create-intent` → `POST /stripe/finalize`

The widget never talks to `/stripe/capture` or anything that moves money — only an admin can do that (see **Admin portal → Reservations** below). The public flow only ever *authorizes*:

```json
// POST /stripe/create-intent
{ "booking_id": "KFB-AB12CD", "amount": "189.50" }
// → { "success": true, "client_secret": "pi_..._secret_...", "payment_intent_id": "pi_..." }
```

The frontend then confirms that `client_secret` directly with Stripe.js (`stripe.confirmCardPayment(...)`), which also handles any 3-D Secure challenge — the raw card number never touches this backend (PCI SAQ-A). Once confirmed:

```json
// POST /stripe/finalize
{ "booking_id": "KFB-AB12CD", "payment_intent_id": "pi_..." }
// → { "success": true, "booking_id": "KFB-AB12CD", "status": "awaiting_approval" }
```

CORS is open by default (`$allowed_origins = ['*']` in `Api.php::__construct`) — lock this down before going live.

---

## 🎨 Customization

| What | Where |
|------|-------|
| Theme colors | CSS vars in `kafeh-booking3.css` (prefixed `--kfb-`) |
| Vehicle list & rates | Admin portal → **Vehicles** (DB-driven via `kfb_vehicles`) |
| Promo codes | Admin portal → **Promo Codes** (DB-driven via `kfb_promo_codes`) |
| Add-on services | Admin portal → **Add-Ons** (DB-driven via `kfb_addons`) |
| Pricing formula | `priceFor(v)` in `kafeh-booking3.js` |
| Meet & Greet fee | Admin portal → **Settings** (global, not per-vehicle — `kfb_settings`) |
| Allowed origins | `Api.php::__construct` → `$allowed_origins` (also `Admin_api.php`) |
| Flight lookups | `backend/application/config/aviationstack.php` (get a free key at aviationstack.com) |
| Email confirmations | `kafeh.php` config + `_send_confirmation()` in `Admin_api.php`, sent when a reservation is **accepted** (not at submission time) |
| Stripe keys | `backend/.env` → `STRIPE_SECRET_KEY` / `STRIPE_PUBLISHABLE_KEY` (see **Secrets & `.env`**) |

---

## 🛡️ Admin portal

Session-based admin portal for managing everything shown in the booking widget.

### Quick start

1. Import the schema + migrations (see **Database migrations** below).
2. Make sure `backend/uploads/vehicles/` is writable by the web server.
3. Create the first admin by opening `/admin/setup` once in your browser and submitting the form. (`php backend/create_admin.php` is documented as a CLI alternative but is currently broken in this environment — CI3's CLI bootstrap treats the argv values as a URI and rejects spaces/punctuation in them; use `/admin/setup` instead.)
4. Open `/admin/login` and sign in.

### Pages

| URL | Purpose |
|-----|---------|
| `/admin` | Dashboard with vehicle stats |
| `/admin/vehicles`, `/vehicles/new`, `/vehicles/:id` | Vehicle list + create/edit |
| `/admin/promos`, `/promos/new`, `/promos/:id` | Promo code list + create/edit |
| `/admin/addons`, `/addons/new`, `/addons/:id` | Add-on service list + create/edit |
| `/admin/settings` | Global settings (Meet & Greet fee) |
| `/admin/reservations`, `/reservations/:id` | Reservation list + detail — accept / reject / charge extra |
| `/admin/api/...` | JSON CRUD backing each of the above (list / save / delete / toggle) — see `routes_admin.php` for the full map |

### Vehicles

Each vehicle row supports: name, code, emoji, description, sort order, enabled/disabled toggle, min/max passengers, luggage capacity, **minimum fare**, and hourly / per-km / surcharge / gratuity / waiting-time rates per region (Chicago / America / Worldwide), plus an uploaded image (JPG/PNG/WEBP, max 5 MB). The Meet & Greet fee used to live here but is now a single global setting — see **Settings** below.

### Promo codes

Percent or fixed-amount discounts with an optional minimum subtotal, max-use cap, and start/expiry date window. `Promo_model::validate()` is called by the widget before payment (`GET /api/promo/validate`); `record_booking_use()` bumps the usage counter when an admin **accepts** the reservation, not at initial submission.

### Add-on services

Configurable extras (Red Carpet, Floral & Balloon Décor, Champagne, Wedding Décor, Premium Child Seat, …), each with flat or percent pricing per region. Seeded by `kfb_migration_v4.sql`.

### Settings

Global key/value config (`kfb_settings`) — currently just the Meet & Greet fee, split by Chicago airports vs. all other airports, applied once per booking regardless of vehicle.

Validation for vehicles/promos/addons/settings is enforced both client-side (`admin.js`) and server-side (`*_model::validate_form()`), so the JSON API is safe to call directly with a valid session cookie.

### Reservations

Every booking is authorized (not charged) when the customer submits it. This is where an admin actually moves money:

- **List** (`/admin/reservations`, filterable by status) and **detail** (`/admin/reservations/:id`) pages, showing trip info, the saved card's brand/last 4, and full payment history.
- **Accept** — captures the held Stripe PaymentIntent, marks the booking `paid`, bumps promo usage, sends the confirmation email. Only available while status is `awaiting_approval`.
- **Reject** — cancels the PaymentIntent (releases the hold, nothing is charged), marks the booking `cancelled`.
- **Charge additional amount** — bills the reservation's saved card again as a new off-session PaymentIntent (e.g. extra waiting time discovered after the trip). Works regardless of status, as long as a card was saved.

A round-trip booking is stored as **two linked rows** (`return_booking_id`) — only the outbound leg carries the amount/payment and appears in the list; the return leg is shown nested on the outbound leg's detail page and has its own status kept in sync automatically.

---

## 🗄️ Database migrations

| File | Adds |
|---|---|
| `kfb_schema.sql` | Base schema: `kfb_bookings`, `kfb_stops`, `kfb_payments`, `kfb_admins`, `kfb_vehicles`, `kfb_promo_codes` |
| `kfb_migration_v3.sql`, `_v3_1.sql`, `_v3_2.sql` | Incremental v3 changes (run in order) |
| `kfb_migration_v4.sql` | `min_fare` + meet & greet pricing on vehicles (later superseded by v6); return-trip, tail-number, e-signature, add-ons, and `customer_id` columns on bookings; new tables `kfb_addons`, `kfb_booking_addons`, `kfb_customers`; seeds default add-ons |
| `kfb_migration_v5.sql` | `kfb_settings` (global key/value config, seeds the Meet & Greet fee); `dropoff_type_detail` / `dropoff_tail_number` on bookings |
| `kfb_migration_v6.sql` | Stripe fields on `kfb_bookings` (`stripe_customer_id`, `stripe_payment_method_id`, `stripe_payment_intent_id`, `card_brand`, `card_last4`, `approved_at`, `approved_by`); widens `status` to add `awaiting_approval`; adds `provider` + `stripe_payment_intent_id` to `kfb_payments` and widens `event` to add `authorize` / `cancel` / `additional_charge` |

All migrations are idempotent (guarded column adds / `CREATE TABLE IF NOT EXISTS` / re-runnable `MODIFY COLUMN`), so they're safe to re-run.

```bash
mysql -u root -p kafeh < backend/sql/kfb_schema.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v3.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v3_1.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v3_2.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v4.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v5.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v6.sql
```

---

## 🔐 Going to production

1. Replace test-mode Stripe keys with **live** ones in `backend/.env` and `test-config.js` / your embed snippet.
2. Replace the Google Maps key with your production key (and restrict it to your domains in GCP).
3. Lock down CORS: change `$allowed_origins` in `Api.php` (and `Admin_api.php`) to a list of client domains.
4. Switch `mail()` in `_send_confirmation()` (`Admin_api.php`) to an SMTP provider (SendGrid, Mailgun, etc.).
5. Set a real `aviationstack_access_key` if flight lookups should be live (otherwise the widget silently falls back to manual entry).
6. Consider adding [Stripe webhooks](https://stripe.com/docs/webhooks) for `payment_intent.payment_failed` / disputes — the admin flow here only reacts to synchronous accept/reject/charge calls, not async events from Stripe.

---

## 🛠 Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Red box: *"setup error: jQuery is required"* | jQuery didn't load before the widget script | Load jQuery **before** `embed/kafeh-booking3.js` |
| "Continue →" does nothing | A required field is empty or invalid | Widget shows a toast with the missing field; check DevTools console |
| Map area is gray, no controls | Google Maps key missing or restricted | Replace `YOUR_GOOGLE_MAPS_KEY`; check GCP API restrictions |
| Card field shows "Payment form failed to load" | Stripe.js didn't load, or `KAFEH_STRIPE_PUBLISHABLE_KEY` is unset/placeholder | Ensure `<script src="https://js.stripe.com/v3/">` loads before Step 4; check the key in `test-config.js` |
| `stripe_create_intent` / `stripe_finalize` return 502 | `STRIPE_SECRET_KEY` missing or invalid | Check `backend/.env` has a real `sk_test_...`/`sk_live_...` key and that `index.php`'s `.env` loader actually found the file |
| Flight lookup always returns unavailable | No `aviationstack_access_key` configured | Set a key in `config/aviationstack.php`, or accept manual entry as the default |
| Network error at payment step | `KAFEH_API` points to a host that isn't running | Edit `test-config.js` to a live URL, or run the CI3 backend |

---

## 📌 Known gaps / ideas for next tasks

- **`test.html` and `test2.html` (widget v1/v2) are now broken** — they still POST to `/api/paypal/create-order` and `/api/paypal/capture-order/:id`, which no longer exist now that Stripe replaced PayPal. Only `test3.html` (v3) was updated. Worth confirming v3 is the one going forward and archiving/removing v1–v2, or porting them to the new Stripe flow if they're still needed.
- **`backend/create_admin.php` is broken** — CI3's CLI bootstrap treats `argv` as a pseudo-URI for its default character-validation pass, so a username/password/display-name containing a space or punctuation (e.g. `'Sup3rSecret!'`, `"Kafeh Admin"` — the exact example in the script's own usage comment) gets rejected with "The URI you submitted has disallowed characters," or with alphanumeric-only args it 404s trying to route `argv[1]/argv[2]` as a controller/method. Use `/admin/setup` instead (only works once, before any admin exists).
- **No Stripe webhook handler** — accept/reject/charge all rely on the admin's synchronous request completing. A card that's disputed, or a capture that fails asynchronously after the admin clicked Accept, won't update the booking's status. Worth adding a `/api/stripe/webhook` endpoint for `payment_intent.payment_failed`, `charge.dispute.created`, etc.
- **Corporate IP tracking is research-only** (`docs/VISITOR_TRACKING_RESEARCH.md`) — no implementation yet; the doc compares MaxMind GeoIP2, IPinfo, and IP2Location.
- **Customer accounts have no login endpoint yet** — `POST /api/customers/register` creates/promotes an account, but there's no matching `POST /api/customers/login`.
