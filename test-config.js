/* ============================================================
   Kafeh Booking — Local test configuration
   ------------------------------------------------------------
   This file sets the two globals the widget reads at boot time.
   ============================================================ */

// Backend API base URL. The widget appends path segments (e.g. "/fleet",
// "/reservation", "/paypal/create-order") to this string, so the value
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

// PayPal client ID. Use "sb" for the PayPal sandbox (no real charges).
// When the backend is deployed, swap this for the live PayPal client ID
// issued for the production merchant account.
window.KAFEH_PAYPAL_CLIENT_ID = "sb";
