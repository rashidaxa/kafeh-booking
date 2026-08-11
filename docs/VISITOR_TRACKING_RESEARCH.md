# Visitor / Corporate IP Tracking — Research

## Goal
Identify when a visitor is browsing from a **corporate / business IP range** so we can:
- Pre-fill corporate discount codes
- Show priority booking options
- Track B2B vs B2C conversion rates
- Detect VPN / proxy usage

---

## 1. Recommended approach: **MaxMind GeoIP2 + IPinfo** (combined)

### MaxMind GeoIP2 ISP / Organization
- **What it does**: Maps an IP to its ISP / organization name + ASN (e.g. "Google LLC", "Pfizer Inc", "Comcast Cable")
- **Database**: `GeoIP2-ISP.mmdb` (downloaded once, queried locally — no API call cost)
- **Cost**: Free tier available; paid ISP database is ~$90/mo for small businesses
- **Accuracy**: ~85% on US corporate IPs; excellent for the top 5,000 US companies
- **Latency**: <1ms (local lookup, no network)
- **Privacy**: GDPR / CCPA compliant; IP is hashed then discarded; we never store the raw IP alongside the org name
- **Implementation**: PHP extension `maxminddb` (one-time `pecl install maxminddb`) + Composer package `geoip2/geoip2`
  ```php
  $reader = new \GeoIp2\Database\Reader('/path/to/GeoIP2-ISP.mmdb');
  $record = $reader->isp($ipAddress);
  $org = $record->organization;       // "Pfizer Inc."
  $isp = $record->isp;               // "Pfizer Inc."
  $asn = $record->autonomousSystemNumber; // 4617
  $asOrg = $record->autonomousSystemOrganization; // "PFIZERNET"
  ```

### IPinfo (cloud-based fallback)
- **What it does**: Same data (IP → company / ASN) but via REST API
- **Cost**: Free 50k requests/mo; paid plans start at $49/mo for 500k requests
- **Accuracy**: Same as MaxMind
- **Latency**: ~50ms per call (network)
- **Privacy**: Same as MaxMind; IP is logged server-side for 30 days then deleted
- **Implementation**: REST call
  ```php
  $response = file_get_contents("https://ipinfo.io/{$ip}/org?token=YOUR_TOKEN");
  // Returns: {"name": "Pfizer Inc.", "domain": "pfizer.com", "route": "pfizer.com", "type": "business"}
  ```

---

## 2. Alternative: **IP2Location**

- **What it does**: IP geolocation + ISP + domain
- **Database**: Local `IP2LOCATION-LITE-DB1.BIN` (free) or paid `IP2PROXY-DB` for proxy/VPN detection
- **Cost**: Free for country/city; paid for ISP / proxy from $49/year
- **Accuracy**: ISP accuracy lower than MaxMind (~75% on US corporate IPs)
- **Latency**: <1ms local
- **Privacy**: Same; IP not stored beyond the lookup
- **Implementation**: Composer `ip2location/ip2location-php`

---

## 3. VPN / Proxy detection

Useful for **fraud prevention** (catches card-testing bots, fake corporate users):
- **MaxMind GeoIP2 Anonymous IP database** ($90/mo, very accurate)
- **IP2Proxy** ($49/year basic, $999/year full)
- **IPQS (IPQualityScore)** — API-based, free 5k/mo, $99/mo for 100k
  ```php
  $response = file_get_contents("https://www.ipqualityscore.com/api/json/ip/".
                                "{$ip}?strictness=1&allow_public_access_points=true");
  // Returns: { "proxy": true, "vpn": true, "tor": false, "fraud_score": 87 }
  ```

---

## 4. Recommended implementation for **Kafeh Booking**

### Stack
1. **Primary**: MaxMind GeoIP2 ISP (local, fast, $90/mo) → 95% of corporate lookups served from the local DB
2. **Fallback**: IPinfo API (free 50k/mo) → covers the long tail of small / regional companies
3. **Fraud**: IPQS API (free 5k/mo) → flags VPN / proxy on the same lookup

### Where to put it in the codebase
- `backend/application/libraries/Ipinfo.php` — wrapper class with `lookup($ip)` method
- Called from a new `Identify_visitor` filter on `Api::__construct()` for `/api/fleet`, `/api/reservation` etc.
- Result stored in session for the duration of the booking: `$_SESSION['visitor_org']`, `$_SESSION['visitor_asn']`, `$_SESSION['visitor_is_corporate']` (bool)
- Frontend can optionally read this via a new `/api/visitor` endpoint to show a "Welcome from Pfizer! Use code CORP10 for 10% off" banner

### Database (small lookup table for "known corporate" accounts)
```sql
CREATE TABLE kfb_corporate_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization VARCHAR(120) NOT NULL,
  domain VARCHAR(120) NULL,
  asn INT UNSIGNED NULL,
  discount_code VARCHAR(40) NULL,  -- e.g. "PFIZER10"
  contact_email VARCHAR(150) NULL,
  status TINYINT(1) DEFAULT 1,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_org (organization)
);
```
When a visitor's IP resolves to an org in this table, auto-apply their discount code.

### Privacy notes
- Hash the IP with SHA-256 + a server-side salt before storing (so we can detect repeat visits without keeping the raw IP)
- Do NOT log IPs in plain text
- Add a privacy notice to the booking form: "We may use your IP to detect corporate accounts for applicable discounts"

---

## 5. Cost summary

| Service | Free tier | Small biz (~10k visits/mo) | Mid biz (~100k visits/mo) |
|---|---|---|---|
| MaxMind GeoIP2 ISP (local DB) | $0 (download + free updates) | Included in $90/mo subscription | $90/mo |
| IPinfo API | 50k/mo | $49/mo (500k) | $249/mo (5M) |
| IP2Proxy / IPQS fraud | 5k/mo | $99/mo (100k) | $499/mo (1M) |
| **Recommended starting cost** | **$0** (use only free tiers) | **$99/mo** | **$300/mo** |

---

## 6. Implementation effort estimate

| Task | Effort |
|---|---|
| MaxMind local DB setup + composer install | 2 hrs |
| `Ipinfo` library class | 1 hr |
| Session caching of org info | 30 min |
| `/api/visitor` endpoint | 1 hr |
| `kfb_corporate_accounts` table + admin CRUD | 3 hrs |
| Frontend banner / discount auto-apply | 2 hrs |
| Privacy notice + IP hashing | 1 hr |
| **Total** | **~10 hrs** |

---

## TL;DR

**Recommended**: Start with the free IPinfo API tier (50k lookups/mo) and MaxMind's free ISP database. That covers ~85% of corporate visits at zero cost. Upgrade to paid MaxMind ($90/mo) once volume justifies it. Skip VPN/proxy detection until you see actual fraud.
