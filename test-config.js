/* ============================================================
   Kafeh Booking — Local test configuration
   ------------------------------------------------------------
   This file sets the two globals the widget reads at boot time.
   ============================================================ */

// Backend API base URL. The widget appends path segments (e.g. "/fleet",
// "/reservation", "/stripe/create-intent") to this string, so the value
// must END at the API root — i.e. include the "/api" segment.
//
//   - WAMP (this repo at C:\wamp64\www\booking\):
//       "http://localhost/booking/backend/api"
//   - Other local dev (CI served at /kafeh-api):
//       "http://localhost/kafeh-api"
//   - Staging:    "https://staging-api.kafeh.com"
//   - Production: "https://api.kafeh.com"
window.KAFEH_API = "http://localhost/booking/backend/api";

// Uploads directory that holds the vehicle images. The widget appends
// `v.image` to this URL when rendering the vehicle card. Keep it in
// sync with the directory served by your backend.
window.KAFEH_UPLOADS = "http://localhost/booking/backend/uploads/vehicles/";

// Stripe PUBLISHABLE key — safe to expose client-side (unlike the secret
// key, which only ever lives server-side in backend/.env). This is a
// test-mode key; swap for the live publishable key in production.
window.KAFEH_STRIPE_PUBLISHABLE_KEY = "pk_test_51U3J4CFYCaJMPL4jSGwInMT5E44BKMKkCLUVw7yH556AuQvQswVdgUMCfIRnGWayQ0frtnqFXp9O31Nn871IErCz00MDpBMZUE";
