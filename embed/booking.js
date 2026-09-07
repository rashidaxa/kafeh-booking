/* ============================================================
   Booking API Widget — V3.1 ("Empire CLS" style)
   ------------------------------------------------------------
   - 3-step wizard: Where & When → Select Vehicle → Payment & Confirm
   - Right column: Map (step 1) OR Trip Summary (steps 2-3)
   - Location type is a button-pill group (not radio)
   - Airport mode reveals Airline / Flight # / Arrival Time / Pickup Point
   - All Google Maps / Places / Directions logic lives in test-map.js
     (which publishes route state on window.kfbRoute)
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
      var host = document.getElementById("kafehBookingWidget") || document.body;
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
      pickup:  { lat: null, lng: null, state: "", country: "" },
      dropoff: { lat: null, lng: null, state: "", country: "" },
      quotesByVehicleId: {}, // { vehicleId: quote } — POST /api/pricing/quote (batched), see fetchQuotes()
      pricingZone: null,     // "local" | "regional" | "long_distance" | "worldwide" from the latest quote
      promo: null,
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
      signatureData: null,   // base64 PNG of customer signature (>$500)
      minFareApplied: 0,     // 0 if subtotal > min, else the min amount (mirrors the latest quote)

      // -------- Customer accounts (v6) --------
      view: "booking",        // "booking" | "login" | "register" | "forgot" | "reset" | "profile"
      authToken: null,        // bearer token, mirrored in localStorage
      customer: null,         // { id, email, first_name, last_name, ... } once logged in
      editingBookingId: null, // set by startEditReservation() — routes submitBooking() to the update endpoint
      savedCards: [],         // display-only: [{id, nickname, card_brand, card_last4, expiry_month, expiry_year, is_default}] — never the full number/CVV
      pendingVerifyEmail: null, // email awaiting OTP verification — set by showVerifyView(), read by doVerifyOtp()/doResendOtp()
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
    // "HH:MM" (24h) -> "Time: hh:MM AM/PM"
    function fmtTime12h(hm) {
      var m = /^(\d{1,2}):(\d{2})/.exec(hm || "");
      if (!m) return "";
      var h = parseInt(m[1], 10);
      var ampm = h >= 12 ? "PM" : "AM";
      h = h % 12; if (h === 0) h = 12;
      return "Time: " + String(h).padStart(2, "0") + ":" + m[2] + " " + ampm;
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
        $overlay.text(isDate ? "Select date" : "Select time").addClass("is-placeholder");
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
      } else {
        $("#kfbMapCard").hide();
        $("#kfbSummaryCard").removeAttr("hidden");
        renderSideSummary();
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
        if (!state.selectedVehicle) { toast("Please choose a vehicle.", "error"); return false; }
        return true;
      }
      return true;
    }

    // ============================================================
    // STEP 1 — WHERE & WHEN
    // ============================================================
    function validateStep1() {
      var missing = [];
      if (!$('input[name="service"]:checked').length) missing.push("Service type");
      if (!$('input[name="pickupDate"]').val()) missing.push("Pickup date");
      if (!$('input[name="pickupTime"]').val()) missing.push("Pickup time");
      if ($('input[name="pickupDate"]').val() && $('input[name="pickupTime"]').val() &&
          isPastDateTime($('input[name="pickupDate"]').val(), $('input[name="pickupTime"]').val())) {
        missing.push("Pickup date/time (can't be in the past)");
      }

      var pickupType = getLocType("pickup");
      if (!$('input[name="pickup"]').val().trim()) missing.push("Pickup location");

      // Dropoff: only if "return at different" is on
      var dropoffType = getLocType("dropoff");
      if ($("#kfbReturnDifferent").is(":checked")) {
        if (!$('input[name="dropoff"]').val().trim()) missing.push("Drop-off location");
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
        if (!$('input[name="returnDate"]').val()) missing.push("Return date");
        if (!$('input[name="returnTime"]').val()) missing.push("Return time");
        if ($('input[name="returnDate"]').val() && $('input[name="returnTime"]').val() &&
            isPastDateTime($('input[name="returnDate"]').val(), $('input[name="returnTime"]').val())) {
          missing.push("Return date/time (can't be in the past)");
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
        toast("Please fill: " + missing.join(", "), "error");
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
        requoteVehicles();
      });
      $("#kfbHoursInput").on("input change", requoteVehicles);
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
        requoteVehicles(); // Airport fee / Meet & Greet auto-surcharges depend on location type
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
        opts.push({ value: value, label: fmtTime12h(value).replace(/^Time: /, "") });
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
      if (stopIndex >= maxStops()) { toast("Maximum " + TRANSFER_MAX_STOPS + " extra stops for Transfer service.", "error"); return; }
      var $c = $("#kfbStopsContainer");
      if (!$c.length) return;
      var i = stopIndex;
      var $row = $(
        '<div class="kfb-stop-row" data-stop="' + i + '">' +
          '<span class="kfb-stop-badge">' + (i + 1) + '</span>' +
          '<input type="text" name="stop[]" class="kfb-stop-input" placeholder="Stop address" autocomplete="off">' +
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
        toast("Trimmed to " + cap + " stops for Transfer service.");
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
      requoteVehicles(); // child seat count is priced server-side (Pricing_engine)
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
    }
    window.addEventListener("kfb:route-updated", function () {
      syncRouteFromMap();
      requoteVehicles();
    });

    // ============================================================
    // PRICING (v16) — server-side authoritative rate engine
    // ------------------------------------------------------------
    // All pricing math (distance zones, travel fee, multipliers,
    // surcharges, gratuity) lives in the backend's Pricing_engine —
    // this file never reimplements the formulas, it just calls
    // POST /api/pricing/quote for a live estimate as the customer fills
    // the form, and caches the response per vehicle so renderVehicles()/
    // recalcSelectedVehicle() can read synchronously. The same endpoint's
    // formulas are what Api::reservation_create() uses to compute the
    // actual charge, so the number shown here IS the number charged
    // (modulo add-ons/promo discount, which stay separate systems — see
    // the note in mergeAddonsAndDiscount() below).
    // ============================================================
    function requestedHours() {
      var n = parseFloat($("#kfbHoursInput").val());
      return isFinite(n) && n > 0 ? n : 4;
    }

    function emptyQuote() {
      return {
        success: false, zone: state.pricingZone || "local",
        requiresQuote: false, requiresQuoteReason: null,
        route_miles: state.distanceMiles || 0,
        transportation: 0, travel_fee: 0,
        surcharges: [], surcharges_total: 0,
        gratuity_pct: 0, gratuity_amount: 0,
        child_seats_count: 0, child_seat_fee_used: 0, child_seats_total: 0,
        taxes_fees: 0, min_fare_applied: 0,
        one_way_total: 0, total: 0, is_return_trip: !!state.isReturnTrip,
      };
    }

    // Add-ons and the promo discount are NOT part of Pricing_engine (they
    // stay separate systems — kfb_addons and Promo_model) — folded in here
    // the same way Api::_apply_quote_to_payload() folds them in
    // server-side: onto the ONE-WAY total, before round-trip doubling, so
    // this estimate always matches what actually gets charged.
    function mergeAddonsAndDiscount(q) {
      var addonsTotal = totalAddons();
      var discount = (state.promo && state.promo.ok) ? +(state.promo.discount || 0) : 0;
      var oneWayTotal = Math.max(0, (q.one_way_total || 0) + addonsTotal - discount);
      // one_way_total never depends on the return-trip flag (Pricing_engine
      // computes it identically either way), so the live state.isReturnTrip
      // toggle can double it instantly here without waiting on a requote.
      var isReturnTrip = !!state.isReturnTrip;
      var total = isReturnTrip ? oneWayTotal * 2 : oneWayTotal;
      return $.extend({}, q, {
        addonsTotal: addonsTotal, discount: discount,
        subtotal: oneWayTotal, oneWayTotal: oneWayTotal, total: total,
        // Legacy field names some call sites/markup still read.
        base: q.transportation, travelFee: q.travel_fee, surcharge: q.surcharges_total,
        gratuity: q.gratuity_amount, gratuityPct: q.gratuity_pct,
        // Already folded into q.one_way_total server-side (Pricing_engine) —
        // exposed here only for display, not added again in oneWayTotal above.
        childSeatsCount: q.child_seats_count || 0, childSeatFee: q.child_seat_fee_used || 0,
        childAdd: q.child_seats_total || 0,
        minFare: q.minimum_fare_used, minFareApplied: q.min_fare_applied,
        isReturnTrip: isReturnTrip, miles: q.route_miles,
        hours: isHourlyService() ? requestedHours() : Math.max(1, (state.durationMins || 0) / 60),
        serviceType: selectedServiceType(), zone: q.zone,
      });
    }

    // Synchronous read of the latest cached quote for this vehicle —
    // never fetches itself (see fetchQuotes()) so every render stays
    // instant; requoteVehicles() is what keeps the cache warm.
    function priceBreakdown(v) {
      syncRouteFromMap();
      var vid = v && (v.id || v.code);
      var q = (vid && state.quotesByVehicleId[vid]) || emptyQuote();
      return mergeAddonsAndDiscount(q);
    }
    function priceFor(v) { return +priceBreakdown(v).total.toFixed(2); }

    // -------- Live quote fetching --------
    var quoteFetchTimer = null;
    var quoteFetchXhr = null;
    function requoteVehicles() {
      clearTimeout(quoteFetchTimer);
      quoteFetchTimer = setTimeout(fetchQuotes, 400);
    }
    function pricingEngineInput() {
      syncRouteFromMap();
      var pickupType  = getLocType("pickup");
      var dropoffType = $("#kfbReturnDifferent").is(":checked") ? getLocType("dropoff") : pickupType;
      return {
        pickupLat: state.pickup.lat, pickupLng: state.pickup.lng,
        pickupState: state.pickup.state, pickupCountry: state.pickup.country,
        dropoffLat: state.dropoff.lat, dropoffLng: state.dropoff.lng,
        dropoffState: state.dropoff.state, dropoffCountry: state.dropoff.country,
        distanceMiles: state.distanceMiles,
        service: selectedServiceType(),
        hours: requestedHours(),
        isReturnTrip: !!state.isReturnTrip,
        pickupType: pickupType, pickupTypeDetail: state.pickupTypeDetail,
        dropoffType: dropoffType, dropoffTypeDetail: state.dropoffTypeDetail,
        pickupTime: $('input[name="pickupTime"]').val() || "",
        childSeats: totalChildSeats(),
        selectedSurchargeCodes: [],
      };
    }
    function fetchQuotes() {
      // Nothing to price until both endpoints are known.
      if (!state.pickup || state.pickup.lat === null || !state.dropoff || state.dropoff.lat === null) return;
      if (quoteFetchXhr) quoteFetchXhr.abort();
      var input = pricingEngineInput();
      quoteFetchXhr = $.ajax({
        url: API_BASE + "/pricing/quote",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify(input),
        dataType: "json",
        timeout: 8000,
      })
      .done(function (res) {
        if (!res || !res.quotes) return;
        state.quotesByVehicleId = res.quotes;
        var anyQuote = null;
        for (var k in res.quotes) { if (res.quotes[k] && res.quotes[k].success) { anyQuote = res.quotes[k]; break; } }
        state.pricingZone = anyQuote ? anyQuote.zone : state.pricingZone;
        renderVehicles();
        recalcSelectedVehicle();
        loadAddons(); // zone may have just changed — re-fetch add-on pricing for it
      })
      .fail(function (xhr) { if (xhr.statusText !== "abort") { /* keep last-known quotes, don't break the page */ } })
      .always(function () { quoteFetchXhr = null; });
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
     * plan), so the new local/regional/long_distance/worldwide zone from
     * the latest quote is mapped onto the closest equivalent bucket.
     * Safe to call multiple times — only the latest response is used.
     */
    function loadAddons() {
      var zoneToRegion = { local: "chicago", regional: "america", long_distance: "america", worldwide: "worldwide" };
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
        var price = Number(a.unit_price || 0);
        // If pricing_type is percent, show a percentage badge
        var type = a.pricing_type || "flat";
        var priceLabel = type === "percent"
          ? (price + "% of subtotal")
          : ("$" + price.toFixed(2));

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
            '<div class="kfb-addon-price">' + priceLabel + '</div>' +
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

    // Recompute the selected vehicle's cached price/breakdown and refresh the
    // summary. Must be called after anything that changes the price inputs
    // (add-ons, pickup/dropoff point, etc.) — renderSideSummary() alone reuses
    // the stale cached breakdown and won't pick up the change.
    function recalcSelectedVehicle() {
      if (state.selectedVehicle) {
        state.selectedVehicle = $.extend({}, state.selectedVehicle, {
          price: priceFor(state.selectedVehicle),
          breakdown: priceBreakdown(state.selectedVehicle),
        });
        renderSideSummary();
        refreshSignatureVisibility();
      }
    }

    function renderVehicles() {
      syncRouteFromMap();
      var $grid = $("#kfbVehicleGrid");
      if (!$grid.length) return;
      var sortBy = $("#kfbSortVehicles").val() || "priceAsc";
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
      if (sortBy === "priceAsc")  list.sort(function (a, b) { return priceFor(a) - priceFor(b); });
      if (sortBy === "priceDesc") list.sort(function (a, b) { return priceFor(b) - priceFor(a); });
      if (sortBy === "capacity")  list.sort(function (a, b) { return capOf(b) - capOf(a); });

      // Deselect if the passenger count grew past the previously-picked vehicle's capacity.
      if (state.selectedVehicle && !list.some(function (v) { return v.id === state.selectedVehicle.id; })) {
        state.selectedVehicle = null;
      }

      var ZONE_LABELS = { local: "Local", regional: "Regional", long_distance: "Long-Distance", worldwide: "Worldwide" };
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
        var bd = priceBreakdown(v);
        var priceDisplay = bd.requiresQuote ? "Request a Quote" : fmtMoney(bd.total);
        var zoneText = ZONE_LABELS[bd.zone] || "Local";
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
            '<div class="kfb-vehicle-price"><b>' + priceDisplay + '</b><small>' +
              (isHourlyService()
                ? bd.hours.toFixed(1) + 'h · ' + zoneText
                : fmtMiles(bd.miles) + ' mi · ' + zoneText
              ) + (bd.isReturnTrip ? ' · round trip (× 2)' : '') + '</small></div>' +
          '</div>'
        );
        $card.on("click", function () {
          state.selectedVehicle = $.extend({}, v, {
            price: priceFor(v),
            breakdown: priceBreakdown(v),
          });
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

      $("#kfbSumWhen").text(
        fmtDateMDY($('input[name="pickupDate"]').val()) + "  " +
        fmtTime12h($('input[name="pickupTime"]').val())
      );
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
        var bd = state.selectedVehicle.breakdown || priceBreakdown(state.selectedVehicle);
        $("#kfbBreakdown").show();
        $("#kfbSumBase").text(fmtMoney(bd.base));
        $("#kfbSumGratuity").text(fmtMoney(bd.gratuity) + " (" + bd.gratuityPct + "%)");
        $("#kfbSumTaxesFees").text(fmtMoney(bd.taxes_fees || 0));
        // Child seats — same rate per seat regardless of type, × count.
        if (bd.childAdd > 0) {
          $("#kfbSumChildSeatsRow").show();
          $("#kfbSumChildSeats").text(fmtMoney(bd.childAdd) + " (" + bd.childSeatsCount + " seat" + (bd.childSeatsCount === 1 ? "" : "s") + ")");
        } else {
          $("#kfbSumChildSeatsRow").hide();
        }
        // Travel fee — beyond the local service radius only.
        if (bd.travelFee > 0) {
          $("#kfbSumTravelFeeRow").show();
          $("#kfbSumTravelFee").text(fmtMoney(bd.travelFee));
        } else {
          $("#kfbSumTravelFeeRow").hide();
        }
        // Surcharges — itemized list from the pricing engine (Airport fee,
        // Meet & Greet, etc. — whichever apply to this trip).
        var $surchargesRow = $("#kfbSumSurchargesRow");
        if ($surchargesRow.length) {
          if (bd.surcharges && bd.surcharges.length) {
            $surchargesRow.show();
            var surchargeLines = bd.surcharges.map(function (s) {
              return '<div class="kfb-addon-line"><span>' + escapeHtml(s.name) + '</span><b>' + fmtMoney(s.amount) + '</b></div>';
            }).join("");
            if (bd.surcharges.length > 1) {
              surchargeLines += '<div class="kfb-addon-line kfb-addon-line--total"><span>Total</span><b>' + fmtMoney(bd.surcharge) + '</b></div>';
            }
            $("#kfbSumSurcharges").html(surchargeLines);
          } else {
            $surchargesRow.hide();
          }
        }
        // Add-ons — itemized: each selected add-on's name + amount, plus the total.
        var $addonsRow = $("#kfbSumAddonsRow");
        if ($addonsRow.length) {
          if (state.addons && state.addons.length) {
            $addonsRow.show();
            var addonLines = state.addons.map(function (a) {
              var label = escapeHtml(a.name) + (a.quantity > 1 ? " × " + a.quantity : "");
              return '<div class="kfb-addon-line"><span>' + label + '</span><b>' + fmtMoney(a.line_total) + '</b></div>';
            }).join("");
            // Only show a separate total once there's more than one line to sum.
            if (state.addons.length > 1) {
              addonLines += '<div class="kfb-addon-line kfb-addon-line--total"><span>Total</span><b>' + fmtMoney(bd.addonsTotal) + '</b></div>';
            }
            $("#kfbSumAddons").html(addonLines);
          } else {
            $addonsRow.hide();
          }
        }
        if (bd.discount > 0) {
          $("#kfbSumDiscountRow").show();
          $("#kfbSumDiscount").text(
            '− ' + fmtMoney(bd.discount) + (state.promo ? ' (' + escapeHtml(state.promo.code) + ')' : '')
          );
        } else {
          $("#kfbSumDiscountRow").hide();
        }
        // Round trip: shows the extra amount added by doubling the
        // one-way fare, so the line items above still add up to what's
        // displayed below rather than silently jumping to 2×.
        var $returnTripRow = $("#kfbSumReturnTripRow");
        if ($returnTripRow.length) {
          if (bd.isReturnTrip) {
            $returnTripRow.show();
            $("#kfbSumReturnTrip").text("+ " + fmtMoney(bd.oneWayTotal));
          } else {
            $returnTripRow.hide();
          }
        }
        $("#kfbSumTotal").text(bd.requiresQuote ? "Request a Quote" : fmtMoney(bd.total));
      } else {
        $("#kfbSumVehicle").text("—");
        $("#kfbBreakdown").hide();
        $("#kfbSumTotal").text("$0.00");
      }
    }

    // ============================================================
    // PROMO CODE
    // ============================================================
    function applyPromo() {
      if (state.promo && state.promo.ok) {
        state.promo = null;
        renderPromoState();
        toast("Promo code removed.");
        return;
      }
      var code = ($("#kfbPromoInput").val() || "").trim();
      if (!code) { toast("Enter a promo code first.", "error"); return; }
      var v = state.selectedVehicle;
      if (!v) { toast("Choose a vehicle first.", "error"); return; }
      var subtotal = (v.breakdown && v.breakdown.subtotal) || priceBreakdown(v).subtotal;
      $("#kfbPromoApply").prop("disabled", true).text("Checking…");
      $.ajax({
        url: API_BASE + "/promo/validate",
        method: "GET",
        data: { code: code, amount: subtotal.toFixed(2) },
        dataType: "json",
        timeout: 5000,
      })
      .done(function (res) {
        if (res && res.ok) {
          state.promo = {
            code: res.code, ok: true,
            discount: +(res.discount || 0),
            final: +(res.final || 0),
            reason: res.reason || "ok",
            description: res.description || "",
          };
          toast("Promo code applied — " + fmtMoney(res.discount) + " off!");
        } else {
          state.promo = {
            code: code.toUpperCase(), ok: false,
            reason: (res && res.reason) || "not_found",
            discount: 0, final: subtotal,
          };
          toast(promoReasonText(state.promo.reason), "error");
        }
        renderPromoState();
      })
      .fail(function () { toast("Could not validate promo code (network error).", "error"); })
      .always(function () { $("#kfbPromoApply").prop("disabled", false); });
    }

    function renderPromoState() {
      var $box = $("#kfbPromoStatus");
      if (!$box.length) return;
      if (state.promo && state.promo.ok) {
        $box.removeClass("is-error").addClass("is-ok")
          .html('<span class="kfb-promo-tick">✓</span> <strong>' + escapeHtml(state.promo.code) + '</strong> applied — you save <b>' + fmtMoney(state.promo.discount) + '</b>')
          .show();
        $("#kfbPromoInput").prop("disabled", true);
        $("#kfbPromoApply").text("Remove");
        $("#kfbPromoInput").val(state.promo.code);
      } else if (state.promo && state.promo.reason && state.promo.reason !== "not_found") {
        $box.removeClass("is-ok").addClass("is-error")
          .html('Promo code <b>' + escapeHtml(state.promo.code) + '</b>: ' + promoReasonText(state.promo.reason))
          .show();
        $("#kfbPromoInput").prop("disabled", false);
        $("#kfbPromoApply").text("Apply");
      } else {
        $box.removeClass("is-ok is-error").empty().hide();
        $("#kfbPromoInput").prop("disabled", false);
        $("#kfbPromoApply").text("Apply");
      }
      recalcSelectedVehicle();
    }

    function promoReasonText(reason) {
      switch (reason) {
        case "not_found":   return "we couldn't find this code.";
        case "disabled":    return "this code is no longer active.";
        case "expired":     return "this code has expired.";
        case "not_started": return "this code isn't active yet.";
        case "max_uses":    return "this code has been fully used.";
        case "min_amount":  return "your subtotal doesn't meet the minimum for this code.";
        default:            return "this code can't be applied.";
      }
    }

    // ============================================================
    // RESERVATION + PAYPAL
    // ============================================================
    /**
     * Builds the reservation payload from the current form state — shared
     * by createReservation() (POST /reservation) and updateReservation()
     * (POST /reservation/:id/update), which are otherwise identical
     * except for the URL/method. Returns NULL if no vehicle is selected.
     */
    function buildReservationPayload() {
      if (!state.selectedVehicle) return null;
      // Force a fresh price/breakdown right before we charge — anything that
      // changed the route, add-ons, or child seats since the vehicle was
      // selected must be reflected in the amount we're about to bill.
      recalcSelectedVehicle();
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
        minFareApplied:  v.breakdown ? v.breakdown.minFareApplied || 0 : 0,
        vehicle_id:      v.id || v.code,
        vehicle_name:    v.name,
        distanceMiles:   state.distanceMiles,
        durationMins:    state.durationMins,
        // Pricing_engine inputs (v16) — the backend recomputes/overwrites
        // `amount` authoritatively from these; see Api::_pricing_engine_input().
        pickupLat:       state.pickup.lat,
        pickupLng:       state.pickup.lng,
        pickupState:     state.pickup.state,
        pickupCountry:   state.pickup.country,
        dropoffLat:      state.dropoff.lat,
        dropoffLng:      state.dropoff.lng,
        dropoffState:    state.dropoff.state,
        dropoffCountry:  state.dropoff.country,
        hours:           requestedHours(),
        selectedSurchargeCodes: [],
        amount:          v.breakdown.total,
        discountAmount:  (state.promo && state.promo.ok) ? state.promo.discount : 0,
        promoCode:       (state.promo && state.promo.ok) ? state.promo.code : null,
        firstName:       $('input[name="firstName"]').val(),
        lastName:        $('input[name="lastName"]').val(),
        email:           $('input[name="email"]').val(),
        phone:           $('input[name="phone"]').val(),
        cardBillingAddress:   $('input[name="cardBillingAddress"]').val()   || null,
      };

      // A selected saved card submits ITS OWN (display-only) info instead
      // of reading the now-hidden form fields — there's no full number/
      // CVV to read anyway, since neither is ever stored (see
      // Customer_model::add_card()). "Card number" here is a masked
      // brand+last4 label, same as everywhere else this card is shown.
      var savedCard = selectedSavedCard();
      if (savedCard) {
        payload.cardHolderName = $('input[name="cardHolderName"]').val() || null;
        payload.cardNumber = savedCard.card_last4;
        payload.cardExpiry = cardExpiryLabel(savedCard);
        payload.cvv = savedCard.cvv;
      } else {
        payload.cardHolderName = $('input[name="cardHolderName"]').val() || null;
        payload.cardNumber     = $('input[name="cardNumber"]').val()     || null;
        payload.cardExpiry     = $('input[name="cardExpiry"]').val()     || null;
        payload.cvv            = $('input[name="cvv"]').val()             || null;
      }

      return payload;
    }

    function createReservation() {
      var payload = buildReservationPayload();
      if (!payload) { toast("No vehicle selected.", "error"); return $.Deferred().reject().promise(); }
      return $.ajax({
        url: API_BASE + "/reservation",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify(payload),
        dataType: "json",
        timeout: 10000,
      });
    }

    /** The edit counterpart to createReservation() — same payload, existing booking_id, auth required. */
    function updateReservation() {
      var payload = buildReservationPayload();
      if (!payload) { toast("No vehicle selected.", "error"); return $.Deferred().reject().promise(); }
      return $.ajax({
        url: API_BASE + "/reservation/" + encodeURIComponent(state.editingBookingId) + "/update",
        method: "POST",
        contentType: "application/json",
        headers: authHeaders(),
        data: JSON.stringify(payload),
        dataType: "json",
        timeout: 10000,
      });
    }
    /**
     * Open a PayPal order for the trip amount (intent=AUTHORIZE — nothing
     * is held yet) and return the approval URL to redirect the browser
     * to. return_url/cancel_url point back at this same page (minus any
     * query string) so handlePaypalReturn() can pick up where we left
     * off once PayPal redirects back.
     */
    function createPaypalOrder() {
      var v = state.selectedVehicle;
      if (!v) return $.Deferred().reject().promise();
      var pageUrl = location.href.split("#")[0].split("?")[0];
      var returnUrl = API_BASE + "/paypal/return?booking_id=" + encodeURIComponent(state.bookingId) +
        "&site_return=" + encodeURIComponent(pageUrl);
      var cancelUrl = API_BASE + "/paypal/cancel?booking_id=" + encodeURIComponent(state.bookingId) +
        "&site_return=" + encodeURIComponent(pageUrl);
      return $.ajax({
        url: API_BASE + "/paypal/create-order",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify({
          booking_id: state.bookingId,
          amount:     v.breakdown.total.toFixed(2),
          return_url: returnUrl,
          cancel_url: cancelUrl,
        }),
        dataType: "json",
        timeout: 20000,
      });
    }

    /**
     * Book Now: validate → create the reservation → save signature (if
     * required) → open a PayPal order → redirect the browser to PayPal to
     * approve it. Nothing is charged here — the hold is placed once
     * PayPal redirects back (see handlePaypalReturn()), and an admin
     * still has to accept the reservation before funds are captured.
     */
    function submitBooking() {
      var v = state.selectedVehicle;
      if (!v) { toast("Choose a vehicle first.", "error"); return; }
      var bd = priceBreakdown(v);
      if (bd.requiresQuote) {
        toast(bd.requiresQuoteReason || "This trip requires a custom quote — please contact us.", "error");
        return;
      }

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
      // Optional field — only checked (and flagged) if the customer filled it in.
      function checkOptionalField(name, label, validator) {
        var $el = $('input[name="' + name + '"]');
        var val = ($el.val() || "").trim();
        if (!val) { markFieldValid($el, true); return; }
        var ok = validator(val);
        markFieldValid($el, ok);
        if (!ok) { invalid.push(label); if (!$firstBad) $firstBad = $el; }
      }

      requireField("firstName", "First Name", isValidName);
      requireField("lastName", "Last Name", isValidName);
      requireField("email", "Email", isValidEmail);
      requireField("phone", "Phone", isValidPhone);
      // Card fields are hidden (and their values unused — see
      // buildReservationPayload()) when a saved card is selected, so
      // don't require/validate them in that case.
      if (!selectedSavedCard()) {
        requireField("cardNumber", "Card Number", isValidCardNumber);
        requireField("cardExpiry", "Card Expiry", isValidCardExpiry);
        requireField("cvv", "CVV", isValidCVV);
      }

      if ($("#kfbTermsBlock").is(":visible") && !$('input[name="terms"]').is(":checked")) {
        missing.push("Terms & Conditions");
      }
      if ($("#kfbSignatureBlock").is(":visible") && !state.signatureData) {
        missing.push("Signature");
      }

      if (missing.length || invalid.length) {
        var parts = [];
        if (missing.length) parts.push("Please fill: " + missing.join(", "));
        if (invalid.length) parts.push("Please check the format of: " + invalid.join(", "));
        toast(parts.join(" — "), "error");
        if ($firstBad) $firstBad.focus();
        return;
      }

      var $btn = $("#kfbBookNowBtn");
      $btn.prop("disabled", true);
      var isEditing = !!state.editingBookingId;
      $("#kfbPaymentStatus").show().find("p").text(isEditing ? "Saving your changes…" : "Creating your reservation…");

      // Captured across the .then() chain — updateReservation()'s
      // price_changed flag decides whether we still need a PayPal
      // round-trip, but that response is two steps back by the time
      // saveSignature() resolves.
      var editResult = null;

      (isEditing ? updateReservation() : createReservation())
        .then(function (res) {
          editResult = res;
          state.bookingId = res.booking_id;
          // Signature is saved either way — the customer just drew a fresh
          // one to confirm this submission, price-changing or not.
          return saveSignature();
        })
        .then(function () {
          if (isEditing && editResult && editResult.price_changed === false) {
            // No PayPal round-trip needed — the edit is already saved.
            $("#kfbPaymentStatus").hide();
            $btn.prop("disabled", false);
            toast("Reservation updated ✓");
            state.editingBookingId = null;
            setView("profile");
            return;
          }
          $("#kfbPaymentStatus").find("p").text("Redirecting you to PayPal…");
          return createPaypalOrder().then(function (res) {
            window.location.href = res.approve_url;
            // Browser navigates away here — nothing after this line runs.
          });
        })
        .catch(function (err) {
          $("#kfbPaymentStatus").hide();
          $btn.prop("disabled", false);
          if (isEditing && handleAuthFailure(err)) return;
          var msg = (err && err.responseJSON && err.responseJSON.error) || (err && err.message) || "Could not process payment.";
          toast(msg, "error");
        });
    }

    /**
     * Runs once at boot. If the page was just loaded because PayPal
     * redirected back here (?kfb_paypal=success|cancelled|error), show
     * the right outcome and strip the params so a refresh doesn't replay
     * it. Page-reload form state is gone at this point, so on success we
     * re-fetch the booking to populate the confirmation screen.
     */
    function handlePaypalReturn() {
      var params = new URLSearchParams(location.search);
      var status = params.get("kfb_paypal");
      if (!status) return;
      var bookingId = params.get("booking_id") || "";
      var message   = params.get("kfb_paypal_message") || "";

      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, "", location.pathname + location.hash);
      }

      if (status === "success") {
        state.bookingId = bookingId;
        if (!bookingId) { showSuccess(bookingId); return; }
        $.ajax({ url: API_BASE + "/reservation/" + encodeURIComponent(bookingId), method: "GET", dataType: "json", timeout: 10000 })
          .then(function (res) {
            if (res && res.first_name) $('input[name="firstName"]').val(res.first_name);
            if (res && res.email)      $('input[name="email"]').val(res.email);
            showSuccess(bookingId);
            // Check authToken, not state.customer — the /customers/me
            // call that populates state.customer is still async and may
            // not have resolved yet even with the reorder above (this GET
            // itself is a network round trip). authToken is set
            // synchronously in checkAuthOnBoot() the moment a stored
            // token exists, so it's the reliable "already logged in" signal here.
            if (!state.authToken) promptAccountCreation(bookingId);
          })
          .catch(function () { showSuccess(bookingId); });
      } else if (status === "cancelled") {
        toast("PayPal checkout was cancelled — you can try again.", "error");
      } else {
        toast(message || "PayPal reported a problem completing your payment.", "error");
      }
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
      // Price depends on isReturnTrip (round trip = double the one-way
      // fare) — refresh immediately rather than leaving a stale one-way
      // price on screen until the next unrelated recalc.
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

    /**
     * Save the captured signature to the backend.
     * Called from submitBooking() right after the card is authorized.
     */
    function saveSignature() {
      if (!state.bookingId || !state.signatureData) return $.Deferred().resolve().promise();
      return $.ajax({
        url: API_BASE + "/reservation/sign",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify({
          booking_id:    state.bookingId,
          signature:     state.signatureData,
          terms_version: "v1",
        }),
        dataType: "json",
        timeout: 10000,
      });
    }

    // ============================================================
    // CUSTOMER ACCOUNTS (v6) — login, profile, reservation editing
    // ============================================================
    var TOKEN_STORAGE_KEY = "kfb_token";

    function authHeaders() {
      return state.authToken ? { Authorization: "Bearer " + state.authToken } : {};
    }
    function saveToken(token) {
      state.authToken = token;
      try { localStorage.setItem(TOKEN_STORAGE_KEY, token); } catch (e) { /* storage disabled — token still works for this page load */ }
    }
    function clearAuth() {
      state.authToken = null;
      state.customer = null;
      state.savedCards = [];
      try { localStorage.removeItem(TOKEN_STORAGE_KEY); } catch (e) { /* ignore */ }
      renderAccountBar();
      renderSavedCardDropdown();
    }
    function renderAccountBar() {
      var loggedIn = !!state.customer;
      $("#kfbAccountBarGuest").toggle(!loggedIn);
      $("#kfbAccountBarUser").toggle(loggedIn);
      if (loggedIn) {
        $("#kfbAccountBarName").text(state.customer.first_name || state.customer.email || "there");
      }
    }

    /**
     * Pre-fills first/last name + phone from the logged-in customer's
     * account and locks the email field to the account email — a
     * logged-in customer can't create/edit a reservation under a
     * different email, since that would silently detach it from their
     * account (reservations are looked up by customer_id, not by the
     * email typed into the form).
     */
    function prefillContactFromCustomer() {
      if (!state.customer) return;
      $('input[name="firstName"]').val(state.customer.first_name || "");
      $('input[name="lastName"]').val(state.customer.last_name || "");
      if (state.customer.phone) $('input[name="phone"]').val(state.customer.phone);
      lockEmailToAccount(state.customer.email);
    }

    function lockEmailToAccount(email) {
      $('input[name="email"]').val(email || "").prop("readonly", true).addClass("kfb-field-locked");
    }

    function unlockEmailField() {
      $('input[name="email"]').prop("readonly", false).removeClass("kfb-field-locked");
    }

    /**
     * Toggles between the 3-step booking wizard and the login/register/
     * forgot/reset/profile views. Orthogonal to state.currentStep (1-3),
     * which stays meaningful only while view === "booking" — gotoStep()
     * itself is completely untouched.
     */
    function setView(view) {
      state.view = view;
      $("#kfbBookingView, .kfb-view").attr("hidden", true);
      if (view === "booking") {
        $("#kfbBookingView").removeAttr("hidden");
      } else {
        $("#kfb" + view.charAt(0).toUpperCase() + view.slice(1) + "View").removeAttr("hidden");
      }
      if (view === "profile") {
        loadMyReservations();
        populateProfileSettingsForm();
        setProfileTab("reservations");
      }
      $("html, body").animate({ scrollTop: 0 }, 150);
    }

    /** Switches between the Profile sidebar panels: reservations / account / password / cards. */
    function setProfileTab(tab) {
      $(".kfb-profile-tab").removeClass("is-active");
      $('.kfb-profile-tab[data-profile-tab="' + tab + '"]').addClass("is-active");
      $(".kfb-profile-panel").attr("hidden", true);
      $("#kfbProfilePanel" + tab.charAt(0).toUpperCase() + tab.slice(1)).removeAttr("hidden");
      if (tab === "cards") loadSavedCards();
    }

    function showViewError($el, message) {
      $el.text(message).removeAttr("hidden");
    }

    /** Resolves any existing stored token to a logged-in customer at boot. Silent — no error shown on failure, just falls back to logged-out. */
    function checkAuthOnBoot() {
      var stored = null;
      try { stored = localStorage.getItem(TOKEN_STORAGE_KEY); } catch (e) { /* ignore */ }
      if (!stored) { renderAccountBar(); return; }
      state.authToken = stored;
      $.ajax({ url: API_BASE + "/customers/me", method: "GET", dataType: "json", headers: authHeaders(), timeout: 8000 })
        .done(function (res) {
          if (res && res.success) { state.customer = res.customer; renderAccountBar(); prefillContactFromCustomer(); loadSavedCards(); }
          else clearAuth();
        })
        .fail(function () { clearAuth(); });
    }

    function doLogin(email, password) {
      $.ajax({
        url: API_BASE + "/customers/login", method: "POST", contentType: "application/json",
        data: JSON.stringify({ email: email, password: password }), dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        if (res && res.success) {
          saveToken(res.token);
          state.customer = res.customer;
          renderAccountBar();
          prefillContactFromCustomer();
          loadSavedCards();
          $("#kfbLoginError").attr("hidden", true);
          $('#kfbLoginView input').val("");
          setView("booking");
          toast("Logged in — welcome back!");
        } else {
          showViewError($("#kfbLoginError"), (res && res.error) || "Could not log in.");
        }
      })
      .fail(function (xhr) {
        var body = xhr.responseJSON;
        if (xhr.status === 403 && body && body.requires_verification) {
          showVerifyView(body.email || email);
          toast(body.error || "Please verify your email first.", "error");
          return;
        }
        showViewError($("#kfbLoginError"), (body && body.error) || "Invalid email or password.");
      });
    }

    /** Switches to the OTP-entry view for the given (pending-verification) email. */
    function showVerifyView(email) {
      state.pendingVerifyEmail = email;
      $("#kfbVerifyEmailLabel").text("We sent a 6-digit code to " + email + ".");
      $("#kfbVerifyError").attr("hidden", true);
      $('input[name="verifyOtp"]').val("");
      setView("verify");
    }

    function doVerifyOtp() {
      var email = state.pendingVerifyEmail;
      var otp = ($('input[name="verifyOtp"]').val() || "").trim();
      if (!email) { setView("login"); return; }
      if (!/^\d{6}$/.test(otp)) return showViewError($("#kfbVerifyError"), "Enter the 6-digit code from your email.");

      $.ajax({
        url: API_BASE + "/customers/verify-email", method: "POST", contentType: "application/json",
        data: JSON.stringify({ email: email, otp: otp }), dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        if (res && res.success) {
          saveToken(res.token);
          state.customer = res.customer;
          state.pendingVerifyEmail = null;
          renderAccountBar();
          prefillContactFromCustomer();
          loadSavedCards();
          $('input[name="verifyOtp"]').val("");
          setView("booking");
          toast("Email verified — welcome!");
        } else {
          showViewError($("#kfbVerifyError"), (res && res.error) || "Invalid or expired code.");
        }
      })
      .fail(function (xhr) {
        showViewError($("#kfbVerifyError"), (xhr.responseJSON && xhr.responseJSON.error) || "Invalid or expired code.");
      });
    }

    function doResendOtp() {
      var email = state.pendingVerifyEmail;
      if (!email) return;
      $.ajax({
        url: API_BASE + "/customers/resend-otp", method: "POST", contentType: "application/json",
        data: JSON.stringify({ email: email }), dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        if (res && res.already_verified) {
          toast("This email is already verified — please log in.");
          setView("login");
        } else {
          toast("A new code has been sent.");
        }
      })
      .fail(function () { toast("Could not resend the code — try again shortly.", "error"); });
    }

    function doLogout() {
      $.ajax({ url: API_BASE + "/customers/logout", method: "POST", headers: authHeaders(), timeout: 5000 });
      clearAuth();
      unlockEmailField();
      if (state.view === "profile" || state.editingBookingId) {
        state.editingBookingId = null;
        setView("booking");
      }
      toast("Logged out.");
    }

    /**
     * Shared 401 handler for every authenticated $.ajax call below — an
     * expired/revoked token should degrade gracefully to logged-out
     * everywhere, not just wherever it was first noticed.
     */
    function handleAuthFailure(xhr) {
      if (xhr && xhr.status === 401) {
        clearAuth();
        toast("Your session expired — please log in again.", "error");
        setView("login");
        return true;
      }
      return false;
    }

    function doRegisterStandalone() {
      var $view = $("#kfbRegisterView");
      var email = $('input[name="registerEmail"]').val();
      var password = $('input[name="registerPassword"]').val();
      var firstName = $('input[name="registerFirstName"]').val();
      var lastName = $('input[name="registerLastName"]').val();
      var phone = $('input[name="registerPhone"]').val();
      if (!isValidEmail(email)) return showViewError($("#kfbRegisterError"), "Enter a valid email address.");
      if (!password || password.length < 6) return showViewError($("#kfbRegisterError"), "Password must be at least 6 characters.");

      $.ajax({
        url: API_BASE + "/customers/register", method: "POST", contentType: "application/json",
        data: JSON.stringify({ email: email, password: password, first_name: firstName, last_name: lastName, phone: phone }),
        dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        if (res && res.success) {
          $("#kfbRegisterError").attr("hidden", true);
          $('#kfbRegisterView input').val("");
          // Not logged in yet — the account isn't loginable until the OTP
          // just emailed is verified (see customer_register()'s docblock).
          showVerifyView(res.email || email);
        } else {
          showViewError($("#kfbRegisterError"), (res && res.error) || "Could not create account.");
        }
      })
      .fail(function (xhr) {
        showViewError($("#kfbRegisterError"), (xhr.responseJSON && xhr.responseJSON.error) || "Could not create account.");
      });
    }

    function doForgotPassword() {
      var email = $('input[name="forgotEmail"]').val();
      if (!isValidEmail(email)) return showViewError($("#kfbForgotError"), "Enter a valid email address.");
      $("#kfbForgotError").attr("hidden", true);
      var resetUrlBase = location.href.split("#")[0].split("?")[0];
      $.ajax({
        url: API_BASE + "/customers/forgot-password", method: "POST", contentType: "application/json",
        data: JSON.stringify({ email: email, reset_url_base: resetUrlBase }), dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        $("#kfbForgotSuccess").text((res && res.message) || "If that email is registered, a reset link has been sent.").removeAttr("hidden");
      })
      .fail(function () {
        showViewError($("#kfbForgotError"), "Network error — please try again.");
      });
    }

    function doResetPassword() {
      var token = $('input[name="resetToken"]').val();
      var password = $('input[name="resetPassword"]').val();
      if (!password || password.length < 6) return showViewError($("#kfbResetError"), "Password must be at least 6 characters.");
      $.ajax({
        url: API_BASE + "/customers/reset-password", method: "POST", contentType: "application/json",
        data: JSON.stringify({ token: token, password: password }), dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        if (res && res.success) {
          toast("Password updated — you can log in now.");
          $('#kfbResetView input[name="resetPassword"]').val("");
          setView("login");
        } else {
          showViewError($("#kfbResetError"), (res && res.error) || "This reset link is invalid or has expired.");
        }
      })
      .fail(function (xhr) {
        showViewError($("#kfbResetError"), (xhr.responseJSON && xhr.responseJSON.error) || "This reset link is invalid or has expired.");
      });
    }

    /** ?kfb_reset_token=... on load → jump straight to the reset view, same pattern handlePaypalReturn() uses for ?kfb_paypal=. */
    function handlePasswordResetLink() {
      var params = new URLSearchParams(location.search);
      var token = params.get("kfb_reset_token");
      if (!token) return;
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, "", location.pathname + location.hash);
      }
      $('input[name="resetToken"]').val(token);
      setView("reset");
    }

    // -------- Profile: reservations list --------
    function loadMyReservations() {
      var $list = $("#kfbReservationsList");
      $list.html('<p class="kfb-empty">Loading…</p>');
      $.ajax({ url: API_BASE + "/customers/reservations", method: "GET", dataType: "json", headers: authHeaders(), timeout: 10000 })
        .done(function (res) {
          if (!res || !res.success) { $list.html('<p class="kfb-empty">Could not load your reservations.</p>'); return; }
          renderReservationsList(res.reservations || []);
        })
        .fail(function (xhr) {
          if (handleAuthFailure(xhr)) return;
          $list.html('<p class="kfb-empty">Could not load your reservations.</p>');
        });
    }

    function renderReservationsList(rows) {
      var $list = $("#kfbReservationsList");
      $list.empty();
      if (!rows.length) {
        $list.html('<p class="kfb-empty">No reservations yet.</p>');
        return;
      }
      rows.forEach(function (r) {
        var statusLabel = (r.status || "").replace(/_/g, " ");
        var $card = $(
          '<div class="kfb-reservation-card">' +
            '<div class="kfb-reservation-main">' +
              '<div class="kfb-reservation-route">' + escapeHtml(r.pickup || "—") + ' → ' + escapeHtml(r.dropoff || "—") + '</div>' +
              '<div class="kfb-reservation-meta">' + escapeHtml(fmtDateMDY(r.pickup_date)) + ' · ' + escapeHtml(r.vehicle_name || "—") + ' · ' + escapeHtml(r.booking_id) + '</div>' +
              '<div class="kfb-reservation-amount">' + fmtMoney(r.amount) + '</div>' +
            '</div>' +
            '<div class="kfb-reservation-side">' +
              '<span class="kfb-status-badge kfb-status-badge--' + escapeHtml(r.status) + '">' + (escapeHtml(statusLabel) == 'paid' ? 'Confirmed' : escapeHtml(statusLabel)) + '</span>' +
            '</div>' +
          '</div>'
        );
        if (r.editable) {
          $card.find(".kfb-reservation-side").append(
            $('<button type="button" class="kfb-btn kfb-btn-ghost kfb-btn--sm">Edit</button>')
              .on("click", function () { startEditReservation(r.booking_id); })
          );
        }
        $list.append($card);
      });
    }

    // -------- Profile: account settings (edit info / change password) --------
    function populateProfileSettingsForm() {
      if (!state.customer) return;
      $('input[name="profileFirstName"]').val(state.customer.first_name || "");
      $('input[name="profileLastName"]').val(state.customer.last_name || "");
      $('input[name="profilePhone"]').val(state.customer.phone || "");
      $('input[name="profileEmail"]').val(state.customer.email || "");
      $("#kfbProfileUpdateError, #kfbProfileUpdateSuccess").attr("hidden", true);
      $('input[name="profileCurrentPassword"], input[name="profileNewPassword"]').val("");
      $("#kfbPasswordChangeError, #kfbPasswordChangeSuccess").attr("hidden", true);
    }

    function doUpdateProfile() {
      var firstName = $('input[name="profileFirstName"]').val();
      var lastName = $('input[name="profileLastName"]').val();
      var phone = $('input[name="profilePhone"]').val();
      var email = $('input[name="profileEmail"]').val();
      $("#kfbProfileUpdateError, #kfbProfileUpdateSuccess").attr("hidden", true);
      if (!isValidName(firstName) || !isValidName(lastName)) {
        return showViewError($("#kfbProfileUpdateError"), "Enter a valid first and last name.");
      }
      if (!isValidEmail(email)) return showViewError($("#kfbProfileUpdateError"), "Enter a valid email address.");

      $.ajax({
        url: API_BASE + "/customers/update", method: "POST", contentType: "application/json",
        headers: authHeaders(),
        data: JSON.stringify({ first_name: firstName, last_name: lastName, phone: phone, email: email }),
        dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        if (res && res.success) {
          state.customer = res.customer;
          renderAccountBar();
          // Keep the booking form's contact fields in sync — otherwise
          // they'd keep showing whatever was there from login/registration
          // until the next full page load.
          prefillContactFromCustomer();
          $("#kfbProfileUpdateSuccess").removeAttr("hidden");
          toast("Profile updated ✓");
        } else {
          showViewError($("#kfbProfileUpdateError"), (res && res.error) || "Could not update profile.");
        }
      })
      .fail(function (xhr) {
        if (handleAuthFailure(xhr)) return;
        showViewError($("#kfbProfileUpdateError"), (xhr.responseJSON && xhr.responseJSON.error) || "Could not update profile.");
      });
    }

    function doChangePassword() {
      var current = $('input[name="profileCurrentPassword"]').val();
      var next = $('input[name="profileNewPassword"]').val();
      $("#kfbPasswordChangeError, #kfbPasswordChangeSuccess").attr("hidden", true);
      if (!current) return showViewError($("#kfbPasswordChangeError"), "Enter your current password.");
      if (!next || next.length < 6) return showViewError($("#kfbPasswordChangeError"), "New password must be at least 6 characters.");

      $.ajax({
        url: API_BASE + "/customers/change-password", method: "POST", contentType: "application/json",
        headers: authHeaders(),
        data: JSON.stringify({ current_password: current, new_password: next }),
        dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        if (res && res.success) {
          $('input[name="profileCurrentPassword"], input[name="profileNewPassword"]').val("");
          $("#kfbPasswordChangeSuccess").removeAttr("hidden");
          toast("Password updated ✓");
        } else {
          showViewError($("#kfbPasswordChangeError"), (res && res.error) || "Could not update password.");
        }
      })
      .fail(function (xhr) {
        if (handleAuthFailure(xhr)) return;
        showViewError($("#kfbPasswordChangeError"), (xhr.responseJSON && xhr.responseJSON.error) || "Could not update password.");
      });
    }

    // -------- Profile: saved cards (display-only — see Customer_model.php) --------
    function cardExpiryLabel(card) {
      var mm = ("0" + card.expiry_month).slice(-2);
      var yy = String(card.expiry_year).slice(-2);
      return mm + "/" + yy;
    }
    function cardLabel(card) {
      var label = card.card_brand + " •••• " + card.card_last4.slice(-4);
      if (card.nickname) label += " (" + card.nickname + ")";
      label += " — exp " + cardExpiryLabel(card);
      return label;
    }

    function loadSavedCards() {
      if (!state.customer) { state.savedCards = []; renderSavedCardDropdown(); return; }
      $.ajax({ url: API_BASE + "/customers/cards", method: "GET", dataType: "json", headers: authHeaders(), timeout: 10000 })
        .done(function (res) {
          state.savedCards = (res && res.success) ? (res.cards || []) : [];
          renderSavedCardsList(state.savedCards);
          renderSavedCardDropdown();
        })
        .fail(function (xhr) {
          if (handleAuthFailure(xhr)) return;
          $("#kfbSavedCardsList").html('<p class="kfb-empty">Could not load saved cards.</p>');
        });
    }

    function renderSavedCardsList(cards) {
      var $list = $("#kfbSavedCardsList");
      $list.empty();
      if (!cards.length) {
        $list.html('<p class="kfb-empty">No saved cards yet.</p>');
        return;
      }
      cards.forEach(function (c) {
        var $row = $(
          '<div class="kfb-saved-card-row">' +
            '<div class="kfb-saved-card-main">' +
              '<span class="kfb-saved-card-brand">' + escapeHtml(c.card_brand) + ' •••• ' + escapeHtml(c.card_last4.slice(-4)) + '</span>' +
              (c.nickname ? '<span class="kfb-saved-card-nickname">' + escapeHtml(c.nickname) + '</span>' : '') +
              '<span class="kfb-saved-card-expiry">exp ' + escapeHtml(cardExpiryLabel(c)) + '</span>' +
            '</div>' +
            '<div class="kfb-saved-card-side"></div>' +
          '</div>'
        );
        var $side = $row.find(".kfb-saved-card-side");
        if (String(c.is_default) === "1") {
          $side.append('<span class="kfb-status-badge kfb-status-badge--paid">Default</span>');
        } else {
          $side.append(
            $('<button type="button" class="kfb-btn kfb-btn-ghost kfb-btn--sm">Set Default</button>')
              .on("click", function () { doSetDefaultCard(c.id); })
          );
        }
        $side.append(
          $('<button type="button" class="kfb-link-btn kfb-saved-card-delete">Remove</button>')
            .on("click", function () { doDeleteCard(c.id); })
        );
        $list.append($row);
      });
    }

    function doAddCard() {
      var number = $('input[name="newCardNumber"]').val();
      var expiry = $('input[name="newCardExpiry"]').val();
      var nickname = $('input[name="newCardNickname"]').val();
      var cvv = $('input[name="newCardCvv"]').val();
      $("#kfbAddCardError, #kfbAddCardSuccess").attr("hidden", true);
      if (!isValidCardNumber(number)) return showViewError($("#kfbAddCardError"), "Enter a valid card number.");
      if (!isValidCardExpiry(expiry)) return showViewError($("#kfbAddCardError"), "Enter a valid, unexpired expiry (MM/YY).");
      if (!cvv) return showViewError($("#kfbAddCardError"), "Enter a valid CVV.");

      // No CVV is collected here on purpose — it's never stored (see
      // Customer_model::add_card()), and this form doesn't need it since
      // it's only deriving brand/last4/expiry for display, not charging.
      $.ajax({
        url: API_BASE + "/customers/cards", method: "POST", contentType: "application/json",
        headers: authHeaders(),
        data: JSON.stringify({ card_number: number, expiry: expiry, nickname: nickname, cvv: cvv }),
        dataType: "json", timeout: 10000,
      })
      .done(function (res) {
        if (res && res.success) {
          $('input[name="newCardNumber"], input[name="newCardExpiry"], input[name="newCardNickname"], input[name="newCardCvv"]').val("");
          $("#kfbAddCardSuccess").removeAttr("hidden");
          toast("Card saved ✓");
          loadSavedCards();
        } else {
          showViewError($("#kfbAddCardError"), (res && res.error) || "Could not save card.");
        }
      })
      .fail(function (xhr) {
        if (handleAuthFailure(xhr)) return;
        showViewError($("#kfbAddCardError"), (xhr.responseJSON && xhr.responseJSON.error) || "Could not save card.");
      });
    }

    function doSetDefaultCard(cardId) {
      $.ajax({ url: API_BASE + "/customers/cards/" + encodeURIComponent(cardId) + "/default", method: "POST", headers: authHeaders(), dataType: "json", timeout: 10000 })
        .done(function (res) {
          if (res && res.success) { state.savedCards = res.cards || []; renderSavedCardsList(state.savedCards); renderSavedCardDropdown(); toast("Default card updated ✓"); }
        })
        .fail(function (xhr) { if (!handleAuthFailure(xhr)) toast("Could not update default card.", "error"); });
    }

    function doDeleteCard(cardId) {
      $.ajax({ url: API_BASE + "/customers/cards/" + encodeURIComponent(cardId) + "/delete", method: "POST", headers: authHeaders(), dataType: "json", timeout: 10000 })
        .done(function (res) {
          if (res && res.success) { state.savedCards = res.cards || []; renderSavedCardsList(state.savedCards); renderSavedCardDropdown(); toast("Card removed."); }
        })
        .fail(function (xhr) { if (!handleAuthFailure(xhr)) toast("Could not remove card.", "error"); });
    }

    /**
     * Populates the "Use a Saved Card" dropdown on the booking payment
     * step and auto-selects the customer's default card, if any. When a
     * saved card is selected, the card holder/number/expiry/CVV fields
     * are hidden entirely (see applySavedCardSelection()) — since no PAN
     * is stored, there's nothing to prefill them WITH; the saved card's
     * display info (brand/last4/expiry) is submitted directly instead,
     * see selectedSavedCard() / buildReservationPayload().
     */
    function renderSavedCardDropdown() {
      var $wrap = $("#kfbSavedCardWrap");
      var $select = $("#kfbSavedCardSelect");
      if (!state.customer || !state.savedCards.length) {
        $wrap.attr("hidden", true);
        $("#kfbSavedCardHint").attr("hidden", true);
        $("#kfbNewCardFieldsWrap").removeAttr("hidden");
        return;
      }
      $select.find('option[value!=""]').remove();
      var defaultCard = null;
      state.savedCards.forEach(function (c) {
        $select.append($('<option></option>').attr("value", c.id).text(cardLabel(c) + (String(c.is_default) === "1" ? " (Default)" : "")));
        if (String(c.is_default) === "1") defaultCard = c;
      });
      $wrap.removeAttr("hidden");
      // Only auto-select a saved card for a fresh booking — editing an
      // existing reservation already prefilled the card fields from that
      // booking's own historical data above, which this must NOT hide/
      // clobber. Setting .val("") directly (no "change" event) just resets
      // the dropdown's own selection without touching those fields —
      // applySavedCardSelection()'s new-card branch only runs for a real
      // user-driven switch (wired via .on("change", ...) below) or the
      // explicit default-prefill call right here.
      if (defaultCard && !state.editingBookingId) {
        $select.val(String(defaultCard.id));
        applySavedCardSelection();
      } else {
        $select.val("");
        $("#kfbSavedCardHint").attr("hidden", true);
        $("#kfbNewCardFieldsWrap").removeAttr("hidden");
      }
    }

    /** The currently-selected saved card object, or null if "+ Enter a new card" is selected. */
    function selectedSavedCard() {
      var id = $("#kfbSavedCardSelect").val();
      if (!id) return null;
      return state.savedCards.filter(function (c) { return String(c.id) === String(id); })[0] || null;
    }

    /**
     * Only meant to run from the dropdown's own "change" event (user-
     * driven) or the default-prefill call in renderSavedCardDropdown().
     * Toggles the card detail fields — a saved card is selected → hide
     * them (nothing to fill in, the saved card's own info is submitted
     * directly); "+ Enter a new card" → show them, blank, for validation.
     */
    function applySavedCardSelection() {
      var card = selectedSavedCard();
      // Deliberately doesn't clear the fields on either transition: for a
      // fresh booking they're empty anyway, and for an edit, switching
      // back to "+ Enter a new card" should restore the booking's own
      // historical card data (prefilled by prefillFormFromBooking()), not
      // wipe it — same reasoning as the historical-data guard above.
      $("#kfbNewCardFieldsWrap").attr("hidden", !!card);
      $("#kfbSavedCardHint").attr("hidden", !card);
    }

    // -------- Editing: re-open the 3-step form pre-filled --------
    function startEditReservation(bookingId) {
      $.ajax({ url: API_BASE + "/reservation/" + encodeURIComponent(bookingId), method: "GET", dataType: "json", timeout: 10000 })
        .done(function (booking) {
          if (!booking || !booking.booking_id) { toast("Could not load that reservation.", "error"); return; }
          resetAll(); // clears any in-progress form state cleanly before prefilling
          state.editingBookingId = bookingId;
          $("#kfbBookNowBtn").text("Save Changes");
          prefillFormFromBooking(booking);
          setView("booking");
        })
        .fail(function () { toast("Could not load that reservation.", "error"); });
    }

    /**
     * Fills every form field/state.* value from an existing booking (as
     * returned by GET /reservation/:id), then re-runs the same
     * recalculation functions manual entry already triggers — no
     * parallel pricing logic. Region isn't persisted on the booking row
     * (only computed live from the Places result at booking time), so
     * distanceMiles/durationMins here are a starting point only; the
     * existing gotoStep() safety-net / autocomplete blur fallback
     * refreshes them for real once the customer proceeds through the
     * wizard, exactly as if they'd typed the trip in fresh.
     */
    function prefillFormFromBooking(booking) {
      $('input[name="service"][value="' + booking.service_type + '"]').prop("checked", true).trigger("change");

      $('input[name="pickupDate"]').val(booking.pickup_date || "");
      $('input[name="pickupTime"]').val((booking.pickup_time || "").slice(0, 5));

      var pickupLocType = booking.pickup_loc_type || "Search All";
      $('.kfb-loc-type-btn[data-group="pickup"][data-value="' + pickupLocType + '"]').trigger("click");
      $('input[name="pickup"]').val(booking.pickup || "");
      $('input[name="airline"]').val(booking.airline || "");
      $('input[name="flightNumber"]').val(booking.flight_number || "");
      $('input[name="arrivalTime"]').val((booking.arrival_time || "").slice(0, 5));
      state.pickupTypeDetail = booking.pickup_type_detail || "Curbside";
      $("#kfbPickupTypeDetail").val(state.pickupTypeDetail);
      state.tailNumber = booking.tail_number || "";
      $('input[name="tailNumber"]').val(state.tailNumber);

      // The dropoff section only exists when "Return at a different
      // location" is on — a booking always has a real dropoff value
      // (falls back to pickup at creation time if it wasn't), so show it.
      $("#kfbReturnDifferent").prop("checked", true).trigger("change");
      var dropoffLocType = booking.dropoff_loc_type || "Search All";
      $('.kfb-loc-type-btn[data-group="dropoff"][data-value="' + dropoffLocType + '"]').trigger("click");
      $('input[name="dropoff"]').val(booking.dropoff || "");
      $('input[name="dropoffAirline"]').val(booking.dropoff_airline || "");
      $('input[name="dropoffFlightNumber"]').val(booking.dropoff_flight_number || "");
      $('input[name="dropoffArrivalTime"]').val((booking.dropoff_arrival_time || "").slice(0, 5));
      state.dropoffTypeDetail = booking.dropoff_type_detail || "Curbside";
      $("#kfbDropoffTypeDetail").val(state.dropoffTypeDetail);
      state.dropoffTailNumber = booking.dropoff_tail_number || "";
      $('input[name="dropoffTailNumber"]').val(state.dropoffTailNumber);

      // Stops
      $("#kfbStopsContainer").empty();
      stopIndex = 0;
      (booking.stops || []).forEach(function () { addStopRow(); });
      $("#kfbStopsContainer input[name='stop[]']").each(function (i) {
        if (booking.stops[i]) $(this).val(booking.stops[i].address);
      });

      $('input[name="passengers"]').val(booking.passengers || 1);
      $('input[name="bags"]').val(booking.luggage || 0);

      // Child seats
      $("#kfbChildSeatsContainer").empty();
      state.childSeats = {};
      var breakdown = {};
      try { breakdown = booking.child_seats_breakdown ? JSON.parse(booking.child_seats_breakdown) : {}; } catch (e) { breakdown = {}; }
      Object.keys(breakdown).forEach(function (type) {
        addChildSeatRow();
        var $row = $("#kfbChildSeatsContainer .kfb-cs-row").last();
        $row.find(".kfb-cs-type").val(type);
        $row.find(".kfb-cs-qty").val(breakdown[type]).trigger("input");
      });

      $('textarea[name="notes"]').val(booking.notes || "");

      $('input[name="firstName"]').val(booking.first_name || "");
      $('input[name="lastName"]').val(booking.last_name || "");
      $('input[name="phone"]').val(booking.phone || "");
      // Email is locked to the current account email, not the booking's
      // historical value — editing is only reachable while logged in, and
      // the backend forces the email server-side too (see
      // Api::reservation_update()), so this just keeps the form honest.
      if (state.customer) lockEmailToAccount(state.customer.email);
      else $('input[name="email"]').val(booking.email || "");
      $('input[name="cardHolderName"]').val(booking.cardHolderName || "");
      $('input[name="cardNumber"]').val(booking.cardNumber || "");
      $('input[name="cardExpiry"]').val(booking.cardExpiry || "");
      $('input[name="cvv"]').val(booking.cvv || "");
      $('input[name="cardBillingAddress"]').val(booking.cardBillingAddress || "");

      // Return trip
      var isReturn = !!parseInt(booking.is_return_trip, 10) && !!booking.return_leg;
      $("#kfbReturnTripToggle").prop("checked", isReturn);
      applyReturnTrip(isReturn);
      if (isReturn) {
        var leg = booking.return_leg;
        state.returnDate = booking.return_date;
        state.returnTime = (booking.return_time || "").slice(0, 5);
        $('input[name="returnDate"]').val(state.returnDate || "");
        $('input[name="returnTime"]').val(state.returnTime || "");
        $('input[name="returnAirline"]').val(leg.airline || "");
        $('input[name="returnFlightNumber"]').val(leg.flight_number || "");
        $('input[name="returnArrivalTime"]').val((leg.arrival_time || "").slice(0, 5));
        state.returnPickupTypeDetail = leg.pickup_type_detail || "Curbside";
        $("#kfbReturnPickupTypeDetail").val(state.returnPickupTypeDetail);
        state.returnTailNumber = leg.tail_number || "";
        $('input[name="returnTailNumber"]').val(state.returnTailNumber);
        $('input[name="returnDropoffAirline"]').val(leg.dropoff_airline || "");
        $('input[name="returnDropoffFlightNumber"]').val(leg.dropoff_flight_number || "");
        $('input[name="returnDropoffArrivalTime"]').val((leg.dropoff_arrival_time || "").slice(0, 5));
        state.returnDropoffTypeDetail = leg.dropoff_type_detail || "Curbside";
        $("#kfbReturnDropoffTypeDetail").val(state.returnDropoffTypeDetail);
        state.returnDropoffTailNumber = leg.dropoff_tail_number || "";
        $('input[name="returnDropoffTailNumber"]').val(state.returnDropoffTailNumber);
        updateReturnTripAirportBlocks();
      }

      // priceBreakdown() calls syncRouteFromMap() on every invocation,
      // which unconditionally overwrites state.distanceMiles/durationMins/
      // pickup/dropoff FROM window.kfbRoute — setting state.* directly here
      // would just get clobbered the moment recalcSelectedVehicle() below
      // runs. Seed window.kfbRoute itself instead, so the sync picks up
      // real starting values.
      //
      // KNOWN LIMITATION: kfb_bookings stores pickup/dropoff as address
      // strings, not lat/lng — so fetchQuotes() (which needs coordinates
      // for the garage-distance zone check) can't run until the customer
      // actually re-selects an address from the map autocomplete. Until
      // then the price shown is whatever was last charged (below), not a
      // live requote.
      window.kfbRoute = window.kfbRoute || {};
      window.kfbRoute.distanceMiles = Number(booking.distance_miles) || 0;
      window.kfbRoute.durationMins  = Number(booking.duration_mins) || 0;

      // Seed a synthetic "quote" for this vehicle from what was actually
      // charged, so the summary shows real numbers immediately instead of
      // $0.00 while waiting on a requote that may never come (see above).
      // Pre-v16 bookings have no pricing_zone on record — left unseeded,
      // falling back to emptyQuote() until a fresh requote succeeds.
      if (booking.pricing_zone && booking.vehicle_id) {
        state.pricingZone = booking.pricing_zone;
        state.quotesByVehicleId[booking.vehicle_id] = {
          success: true, zone: booking.pricing_zone,
          requiresQuote: false, requiresQuoteReason: null,
          route_miles: Number(booking.distance_miles) || 0,
          rate_per_mile_used: 0, minimum_fare_used: 0,
          transportation: Math.max(0, Number(booking.amount) - Number(booking.travel_fee_amount || 0)
            - Number(booking.surcharges_total_amount || 0) - Number(booking.gratuity_amount || 0)
            - Number(booking.addons_total || 0)),
          travel_fee: Number(booking.travel_fee_amount) || 0,
          surcharges: [], surcharges_total: Number(booking.surcharges_total_amount) || 0,
          gratuity_pct: Number(booking.gratuity_pct) || 0, gratuity_amount: Number(booking.gratuity_amount) || 0,
          taxes_fees: 0, min_fare_applied: Number(booking.min_fare_applied) || 0,
          one_way_total: !!booking.is_return_trip ? Number(booking.amount) / 2 : Number(booking.amount),
          is_return_trip: !!booking.is_return_trip, total: Number(booking.amount) || 0,
        };
      }
      requoteVehicles();

      // Add-ons — same array shape the widget already stores/sends, so it
      // can be assigned directly; renderAddons() derives checked/qty state
      // from state.addons against the loaded catalog.
      try { state.addons = booking.addons_json ? JSON.parse(booking.addons_json) : []; } catch (e) { state.addons = []; }
      recomputeAddonTotals();
      renderAddons();

      // Vehicle — best-effort match against the currently loaded fleet.
      var fleetMatch = (state.fleet || []).filter(function (f) { return (f.id || f.code) === booking.vehicle_id; })[0];
      if (fleetMatch) {
        state.selectedVehicle = $.extend({}, fleetMatch, {
          price: priceFor(fleetMatch),
          breakdown: priceBreakdown(fleetMatch),
        });
      }

      renderVehicles();
      recalcSelectedVehicle();
      renderSideSummary();
      refreshSignatureVisibility();
      syncAllDtOverlays(); // pickupDate/pickupTime/returnDate/returnTime were just set via .val()
      // Re-evaluate now that state.editingBookingId is set (by the caller,
      // before this function runs) — an edit should default to "+ Enter a
      // new card", not auto-pick the account's default saved card, since
      // the fields above were just filled from THIS booking's own history.
      renderSavedCardDropdown();
    }

    // ============================================================
    // POST-BOOKING ACCOUNT CREATION (v4)
    // ============================================================
    function promptAccountCreation(bookingId) {
      var $block = $("#kfbAccountPrompt");
      if (!$block.length) return;
      $block.removeAttr("hidden");
      $block.find(".kfb-account-prompt-btn").on("click", function () {
        var pw = $block.find('input[name="accountPassword"]').val();
        if (!pw || pw.length < 6) {
          toast("Password must be at least 6 characters.", "error");
          return;
        }
        var email = $('input[name="email"]').val();
        $.ajax({
          url: API_BASE + "/customers/register",
          method: "POST",
          contentType: "application/json",
          data: JSON.stringify({
            email:      email,
            password:   pw,
            first_name: $('input[name="firstName"]').val(),
            last_name:  $('input[name="lastName"]').val(),
            phone:      $('input[name="phone"]').val(),
          }),
          dataType: "json",
          timeout: 8000,
        })
        .done(function (res) {
          if (res && res.success) {
            $block.find(".kfb-account-prompt-msg").text("Almost there — check your email for a verification code.");
            $block.find(".kfb-account-prompt-fields").attr("hidden", true);
            // Not loginable yet — needs the OTP just emailed. Navigating
            // away from the success screen is fine here: the booking
            // itself is already confirmed either way.
            showVerifyView(res.email || email);
          } else {
            toast((res && res.error) || "Could not create account.", "error");
          }
        })
        .fail(function () { toast("Network error creating account.", "error"); });
      });
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
      $("#kfbAccountPrompt").attr("hidden", true);
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
      // Native form.reset() above clears the email field too — re-apply
      // the logged-in lock (or make sure it's unlocked for a guest).
      if (state.customer) prefillContactFromCustomer();
      else unlockEmailField();
      renderSavedCardDropdown();
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
        if (invalid) toast(label + " can't be in the past — please pick a different date/time.", "error");
      }
      $(document).on("change", 'input[name="pickupDate"], input[name="pickupTime"]', function () {
        checkPastDateTimeField("pickupDate", "pickupTime", "Pickup date/time");
      });
      $(document).on("change", 'input[name="returnDate"], input[name="returnTime"]', function () {
        checkPastDateTimeField("returnDate", "returnTime", "Return date/time");
      });
      // Pickup time can trigger/clear the Late-Night / Early-Morning
      // Pickup Fee surcharge (11 PM - 5 AM) — see Pricing_engine.
      $(document).on("change", 'input[name="pickupTime"]', requoteVehicles);

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

      $("#kfbPromoApply").on("click", applyPromo);
      $("#kfbPromoInput").on("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); applyPromo(); }
      });
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
      initSignaturePad();

      // -------- Customer accounts: nav + forms --------
      $("#kfbShowLoginBtn").on("click", function () { setView("login"); });
      $("#kfbShowRegisterBtn").on("click", function () { setView("register"); });
      $("#kfbShowProfileBtn").on("click", function () { setView("profile"); });
      $(document).on("click", ".kfb-profile-tab", function () { setProfileTab($(this).data("profile-tab")); });
      $("#kfbLogoutBtn").on("click", doLogout);
      $("#kfbLoginBackBtn, #kfbRegisterBackBtn, #kfbProfileBackBtn").on("click", function () { setView("booking"); });
      $("#kfbLoginToRegisterBtn").on("click", function () { setView("register"); });
      $("#kfbRegisterToLoginBtn").on("click", function () { setView("login"); });
      $("#kfbLoginToForgotBtn").on("click", function () { setView("forgot"); });
      $("#kfbForgotToLoginBtn").on("click", function () { setView("login"); });
      $("#kfbVerifyBackBtn").on("click", function () { setView("login"); });

      $("#kfbLoginSubmitBtn").on("click", function () {
        doLogin($('input[name="loginEmail"]').val(), $('input[name="loginPassword"]').val());
      });
      $("#kfbLoginView input").on("keydown", function (e) { if (e.key === "Enter") $("#kfbLoginSubmitBtn").click(); });
      $("#kfbRegisterSubmitBtn").on("click", doRegisterStandalone);
      $("#kfbForgotSubmitBtn").on("click", doForgotPassword);
      $("#kfbResetSubmitBtn").on("click", doResetPassword);
      $("#kfbVerifySubmitBtn").on("click", doVerifyOtp);
      $("#kfbVerifyResendBtn").on("click", doResendOtp);
      $("#kfbVerifyView input").on("keydown", function (e) { if (e.key === "Enter") $("#kfbVerifySubmitBtn").click(); });
      $("#kfbProfileUpdateBtn").on("click", doUpdateProfile);
      $("#kfbPasswordChangeBtn").on("click", doChangePassword);
      $("#kfbAddCardBtn").on("click", doAddCard);
      $("#kfbSavedCardSelect").on("change", applySavedCardSelection);

      // checkAuthOnBoot() sets state.authToken synchronously (before its
      // own async /customers/me call) — it must run before
      // handlePaypalReturn(), whose post-payment "create an account?"
      // prompt needs to see that token to know a customer is already
      // logged in, or it wrongly offers the prompt to an already-logged-
      // in customer returning from PayPal.
      checkAuthOnBoot();
      handlePaypalReturn();
      handlePasswordResetLink();

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
        requoteVehicles(); // pickup type detail can trigger/clear the Meet & Greet surcharge
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
        requoteVehicles(); // dropoff type detail can trigger/clear the Meet & Greet surcharge
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
