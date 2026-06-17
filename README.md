# Kafeh Booking — API + Embed Widget

A drop-in booking widget for any HTML/jQuery website, backed by a **CodeIgniter 3** REST API and **PayPal Sandbox** payments.

---

## 📁 What's inside

```
kafeh-booking/
├── embed/                                 # Drop-in widget (paste into any site)
│   ├── kafeh-booking.css                  # Light B&W theme
│   └── kafeh-booking.js                   # jQuery wizard (stepper, validation, PayPal)
│
├── vendor/                                # Local copies of vendor libs (offline test)
│   └── jquery-3.7.1.min.js
│
├── test.html                              # Local test harness — widget markup + map
├── test.css                               # Test harness styles
├── test-config.js                         # KAFEH_API + KAFEH_PAYPAL_CLIENT_ID globals
├── test-status.js                         # Tiny status panel filler
├── test-map.js                            # Google Maps controller (markers, route, km/min)
│
├── backend/                               # CodeIgniter 3 application
│   ├── application/
│   │   ├── controllers/
│   │   │   ├── Api.php                    # Public REST endpoints
│   │   │   ├── Auth.php                   # Admin login / logout / setup
│   │   │   ├── Admin.php                  # Admin pages (dashboard, vehicles)
│   │   │   └── Admin_api.php              # Admin CRUD JSON endpoints
│   │   ├── models/
│   │   │   ├── Booking_model.php          # Bookings / stops / payments
│   │   │   ├── Admin_model.php            # Admin login (bcrypt)
│   │   │   └── Vehicle_model.php          # Vehicle CRUD + image upload
│   │   ├── libraries/Paypal.php           # PayPal Orders v2 wrapper
│   │   ├── views/admin/                   # Admin portal UI
│   │   └── config/
│   │       ├── paypal.php                 # PayPal credentials
│   │       ├── kafeh.php                  # Top-level config
│   │       ├── routes_kafeh.php           # Public REST routes
│   │       └── routes_admin.php           # Admin portal routes
│   ├── assets/admin/                      # Admin CSS + JS
│   ├── uploads/vehicles/                  # Uploaded vehicle images
│   ├── sql/kfb_schema.sql                 # MySQL schema
│   ├── create_admin.php                   # CLI: create an admin user
│   └── .env.example
│
├── .env.example
└── README.md
```

---

## 🚀 Quick start (local testing)

### 1. Open the test page

Just open `test.html` in your browser. The page is wired up the same way a production host page would be:

- Loads **jQuery** from `vendor/jquery-3.7.1.min.js` (local copy, no internet needed for this)
- Loads **PayPal SDK** from `https://www.paypal.com/sdk/js?client-id=sb&...` (sandbox, free)
- Loads **Google Maps** from `https://maps.googleapis.com/maps/api/js?key=...&libraries=places&callback=kfbTestInitMap` — **replace `YOUR_GOOGLE_MAPS_KEY` in `test.html`** with your own key
- Sets `KAFEH_API` and `KAFEH_PAYPAL_CLIENT_ID` via `test-config.js`

No build step, no server required. Works on `file://` as long as your browser allows CDN scripts (most do).

> **Heads-up:** the Google Maps API does **not** work over `file://` in some browsers (it refuses to serve the script to a `file://` page). If the map doesn't initialize, run a quick local server:
>
> ```bash
> # from the project root
> python -m http.server 8000
> # then visit http://localhost:8000/test.html
> ```

### 2. Wire it up to the backend (when you have one running)

Edit `test-config.js` and change `KAFEH_API` to your backend URL:

```js
// Local dev (CodeIgniter served by XAMPP / WAMP / php -S):
window.KAFEH_API = "http://localhost/kafeh-api";

// Later, when deployed:
window.KAFEH_API = "https://api.kafeh.com";
```

That's the only line that needs to change between local and production.

### 3. Backend (CodeIgniter 3) — when you're ready

1. Copy `backend/application/` files into your CI3 project's `application/` directory.
2. Append `backend/application/config/routes_kafeh.php` content to your `application/config/routes.php`.
3. Import the schema: `mysql -u root -p < backend/sql/kfb_schema.sql`
4. Set DB creds in CI3's `application/config/database.php`.
5. Set PayPal sandbox keys in `application/config/paypal.php` (or as env vars).
6. Test: `curl http://localhost/kafeh-api/api/health`

---

## 🧩 Embedding the widget in a production site

> The canonical reference markup for the widget is currently living in **`test.html`** (the `<div class="kfb-widget">…</div>` block — lines 55 onwards). The `embed/` folder only holds the CSS and the wizard JS. When you're ready to split the widget HTML out into its own file, copy that block into `embed/kafeh-booking.html` and reference it from your host pages.

All CSS lives in `embed/kafeh-booking.css` and all wizard JS in `embed/kafeh-booking.js` — **no inline CSS or scripts**.

To drop the widget into a host page, copy the markup from `test.html` (the `<div class="kfb-widget">…</div>` block) into your page, then load the assets in this order:

```html
<!-- 1. Styles -->
<link rel="stylesheet" href="/path/to/embed/kafeh-booking.css">

<!-- 2. Widget markup (paste from test.html's <div class="kfb-widget"> block) -->
<div class="kfb-widget" id="kafehBookingWidget">
  …
</div>

<!-- 3. Required vendor scripts (BEFORE the widget JS) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://www.paypal.com/sdk/js?client-id=YOUR_CLIENT_ID&currency=USD&intent=capture"></script>

<!-- 4. Globals the widget reads at boot time -->
<script>
  window.KAFEH_API              = "https://api.kafeh.com";
  window.KAFEH_PAYPAL_CLIENT_ID = "YOUR_LIVE_PAYPAL_CLIENT_ID";
</script>

<!-- 5. Google Maps LAST, with the same callback name the test harness uses -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_KEY&libraries=places&callback=kfbTestInitMap" async defer></script>

<!-- 6. Widget script (after jQuery + Google Maps) -->
<script src="/path/to/embed/kafeh-booking.js"></script>
```

**Required load order:** jQuery → PayPal SDK → widget JS → Google Maps. The widget polls for jQuery and replays the Google Maps callback if either loads out of order, but the order above is the safe canonical layout.

**The widget is a drop-in, but the host page is responsible for:**

- Loading jQuery
- Loading the PayPal SDK with a valid `client-id`
- Loading Google Maps with a valid `key` and `&libraries=places&callback=kfbTestInitMap`
- Setting `KAFEH_API` and `KAFEH_PAYPAL_CLIENT_ID`

---

## 🔌 REST API reference

Base URL: `https://YOUR-API-HOST/api`

### `GET /health`
Health check.

```json
{ "ok": true, "env": "sandbox", "time": "2026-06-16T...", "php": "8.x" }
```

### `GET /fleet`
Returns the available vehicle list.

### `POST /reservation`
Create a booking (returns `booking_id`, status `pending`).

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

### `GET /reservation/:id`
Fetch a booking with stops + payment history.

### `POST /paypal/create-order`
Creates a PayPal Sandbox order.

```json
{ "amount": "189.50", "bookingId": "KFB-AB12CD" }
```

Response: `{ "id": "5O190127TN364715T", "status": "CREATED" }`

### `POST /paypal/capture-order/:orderId`
Captures a previously created order. Marks booking as `paid` on success.

Response:
```json
{ "success": true, "status": "COMPLETED", "bookingId": "KFB-AB12CD" }
```

---

## 🎨 Customization

| What | Where |
|------|-------|
| Theme colors | CSS vars in `kafeh-booking.css` (prefixed `--kfb-`) |
| Vehicle list | Admin portal → **Vehicles** (DB-driven via `kfb_vehicles`) |
| Pricing formula | `priceFor(v)` in `kafeh-booking.js` |
| Allowed origins | `Api.php::__construct` → `$allowed_origins` |
| Email confirmations | `kafeh.php` config + `_send_confirmation()` in controller |

---

## 🛡️ Admin portal (vehicle management)

The backend ships with a session-based admin portal for managing the fleet shown in step 2 of the booking widget.

### Quick start

1. Import the schema (creates `kfb_admins` and `kfb_vehicles`):

   ```bash
   mysql -u root -p kafeh < backend/sql/kfb_schema.sql
   ```

2. Make sure `backend/uploads/vehicles/` is writable by the web server.

3. Create the first admin — pick **one** of the two paths:

   ```bash
   # CLI (from inside backend/)
   php create_admin.php admin 'Sup3rSecret!' "Kafeh Admin" admin@kafeh.com
   ```

   **OR** open the URL `/admin/setup` once in your browser and submit the form.

4. Open `/admin/login` and sign in.

### Pages

| URL | Purpose |
|-----|---------|
| `/admin` | Dashboard with vehicle stats |
| `/admin/vehicles` | List + create / edit forms |
| `/admin/vehicles/:id` | Direct link to edit a specific vehicle |
| `/admin/api/vehicles` | JSON list / create / update / delete / toggle |
| `/admin/api/me` | Current logged-in admin (for AJAX) |

### Vehicle fields

Each vehicle row supports:

- **General** — name, code (slug used by the widget), emoji, description, sort order, enabled/disabled toggle
- **Passenger limits** — minimum and maximum passengers, luggage capacity
- **Hourly rates** — one column per region (Chicago / America / Worldwide)
- **Per-kilometer rates** — same three regions
- **Surcharges** — same three regions
- **Gratuity** — same three regions
- **Waiting time (per minute)** — same three regions
- **Image** — uploaded JPG / JPEG / PNG / WEBP, max 5 MB, with live preview

### Validation

All rates must be positive numerics. `max_passengers` must be ≥ `min_passengers`. Image uploads are restricted to the allowed MIME types and size cap. The same rules are enforced both in the AJAX client (`admin.js`) and the server (`Vehicle_model::validate()`), so the API is safe to call directly.

### JSON API (admin)

```bash
# List (returns every row, enabled + disabled)
curl -b cookies.txt http://localhost/kafeh-api/admin/api/vehicles

# Create
curl -b cookies.txt -F "name=Luxury Sedan" -F "min_passengers=1" \
     -F "max_passengers=3" -F "hourly_chicago=95" \
     -F "image=@/path/to/photo.jpg" \
     http://localhost/kafeh-api/admin/api/vehicles

# Toggle enabled/disabled
curl -b cookies.txt -X POST \
     http://localhost/kafeh-api/admin/api/vehicles/4/toggle
```

All admin endpoints require an authenticated session (cookie-based).

---

## 🔐 Going to production

1. Replace sandbox PayPal keys with **live** ones (`PAYPAL_ENV=live`).
2. Replace `client-id=sb` in your embed snippet with your **live** client ID.
3. Replace the Google Maps key with your production key (and restrict it to your domains in GCP).
4. Lock down CORS: change `$allowed_origins` to a list of client domains.
5. Switch `mail()` in `_send_confirmation()` to an SMTP provider (SendGrid, Mailgun, etc.).

---

## 🧪 Testing checklist

- [ ] `test.html` opens and the widget renders (Step 1 visible)
- [ ] Filling required fields and clicking **Continue →** advances to Step 2
- [ ] Google Map loads (requires replacing `YOUR_GOOGLE_MAPS_KEY` in `test.html`)
- [ ] Pickup + drop-off autocomplete dropdowns appear as you type
- [ ] **+ Add Stop** adds a stop row with a numbered badge and a working × button
- [ ] "Return at a different location" toggles the drop-off field
- [ ] Step 2 vehicle cards sort by price / capacity
- [ ] Step 3 form rejects if terms checkbox is unchecked
- [ ] Step 4 PayPal button renders (requires a valid PayPal client-id)
- [ ] Schema imported; `GET /api/health` returns `ok: true`
- [ ] Booking created in DB after a successful test payment
- [ ] Confirmation email arrives (or SMTP works)
- [ ] CORS allows your client domain only

---

## 🛠 Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Red box on page: *"setup error: jQuery is required"* | jQuery didn't load before the widget script | Make sure `<script src="…jquery…"></script>` comes **before** `embed/kafeh-booking.js` |
| "Continue →" does nothing | A required field is empty or invalid | The widget shows a toast with the missing field. Open DevTools console for the full validation log |
| Map area is gray, no controls | Google Maps key missing or restricted | Replace `YOUR_GOOGLE_MAPS_KEY` in `test.html`; check GCP API restrictions |
| PayPal button says "PayPal SDK not loaded" | `paypal` global not in scope | Ensure `<script src="https://www.paypal.com/sdk/js?client-id=…"></script>` is loaded before reaching Step 4 |
| Network error in Step 4 | `KAFEH_API` points to a host that isn't running | Edit `test-config.js` to a live URL (or run the CI3 backend) |
