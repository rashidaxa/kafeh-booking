# Kafeh Booking — API + Embed Widget

A drop-in chauffeur/limousine booking widget for any HTML/jQuery website, backed by a **CodeIgniter 3** REST API, MySQL, and **PayPal Sandbox** payments. The backend also ships a session-based **admin portal** for managing the fleet, promo codes, and add-on services.

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
├── test.css                               # Shared test harness styles
├── test-config.js                         # KAFEH_API + KAFEH_PAYPAL_CLIENT_ID globals
├── test-status.js                         # Tiny status panel filler
├── test-map.js                            # Google Maps controller (markers, route, km/min)
│
├── backend/                               # CodeIgniter 3 application
│   ├── application/
│   │   ├── controllers/
│   │   │   ├── Api.php                    # Public REST endpoints
│   │   │   ├── Auth.php                   # Admin login / logout / setup
│   │   │   ├── Admin.php                  # Admin pages (dashboard, vehicles, promos, add-ons)
│   │   │   └── Admin_api.php              # Admin CRUD JSON endpoints
│   │   ├── models/
│   │   │   ├── Booking_model.php          # Bookings / stops / payments / signatures
│   │   │   ├── Admin_model.php            # Admin login (bcrypt)
│   │   │   ├── Vehicle_model.php          # Vehicle CRUD + image upload + rates
│   │   │   ├── Promo_model.php            # Promo code CRUD + validation/discount math
│   │   │   ├── Addon_model.php            # Add-on services catalog (Red Carpet, Floral, …)
│   │   │   └── Customer_model.php         # Guest → account customer records
│   │   ├── libraries/
│   │   │   ├── Paypal.php                 # PayPal Orders v2 wrapper
│   │   │   └── Flights.php                # Aviationstack flight-lookup wrapper
│   │   ├── views/admin/                   # Admin portal UI (dashboard, vehicles, promos, addons)
│   │   └── config/
│   │       ├── paypal.php                 # PayPal credentials
│   │       ├── kafeh.php                  # Top-level config
│   │       ├── aviationstack.php          # Flight-lookup API key/config
│   │       ├── routes_kafeh.php           # Public REST routes
│   │       └── routes_admin.php           # Admin portal routes
│   ├── assets/admin/                      # Admin CSS + JS
│   ├── uploads/vehicles/                  # Uploaded vehicle images
│   ├── sql/
│   │   ├── kfb_schema.sql                 # Base MySQL schema (fresh install)
│   │   └── kfb_migration_v3*.sql, v4.sql  # Incremental migrations (see below)
│   ├── create_admin.php                   # CLI: create an admin user
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
- Loads **PayPal SDK** from `https://www.paypal.com/sdk/js?client-id=sb&...` (sandbox, free)
- Loads **Google Maps** — replace `YOUR_GOOGLE_MAPS_KEY` with your own key
- Sets `KAFEH_API` and `KAFEH_PAYPAL_CLIENT_ID` via `test-config.js`

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
5. Set PayPal sandbox keys in `backend/application/config/paypal.php` (or env vars).
6. (Optional) Set a flight-lookup API key in `backend/application/config/aviationstack.php`.
7. Test: `curl http://localhost/booking/backend/api/health`

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
<script src="https://www.paypal.com/sdk/js?client-id=YOUR_CLIENT_ID&currency=USD&intent=capture"></script>

<!-- 4. Globals the widget reads at boot time -->
<script>
  window.KAFEH_API              = "https://api.kafeh.com";
  window.KAFEH_PAYPAL_CLIENT_ID = "YOUR_LIVE_PAYPAL_CLIENT_ID";
</script>

<!-- 5. Google Maps LAST -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_KEY&libraries=places&callback=kfbTestInitMap" async defer></script>

<!-- 6. Widget script (after jQuery + Google Maps) -->
<script src="/path/to/embed/kafeh-booking3.js"></script>
```

**Required load order:** jQuery → PayPal SDK → widget JS → Google Maps.

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
| POST | `/paypal/create-order` | Create a PayPal Sandbox order (`{ amount, bookingId }`) |
| POST | `/paypal/capture-order/:orderId` | Capture a previously created order; marks booking `paid` |
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

### `POST /paypal/create-order` → `POST /paypal/capture-order/:orderId`

```json
{ "amount": "189.50", "bookingId": "KFB-AB12CD" }
```
Capture response: `{ "success": true, "status": "COMPLETED", "bookingId": "KFB-AB12CD" }`

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
| Allowed origins | `Api.php::__construct` → `$allowed_origins` |
| Flight lookups | `backend/application/config/aviationstack.php` (get a free key at aviationstack.com) |
| Email confirmations | `kafeh.php` config + `_send_confirmation()` in `Api.php` |

---

## 🛡️ Admin portal

Session-based admin portal for managing everything shown in the booking widget.

### Quick start

1. Import the schema + migrations (see **Database migrations** below).
2. Make sure `backend/uploads/vehicles/` is writable by the web server.
3. Create the first admin — pick **one**:
   ```bash
   php backend/create_admin.php admin 'Sup3rSecret!' "Kafeh Admin" admin@kafeh.com
   ```
   **OR** open `/admin/setup` once in your browser and submit the form.
4. Open `/admin/login` and sign in.

### Pages

| URL | Purpose |
|-----|---------|
| `/admin` | Dashboard with vehicle stats |
| `/admin/vehicles`, `/vehicles/new`, `/vehicles/:id` | Vehicle list + create/edit |
| `/admin/promos`, `/promos/new`, `/promos/:id` | Promo code list + create/edit |
| `/admin/addons`, `/addons/new`, `/addons/:id` | Add-on service list + create/edit |
| `/admin/api/...` | JSON CRUD backing each of the above (list / save / delete / toggle) — see `routes_admin.php` for the full map |

### Vehicles

Each vehicle row supports: name, code, emoji, description, sort order, enabled/disabled toggle, min/max passengers, luggage capacity, **minimum fare**, hourly / per-km / surcharge / gratuity / waiting-time rates per region (Chicago / America / Worldwide), **meet & greet fees** (Chicago vs. elsewhere), and an uploaded image (JPG/PNG/WEBP, max 5 MB).

### Promo codes

Percent or fixed-amount discounts with an optional minimum subtotal, max-use cap, and start/expiry date window. `Promo_model::validate()` is called by the widget before payment (`GET /api/promo/validate`) and `record_booking_use()` bumps the usage counter after a successful capture.

### Add-on services

Configurable extras (Red Carpet, Floral & Balloon Décor, Champagne, Wedding Décor, Premium Child Seat, …), each with flat or percent pricing per region. Seeded by `kfb_migration_v4.sql`.

Validation for all three (vehicles/promos/addons) is enforced both client-side (`admin.js`) and server-side (`*_model::validate_form()`), so the JSON API is safe to call directly with a valid session cookie.

---

## 🗄️ Database migrations

| File | Adds |
|---|---|
| `kfb_schema.sql` | Base schema: `kfb_bookings`, `kfb_stops`, `kfb_payments`, `kfb_admins`, `kfb_vehicles`, `kfb_promo_codes` |
| `kfb_migration_v3.sql`, `_v3_1.sql`, `_v3_2.sql` | Incremental v3 changes (run in order) |
| `kfb_migration_v4.sql` | `min_fare` + meet & greet pricing on vehicles; return-trip, tail-number, e-signature, add-ons, and `customer_id` columns on bookings; new tables `kfb_addons`, `kfb_booking_addons`, `kfb_customers`; seeds default add-ons |

All migrations are idempotent (guarded column adds / `CREATE TABLE IF NOT EXISTS`), so they're safe to re-run.

```bash
mysql -u root -p kafeh < backend/sql/kfb_schema.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v3.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v3_1.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v3_2.sql
mysql -u root -p kafeh < backend/sql/kfb_migration_v4.sql
```

---

## 🔐 Going to production

1. Replace sandbox PayPal keys with **live** ones.
2. Replace `client-id=sb` in your embed snippet with your **live** client ID.
3. Replace the Google Maps key with your production key (and restrict it to your domains in GCP).
4. Lock down CORS: change `$allowed_origins` in `Api.php` to a list of client domains.
5. Switch `mail()` in `_send_confirmation()` to an SMTP provider (SendGrid, Mailgun, etc.).
6. Set a real `aviationstack_access_key` if flight lookups should be live (otherwise the widget silently falls back to manual entry).

---

## 🛠 Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Red box: *"setup error: jQuery is required"* | jQuery didn't load before the widget script | Load jQuery **before** `embed/kafeh-booking3.js` |
| "Continue →" does nothing | A required field is empty or invalid | Widget shows a toast with the missing field; check DevTools console |
| Map area is gray, no controls | Google Maps key missing or restricted | Replace `YOUR_GOOGLE_MAPS_KEY`; check GCP API restrictions |
| PayPal button says "PayPal SDK not loaded" | `paypal` global not in scope | Ensure the PayPal SDK script loads before Step 4 |
| Flight lookup always returns unavailable | No `aviationstack_access_key` configured | Set a key in `config/aviationstack.php`, or accept manual entry as the default |
| Network error at payment step | `KAFEH_API` points to a host that isn't running | Edit `test-config.js` to a live URL, or run the CI3 backend |

---

## 📌 Known gaps / ideas for next tasks

- **`Api::reservation_create()` references an undefined `$bookingId`** when linking a new booking to a customer record (`backend/application/controllers/Api.php`, the variable created earlier in the method is `$booking_id`) — the customer auto-link silently fails today.
- **Three widget versions coexist** (`embed/kafeh-booking{,2,3}.js`) with three matching test harnesses. Worth confirming `v3` is the one going forward and archiving/removing v1–v2 once confirmed.
- **Corporate IP tracking is research-only** (`docs/VISITOR_TRACKING_RESEARCH.md`) — no implementation yet; the doc compares MaxMind GeoIP2, IPinfo, and IP2Location.
- **Customer accounts have no login endpoint yet** — `POST /api/customers/register` creates/promotes an account, but there's no matching `POST /api/customers/login`.
- **Return-trip fields exist in the schema** (`is_return_trip`, `return_booking_id`, `return_date`, `return_time`) but no controller/model logic currently reads or writes them — the widget doesn't yet expose a return-trip flow.
- **PayPal is still the only payment method** — no alternative gateway is wired up yet.
