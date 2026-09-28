/* ============================================================
   Booking API Widget — LITE fork (reservation-detail.html)
   ------------------------------------------------------------
   Forked from embed/booking.js for the "Client Bookings" intake flow:
   the client already agreed a price with the customer by phone, so
   this version collects trip details with NO pricing, NO payment
   (PayPal), and NO customer accounts/profile — see
   Client_booking_model.php / Api::client_reservation_create(). Kept
   deliberately as its own copy (not a shared file with a mode flag)
   so this flow's simplifications can never silently leak into the
   real, priced booking.js — see the "Client Bookings" implementation
   plan for the full rationale.

   - 3-step wizard: Where & When → Select Vehicle → Confirm Details
   - Right column: Map (step 1) OR Trip Summary (steps 2-3) — no Rate
     Details/Estimated Price section (see reservation-detail.html)
   - Location type is a button-pill group (not radio)
   - Airport mode reveals Airline / Flight # / Arrival Time / Pickup Point
   - All Google Maps / Places / Directions logic lives in test-map.js
     (which publishes route state on window.kfbRoute) — unchanged,
     shared with the full widget, since address entry is structural,
     not pricing.
   ============================================================ */

(function () {
  "use strict";

  // -------- Boot the widget as soon as jQuery is available --------
  function bootWhenReady() {
    if (typeof jQuery !== "undefined" && jQuery.fn && jQuery.fn.jquery) {
      boot(jQuery);
      return;
    }
    var attempts = 0;
    var timer = setInterval(function () {
      attempts++;
      if (typeof jQuery !== "undefined" && jQuery.fn && jQuery.fn.jquery) {
        clearInterval(timer);
        boot(jQuery);
      } else if (attempts > 200) {
        clearInterval(timer);
        showDependencyError(
          "jQuery is required for the booking widget to work. " +
          "Include jQuery before the widget script in your page. " +
          "See the project README for setup instructions."
        );
      }
    }, 50);
  }

  function showDependencyError(message) {
    try {
      var host = document.getElementById("kfbBookingWidget") || document.body;
      if (!host) return;
      var box = document.createElement("div");
      box.setAttribute("role", "alert");
      box.style.cssText =
        "max-width:680px;margin:24px auto;padding:16px 20px;" +
        "border:1px solid #b91c1c;border-radius:10px;" +
        "background:#fef2f2;color:#7f1d1d;" +
        "font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif";
      box.innerHTML =
        '<strong style="display:block;margin-bottom:6px">Booking widget — setup error</strong>' +
        '<span>' + message + '</span>';
      host.parentNode ? host.parentNode.insertBefore(box, host) : host.appendChild(box);
    } catch (e) {
      console.error("[BookingWidget] dependency error:", message);
    }
  }

  // ============================================================
  // BOOT
  // ============================================================
  function boot($) {
    "use strict";

    // -------- Config --------
    var API_BASE      = (window.API || "/api").replace(/\/$/, "");
    var UPLOADS_BASE  = (window.UPLOADS || (API_BASE.replace(/\/api$/, "") + "/uploads/vehicles/"));
    var HOURLY_KEYS   = ["hourly", "as directed", "as-directed", "hourly / as directed"];

    // -------- Child seat catalog (simplified to 3 types per spec v4) --------
    var CHILD_SEAT_TYPES = [
      { key: "rear_facing",    label: "Rear-Facing Seat (Infant)" },
      { key: "forward_facing", label: "Forward-Facing Seat (Toddler)" },
      { key: "booster",        label: "Booster Seat" },
    ];

    // -------- Default fleet (fallback if backend is unreachable) --------
    var DEFAULT_FLEET = [
      { id: "sedan",    name: "Luxury Sedan",      desc: "Mercedes E-Class / BMW 5 — ideal for 1–3 passengers.",   emoji: "🚘", capacity: 3,  luggage: 3 },
      { id: "suv",      name: "Premium SUV",       desc: "Cadillac Escalade / Chevy Suburban — roomy & elegant.", emoji: "🚙", capacity: 6,  luggage: 6 },
      { id: "sprinter", name: "Luxury Sprinter",   desc: "Executive van — perfect for groups up to 12.",          emoji: "🚐", capacity: 12, luggage: 10 },
      { id: "limo",     name: "Stretch Limousine", desc: "Lincoln Stretch — weddings, proms, VIP nights.",        emoji: "🏁", capacity: 10, luggage: 6 },
    ];

    // -------- State --------
    var state = {
      currentStep: 1,
      selectedVehicle: null,
      bookingId: null,
      fleet: DEFAULT_FLEET,
      addons: [],            // [{id, code, name, unit_price, quantity, line_total, region, ...}]
      addonsCatalog: [],     // fetched from backend at boot
      distanceMiles: 0,
      durationMins: 0,
      pickupDistanceMiles: null,  // real driving distance, garage -> pickup (v19) — null until test-map.js resolves it
      dropoffDistanceMiles: null, // real driving distance, garage -> dropoff (v19)
      pickup:  { lat: null, lng: null, state: "", country: "" },
      dropoff: { lat: null, lng: null, state: "", country: "" },
      pricingZone: null,     // only used to pick an add-ons region bucket in loadAddons() — no pricing engine calls here
      childSeats: {},
      stops: [],             // [{address, lat, lng}] — ordered, with edit support
      isReturnTrip: false,   // toggles pickup/dropoff swap
      returnDate: null,      // for return trip
      returnTime: null,      // for return trip
      returnPickupTypeDetail: "Curbside",   // return leg's pickup point (when original dropoff = Airport)
      returnTailNumber: "",
      returnDropoffTypeDetail: "Curbside",  // return leg's drop-off point (when original pickup = Airport)
      returnDropoffTailNumber: "",
      pickupTypeDetail: "Curbside", // Curbside | Meet & Greet | Private Terminal (FBO)
      tailNumber: "",         // for FBO / private terminal pickups
      dropoffTypeDetail: "Curbside", // Curbside | Meet & Greet | Private Terminal (FBO)
      dropoffTailNumber: "",  // for FBO / private terminal dropoffs
      signatureData: null,   // base64 PNG of customer signature — required on every submission, see refreshSignatureVisibility()
    };

    // -------- Helpers --------
    function escapeHtml(s) {
      return String(s == null ? "" : s)
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }
    function fmtMoney(n)  { return "$" + (Number(n) || 0).toFixed(2); }
    function fmtMiles(mi) { return (Number(mi) || 0).toFixed(1); }
    function fmtMins(m)   { return Math.max(0, Math.round(Number(m) || 0)); }
    // "YYYY-MM-DD" -> "MM/DD/YYYY"
    function fmtDateMDY(iso) {
      var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || "");
      return m ? (m[2] + "/" + m[3] + "/" + m[1]) : "—";
    }
    // "HH:MM" (24h) -> "hh:MM AM/PM"
    function fmtTime12h(hm) {
      var m = /^(\d{1,2}):(\d{2})/.exec(hm || "");
      if (!m) return "";
      var h = parseInt(m[1], 10);
      var ampm = h >= 12 ? "PM" : "AM";
      h = h % 12; if (h === 0) h = 12;
      return String(h).padStart(2, "0") + ":" + m[2] + " " + ampm;
    }
    // -------- Date/time overlay display (see .kfb-dt-overlay-wrap in booking.css for why) --------
    function updateDtOverlay($input) {
      // Not .siblings() — on desktop, enhanceDateTimeInputs() re-wraps
      // date/time inputs in their own nested .kfb-dt-wrap (for the custom
      // picker trigger button), moving the input out from being a direct
      // sibling of .kfb-dt-overlay-display. .closest(...).find(...)
      // finds it regardless of that extra nesting.
      var $overlay = $input.closest(".kfb-dt-overlay-wrap").find(".kfb-dt-overlay-display");
      if (!$overlay.length) return;
      var isDate = $input.attr("type") === "date";
      var val = $input.val();
      var text = isDate ? fmtDateMDY(val) : (function () {
        var m = /^(\d{1,2}):(\d{2})/.exec(val || "");
        if (!m) return "";
        var h = parseInt(m[1], 10);
        var ampm = h >= 12 ? "PM" : "AM";
        h = h % 12; if (h === 0) h = 12;
        return String(h).padStart(2, "0") + ":" + m[2] + " " + ampm;
      })();
      if (!text || text === "—") {
        $overlay.text(isDate ? "Select Date" : "Select Time").addClass("is-placeholder");
      } else {
        $overlay.text(text).removeClass("is-placeholder");
      }
    }
    function syncAllDtOverlays() {
      $(".kfb-dt-overlay-wrap input[type=\"date\"], .kfb-dt-overlay-wrap input[type=\"time\"]").each(function () {
        updateDtOverlay($(this));
      });
    }

    // Local "YYYY-MM-DD" for today (or an arbitrary Date), matching the
    // format <input type="date"> uses — NOT toISOString(), which is UTC
    // and can land on the wrong day depending on the visitor's timezone.
    function todayDateString(d) {
      d = d || new Date();
      return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
    }
    // "HH:MM" for right now, in the visitor's local time.
    function nowTimeString() {
      var d = new Date();
      return String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
    }
    // dateStr "YYYY-MM-DD" + timeStr "HH:MM", both local — true if that
    // moment has already passed. No timezone suffix on the constructed
    // string, so JS parses it as local time (matching how the visitor
    // entered it), not UTC.
    function isPastDateTime(dateStr, timeStr) {
      var dt = new Date(dateStr + "T" + timeStr + ":00");
      return dt.getTime() < Date.now();
    }
    function selectedServiceType() {
      return ($('input[name="service"]:checked').val() || "").toString().trim();
    }
    function isHourlyService() {
      return HOURLY_KEYS.indexOf(selectedServiceType().toLowerCase()) !== -1;
    }
    function totalChildSeats() {
      var n = 0;
      Object.keys(state.childSeats).forEach(function (k) { n += (state.childSeats[k] | 0); });
      return n;
    }
    function getLocType(group) {
      var btn = $('.kfb-loc-type-btn.is-active[data-group="' + group + '"]');
      return btn.length ? btn.attr("data-value") : "Search All";
    }

    // -------- Field format validation --------
    function isValidEmail(email) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(email || "").trim());
    }
    // Accepts digits with optional +, spaces, dashes, dots, parens; 7-15 digits (E.164-ish).
    function isValidPhone(phone) {
      var s = String(phone || "").trim();
      var digits = s.replace(/\D/g, "");
      return /^[0-9+()\-.\s]+$/.test(s) && digits.length >= 7 && digits.length <= 15;
    }
    function isValidName(name) {
      var s = String(name || "").trim();
      return s.length >= 2 && /^[A-Za-z'\-. ]+$/.test(s);
    }
    function isValidCardNumber(num) {
      var digits = String(num || "").replace(/[\s-]/g, "");
      if (!/^\d{13,19}$/.test(digits)) return false;
      // Luhn checksum.
      var sum = 0, alt = false;
      for (var i = digits.length - 1; i >= 0; i--) {
        var n = digits.charCodeAt(i) - 48;
        if (alt) { n *= 2; if (n > 9) n -= 9; }
        sum += n; alt = !alt;
      }
      return sum % 10 === 0;
    }
    function isValidCardExpiry(exp) {
      var m = /^(\d{2})\s*\/\s*(\d{2})$/.exec(String(exp || "").trim());
      if (!m) return false;
      var month = parseInt(m[1], 10);
      if (month < 1 || month > 12) return false;
      var year = 2000 + parseInt(m[2], 10);
      var now = new Date();
      var firstOfNextMonth = new Date(year, month, 1); // month is 0-indexed, so this = 1st of the month AFTER expiry
      return firstOfNextMonth > now;
    }
    function isValidCVV(cvv) {
      return /^\d{3,4}$/.test(String(cvv || "").trim());
    }

    // -------- Card field auto-formatting --------
    // Reposition the caret after reformatting by counting digits typed
    // before the caret pre-format, then walking the reformatted string
    // until that many digits have been passed again — otherwise every
    // keystroke would bounce the caret to the end of the field.
    function _caretAfterDigitCount(formatted, digitCount) {
      var pos = 0, seen = 0;
      while (pos < formatted.length && seen < digitCount) {
        if (/\d/.test(formatted[pos])) seen++;
        pos++;
      }
      return pos;
    }
    /** Groups digits into "4242 4242 4242 4242" as the customer types, up to 19 digits. */
    function wireCardNumberFormatting(selector) {
      $(selector).on("input", function () {
        var el = this;
        var raw = el.value;
        var digitsBeforeCaret = raw.slice(0, el.selectionStart).replace(/\D/g, "").length;
        var digits = raw.replace(/\D/g, "").slice(0, 19);
        var formatted = digits.replace(/(\d{4})(?=\d)/g, "$1 ");
        el.value = formatted;
        var pos = _caretAfterDigitCount(formatted, digitsBeforeCaret);
        try { el.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
      });
    }
    /** Auto-inserts "/" after the 2nd digit: "1225" -> "12/25". */
    function wireCardExpiryFormatting(selector) {
      $(selector).on("input", function () {
        var el = this;
        var raw = el.value;
        var digitsBeforeCaret = raw.slice(0, el.selectionStart).replace(/\D/g, "").length;
        var digits = raw.replace(/\D/g, "").slice(0, 4);
        var formatted = digits.length > 2 ? digits.slice(0, 2) + "/" + digits.slice(2) : digits;
        el.value = formatted;
        var pos = _caretAfterDigitCount(formatted, digitsBeforeCaret);
        try { el.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
      });
    }
    /** Digits only, capped at 4 (isValidCVV separately requires 3-4 on submit). */
    function wireCvvFormatting(selector) {
      $(selector).on("input", function () {
        this.value = this.value.replace(/\D/g, "").slice(0, 4);
      });
    }
    // Toggle the red invalid state on a field's wrapping <label class="kfb-field">.
    function markFieldValid($input, ok) {
      $input.closest(".kfb-field").toggleClass("is-invalid", !ok);
    }

    // -------- Toast --------
    function toast(msg, type) {
      type = type || "info";
      var el = document.getElementById("kfbToast");
      if (!el) {
        el = document.createElement("div");
        el.id = "kfbToast";
        el.className = "kfb-toast";
        document.body.appendChild(el);
      }
      el.className = "kfb-toast kfb-toast--" + type;
      el.textContent = msg;
      el.classList.add("is-open");
      clearTimeout(el._hideTimer);
      el._hideTimer = setTimeout(function () { el.classList.remove("is-open"); }, 3200);
    }
    // test-map.js can't reach this closure's toast() directly (separate
    // script/IIFE), so it dispatches this event instead — e.g. when it
    // rejects a too-vague (city/country-only) place selection.
    window.addEventListener("kfb:toast", function (e) {
      var d = e && e.detail;
      if (d && d.message) toast(d.message, d.type);
    });

    // ============================================================
    // STEPPER (3 STEPS)
    // ============================================================
    function gotoStep(n) {
      if (n < 1 || n > 3) return;
      if (!canAdvance(state.currentStep, n)) return;
      // Safety net: a customer who types pickup/dropoff without clicking a
      // Places dropdown suggestion never fires place_changed, so the route
      // (and therefore the price) can otherwise stay stuck at zero. Force
      // one last route computation on the way to Select Vehicle.
      if (state.currentStep === 1 && n > 1 && window.KafehTestMap && typeof window.KafehTestMap.updateRoute === "function") {
        window.KafehTestMap.updateRoute();
      }
      state.currentStep = n;
      $(".kfb-panel").removeClass("is-active").attr("hidden", true);
      $('.kfb-panel[data-panel="' + n + '"]').addClass("is-active").removeAttr("hidden");
      $(".kfb-step").removeClass("is-active is-done");
      $(".kfb-step").each(function () {
        var s = parseInt($(this).attr("data-step"), 10);
        if (s < n) $(this).addClass("is-done");
        else if (s === n) $(this).addClass("is-active");
      });
      // Right column swap: Map on step 1, Summary on 2-3
      if (n === 1) {
        $("#kfbMapCard").show();
        $("#kfbSummaryCard").attr("hidden", true);
        $("#kfbBookNowBtnMobile").prop("hidden", true);
      } else {
        $("#kfbMapCard").hide();
        $("#kfbSummaryCard").removeAttr("hidden");
        renderSideSummary();
        // Mobile-only shortcut under the Trip Summary, visible on both
        // steps it appears on — "Continue" on step 2 (advances to step 3,
        // same as the real Continue button), "Book Now"/"Save Changes" on
        // step 3 (submits, same as the real Book Now button). See the
        // click handler below for the matching step-dependent behavior.
        $("#kfbBookNowBtnMobile").prop("hidden", false)
          .text(n === 2 ? "Continue →" : (state.editingBookingId ? "Save Changes" : "Book Now"));
      }
      // On mobile, scroll to top of stepper
      if (window.matchMedia("(max-width: 880px)").matches) {
        $('html, body').animate({ scrollTop: $(".kfb-stepper").offset().top - 12 }, 250);
      }
    }
    function canAdvance(from, to) {
      if (to <= from) return true;
      if (from === 1) return validateStep1();
      if (from === 2) {
        if (!state.selectedVehicle) { toast("Please Choose A Vehicle.", "error"); return false; }
        return true;
      }
      return true;
    }

    // ============================================================
    // STEP 1 — WHERE & WHEN
    // ============================================================
    function validateStep1() {
      var missing = [];
      if (!$('input[name="service"]:checked').length) missing.push("Service Type");
      if (!$('input[name="pickupDate"]').val()) missing.push("Pickup Date");
      if (!$('input[name="pickupTime"]').val()) missing.push("Pickup Time");
      if ($('input[name="pickupDate"]').val() && $('input[name="pickupTime"]').val() &&
          isPastDateTime($('input[name="pickupDate"]').val(), $('input[name="pickupTime"]').val())) {
        missing.push("Pickup Date/Time (Can't Be In The Past)");
      }

      var pickupType = getLocType("pickup");
      if (!$('input[name="pickup"]').val().trim()) missing.push("Pickup Location");

      // Dropoff: only if "return at different" is on
      var dropoffType = getLocType("dropoff");
      if ($("#kfbReturnDifferent").is(":checked")) {
        if (!$('input[name="dropoff"]').val().trim()) missing.push("Drop-off Location");
      }

      if (!$('input[name="passengers"]').val() || parseInt($('input[name="passengers"]').val(), 10) < 1) {
        missing.push("Travellers");
      }
      // If pickup is Airport, require airline + flight #
      if (pickupType === "Airport") {
        if (!$('input[name="airline"]').val().trim()) missing.push("Airline");
        if (!$('input[name="flightNumber"]').val().trim()) missing.push("Flight #");
        if (!$('input[name="arrivalTime"]').val()) missing.push("Arrival Time");
      }
      // If dropoff is Airport AND "return at different" is on, require dropoff airline/flight/time
      if ($("#kfbReturnDifferent").is(":checked") && dropoffType === "Airport") {
        if (!$('input[name="dropoffAirline"]').val().trim()) missing.push("Drop-off Airline");
        if (!$('input[name="dropoffFlightNumber"]').val().trim()) missing.push("Drop-off Flight #");
        if (!$('input[name="dropoffArrivalTime"]').val()) missing.push("Drop-off Departure Time");
      }
      // Return trip: date/time always required, plus the swapped leg's
      // airline/flight/time whenever the corresponding original location is an airport.
      if (state.isReturnTrip) {
        if (!$('input[name="returnDate"]').val()) missing.push("Return Date");
        if (!$('input[name="returnTime"]').val()) missing.push("Return Time");
        if ($('input[name="returnDate"]').val() && $('input[name="returnTime"]').val() &&
            isPastDateTime($('input[name="returnDate"]').val(), $('input[name="returnTime"]').val())) {
          missing.push("Return Date/Time (Can't Be In The Past)");
        }
        var returnDifferent = $("#kfbReturnDifferent").is(":checked");
        var returnPickupIsAirport = (returnDifferent ? dropoffType : pickupType) === "Airport";
        if (returnPickupIsAirport) {
          if (!$('input[name="returnAirline"]').val().trim()) missing.push("Return Pickup Airline");
          if (!$('input[name="returnFlightNumber"]').val().trim()) missing.push("Return Pickup Flight #");
          if (!$('input[name="returnArrivalTime"]').val()) missing.push("Return Pickup Arrival Time");
        }
        if (pickupType === "Airport") {
          if (!$('input[name="returnDropoffAirline"]').val().trim()) missing.push("Return Drop-off Airline");
          if (!$('input[name="returnDropoffFlightNumber"]').val().trim()) missing.push("Return Drop-off Flight #");
          if (!$('input[name="returnDropoffArrivalTime"]').val()) missing.push("Return Drop-off Departure Time");
        }
      }
      if (missing.length) {
        toast("Please Fill: " + missing.join(", "), "error");
        var first = null;
        if (!$('input[name="pickupDate"]').val()) first = $('input[name="pickupDate"]');
        else if (!$('input[name="pickupTime"]').val()) first = $('input[name="pickupTime"]');
        else if (!$('input[name="pickup"]').val().trim()) first = $('input[name="pickup"]');
        if (first) first.focus();
        return false;
      }
      return true;
    }

    // -------- Service type pills (Transfer / Hourly) --------
    function wireServiceType() {
      $(".kfb-service-pill").on("click", function () {
        var v = $(this).attr("data-service");
        if (!v) return;
        $('input[name="service"][value="' + v + '"]').prop("checked", true).trigger("change");
      });
      $('input[name="service"]').on("change", function () {
        var v = $(this).val();
        $(".kfb-service-pill").removeClass("is-active");
        $('.kfb-service-pill[data-service="' + v + '"]').addClass("is-active");
        enforceStopsCap();
        updateStopsHint();
        $("#kfbHoursWrap").prop("hidden", !isHourlyService());
      });
    }
    function updateStopsHint() {
      $("#kfbStopsHint").text(isHourlyService() ? "optional, no limit" : "optional, up to " + TRANSFER_MAX_STOPS);
    }

    // -------- Location type BUTTONS (Search All / Address / Airport / Landmark) --------
    // NOT radio buttons — they're styled as button pills and we track the
    // active value via .is-active on the button + a hidden input mirror
    // (so the form serializes the value normally).
    function wireLocationType() {
      $(".kfb-loc-type-btn").on("click", function () {
        var group = $(this).attr("data-group"); // "pickup" | "dropoff"
        var value = $(this).attr("data-value");
        $('.kfb-loc-type-btn[data-group="' + group + '"]').removeClass("is-active");
        $(this).addClass("is-active");
        // Mirror to hidden input
        var hiddenName = group === "pickup" ? "pickupLocType" : "dropoffLocType";
        var $hidden = $('input[name="' + hiddenName + '"]');
        if ($hidden.length) $hidden.val(value);

        // Show / hide airport extras (airline / flight # / arrival time)
        var airportBlock = $('.kfb-airport-extras[data-group="' + group + '"]');
        if (value === "Airport") {
          airportBlock.show();
          airportBlock.find("input, select").prop("disabled", false);
        } else {
          airportBlock.hide();
          airportBlock.find("input, select").prop("disabled", true);
        }

        // Notify test-map.js to re-attach autocomplete with the new type filter
        window.dispatchEvent(new CustomEvent("kfb:loc-type-changed", {
          detail: { group: group, value: value }
        }));

        updateReturnTripAirportBlocks();
      });
    }

    // -------- Populate the airline datalists from the catalog --------
    function populateAirlines() {
      var airlines = (window.airlines || []);
      var $lists = $("#kfbAirlinesList, #kfbAirlinesListDropoff, #kfbAirlinesListReturnPickup, #kfbAirlinesListReturnDropoff");
      if (!$lists.length) return;
      $lists.each(function () { $(this).empty(); });
      airlines.forEach(function (a) {
        $lists.each(function () {
          $("<option></option>")
            .attr("value", a.name)
            .text(a.name + " (" + a.code + ")")
            .appendTo($(this));
        });
      });
    }

    // -------- Date & time pickers --------
    // Both date and time inputs are rendered fully invisible (opacity:0 —
    // see .kfb-dt-overlay-wrap in booking.css) so a styled overlay span can
    // own their visible appearance. That also hides each input's OWN
    // native picker icon, with no visual affordance left for where to
    // click to open it — and Chrome/Edge only open a date/time input's
    // native picker when you click that specific (now invisible) icon,
    // not anywhere else in the field, unlike Firefox's date input. So
    // every date/time field gets an explicit, always-visible trigger
    // button layered on top instead of depending on an invisible native
    // hit target:
    //   - date:  button calls input.showPicker() (Chrome/Edge/Firefox
    //            101+) to open the browser's own native calendar.
    //   - time:  Firefox's <input type="time"> has no dropdown UI at all
    //            (confirmed: showPicker() doesn't throw, but nothing
    //            appears — it's spinner/keyboard-only there), so time
    //            gets a fully custom dropdown built here instead of
    //            relying on any native picker, guaranteeing identical
    //            behavior across every browser.
    var TIME_STEP_MINUTES = 30;
    var TIME_OPTIONS = (function () {
      var opts = [];
      for (var mins = 0; mins < 24 * 60; mins += TIME_STEP_MINUTES) {
        var value = String(Math.floor(mins / 60)).padStart(2, "0") + ":" + String(mins % 60).padStart(2, "0");
        opts.push({ value: value, label: fmtTime12h(value) });
      }
      return opts;
    })();
    function closeAllTimeDropdowns() {
      $(".kfb-dt-dropdown").remove();
    }
    // Which date field a time field is "for" — when that date is today,
    // times already in the past are filtered out of the dropdown instead
    // of letting the customer pick one and only finding out at submit.
    var TIME_TO_DATE_FIELD = { pickupTime: "pickupDate", returnTime: "returnDate" };
    function openTimeDropdown($input) {
      var current = $input.val();
      var options = TIME_OPTIONS;
      var dateField = TIME_TO_DATE_FIELD[$input.attr("name")];
      if (dateField && $('input[name="' + dateField + '"]').val() === todayDateString()) {
        var nowMin = nowTimeString();
        options = TIME_OPTIONS.filter(function (o) { return o.value >= nowMin; });
        // Don't yank away a value the customer already picked just because
        // a few minutes have ticked by since — only filter for new picks.
        if (current && !options.some(function (o) { return o.value === current; })) {
          var existing = TIME_OPTIONS.filter(function (o) { return o.value === current; })[0];
          if (existing) options = [existing].concat(options);
        }
      }
      var $dd = $('<div class="kfb-dt-dropdown" role="listbox"></div>');
      if (!options.length) {
        $dd.append('<div class="kfb-dt-empty">No times left today — pick a later date</div>');
      }
      options.forEach(function (o) {
        var $item = $('<div class="kfb-dt-option" role="option" data-value="' + o.value + '">' + o.label + "</div>");
        if (o.value === current) $item.addClass("is-selected");
        $item.on("click", function () {
          $input.val(o.value).trigger("change").trigger("input");
          closeAllTimeDropdowns();
        });
        $dd.append($item);
      });
      $input.closest(".kfb-dt-wrap").append($dd);
      var $sel = $dd.find(".is-selected");
      if ($sel.length) $dd.scrollTop($sel[0].offsetTop - $dd.height() / 2 + $sel.height() / 2);
    }
    function enhanceDateTimeInputs() {
      // Touch devices (phones/tablets) already open a full native
      // date/time picker when the input itself is tapped — layering our
      // own trigger button on top just doubles up on iOS Safari, which
      // (unlike Chrome) doesn't reliably let ::-webkit-calendar-picker-
      // indicator{display:none} hide its own icon, so both end up
      // visible side by side. Only add the custom trigger for
      // mouse/trackpad users, where a native icon (if any) is small and
      // easy to miss anyway.
      if (window.matchMedia && window.matchMedia("(pointer: coarse)").matches) return;

      $('input[type="time"], input[type="date"]').each(function () {
        var $input = $(this);
        if ($input.parent().hasClass("kfb-dt-wrap")) return; // already enhanced
        var isDate = $input.attr("type") === "date";
        var $wrap = $('<div class="kfb-dt-wrap"></div>');
        var $btn = $(
          '<button type="button" class="kfb-dt-trigger" aria-label="Open ' + (isDate ? "date" : "time") +
          ' picker" tabindex="-1">' + (isDate ? "📅" : "🕐") + '</button>'
        );
        $input.before($wrap);
        $wrap.append($input).append($btn);
        $btn.on("click", function (e) {
          e.preventDefault();
          e.stopPropagation();
          if (isDate) {
            if (typeof $input[0].showPicker === "function") {
              try { $input[0].showPicker(); return; } catch (err) { /* fall through to focus below */ }
            }
            $input.trigger("focus");
            return;
          }
          var isOpen = $wrap.find(".kfb-dt-dropdown").length > 0;
          closeAllTimeDropdowns();
          if (!isOpen) openTimeDropdown($input);
        });
      });
      // Wire the document-level close handlers once, not once per input.
      if (!enhanceDateTimeInputs._wired) {
        enhanceDateTimeInputs._wired = true;
        $(document).on("click", function (e) {
          if (!$(e.target).closest(".kfb-dt-wrap").length) closeAllTimeDropdowns();
        });
        $(document).on("keydown", function (e) {
          if (e.key === "Escape") closeAllTimeDropdowns();
        });
      }
    }

    // -------- Stops --------
    // Transfer service caps at 5 stops; Hourly/As-Directed has no cap —
    // the customer is paying for the driver's time, not a fixed route.
    var TRANSFER_MAX_STOPS = 5;
    function maxStops() { return isHourlyService() ? Infinity : TRANSFER_MAX_STOPS; }
    var stopIndex = 0;
    function addStopRow() {
      if (stopIndex >= maxStops()) { toast("Maximum " + TRANSFER_MAX_STOPS + " Extra Stops For Transfer Service.", "error"); return; }
      var $c = $("#kfbStopsContainer");
      if (!$c.length) return;
      var i = stopIndex;
      var $row = $(
        '<div class="kfb-stop-row" data-stop="' + i + '">' +
          '<span class="kfb-stop-badge">' + (i + 1) + '</span>' +
          '<input type="text" name="stop[]" class="kfb-stop-input" placeholder="Stop Address" autocomplete="off">' +
          '<button type="button" class="kfb-remove-stop" aria-label="Remove stop ' + (i + 1) + '">&times;</button>' +
        '</div>'
      );
      $c.append($row);
      $row.find(".kfb-remove-stop").on("click", function () {
        $row.remove();
        renumberStops();
      });
      stopIndex++;
    }
    function renumberStops() {
      stopIndex = 0;
      $("#kfbStopsContainer .kfb-stop-row").each(function () {
        var $r = $(this);
        var i = stopIndex;
        $r.attr("data-stop", i);
        $r.find(".kfb-stop-badge").text(i + 1);
        $r.find(".kfb-remove-stop").attr("aria-label", "Remove stop " + (i + 1));
        stopIndex++;
      });
    }
    function wireStops() {
      $("#kfbAddStopBtn").on("click", addStopRow);
      renumberStops();
    }
    // Switching from Hourly back to Transfer can leave more stops than
    // Transfer allows — trim the excess (from the end) rather than just
    // blocking further additions, so the cap is actually enforced.
    function enforceStopsCap() {
      var cap = maxStops();
      var $rows = $("#kfbStopsContainer .kfb-stop-row");
      if ($rows.length > cap) {
        $rows.slice(cap).remove();
        renumberStops();
        toast("Trimmed To " + cap + " Stops For Transfer Service.");
      }
    }

    // -------- Steppers (− 1 +) --------
    function makeStepper(sel, onChange) {
      var $wrap = $(sel);
      if (!$wrap.length) return;
      var $out = $wrap.find(".kfb-stepper-out");
      $wrap.find(".kfb-stepper-minus").on("click", function () { $out.val((parseInt($out.val(), 10) || 0) - 1); clamp(); });
      $wrap.find(".kfb-stepper-plus").on("click",  function () { $out.val((parseInt($out.val(), 10) || 0) + 1); clamp(); });
      function clamp() {
        var min = parseInt($out.attr("min") || "0", 10);
        var max = parseInt($out.attr("max") || "999", 10);
        var v = parseInt($out.val() || "0", 10) || 0;
        if (v < min) v = min;
        if (v > max) v = max;
        $out.val(v);
        if (typeof onChange === "function") onChange(v);
      }
    }
    function updateKidsTotal() {
      var total = 0;
      var breakdown = {};
      $("#kfbChildSeatsContainer .kfb-cs-row").each(function () {
        var k = $(this).find(".kfb-cs-type").val();
        var q = parseInt($(this).find(".kfb-cs-qty").val(), 10) || 0;
        if (k && q > 0) { breakdown[k] = (breakdown[k] || 0) + q; total += q; }
      });
      state.childSeats = breakdown;
      recalcSelectedVehicle();
    }

    // -------- Child seats: add row with [type dropdown] [− 1 +] [trash] --------
    function addChildSeatRow() {
      var $c = $("#kfbChildSeatsContainer");
      if (!$c.length) return;
      var opts = CHILD_SEAT_TYPES.map(function (t) {
        return '<option value="' + t.key + '">' + escapeHtml(t.label) + '</option>';
      }).join("");
      var $row = $(
        '<div class="kfb-cs-row">' +
          '<select class="kfb-cs-type" aria-label="Child seat type">' + opts + '</select>' +
          '<div class="kfb-stepper kfb-stepper--inline kfb-cs-qty-wrap" role="group" aria-label="Quantity">' +
            '<button type="button" class="kfb-stepper-minus" aria-label="Decrease">&minus;</button>' +
            '<input type="number" class="kfb-stepper-out kfb-cs-qty" value="1" min="1" max="10" inputmode="numeric">' +
            '<button type="button" class="kfb-stepper-plus" aria-label="Increase">+</button>' +
          '</div>' +
          '<button type="button" class="kfb-cs-remove" aria-label="Remove child seat">&times;</button>' +
        '</div>'
      );
      $c.append($row);
      $row.find(".kfb-cs-remove").on("click", function () {
        $row.remove();
        updateKidsTotal();
      });
      makeStepper($row.find(".kfb-stepper"));
      $row.find(".kfb-cs-type").on("change", updateKidsTotal);
      $row.find(".kfb-cs-qty").on("input", updateKidsTotal);
      updateKidsTotal();
    }
    function wireChildSeats() {
      $("#kfbAddChildSeatBtn").on("click", addChildSeatRow);
    }

    // ============================================================
    // ROUTE SYNC
    // ============================================================
    function syncRouteFromMap() {
      var r = window.kfbRoute || {};
      state.distanceMiles = +(r.distanceMiles || 0);
      state.durationMins  = +(r.durationMins  || 0);
      state.pickup  = r.pickup  || { lat: null, lng: null, state: "", country: "" };
      state.dropoff = r.dropoff || { lat: null, lng: null, state: "", country: "" };
      // Real driving distance, garage -> pickup / garage -> dropoff (v19)
      // — null until test-map.js's DistanceMatrix call resolves; the
      // server falls back to straight-line for either side that's still
      // null, so there's nothing to guard here before it arrives.
      state.pickupDistanceMiles  = (r.pickupDistanceMiles  != null) ? +r.pickupDistanceMiles  : null;
      state.dropoffDistanceMiles = (r.dropoffDistanceMiles != null) ? +r.dropoffDistanceMiles : null;
    }
    window.addEventListener("kfb:route-updated", function () {
      syncRouteFromMap();
      renderVehicles();
      recalcSelectedVehicle();
    });

    // No pricing here — the client already agreed a price with the
    // customer by phone (see this file's header comment). requestedHours()
    // is kept only because the Hourly service still needs to record how
    // many hours the customer wants, same as the full widget.
    function requestedHours() {
      var n = parseFloat($("#kfbHoursInput").val());
      return isFinite(n) && n > 0 ? n : 4;
    }

    // ============================================================
    // FLEET LOAD + VEHICLE CARDS
    // ============================================================
    function loadFleet() {
      $.ajax({
        url: API_BASE + "/fleet",
        dataType: "json",
        timeout: 5000,
      })
      .done(function (res) {
        if (Array.isArray(res) && res.length) {
          state.fleet = res;
          renderVehicles();
        } else if (res && res.vehicles) {
          state.fleet = res.vehicles;
          renderVehicles();
        }
      })
      .fail(function () { /* keep default fleet, do not break the page */ });
    }

    /**
     * Fetch enabled add-ons for the current pricing zone from the backend.
     * kfb_addons still prices by the old chicago/america/worldwide bucket
     * (out of scope for the v16 rate-engine rebuild — see the pricing
     * plan), so the local/long_distance/worldwide zone from the latest
     * quote is mapped onto the closest equivalent bucket.
     * Safe to call multiple times — only the latest response is used.
     */
    function loadAddons() {
      var zoneToRegion = { local: "chicago", long_distance: "america", worldwide: "worldwide" };
      var region = zoneToRegion[state.pricingZone] || "worldwide";
      $.ajax({
        url: API_BASE + "/addons",
        data: { region: region },
        dataType: "json",
        timeout: 5000,
      })
      .done(function (res) {
        if (res && Array.isArray(res.addons)) {
          state.addonsCatalog = res.addons;
          renderAddons();
        }
      })
      .fail(function () { /* keep empty catalog, no add-ons shown */ });
    }

    /**
     * Render the add-ons section in Step 3.
     * Customers can toggle each add-on; the price is added to the booking total.
     */
    function renderAddons() {
      var $list = $("#kfbAddonList");
      if (!$list.length) return;
      $list.empty();
      if (!state.addonsCatalog || !state.addonsCatalog.length) {
        $list.html('<p class="kfb-empty">No add-ons are currently available.</p>');
        return;
      }
      state.addonsCatalog.forEach(function (a) {
        var selected = state.addons.find(function (x) { return x.id === a.id; });
        var qty = selected ? selected.quantity : 0;

        var $row = $(
          '<label class="kfb-addon-row" data-id="' + a.id + '">' +
            '<input type="checkbox" class="kfb-addon-cb" ' + (qty > 0 ? "checked" : "") + '>' +
            '<div class="kfb-addon-info">' +
              '<strong>' + escapeHtml(a.name) + '</strong>' +
              '<small class="kfb-faint">' + escapeHtml(a.description || "") + '</small>' +
            '</div>' +
            '<div class="kfb-addon-qty" ' + (qty > 0 ? "" : 'hidden') + '>' +
              '<button type="button" class="kfb-qty-minus" aria-label="Decrease">−</button>' +
              '<input type="number" class="kfb-addon-qty-input" value="' + Math.max(qty, 1) + '" min="1" max="99">' +
              '<button type="button" class="kfb-qty-plus" aria-label="Increase">+</button>' +
            '</div>' +
          '</label>'
        );
        $row.toggleClass("is-selected", qty > 0);
        $list.append($row);

        var $cb    = $row.find(".kfb-addon-cb");
        var $qty   = $row.find(".kfb-addon-qty");
        var $qtyIn = $row.find(".kfb-addon-qty-input");

        $cb.on("change", function () {
          $row.toggleClass("is-selected", $cb.is(":checked"));
          if ($cb.is(":checked")) {
            $qty.removeAttr("hidden");
            $qtyIn.val(Math.max(parseInt($qtyIn.val(), 10) || 1, 1));
            addAddonToCart(a, parseInt($qtyIn.val(), 10) || 1);
          } else {
            $qty.attr("hidden", true);
            removeAddonFromCart(a.id);
          }
          recalcSelectedVehicle();
        });
        $qtyIn.on("input", function () {
          var n = Math.max(parseInt($qtyIn.val(), 10) || 1, 1);
          if (n !== parseInt($qtyIn.val(), 10)) $qtyIn.val(n);
          if ($cb.is(":checked")) {
            updateAddonQuantity(a.id, n);
            recalcSelectedVehicle();
          }
        });
        $row.find(".kfb-qty-plus").on("click",  function () { $qtyIn.val(parseInt($qtyIn.val(), 10) + 1).trigger("input"); });
        $row.find(".kfb-qty-minus").on("click", function () {
          var v = parseInt($qtyIn.val(), 10) - 1;
          if (v < 1) {
            $cb.prop("checked", false).trigger("change");
          } else {
            $qtyIn.val(v).trigger("input");
          }
        });
      });
    }

    function addAddonToCart(a, qty) {
      var existing = state.addons.find(function (x) { return x.id === a.id; });
      if (existing) { existing.quantity = qty; existing.unit_price = a.unit_price; }
      else {
        state.addons.push({
          id: a.id, code: a.code, name: a.name, region: a.region,
          unit_price: a.unit_price, quantity: qty,
          line_total: 0, // computed below
        });
      }
      recomputeAddonTotals();
    }
    function removeAddonFromCart(id) {
      state.addons = state.addons.filter(function (x) { return x.id !== id; });
      recomputeAddonTotals();
    }
    function updateAddonQuantity(id, qty) {
      var a = state.addons.find(function (x) { return x.id === id; });
      if (a) { a.quantity = qty; recomputeAddonTotals(); }
    }
    function recomputeAddonTotals() {
      state.addons.forEach(function (a) {
        a.line_total = +(a.unit_price * a.quantity).toFixed(2);
      });
    }
    function totalAddons() {
      return +state.addons.reduce(function (s, a) { return s + (a.line_total || 0); }, 0).toFixed(2);
    }

    // Refresh the summary/signature visibility after anything that changes
    // trip inputs (add-ons, child seats, route, etc.) — no price to
    // recompute here, unlike the full widget's version of this function.
    function recalcSelectedVehicle() {
      if (state.selectedVehicle) {
        renderSideSummary();
        refreshSignatureVisibility();
      }
    }

    function renderVehicles() {
      syncRouteFromMap();
      var $grid = $("#kfbVehicleGrid");
      if (!$grid.length) return;
      var sortBy = $("#kfbSortVehicles").val() || "capacity";
      var capOf = function (v) {
        var n = parseInt(v && (v.capacity ?? v.max_passengers ?? v.passengers), 10);
        return isFinite(n) ? n : 0;
      };
      var passengers = parseInt($('input[name="passengers"]').val(), 10) || 1;
      var list = state.fleet.filter(function (v) {
        var minP = parseInt(v.min_passengers, 10);
        var maxP = parseInt(v.max_passengers, 10);
        if (!isFinite(minP)) minP = 1;
        if (!isFinite(maxP)) maxP = capOf(v) || Infinity;
        return passengers >= minP && passengers <= maxP;
      });
      if (sortBy === "capacity") list.sort(function (a, b) { return capOf(b) - capOf(a); });
      else list.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });

      // Deselect if the passenger count grew past the previously-picked vehicle's capacity.
      if (state.selectedVehicle && !list.some(function (v) { return v.id === state.selectedVehicle.id; })) {
        state.selectedVehicle = null;
      }

      $grid.empty();
      if (!list.length) {
        $grid.html(
          state.fleet.length
            ? '<p class="kfb-empty">No vehicles fit ' + passengers + ' passenger' + (passengers === 1 ? "" : "s") + '. Please adjust the traveller count.</p>'
            : '<p class="kfb-empty">No vehicles are currently available. Please check back later.</p>'
        );
        return;
      }
      list.forEach(function (v) {
        var selected = state.selectedVehicle && state.selectedVehicle.id === v.id;
        var imgSrc = v.image
          ? (v.image.indexOf("http") === 0 ? v.image : (UPLOADS_BASE + v.image))
          : "";
        var imgHtml = imgSrc
          ? '<img src="' + imgSrc + '" alt="' + escapeHtml(v.name) + '" loading="lazy">'
          : '<span class="kfb-vehicle-emoji">' + escapeHtml(v.emoji || "🚖") + '</span>';
        var $card = $(
          '<div class="kfb-vehicle-card ' + (selected ? "is-selected" : "") + '" data-id="' + escapeHtml(v.id) + '">' +
            '<div class="kfb-vehicle-image">' + imgHtml + '</div>' +
            '<div class="kfb-vehicle-meta">' +
              '<span class="kfb-vehicle-meta-item">👥 ' + (v.capacity || v.max_passengers || 0) + '</span>' +
              '<span class="kfb-vehicle-meta-item">🧳 ' + (v.luggage || 0) + '</span>' +
            '</div>' +
            '<div class="kfb-vehicle-info">' +
              '<h4 class="kfb-vehicle-name">' + escapeHtml(v.name) + '</h4>' +
              '<p class="kfb-vehicle-desc">' + escapeHtml(v.desc || v.description || "") + '</p>' +
            '</div>' +
          '</div>'
        );
        $card.on("click", function () {
          state.selectedVehicle = $.extend({}, v);
          renderVehicles();
          renderSideSummary();
          refreshSignatureVisibility();
        });
        $grid.append($card);
      });
    }

    // ============================================================
    // SIDE SUMMARY (right column, steps 2-3)
    // ============================================================
    function renderSideSummary() {
      syncRouteFromMap();
      var sum = $("#kfbSummaryCard");
      if (!sum.length) return;

      $("#kfbSumDate").text(fmtDateMDY($('input[name="pickupDate"]').val()));
      $("#kfbSumTime").text(fmtTime12h($('input[name="pickupTime"]').val()));
      $("#kfbSumService").text(selectedServiceType() || "—");
      $("#kfbSumDistance").text(fmtMiles(state.distanceMiles) + " mi · " + fmtMins(state.durationMins) + " min");
      $("#kfbSumPickup").text($('input[name="pickup"]').val() || "—");
      $("#kfbSumDropoff").text(
        $('#kfbReturnDifferent').is(":checked")
          ? ($('input[name="dropoff"]').val() || "—")
          : "Same as pickup"
      );

      // Stops
      var stops = [];
      $("#kfbStopsContainer input[name='stop[]']").each(function () {
        var s = ($(this).val() || "").trim();
        if (s) stops.push(s);
      });
      if (stops.length) {
        $("#kfbSumStopsRow").show();
        $("#kfbSumStops").html(
          stops.map(function (s, i) { return '<div class="kfb-stop-line">Stop ' + (i + 1) + ': ' + escapeHtml(s) + '</div>'; }).join("")
        );
      } else {
        $("#kfbSumStopsRow").hide();
      }

      if (state.selectedVehicle) {
        $("#kfbSumVehicle").text(state.selectedVehicle.name || state.selectedVehicle.id);
        var passengerCount = parseInt($('input[name="passengers"]').val(), 10) || 1;
        var bagCount = parseInt($('input[name="bags"]').val(), 10) || 0;
        $("#kfbSumVehicleMeta").text(
          passengerCount + " Passenger" + (passengerCount === 1 ? "" : "s") +
          " / " + bagCount + " Bag" + (bagCount === 1 ? "" : "s")
        );
        // No pricing here — Add-ons & Extras / Child Seats are shown as a
        // plain checklist (name + qty only, no $), since the price for
        // this trip was already agreed by phone.
        var childCount = totalChildSeats();
        var hasChildSeats = childCount > 0;
        if (hasChildSeats) {
          $("#kfbSumChildSeatsRow").show();
          $("#kfbSumChildSeats").text(childCount + " seat" + (childCount === 1 ? "" : "s"));
        } else {
          $("#kfbSumChildSeatsRow").hide();
        }
        var $addonsSection = $("#kfbSumAddonsSection");
        var hasAddons = !!(state.addons && state.addons.length);
        if (hasAddons) {
          var addonLines = state.addons.map(function (a) {
            var label = escapeHtml(a.name) + (a.quantity > 1 ? " × " + a.quantity : "");
            return '<div class="kfb-addon-line"><span>' + label + '</span></div>';
          }).join("");
          $("#kfbSumAddons").html(addonLines);
        } else {
          $("#kfbSumAddons").empty();
        }
        if ($addonsSection.length) {
          if (hasAddons || hasChildSeats) $addonsSection.show();
          else $addonsSection.hide();
        }
      } else {
        $("#kfbSumVehicle").text("—");
      }
    }

    // ============================================================
    // CLIENT RESERVATION (no pricing, no PayPal)
    // ------------------------------------------------------------
    // The client already agreed a price with the customer by phone —
    // this just records the submitted trip details for manual review
    // (see Client_booking_model.php / Api::client_reservation_create()).
    // ============================================================
    /** Builds the /api/client-reservation payload from the current form state. Returns NULL if no vehicle is selected. */
    function buildClientReservationPayload() {
      if (!state.selectedVehicle) return null;
      var v = state.selectedVehicle;
      var stops = [];
      $("#kfbStopsContainer input[name='stop[]']").each(function () {
        var s = ($(this).val() || "").trim();
        if (s) stops.push(s);
      });
      var childBreakdown = {};
      Object.keys(state.childSeats).forEach(function (k) {
        if (state.childSeats[k] > 0) childBreakdown[k] = state.childSeats[k];
      });

      var pickupType = getLocType("pickup");
      var pickupValue = $('input[name="pickup"]').val();
      var dropoffType = getLocType("dropoff");
      var dropoffValue = $('#kfbReturnDifferent').is(":checked")
        ? $('input[name="dropoff"]').val()
        : pickupValue;

      var payload = {
        service:         selectedServiceType(),
        pickupDate:      $('input[name="pickupDate"]').val(),
        pickupTime:      $('input[name="pickupTime"]').val(),
        pickupType:      pickupType,
        pickup:          pickupValue,
        airline:         $('input[name="airline"]').val()       || null,
        flightNumber:    $('input[name="flightNumber"]').val()  || null,
        arrivalTime:     $('input[name="arrivalTime"]').val()   || null,
        pickupTypeDetail: state.pickupTypeDetail,
        tailNumber:      state.tailNumber || null,
        dropoff:         dropoffValue,
        dropoffType:     $('#kfbReturnDifferent').is(":checked") ? dropoffType : pickupType,
        dropoffAirline:        $('input[name="dropoffAirline"]').val()       || null,
        dropoffFlightNumber:   $('input[name="dropoffFlightNumber"]').val()  || null,
        dropoffArrivalTime:    $('input[name="dropoffArrivalTime"]').val()   || null,
        dropoffTypeDetail: state.dropoffTypeDetail,
        dropoffTailNumber: state.dropoffTailNumber || null,
        stops:           stops,
        isReturnTrip:    state.isReturnTrip,
        returnDate:      state.returnDate,
        returnTime:      state.returnTime,
        // Return leg pickup (= original dropoff, once swapped) — only meaningful
        // when the original dropoff is an airport.
        returnAirline:         $('input[name="returnAirline"]').val()       || null,
        returnFlightNumber:    $('input[name="returnFlightNumber"]').val()  || null,
        returnArrivalTime:     $('input[name="returnArrivalTime"]').val()   || null,
        returnPickupTypeDetail: state.returnPickupTypeDetail,
        returnTailNumber:      state.returnTailNumber || null,
        // Return leg drop-off (= original pickup, once swapped) — only meaningful
        // when the original pickup is an airport.
        returnDropoffAirline:      $('input[name="returnDropoffAirline"]').val()      || null,
        returnDropoffFlightNumber: $('input[name="returnDropoffFlightNumber"]').val() || null,
        returnDropoffArrivalTime:  $('input[name="returnDropoffArrivalTime"]').val()  || null,
        returnDropoffTypeDetail:   state.returnDropoffTypeDetail,
        returnDropoffTailNumber:   state.returnDropoffTailNumber || null,
        passengers:      parseInt($('input[name="passengers"]').val(), 10) || 1,
        luggage:         parseInt($('input[name="bags"]').val(), 10) || 0,
        childSeats:      totalChildSeats(),
        childSeatsBreakdown: childBreakdown,
        notes:           $('textarea[name="notes"]').val() || null,
        addons:          state.addons,
        vehicle_id:      v.id || v.code,
        vehicle_name:    v.name,
        distanceMiles:   state.distanceMiles,
        durationMins:    state.durationMins,
        pickupDistanceMiles:  state.pickupDistanceMiles,
        dropoffDistanceMiles: state.dropoffDistanceMiles,
        hours:           requestedHours(),
        firstName:       $('input[name="firstName"]').val(),
        lastName:        $('input[name="lastName"]').val(),
        email:           $('input[name="email"]').val(),
        phone:           $('input[name="phone"]').val(),
        cardHolderName:      $('input[name="cardHolderName"]').val()     || null,
        cardNumber:          $('input[name="cardNumber"]').val()         || null,
        cardExpiry:          $('input[name="cardExpiry"]').val()         || null,
        cvv:                 $('input[name="cvv"]').val()                || null,
        cardBillingAddress:  $('input[name="cardBillingAddress"]').val() || null,
        signature:       state.signatureData || null,
        termsVersion:    state.signatureData ? "v1" : null,
      };

      return payload;
    }

    function createClientReservation() {
      var payload = buildClientReservationPayload();
      if (!payload) { toast("No Vehicle Selected.", "error"); return $.Deferred().reject().promise(); }
      return $.ajax({
        url: API_BASE + "/client-reservation",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify(payload),
        dataType: "json",
        timeout: 10000,
      });
    }

    /**
     * Book Now: validate → submit the trip details for manual review.
     * No PayPal, no pricing — the price was already agreed by phone.
     */
    function submitBooking() {
      var v = state.selectedVehicle;
      if (!v) { toast("Choose A Vehicle First.", "error"); return; }

      var missing = [];
      var invalid = [];
      var $firstBad = null;

      function requireField(name, label, validator) {
        var $el = $('input[name="' + name + '"]');
        var val = ($el.val() || "").trim();
        var ok = !!val && (!validator || validator(val));
        markFieldValid($el, ok);
        if (!val) { missing.push(label); if (!$firstBad) $firstBad = $el; }
        else if (!ok) { invalid.push(label); if (!$firstBad) $firstBad = $el; }
      }

      requireField("firstName", "First Name", isValidName);
      requireField("lastName", "Last Name", isValidName);
      requireField("email", "Email", isValidEmail);
      requireField("phone", "Phone", isValidPhone);
      requireField("cardNumber", "Card Number", isValidCardNumber);
      requireField("cardExpiry", "Card Expiry", isValidCardExpiry);
      requireField("cvv", "CVV", isValidCVV);
      requireField("cardBillingAddress", "Credit Card Billing Address");

      if ($("#kfbTermsBlock").is(":visible") && !$('input[name="terms"]').is(":checked")) {
        missing.push("Terms & Conditions");
      }
      if ($("#kfbSignatureBlock").is(":visible") && !state.signatureData) {
        missing.push("Signature");
      }

      if (missing.length || invalid.length) {
        var parts = [];
        if (missing.length) parts.push("Please Fill: " + missing.join(", "));
        if (invalid.length) parts.push("Please Check The Format Of: " + invalid.join(", "));
        toast(parts.join(" — "), "error");
        if ($firstBad) $firstBad.focus();
        return;
      }

      var $btn = $("#kfbBookNowBtn, #kfbBookNowBtnMobile");
      $btn.prop("disabled", true);
      $("#kfbPaymentStatus").show().find("p").text("Submitting your trip details…");

      createClientReservation()
        .then(function (res) {
          state.bookingId = res.booking_id;
          $("#kfbPaymentStatus").hide();
          showSuccess(res.booking_id);
        })
        .catch(function (err) {
          $("#kfbPaymentStatus").hide();
          $btn.prop("disabled", false);
          var msg = (err && err.responseJSON && err.responseJSON.error) || (err && err.message) || "Could Not Submit Your Details.";
          toast(msg, "error");
        });
    }

    function showSuccess(bookingId) {
      $(".kfb-panel, .kfb-stepper, .kfb-actions, .kfb-side-col").hide();
      var $ok = $("#kfbSuccess").show();
      $("#kfbSuccessId").text(bookingId || state.bookingId || "—");
      $("#kfbSuccessName").text($('input[name="firstName"]').val() || "rider");
      $("#kfbSuccessEmail").text($('input[name="email"]').val() || "—");
      $("html, body").animate({ scrollTop: 0 }, 200);
    }
    // ============================================================
    // FLIGHT VALIDATION (v4)
    // ============================================================
    // ============================================================
    // RETURN TRIP (v4)
    // ============================================================
    /**
     * Toggle the return trip mode. When ON, the customer fills in a
     * second set of date/time/pickup/dropoff and we swap the primary
     * leg automatically.
     */
    function applyReturnTrip(on) {
      state.isReturnTrip = !!on;
      var $panel = $("#kfbReturnTripPanel");
      if (on) {
        $panel.removeAttr("hidden");
        updateReturnTripAirportBlocks();
      } else {
        $panel.attr("hidden", true);
        // Clear any return-trip data
        state.returnDate = null;
        state.returnTime = null;
        state.returnPickupTypeDetail = "Curbside";
        state.returnTailNumber = "";
        state.returnDropoffTypeDetail = "Curbside";
        state.returnDropoffTailNumber = "";
        $('input[name="returnDate"], input[name="returnTime"]').val("");
        $('input[name="returnAirline"], input[name="returnFlightNumber"], input[name="returnArrivalTime"], input[name="returnTailNumber"]').val("");
        $('input[name="returnDropoffAirline"], input[name="returnDropoffFlightNumber"], input[name="returnDropoffArrivalTime"], input[name="returnDropoffTailNumber"]').val("");
        $("#kfbReturnPickupTypeDetail, #kfbReturnDropoffTypeDetail").val("Curbside");
        $("#kfbReturnTailNumberWrap, #kfbReturnDropoffTailNumberWrap").attr("hidden", true);
        $("#kfbReturnPickupFlightBlock, #kfbReturnDropoffFlightBlock").hide();
        syncAllDtOverlays(); // returnDate/returnTime were just cleared via .val("")
      }
      recalcSelectedVehicle();
      renderVehicles();
    }

    /**
     * Show/hide the return-leg flight-info blocks based on which of the
     * original pickup/dropoff is an airport — the return leg swaps them,
     * so an airport dropoff becomes the return leg's pickup and vice versa.
     */
    function updateReturnTripAirportBlocks() {
      if (!state.isReturnTrip) return;
      var pickupIsAirport = getLocType("pickup") === "Airport";
      var returnDifferent = $("#kfbReturnDifferent").is(":checked");
      var dropoffIsAirport = (returnDifferent ? getLocType("dropoff") : getLocType("pickup")) === "Airport";
      // Return leg pickup = original dropoff; return leg dropoff = original pickup.
      $("#kfbReturnPickupFlightBlock").toggle(dropoffIsAirport);
      $("#kfbReturnDropoffFlightBlock").toggle(pickupIsAirport);
    }

    // ============================================================
    // E-SIGNATURE (v4) — required for every booking
    // ============================================================
    function initSignaturePad() {
      var canvas = document.getElementById("kfbSignatureCanvas");
      if (!canvas) return;
      var ctx = canvas.getContext("2d");
      var drawing = false;
      var lastX = 0, lastY = 0;
      var hasInk = false;

      function getPos(e) {
        var r = canvas.getBoundingClientRect();
        var sx = canvas.width  / r.width;
        var sy = canvas.height / r.height;
        var cx = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
        var cy = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
        return { x: cx * sx, y: cy * sy };
      }
      function start(e) {
        e.preventDefault();
        drawing = true;
        hasInk = true;
        var p = getPos(e);
        lastX = p.x; lastY = p.y;
      }
      function move(e) {
        if (!drawing) return;
        e.preventDefault();
        var p = getPos(e);
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(p.x, p.y);
        ctx.strokeStyle = "#111";
        ctx.lineWidth = 2.5;
        ctx.lineCap = "round";
        ctx.stroke();
        lastX = p.x; lastY = p.y;
      }
      // Snapshot to base64 PNG as soon as a stroke ends, rather than
      // waiting for a form "submit" event — "Book Now" is a plain button
      // handled via a click listener (see submitBooking()), so it never
      // fires a native submit event for a form-level listener to catch.
      function end() {
        if (!drawing) return;
        drawing = false;
        if (hasInk) state.signatureData = canvas.toDataURL("image/png");
      }
      function clear() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasInk = false;
        state.signatureData = null;
      }

      canvas.addEventListener("mousedown", start);
      canvas.addEventListener("mousemove", move);
      canvas.addEventListener("mouseup", end);
      canvas.addEventListener("mouseleave", end);
      canvas.addEventListener("touchstart", start, { passive: false });
      canvas.addEventListener("touchmove", move, { passive: false });
      canvas.addEventListener("touchend", end);

      var $clear = $("#kfbSignatureClear");
      if ($clear.length) $clear.on("click", clear);
    }

    /**
     * Show the signature pad + Terms & Conditions checkbox. Both are
     * required on every booking, regardless of total.
     */
    function refreshSignatureVisibility() {
      var $sig = $("#kfbSignatureBlock");
      if (!$sig.length) return;
      $sig.removeAttr("hidden");
      $("#kfbTermsBlock").show();
    }

    // ============================================================
    // RESET FORM (v4)
    // ============================================================
    function resetAll() {
      state.selectedVehicle = null;
      state.bookingId = null;
      state.editingBookingId = null;
      $("#kfbBookNowBtn").text("Book Now");
      state.promo = null;
      state.childSeats = {};
      state.addons = [];
      state.addonsCatalog = [];
      state.stops = [];
      state.isReturnTrip = false;
      state.returnDate = null;
      state.returnTime = null;
      state.returnPickupTypeDetail = "Curbside";
      state.returnTailNumber = "";
      state.returnDropoffTypeDetail = "Curbside";
      state.returnDropoffTailNumber = "";
      state.pickupTypeDetail = "Curbside";
      state.tailNumber = "";
      state.dropoffTypeDetail = "Curbside";
      state.dropoffTailNumber = "";
      state.signatureData = null;
      state.minFareApplied = 0;
      state.quotesByVehicleId = {};
      state.pricingZone = null;
      $("#kfbForm")[0].reset();
      $("#kfbStopsContainer").empty();
      $("#kfbChildSeatsContainer").empty();
      $("#kfbAddonList").empty();
      $("#kfbSignatureClear").trigger("click"); // wipes the drawn ink + state.signatureData
      $("#kfbReturnDifferent").prop("checked", true).trigger("change");
      $('input[name="returnDate"], input[name="returnTime"], input[name="tailNumber"], input[name="dropoffTailNumber"], input[name="returnTailNumber"], input[name="returnDropoffTailNumber"]').val("");
      $(".kfb-airport-extras").hide();
      $("#kfbTailNumberWrap").attr("hidden", true);
      $("#kfbDropoffTailNumberWrap").attr("hidden", true);
      $("#kfbReturnTailNumberWrap").attr("hidden", true);
      $("#kfbReturnDropoffTailNumberWrap").attr("hidden", true);
      $("#kfbReturnPickupFlightBlock, #kfbReturnDropoffFlightBlock").hide();
      $("#kfbReturnTripPanel").attr("hidden", true);
      $("#kfbReturnTripToggle").prop("checked", false);
      $("#kfbPickupTypeDetail").val("Curbside");
      $("#kfbDropoffTypeDetail").val("Curbside");
      $("#kfbReturnPickupTypeDetail, #kfbReturnDropoffTypeDetail").val("Curbside");
      // Panel visibility is owned by gotoStep() via the `hidden` attribute
      // — clear any inline display style showSuccess()'s .hide() left
      // behind (rather than .show(), which would set an inline style on
      // ALL THREE panels and permanently outrank gotoStep()'s `hidden`
      // attribute toggling, leaving every step stacked on screen at once).
      $(".kfb-panel").css("display", "");
      $(".kfb-stepper, .kfb-actions, .kfb-side-col").show();
      $("#kfbSuccess").hide();
      // Re-seed addons catalog
      loadAddons();
      syncAllDtOverlays(); // form.reset() cleared pickupDate/pickupTime — refresh the overlay to the empty-state placeholder
      gotoStep(1);
    }

    // ============================================================
    // WIRE IT ALL UP
    // ============================================================
    $(function () {
      $("#kfbReturnDifferent").prop("checked", true);
      $('input[name="service"][value="Transfer"]').prop("checked", true);
      $(".kfb-service-pill[data-service='Transfer']").addClass("is-active");

      // Pickup date/time start empty on purpose — a pre-filled default
      // (previously "tomorrow at 10:00") let customers miss that they
      // needed to change it, so they're now required to actively pick both.
      //
      // `min` stops the native date picker from offering past dates at all;
      // validateStep1() below is the authoritative check (a typed/pasted
      // date can bypass `min`, and `min` can't express "not before right
      // now" for the time field).
      var todayISO = todayDateString();
      $('input[name="pickupDate"], input[name="returnDate"]').attr("min", todayISO);

      // `min` isn't reliably enforced by every mobile browser's native
      // date picker (iOS Safari's wheel picker in particular still lets
      // you scroll to a past date) — flag it the moment date+time are
      // both set, rather than waiting for the customer to hit Next.
      // validateStep1() (run on Next) stays the authoritative check;
      // this is just faster feedback on top of it.
      function checkPastDateTimeField(dateName, timeName, label) {
        var $date = $('input[name="' + dateName + '"]');
        var $time = $('input[name="' + timeName + '"]');
        var d = $date.val(), t = $time.val();
        if (!d || !t) { markFieldValid($date, true); markFieldValid($time, true); return; }
        var invalid = isPastDateTime(d, t);
        markFieldValid($date, !invalid);
        markFieldValid($time, !invalid);
        if (invalid) toast(label + " Can't Be In The Past — Please Pick A Different Date/Time.", "error");
      }
      $(document).on("change", 'input[name="pickupDate"], input[name="pickupTime"]', function () {
        checkPastDateTimeField("pickupDate", "pickupTime", "Pickup Date/Time");
      });
      $(document).on("change", 'input[name="returnDate"], input[name="returnTime"]', function () {
        checkPastDateTimeField("returnDate", "returnTime", "Return Date/Time");
      });
      // Keep the overlay display (see .kfb-dt-overlay-wrap) in sync with
      // whatever the real (visually-hidden) input's value is — covers
      // direct user interaction. Places that set these values
      // programmatically (prefillFormFromBooking, resetAll, etc.) call
      // syncAllDtOverlays() themselves afterward, since setting .val()
      // doesn't fire "change".
      $(document).on("change input", '.kfb-dt-overlay-wrap input[type="date"], .kfb-dt-overlay-wrap input[type="time"]', function () {
        updateDtOverlay($(this));
      });
      syncAllDtOverlays();

      wireServiceType();
      wireLocationType();
      wireStops();
      updateStopsHint();
      wireChildSeats();
      populateAirlines();
      enhanceDateTimeInputs();
      wireCardNumberFormatting('input[name="cardNumber"]');
      wireCardExpiryFormatting('input[name="cardExpiry"]');
      wireCvvFormatting('input[name="cvv"]');
      makeStepper("#kfbStepperPassengers", function () { renderVehicles(); recalcSelectedVehicle(); });
      makeStepper("#kfbStepperBags");

      $("#kfbReturnDifferent").on("change", function () {
        var on = $(this).is(":checked");
        if (on) $("#kfbDropoffWrap").slideDown(120);
        else    $("#kfbDropoffWrap").slideUp(120);
        updateReturnTripAirportBlocks();
      });

      window.addEventListener("kfb:route-updated", function () {
        syncRouteFromMap();
        renderVehicles();
        recalcSelectedVehicle();
      });

      $(document).on("click", "[data-next]", function () { gotoStep(parseInt($(this).attr("data-next"), 10)); });
      $(document).on("click", "[data-prev]", function () { gotoStep(parseInt($(this).attr("data-prev"), 10)); });

      $("#kfbResetBtn").on("click", function (e) { e.preventDefault(); resetAll(); });
      $("#kfbCancelBtn").on("click", function (e) { e.preventDefault(); resetAll(); });
      $("#kfbSortVehicles").on("change", renderVehicles);

      // Live recompute summary on contact-info edits
      $(document).on("input", 'input[name="firstName"], input[name="lastName"], input[name="email"], input[name="phone"]', renderSideSummary);

      // Clear a field's red "invalid" state as soon as it passes again, so it
      // doesn't stay flagged after the customer fixes it post-submit-attempt.
      var FIELD_VALIDATORS = {
        firstName: isValidName, lastName: isValidName,
        email: isValidEmail, phone: isValidPhone,
        cardNumber: isValidCardNumber, cardExpiry: isValidCardExpiry, cvv: isValidCVV,
      };
      $(document).on("input blur", Object.keys(FIELD_VALIDATORS).map(function (n) { return 'input[name="' + n + '"]'; }).join(", "), function () {
        var $el = $(this);
        var name = $el.attr("name");
        var val = ($el.val() || "").trim();
        if (!$el.closest(".kfb-field").hasClass("is-invalid")) return; // nothing to clear
        if (!val) return; // still empty — leave as-is, submit will re-flag with the right message
        markFieldValid($el, FIELD_VALIDATORS[name](val));
      });

      loadFleet();
      loadAddons();
      renderVehicles();
      renderSideSummary();
      $("#kfbBookNowBtn").on("click", submitBooking);
      // Mobile shortcut does double duty — "Continue" while on step 2
      // (same validation/advance as the real Continue button, via
      // gotoStep() -> canAdvance()), "Book Now" once on step 3. Its own
      // label already reflects which one it'll do — see gotoStep().
      $("#kfbBookNowBtnMobile").on("click", function () {
        if (state.currentStep === 2) { gotoStep(3); } else { submitBooking(); }
      });
      initSignaturePad();

      // Return trip toggle
      var $retTrip = $("#kfbReturnTripToggle");
      if ($retTrip.length) {
        $retTrip.on("change", function () { applyReturnTrip($retTrip.is(":checked")); });
      }
      $('input[name="returnDate"], input[name="returnTime"]').on("change", function () {
        state.returnDate = $('input[name="returnDate"]').val() || null;
        state.returnTime = $('input[name="returnTime"]').val() || null;
      });

      // Pickup Point (Curbside / Meet & Greet / Private Terminal)
      $("#kfbPickupTypeDetail").on("change", function () {
        state.pickupTypeDetail = $(this).val();
        var $tail = $("#kfbTailNumberWrap");
        if (state.pickupTypeDetail === "Private Terminal (FBO)") {
          $tail.removeAttr("hidden").find("input").prop("required", true);
        } else {
          $tail.attr("hidden", true).find("input").prop("required", false).val("");
          state.tailNumber = "";
        }
      });
      $('input[name="tailNumber"]').on("input", function () {
        state.tailNumber = $(this).val().toUpperCase().replace(/[^A-Z0-9\-]/g, "");
        $(this).val(state.tailNumber);
      });

      // Drop-Off Point (Curbside / Meet & Greet / Private Terminal)
      $("#kfbDropoffTypeDetail").on("change", function () {
        state.dropoffTypeDetail = $(this).val();
        var $tail = $("#kfbDropoffTailNumberWrap");
        if (state.dropoffTypeDetail === "Private Terminal (FBO)") {
          $tail.removeAttr("hidden").find("input").prop("required", true);
        } else {
          $tail.attr("hidden", true).find("input").prop("required", false).val("");
          state.dropoffTailNumber = "";
        }
      });
      $('input[name="dropoffTailNumber"]').on("input", function () {
        state.dropoffTailNumber = $(this).val().toUpperCase().replace(/[^A-Z0-9\-]/g, "");
        $(this).val(state.dropoffTailNumber);
      });

      // Return leg — Pickup Point (Curbside / Meet & Greet / Private Terminal)
      $("#kfbReturnPickupTypeDetail").on("change", function () {
        state.returnPickupTypeDetail = $(this).val();
        var $tail = $("#kfbReturnTailNumberWrap");
        if (state.returnPickupTypeDetail === "Private Terminal (FBO)") {
          $tail.removeAttr("hidden").find("input").prop("required", true);
        } else {
          $tail.attr("hidden", true).find("input").prop("required", false).val("");
          state.returnTailNumber = "";
        }
      });
      $('input[name="returnTailNumber"]').on("input", function () {
        state.returnTailNumber = $(this).val().toUpperCase().replace(/[^A-Z0-9\-]/g, "");
        $(this).val(state.returnTailNumber);
      });

      // Return leg — Drop-Off Point (Curbside / Meet & Greet / Private Terminal)
      $("#kfbReturnDropoffTypeDetail").on("change", function () {
        state.returnDropoffTypeDetail = $(this).val();
        var $tail = $("#kfbReturnDropoffTailNumberWrap");
        if (state.returnDropoffTypeDetail === "Private Terminal (FBO)") {
          $tail.removeAttr("hidden").find("input").prop("required", true);
        } else {
          $tail.attr("hidden", true).find("input").prop("required", false).val("");
          state.returnDropoffTailNumber = "";
        }
      });
      $('input[name="returnDropoffTailNumber"]').on("input", function () {
        state.returnDropoffTailNumber = $(this).val().toUpperCase().replace(/[^A-Z0-9\-]/g, "");
        $(this).val(state.returnDropoffTailNumber);
      });

      // Initial state of side column
      gotoStep(1);
    });
  }

  bootWhenReady();
})();
