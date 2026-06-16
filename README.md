# Kafeh Booking — API + Embed Widget

A drop-in booking widget for any HTML/jQuery website, backed by a **CodeIgniter 3** REST API and **PayPal Sandbox** payments.

---

## 📁 What's inside

```
kafeh-booking/
├── embed/                                 # Drop-in widget (paste into any site)
│   ├── kafeh-booking.html                 # Reference markup (canonical source)
│   ├── kafeh-booking.css                  # Light B&W theme
│   └── kafeh-booking.js                   # jQuery wizard + Google Maps + PayPal
│
├── vendor/                                # Local copies of vendor libs (offline test)
│   └── jquery-3.7.1.min.js
│
├── test.html                              # Local test harness (open this in your browser)
├── test.css                               # Test harness styles
├── test-config.js                         # KAFEH_API + KAFEH_PAYPAL_CLIENT_ID globals
├── test-status.js                         # Tiny status panel filler
│
├── backend/                               # CodeIgniter 3 application
│   ├── application/
│   │   ├── controllers/Api.php            # REST endpoints
│   │   ├── models/Booking_model.php       # DB layer
│   │   ├── libraries/Paypal.php           # PayPal Orders v2 wrapper
│   │   └── config/
│   │       ├── paypal.php                 # PayPal credentials
│   │       ├── kafeh.php                  # Top-level config
│   │       └── routes_kafeh.php           # Add to your routes.php
│   ├── sql/kfb_schema.sql                 # MySQL schema
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
- Loads **Google Maps** from `https://maps.googleapis.com/maps/api/js?key=...&libraries=places&callback=kfbInitMap` — **replace `YOUR_GOOGLE_MAPS_KEY` in `test.html`** with your own key
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

`embed/kafeh-booking.html` is the canonical reference markup. All CSS lives in `embed/kafeh-booking.css` and all JS in `embed/kafeh-booking.js` — **no inline CSS or scripts**.

To drop the widget into a host page, copy the markup from `embed/kafeh-booking.html` (the `<div class="kfb-widget">…</div>` block) into your page, then load the assets in this order:

```html
<!-- 1. Styles -->
<link rel="stylesheet" href="/path/to/embed/kafeh-booking.css">

<!-- 2. Widget markup (paste from embed/kafeh-booking.html) -->
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

<!-- 5. Google Maps LAST so its kfbInitMap callback finds the widget ready -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_KEY&libraries=places&callback=kfbInitMap" async defer></script>

<!-- 6. Widget script (after jQuery + Google Maps) -->
<script src="/path/to/embed/kafeh-booking.js"></script>
```

**Required load order:** jQuery → PayPal SDK → widget JS → Google Maps. The widget polls for jQuery and replays the Google Maps callback if either loads out of order, but the order above is the safe canonical layout.

**The widget is a drop-in, but the host page is responsible for:**

- Loading jQuery
- Loading the PayPal SDK with a valid `client-id`
- Loading Google Maps with a valid `key` and `&libraries=places&callback=kfbInitMap`
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
| Vehicle list | `Booking_model::get_fleet()` in PHP, `state.fleet` in JS |
| Pricing formula | `priceFor(v)` in `kafeh-booking.js` |
| Allowed origins | `Api.php::__construct` → `$allowed_origins` |
| Email confirmations | `kafeh.php` config + `_send_confirmation()` in controller |

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
