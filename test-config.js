/* ============================================================
   Kafeh Booking — Local test configuration
   ------------------------------------------------------------
   This file sets the two globals the widget reads at boot time.
   All references are to local files (file:// / relative paths)
   until the backend is deployed. When the backend goes live,
   update KAFEH_API to the real API base URL.
   ============================================================ */

// Backend API base URL.
//   - Local dev:  "http://localhost/kafeh-api"
//   - Staging:    "https://staging-api.kafeh.com"
//   - Production: "https://api.kafeh.com"
window.KAFEH_API = "http://localhost/kafeh-api";

// PayPal client ID. Use "sb" for the PayPal sandbox (no real charges).
// When the backend is deployed, swap this for the live PayPal client ID
// issued for the production merchant account.
window.KAFEH_PAYPAL_CLIENT_ID = "sb";
