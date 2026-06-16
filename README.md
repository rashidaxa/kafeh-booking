# Kafeh Booking — API + Embed Widget

A drop-in booking widget for any HTML/jQuery website, backed by a **CodeIgniter 3** REST API and **PayPal Sandbox** payments.

---

## 📁 What's inside

```
kafeh-booking/
├── embed/                                 # Drop-in widget (paste into any site)
│   ├── kafeh-booking.html                 # Reference markup
│   ├── kafeh-booking.css                  # Light B&W theme
│   ├── kafeh-booking.js                   # jQuery wizard + Google Maps + PayPal
│   └── demo.html                          # Standalone preview
│
└── backend/                               # CodeIgniter 3 application
    ├── application/
    │   ├── controllers/Api.php            # REST endpoints
    │   ├── models/Booking_model.php       # DB layer
    │   ├── libraries/Paypal.php           # PayPal Orders v2 wrapper
    │   └── config/
    │       ├── paypal.php                 # PayPal credentials
    │       ├── kafeh.php                  # Top-level config
    │       └── routes_kafeh.php           # Add to your routes.php
    ├── sql/kfb_schema.sql                 # MySQL schema
    └── .env.example
```

---

## 🚀 Quick start

### 1. Backend (CodeIgniter 3)

1. Copy `backend/application/` files into your CI3 project's `application/` directory.
2. Append `backend/application/config/routes_kafeh.php` content to your `application/config/routes.php`.
3. Import the schema: `mysql -u root -p < backend/sql/kfb_schema.sql`
4. Set DB creds in CI3's `application/config/database.php`.
5. Set PayPal sandbox keys in `application/config/paypal.php` (or as env vars).
6. Test: `curl http://localhost/kafeh-api/api/health`

### 2. Embed widget on any site

Paste this anywhere inside `<body>`:

```html
<link rel="stylesheet" href="https://YOUR-API-HOST/embed/kafeh-booking.css">
<div id="kafeh-booking"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://www.paypal.com/sdk/js?client-id=sb&currency=USD&intent=capture"></script>
<script>
  window.KAFEH_API = "https://YOUR-API-HOST/api";
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_KEY&libraries=places"></script>
<script src="https://YOUR-API-HOST/embed/kafeh-booking.js"></script>
```

**That's it.** The widget renders the 4-step wizard, hooks Google Maps for pickup/drop-off autocomplete, and charges via PayPal Sandbox.

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
| Theme colors | `:root` vars in `kafeh-booking.css` (prefixed `--kfb-`) |
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

- [ ] Schema imported; `GET /api/health` returns `ok: true`
- [ ] Sandbox PayPal business + personal accounts created
- [ ] Booking created in DB after a successful test payment
- [ ] Confirmation email arrives (or SMTP works)
- [ ] CORS allows your client domain only
