/* ============================================================
   Booking API — Local test configuration
   ------------------------------------------------------------
   This file sets the globals the widget reads at boot time.
   ============================================================ */

// Backend API base URL. The widget appends path segments (e.g. "/fleet",
// "/reservation", "/paypal/authorize") to this string, so the value
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

// No client-side payment key is needed — the widget just redirects the
// browser to the approval URL the backend returns. The backend talks to
// PayPal's Orders v2 REST API server-to-server (see backend/.env for the
// PayPal Client ID / Secret, which only ever live server-side).
