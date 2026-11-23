# Admin & Maintenance Guide — /works (legacy system) and /booking (new system)

Written for the business owner. It explains where each setting lives, what to click, what to change, how to save and how to check that it worked.

**How this guide was prepared**

- It is based on the actual code of both systems: **/works** = the `bookingOld` project (the latest legacy code, including the SMS and reminder system), **/booking** = the new booking system.
- **Screenshots** could not be produced from the code. Every step names the exact menu, button, field or file instead. You can add screenshots next to the steps after you try them once.
- Two kinds of task exist: ones done **inside the admin screens** (safe, no code) and ones that need a **file edited on the server** (see the next section). If you are not comfortable editing files, ask your developer for those items only.
- Web addresses below use `https://grounddispatch.com/works/index.php` for /works and `https://grounddispatch.com/booking/backend/admin` for the /booking admin. Use the address you normally log in with if it differs.

---

## Before you edit any file on the server

The "edit a file" steps use your hosting control panel (cPanel):

1. Log in to cPanel → **File Manager**.
2. Open `public_html/works/` (legacy) or `public_html/booking/` (new).
3. **Copy before editing:** right-click the file → **Copy** → name it like `sms.php.bak-2026-11-24`.
4. Right-click the original → **Edit** (accept the encoding prompt) → make the change → **Save Changes**.
5. Test (each section says how). If something breaks, delete the edited file and rename the `.bak` copy back to the original name.

Only change what you are told to. Keep the quote marks (`'like this'`) and the semicolon `;` at the end of each line exactly as they are.

---

## 1. Admin password

### /works (legacy system)

Admin accounts are managed on one screen, available to administrators only:

1. Log in to /works with an admin account.
2. Left menu → **Setup → Users**. The page opens on the tab **Add Update User**.
3. In the table, find the user (columns: Sr#, Username, First Name, Last Name) and click the blue **pencil (edit)** button on that row. The user's details load into the form above.
4. Type the new value in the **Password** box (the form also has Username, First Name and Last Name).
5. Click **Save F10** (top or bottom of the form).
6. **Verify:** log out (top-right menu → logout), then log in with the new password.

Notes:

- The **red trash** button on the same row deletes the user — be careful.
- This screen shows passwords as plain text and stores them readable. Anyone who opens Setup → Users can read every password. Only give admin access to people you trust, and ask your developer to switch the system to encrypted passwords.
- The code also has a "My profile" page (`/works/index.php/user/updateProfile`) where a logged-in user changes their own name, email, mobile and password (it asks for the current password). I could not find a menu link for it, so use Setup → Users unless your developer confirms the link.

### /booking (new system)

- Admin login page: `https://grounddispatch.com/booking/backend/admin/login`.
- **There is currently no "Change password" screen in the /booking admin.** The only admin-account screen is `/admin/setup`, which works once, before any admin exists.
- Customer accounts (not admins) can be managed in **Admin → Users → click a user → Set password / Send reset link**.
- Changing an **admin** password in /booking today needs a developer to update it in the database. **Recommendation:** ask your developer to add a small "My account — change email / password" page to the /booking admin.

---

## 2. Email address and password changes

"Email and password" can mean three different things. All are covered.

### A. The mailbox the systems send mail from (confirmations, receipts, reminders, verification codes, password resets)

Both systems send through the same mailbox on your mail server (`mail.grounddispatch.com`). Each system has its own copy of the login in a config file, so a change must be made in **both**.

**/works** — file `public_html/works/application/config/constants.php` (near line 33):

```php
defined('SMTP_EMAIL')    OR define('SMTP_EMAIL',    '...');   // From address AND login
defined('SMTP_PASSWORD') OR define('SMTP_PASSWORD', '...');
defined('EMAIL_HOST')    OR define('EMAIL_HOST',    'mail.grounddispatch.com');
```

Below these there are three commented-out lines (they start with `//`) from an old Gmail setup. Leave them alone.

**/booking** — file `public_html/booking/backend/application/config/crmsync.php` (near line 31):

```php
$config['smtp_host']     = 'mail.grounddispatch.com';
$config['smtp_email']    = '...';   // From address AND login
$config['smtp_password'] = '...';
$config['smtp_port']     = 465;
$config['smtp_secure']   = 'ssl';
```

Steps:

1. In cPanel → **Email Accounts**, create or choose the mailbox (note the full address and its password).
2. Edit **both** files as described above: put the same address and password in `SMTP_EMAIL` / `SMTP_PASSWORD` (/works) and `smtp_email` / `smtp_password` (/booking).
3. Only change the host/port/security if you move to another mail provider (the provider gives you the values). In /works the port (465) and security (ssl) are written inside the sending code, so a provider with different settings needs a developer.
4. **Verify /works:** in /works, right-click a reservation in the dashboard → **Send Confirmation** to your own email.
5. **Verify /booking:** make a test reservation and accept it, or use "Forgot password" in the widget, and check that the email arrives with the new From address.

> **Important:** if you change the mailbox password in cPanel, update it in **both** files, otherwise emails stop sending (and nobody is told).

Also in /booking `crmsync.php`: `admin_notification_email` — the inbox that receives the "new reservation" alerts.

### B. Contact, reply-to and notification emails in /booking (no file editing)

**/booking → Admin → Companies → click the company → "Branding & contact":**

- **Contact email** — shown in the email footer and used as Reply-To.
- **Notification email** — receives that company's new-reservation emails.
- Also here: logo, address and phone numbers shown on emails and receipts.

Click **Save**. **Verify:** make a test reservation and read the footer and Reply-To of the email.

### C. Your own login email / username

- **/works:** Setup → Users (section 1) — the form edits username and name; the email field is part of the user profile (My profile page, if your developer enables a link).
- **/booking:** not editable today (see section 1; ask for the "My account" page).

### D. The secret key shared between /booking and /works

When an admin accepts a reservation in /booking, it is pushed into /works using a secret key. The same key must be in both files:

- /booking: `backend/application/config/crmsync.php` → `legacy_sync_api_key`
- /works: `application/config/sync.php` → `reservation_sync_api_key`

If you ever change one, change the other to exactly the same text, or accepted reservations will stop appearing in /works. **Verify:** accept a test reservation in /booking, then check /works.

---

## 3. Email templates — /works (legacy)

Templates are files in `public_html/works/application/views/`. In /works, **right-click a reservation on the dashboard** to send or download each one: **Send Confirmation**, **Send Receipt**, **Send Aff. Trp Tick** (and **Download Confirmation / Receipt / Affiliate TT**).

| Email | Template file | Subject line (in `application/controllers/`) |
|---|---|---|
| Reservation Confirmation | `views/confirmation_email.php` | `sendpdf.php`: "Booking … Confirmed" |
| Itemized Trip Receipt | `views/receipt.php` | `sendpdf.php`: "Itemized Trip Receipt" |
| Affiliate Trip Ticket | `views/affiliate_trip.php` | `sendpdf.php`: "Affiliate Trip Ticket" |
| Customer Email Reminder | `views/reminder_24h.php` | `notify.php`: "A1 Limousines \| 24-Hour Reservation Reminder \| date" |

Notes:

- `confirmation_email.php` is a copy of the /booking confirmation template. If you change the wording in one system and want both emails to match, make the same change in the other.
- The files with a leading underscore (`_receipt.php`, `_sendconfirmation.php`, `_affiliate_trip.php`) and `sendconfirmation.php` are older copies — the system does not use them for these emails.
- **How to edit:** follow "Before you edit any file", then change only the visible words between the tags (for example `<p>Thank you for choosing …</p>`). Do not change anything that looks like `<?= $something ?>` or `<?php … ?>` — those insert the customer's data.
- **The reminder template is different:** it uses fill-in words in square brackets which are replaced when the email is sent. Keep them exactly as written:
  `[Pick-Up Date]`, `[Pick-Up Time]`, `[Drop-Off Time]`, `[Select Trip Type]`, `[Passenger Name]`, `[Phone Number]`, `[Vehicle Type]`, `[Number of Passengers]`, `[Pick-Up Address]`, `[Stops]`, `[Drop-Off Address]`.
  The file is called "24h" but when it is sent is set in **Setup → Reminder Settings** (section 5).
- Subject lines are in the controller files named in the table (search for the word `Subject`); change only the text between the quote marks.

**Verify:** right-click a test reservation → send the email to yourself (for the reminder, see section 5).

---

## 4. Email templates — /booking

Templates are files in `public_html/booking/backend/application/views/emails/`. Edit them as described in "Before you edit any file".

| Email | File | Notes |
|---|---|---|
| Reservation Confirmation | `booking_confirmation.php` | Sent when an admin accepts a reservation. |
| Itemized Trip Receipt | `booking_confirmation.php` | Same file: the receipt is the same layout in "receipt" mode. |
| Profile → Invoices & Receipts | `booking_confirmation.php` | Same file again. |
| Request Declined | `request_declined.php` | Sent when an admin rejects a reservation. |
| Verification code, password reset, account notices | `notification.php` | |
| Customer Email Reminder | **Not in /booking** | The reminder is sent by /works (section 3 and 5). |

Rules for editing:

- Change only visible words. Do **not** touch anything between `<?php … ?>` or `<?= … ?>`.
- The company's logo, address, phones and contact email come from **Admin → Companies** (section 2B), not from the template file.
- `request_declined.php` has its own fixed A1 Limousines logo and phone number inside the file; change them there if needed.
- Email **subject lines** are in code (for example "Booking … Confirmed" in `controllers/Admin_api.php`); ask your developer to change those.

**Verify:** make a test reservation → accept it (confirmation) or reject it (declined) from **Admin → Reservations** and read the email. For receipts, log in as a customer → profile → Invoices & Receipts.

The **Terms & Conditions** text is different — edit it directly in **Admin → Terms**, then Save.

---

## 5. SMS and reminder templates (/works)

All reminders and SMS run in /works, using Twilio. There are three reminders, each with its own on/off switch and timing.

### Where each one is edited

| Reminder / message | What to edit | Where |
|---|---|---|
| Customer SMS Reminder text | `customer_sms_template` | file `public_html/works/application/config/sms.php` |
| Driver SMS Reminder text | `driver_sms_template` | same file |
| Customer Email Reminder text | the template | file `application/views/reminder_24h.php` (section 3) |
| On/off and "how long before pickup" for each of the three reminders | — | **/works → Setup → Reminder Settings** (no file editing) |
| One-off "Driver Trip Information" SMS (sent by hand) | the text is editable every time you send it | right-click a reservation → **Driver Trip Information** |
| List of people who must not be messaged | — | **Setup → Subscriptions** (Subscription Management) |
| Replies from customers and drivers | — | left menu → **Inbox** (you can reply from there) |

### Edit the SMS wording

Open `application/config/sms.php`. The two lines look like:

```php
$config['customer_sms_template'] = 'A1 Limousine: Reminder — your reservation is scheduled for tomorrow, [Date] at [Time]. Pickup: [Pickup Address]. …';
$config['driver_sms_template']   = 'A1 Limousine: Reminder — Your trip is scheduled for [Trip Date] at [Pickup Time]. Pickup: [Pickup Address]. …';
```

- Change only the words. Keep the words in square brackets exactly: customer SMS uses `[Date]`, `[Time]`, `[Pickup Address]`; driver SMS uses `[Trip Date]`, `[Pickup Time]`, `[Pickup Address]`.
- If your text contains an apostrophe, write it as `\'` (backslash then apostrophe), or the file will break.
- The wording says "tomorrow" because the default timing is 24 hours; if you change the timing in Reminder Settings, update the wording to match.

### Change when and whether each reminder is sent

1. /works → left menu **Setup → Reminder Settings** (administrators only).
2. For each reminder (Customer SMS, Customer email, Driver SMS): tick or untick **Enabled**, set a number and choose minutes / hours / days in **Send before pickup** (allowed: 5 minutes to 7 days).
3. Click **Save settings**. Changes apply to the next run (the job runs every 5 minutes).
4. Defaults: customer SMS and email 24 hours before pickup, driver SMS 60 minutes before. Customer reminders are skipped for a booking made inside the reminder window.

### How the reminders get sent (the scheduled job)

A single scheduled job ("cron job") must run every 5 minutes. If reminders ever stop, check this first:

- cPanel → **Cron Jobs**. There should be one running every 5 minutes with a command like
  `wget -q -O- "https://grounddispatch.com/works/index.php/notify/cron?key=YOUR_KEY"`
  (or `php /path/to/index.php notify cron`).
- The key is the `reminder_cron_key` value in `application/config/sms.php`. If you change the key there, update the cron job to match.

### Test mode

`sms.php` ends with two settings: `test_sms_to` and `test_email_to`. While either has a value, **every** SMS or reminder email goes to that test number/address instead of the real customer or driver. For normal operation both must be empty (`''`). If customers say they never get reminders, check these two lines.

---

## 6. Google Maps API key

**/booking widget** — the key is in the script tag of the embed snippet:

```html
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_KEY&libraries=places&callback=kfbInitMap" async defer></script>
```

Where to change it:

1. `public_html/booking/index.html` — replace the value after `key=`.
2. **Every client website** that pasted the booking snippet has its own copy of this line. They all need the new key if you replace it. (If you only add restrictions to the same key, nothing changes on those sites.)

**/works** — I did not find a Google Maps key in the /works code (it only contains map-library files), so there is nothing to change there unless your developer says otherwise.

How to create / manage the key (Google Cloud):

1. Go to `https://console.cloud.google.com` and sign in with the Google account that owns the project.
2. Menu → **APIs & Services → Library**. Make sure these are **Enabled**: *Maps JavaScript API*, *Places API*, *Directions API*, *Distance Matrix API* and *Geocoding API* (Geocoding is needed for the "Make a Return Reservation" button).
3. Menu → **APIs & Services → Credentials → Create credentials → API key**. Copy it.
4. Click the key → **Application restrictions → Websites** → add every site that shows the widget (for example `https://grounddispatch.com/*` and each client domain). **API restrictions** → restrict to the five APIs above. Save.
5. Make sure **Billing** is active for the Google project (Google stops Maps if it is not).

**Verify:** open the booking page, type a pickup address — suggestions should appear and a route should be drawn. If you see "This page can't load Google Maps correctly", open the browser console (see "Finding errors") for the exact message.

---

## 7. Twilio API (/works)

Twilio is used only by /works (SMS reminders, driver trip SMS and the Inbox). /booking does not use Twilio.

**Where the credentials are:** `public_html/works/application/config/sms.php`, three values:

```php
$config['sms_account_sid'] = '...';   // Twilio Account SID (starts with AC)
$config['sms_auth_token']  = '...';   // Twilio Auth Token
$config['sms_from']        = '+1...'; // your Twilio phone number
```

**Get the values:** log in at `https://console.twilio.com` → the home page ("Account Info") shows **Account SID** and **Auth Token** (click the eye icon to show it). Your SMS phone number is under **Phone Numbers → Manage → Active numbers**.

**Change them:**

1. Edit `sms.php` (copy it first). Replace the three values; keep the quotes. The phone number must be in the form `+18333431200`.
2. Save.

**If you change the Auth Token:** also change it in the same file only — the system uses it both to send messages and to check that incoming replies really come from Twilio. Anyone using the old token stops working immediately, so change it at a quiet time.

**Receiving replies (Inbox / STOP requests):** in Twilio Console → **Phone Numbers → Manage → Active numbers → click your number → Messaging configuration → "A message comes in"** → Webhook, method **HTTP POST**, URL:
`https://grounddispatch.com/works/index.php/notify/twilio_inbound`
Save. When a customer replies STOP, the system adds them to **Setup → Subscriptions** automatically.

**Verify:**

1. Temporarily put your own mobile number in `test_sms_to` (section 5), then in /works right-click a reservation → **Driver Trip Information** → pick a driver → **Send SMS**. You should receive it, and the dialog shows any error message.
2. Remember to set `test_sms_to` back to `''` afterwards.
3. In Twilio Console → **Monitor → Logs → Messaging** you can see every message and its delivery status.

---

## 8. PayPal credentials (/booking)

PayPal is used only by /booking. It uses a **Client ID** and a **Secret**. They are **not** your PayPal login email or password — your PayPal account password is changed only on paypal.com → Settings → Security.

**Where the credentials live:** the file `public_html/booking/backend/.env` on the server (a hidden file — in File Manager click **Settings → Show Hidden Files**):

```
PAYPAL_CLIENT_ID=your_client_id_here
PAYPAL_CLIENT_SECRET=your_secret_here
PAYPAL_ENV=live
```

**Get new credentials:**

1. Go to `https://developer.paypal.com/dashboard` and log in with the PayPal Business account.
2. Make sure the switch at the top is on **Live** (not Sandbox).
3. **Apps & Credentials → your app** (or **Create App**). Copy the **Client ID**; click **Show** to copy the **Secret**.

**Change them:**

1. Edit `backend/.env` (copy it first). Replace the Client ID and Secret. Keep `PAYPAL_ENV=live` (use `sandbox` only for testing with sandbox credentials).
2. Save.

**Verify:** make a small test booking on the widget and confirm it goes to PayPal and back. Then in **Admin → Reservations → open it → Accept** (captures) or **Reject** (releases) to confirm those work with the new credentials.

**Admin switch:** **Admin → Settings → Payments** has the PayPal ON / OFF switch. When OFF, reservations are saved as "awaiting approval" with no online payment (the card is charged manually).

---

## Finding errors (troubleshooting)

### /works (legacy)

1. **Error log (already on):** error logging is enabled. Files appear in `public_html/works/application/logs/` named `log-YYYY-MM-DD.php`. Open one in File Manager → **View** and read the newest lines at the bottom (look for the word ERROR and the time it happened).
2. **Reminder log:** every run of the reminder job writes one line to `application/logs/reminders-YYYY-MM-DD.php`, for example `… checked 12 reservation(s): #345 customer_sms: sent | #346 driver_sms: FAILED (…reason…)`. "nothing due" is normal when nothing needed sending. A **FAILED** line shows the reason (invalid phone number, number unsubscribed, Twilio error). If there are no new lines at all, the scheduled job is not running (section 5).
3. **Messages:** the **Inbox** shows texts received and sent. Twilio Console → **Monitor → Logs → Messaging** shows delivery status for each SMS.
4. **Emails not arriving:** check the spam folder; confirm the mailbox login in `constants.php` still matches the real mailbox password (cPanel → Email Accounts → Check Email).
5. **Reservations from /booking not appearing:** the sync key must match in both systems (section 2D); also see the "View Legacy Sync Log" button in /booking (below).

### /booking (new)

1. **Turn error logging on (it is currently OFF).** In `public_html/booking/backend/application/config/config.php` the line `$config['log_threshold'] = 0;` means "log nothing". Change `0` to `1` to log errors (use `2`/`3` only briefly — those fill the disk fast). Save. Logs then appear as `log-YYYY-MM-DD.php` in `public_html/booking/backend/application/logs/`. Change it back to `0` when done.
2. **Server PHP errors:** cPanel → **Metrics → Errors** (latest lines of the server error log), or the file `error_log` inside the affected folder.
3. **Browser errors (widget problems):** open the page → press **F12** (Mac: Cmd+Option+I) → **Console** tab. Red messages show what failed (for example a Google Maps key problem). **Network** tab → click a red/failed request → **Response** shows the server's message.
4. **Reservation-level checks:** open a reservation in the admin → the **payments** section lists every PayPal event (authorize / capture / cancel) with its status; **View Legacy Sync Log** shows what was sent to /works and the answer it gave.
5. **Before you ask for help,** write down: what you did, the exact time, the reservation ID, and any message shown on screen or in the log — it makes the fix much faster.

---

## Security notes for your developer

While reading the code I found secrets stored directly in files that are kept in version control (git): the SMTP mailbox password (`constants.php` in /works and `crmsync.php` in /booking), the Twilio Account SID, Auth Token and the reminder cron key (`sms.php`), and the sync key between the two systems. Recommended: move them to environment variables / an untracked `.env` file, and **change** the Twilio Auth Token, the mailbox password and the sync key, since they are already in the git history. The /works Users screen also stores and shows passwords in plain text.

---

## Summary: what you can do yourself

| Task | /works (legacy) | /booking (new) |
|---|---|---|
| Change admin / user password | Setup → Users (admin screen) | Not yet (needs a small feature) |
| Change sending email / password | Edit `constants.php` | Edit `crmsync.php` (keep both in step) |
| Contact / reply-to / notification emails | — | Admin → Companies |
| Edit email wording | Edit files in `views/` (section 3) | Edit files in `views/emails/` (section 4) |
| Edit Terms & Conditions | — | Admin → Terms |
| Reminder on/off and timing | Setup → Reminder Settings | — |
| SMS wording | Edit `config/sms.php` | — |
| Twilio keys | Edit `config/sms.php` | Not used |
| Google Maps key | None found | `index.html` + every client site |
| PayPal credentials | Not used | `backend/.env` |
| Error logs | `application/logs/` (already on) | Turn on in `config.php` |
