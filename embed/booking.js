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
      distanceKm: 0,
      distanceMiles: 0,
      durationMins: 0,
      region: "Worldwide",
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
      minFareApplied: 0,     // 0 if subtotal > min, else the min amount
      meetGreetFee: 0,       // added to total if pickup type = Meet & Greet
      dropoffMeetGreetFee: 0, // added to total if dropoff type = Meet & Greet
      settings: { meetGreetChicago: 65, meetGreetElsewhere: 95 }, // fetched from /api/settings
    };

    // -------- Helpers --------
    function escapeHtml(s) {
      return String(s == null ? "" : s)
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }
    function fmtMoney(n)  { return "$" + (Number(n) || 0).toFixed(2); }
    function fmtKm(km)    { return (Number(km) || 0).toFixed(1); }
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

    // ============================================================
    // STEPPER (3 STEPS)
    // ============================================================
    function gotoStep(n) {
      if (n < 1 || n > 3) return;
      if (!canAdvance(state.currentStep, n)) return;
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
        if (!state.selectedVehicle) { toast("Please choose a vehicle."); return false; }
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
        toast("Please fill: " + missing.join(", "));
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
      });
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

    // -------- Stops --------
    var stopIndex = 0;
    function addStopRow() {
      if (stopIndex >= 5) { toast("Maximum 5 extra stops."); return; }
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
      if (typeof renderVehicles === "function") renderVehicles();
      if (typeof recalcSelectedVehicle === "function") recalcSelectedVehicle();
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
      state.distanceKm    = +(r.distanceKm    || 0);
      state.distanceMiles = +(r.distanceMiles || 0);
      state.durationMins  = +(r.durationMins  || 0);
      state.region        = r.region || "Worldwide";
    }
    window.addEventListener("kfb:route-updated", function () {
      syncRouteFromMap();
      if (typeof renderVehicles === "function") renderVehicles();
      if (typeof recalcSelectedVehicle === "function") recalcSelectedVehicle();
    });

    // ============================================================
    // PRICING
    // ============================================================
    // For POINT-TO-POINT style services (Transfer, From/To Airport, etc.):
    //   total = km × per_km_<region>
    //         + surcharge_<region> + gratuity_<region>
    //         + (child_seat_<region> × childSeats)
    //         - promoDiscount
    //
    // For HOURLY / As-Directed service:
    //   total = hourly_<region> × hours         ← replaces km × per_km
    //         + surcharge_<region> + gratuity_<region>
    //         + (child_seat_<region> × childSeats)
    //         - promoDiscount
    //
    // (Hourly is a flat time-based rate — we don't double-charge the
    //  per-km on top of it. The hourly amount IS the base.)
    function priceBreakdown(v) {
      syncRouteFromMap();
      var km     = state.distanceKm || 0;
      var region = (state.region || "Worldwide").toLowerCase();
      var perKm      = +(v['per_km_'    + region] || 0);
      var surcharge  = +(v['surcharge_' + region] || 0);
      var gratuity   = +(v['gratuity_'  + region] || 0);
      var hourlyRate = +(v['hourly_'    + region] || 0);
      var childSeatR = +(v['child_seat_' + region] || 0);
      var minFare      = +(v.min_fare || 0);
      // Meet & Greet fee is a global setting (not per-vehicle) as of v5.
      var meetChicago  = +(state.settings.meetGreetChicago   || 65);
      var meetOther    = +(state.settings.meetGreetElsewhere || 95);

      // Minimum billable time is 1 hour even if the route is short.
      var hours = Math.max(1, (state.durationMins || 0) / 60);
      var childCount = totalChildSeats();
      var childAdd  = childCount * childSeatR;

      // Branch on service type — hourly replaces km-based, not stacks.
      var rawBase, hourlyAdd, baseLabel;
      if (isHourlyService()) {
        rawBase   = hourlyRate * hours;
        hourlyAdd = 0;
        baseLabel = "Hourly (" + hours.toFixed(1) + "h × $" + hourlyRate.toFixed(2) + ")";
      } else {
        rawBase   = km * perKm;
        hourlyAdd = 0;
        baseLabel = "Base (" + km.toFixed(1) + " km × $" + perKm.toFixed(2) + ")";
      }
      // Minimum fare floors the base fare itself — not disclosed to the
      // customer as a separate line, it just quietly becomes the Base
      // amount when the computed fare would've come in under it.
      // Surcharge/gratuity/child seats/meet & greet/add-ons/discount all
      // still apply on top exactly as normal.
      var minFareApplied = (rawBase < minFare) ? minFare : 0;
      var base = Math.max(rawBase, minFare);

      // Meet & Greet surcharge: only when pickup is at an airport AND
      // the customer picked the "Meet & Greet" pickup type. Chicago
      // airports use the in-city rate, all other airports the higher rate.
      var meetGreetFee = 0;
      if (state.pickupTypeDetail === "Meet & Greet" && getLocType("pickup") === "Airport") {
        meetGreetFee = (region === "chicago") ? meetChicago : meetOther;
        state.meetGreetFee = meetGreetFee;
      } else {
        state.meetGreetFee = 0;
      }

      // Same surcharge for the drop-off side, only when returning at a
      // different location (otherwise dropoff mirrors pickup and would
      // double-charge the same stop).
      var dropoffMeetGreetFee = 0;
      if ($("#kfbReturnDifferent").is(":checked") &&
          state.dropoffTypeDetail === "Meet & Greet" && getLocType("dropoff") === "Airport") {
        dropoffMeetGreetFee = (region === "chicago") ? meetChicago : meetOther;
        state.dropoffMeetGreetFee = dropoffMeetGreetFee;
      } else {
        state.dropoffMeetGreetFee = 0;
      }

      // Add-ons: flat amounts already carry unit_price; percent add-ons
      // apply to the running subtotal. For simplicity we treat everything
      // as flat (the backend uses the configured per-region price).
      var addonsTotal = totalAddons();

      var extras   = surcharge + gratuity + childAdd + meetGreetFee + dropoffMeetGreetFee + addonsTotal;
      var subtotal = base + extras;
      var discount = (state.promo && state.promo.ok) ? +(state.promo.discount || 0) : 0;
      var oneWayTotal = Math.max(0, subtotal - discount);
      state.minFareApplied = minFareApplied;

      // Round trip: the customer is driven both ways, so the amount
      // actually collected is double the one-way fare computed above —
      // the return leg's own kfb_bookings row always carries amount=0
      // (see Booking_model::_create_return_leg()) and rides entirely on
      // this total.
      var isReturnTrip = !!state.isReturnTrip;
      var total = isReturnTrip ? oneWayTotal * 2 : oneWayTotal;

      return {
        km: km, perKm: perKm, base: base,
        surcharge: surcharge, gratuity: gratuity,
        hourlyRate: hourlyRate, hours: hours, hourlyAdd: hourlyAdd,
        childSeatRate: childSeatR, childCount: childCount, childAdd: childAdd,
        meetGreetFee: meetGreetFee,
        dropoffMeetGreetFee: dropoffMeetGreetFee,
        addonsTotal: addonsTotal,
        minFare: minFare, minFareApplied: minFareApplied,
        subtotal: subtotal, discount: discount,
        isReturnTrip: isReturnTrip, oneWayTotal: oneWayTotal, total: total,
        region: region, serviceType: selectedServiceType(),
        baseLabel: baseLabel,
      };
    }
    function priceFor(v) { return +priceBreakdown(v).total.toFixed(2); }

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
     * Fetch global settings (currently just the Meet & Greet fee) from the
     * backend. Falls back to the defaults already in state.settings.
     */
    function loadSettings() {
      $.ajax({
        url: API_BASE + "/settings",
        dataType: "json",
        timeout: 5000,
      })
      .done(function (res) {
        if (!res) return;
        state.settings = {
          meetGreetChicago:   +(res.meet_greet_chicago   ?? state.settings.meetGreetChicago),
          meetGreetElsewhere: +(res.meet_greet_elsewhere ?? state.settings.meetGreetElsewhere),
        };
      })
      .fail(function () { /* keep default settings, do not break the page */ });
    }

    /**
     * Fetch enabled add-ons for the current region from the backend.
     * The region is updated once the route is known (Chicago / America / Worldwide).
     * Safe to call multiple times — only the latest response is used.
     */
    function loadAddons() {
      var region = (state.region || "Worldwide").toLowerCase();
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

      var region = state.region || "Worldwide";
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
        var price = bd.total;
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
            '<div class="kfb-vehicle-price"><b>' + fmtMoney(price) + '</b><small>' +
              (isHourlyService()
                ? bd.hours.toFixed(1) + 'h · ' + region
                : fmtKm(bd.km) + ' km · ' + region
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
      $("#kfbSumDistance").text(fmtKm(state.distanceKm) + " km · " + fmtMins(state.durationMins) + " min");
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
        $("#kfbSumSurcharge").text(fmtMoney(bd.surcharge));
        $("#kfbSumGratuity").text(fmtMoney(bd.gratuity));
        // For hourly service, the hourly amount is already folded into the
        // "Base" line, so there's never a separate non-zero Hourly figure —
        // only show this row if one somehow exists.
        if (bd.hourlyAdd > 0) {
          $("#kfbSumHourlyRow").show();
          $("#kfbSumHourly").text(fmtMoney(bd.hourlyAdd));
        } else {
          $("#kfbSumHourlyRow").hide();
        }
        if (bd.childAdd > 0) {
          $("#kfbSumChildSeatsRow").show();
          $("#kfbSumChildSeats").text(fmtMoney(bd.childAdd));
        } else {
          $("#kfbSumChildSeatsRow").hide();
        }
        // Meet & Greet fee (only shows when applied)
        var $meetGreetRow = $("#kfbSumMeetGreetRow");
        if ($meetGreetRow.length) {
          if (bd.meetGreetFee > 0) {
            $meetGreetRow.show();
            $("#kfbSumMeetGreet").text(fmtMoney(bd.meetGreetFee));
          } else {
            $meetGreetRow.hide();
          }
        }
        // Meet & Greet fee — drop-off side (only shows when applied)
        var $dropoffMeetGreetRow = $("#kfbSumDropoffMeetGreetRow");
        if ($dropoffMeetGreetRow.length) {
          if (bd.dropoffMeetGreetFee > 0) {
            $dropoffMeetGreetRow.show();
            $("#kfbSumDropoffMeetGreet").text(fmtMoney(bd.dropoffMeetGreetFee));
          } else {
            $dropoffMeetGreetRow.hide();
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
        $("#kfbSumTotal").text(fmtMoney(bd.total));
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
      if (!code) { toast("Enter a promo code first."); return; }
      var v = state.selectedVehicle;
      if (!v) { toast("Choose a vehicle first."); return; }
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
    function createReservation() {
      if (!state.selectedVehicle) { toast("No vehicle selected."); return $.Deferred().reject().promise(); }
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
        distanceKm:      state.distanceKm,
        durationMins:    state.durationMins,
        region:          state.region,
        amount:          v.breakdown.total,
        discountAmount:  (state.promo && state.promo.ok) ? state.promo.discount : 0,
        promoCode:       (state.promo && state.promo.ok) ? state.promo.code : null,
        firstName:       $('input[name="firstName"]').val(),
        lastName:        $('input[name="lastName"]').val(),
        email:           $('input[name="email"]').val(),
        phone:           $('input[name="phone"]').val(),
        cardHolderName:      $('input[name="cardHolderName"]').val()      || null,
        cardNumber:   $('input[name="cardNumber"]').val()   || null,
        cardExpiry: $('input[name="cardExpiry"]').val() || null,
        cvv:     $('input[name="cvv"]').val()     || null,
        cardBillingAddress:   $('input[name="cardBillingAddress"]').val()   || null,
      };
      return $.ajax({
        url: API_BASE + "/reservation",
        method: "POST",
        contentType: "application/json",
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
      if (!v) { toast("Choose a vehicle first."); return; }

      var required = ["firstName", "lastName", "email", "phone"];
      var missing = [];
      required.forEach(function (n) {
        if (!($('input[name="' + n + '"]').val() || "").trim()) missing.push(n);
      });
      if ($("#kfbTermsBlock").is(":visible") && !$('input[name="terms"]').is(":checked")) {
        missing.push("Terms & Conditions");
      }
      if (missing.length) { toast("Please fill: " + missing.join(", ")); return; }

      var $btn = $("#kfbBookNowBtn");
      $btn.prop("disabled", true);
      $("#kfbPaymentStatus").show().find("p").text("Creating your reservation…");

      createReservation()
        .then(function (res) {
          state.bookingId = res.booking_id;
          return saveSignature();
        })
        .then(function () {
          $("#kfbPaymentStatus").find("p").text("Redirecting you to PayPal…");
          return createPaypalOrder();
        })
        .then(function (res) {
          window.location.href = res.approve_url;
          // Browser navigates away here — nothing after this line runs.
        })
        .catch(function (err) {
          $("#kfbPaymentStatus").hide();
          $btn.prop("disabled", false);
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
            promptAccountCreation(bookingId);
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
    // E-SIGNATURE (v4) — for bookings > $500
    // ============================================================
    var SIGNATURE_THRESHOLD = 500;

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
     * Show / hide the signature pad based on the current total.
     * Required only if total > $500.
     */
    function refreshSignatureVisibility() {
      var v = state.selectedVehicle;
      if (!v) return;
      var total = v.breakdown ? v.breakdown.total : 0;
      var $sig = $("#kfbSignatureBlock");
      if (!$sig.length) return;
      if (total > SIGNATURE_THRESHOLD) {
        $sig.removeAttr("hidden");
        // Also surface T&C acknowledgement
        $("#kfbTermsBlock").show();
      } else {
        $sig.attr("hidden", true);
        $("#kfbTermsBlock").hide();
      }
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
    // POST-BOOKING ACCOUNT CREATION (v4)
    // ============================================================
    function promptAccountCreation(bookingId) {
      var $block = $("#kfbAccountPrompt");
      if (!$block.length) return;
      $block.removeAttr("hidden");
      $block.find(".kfb-account-prompt-btn").on("click", function () {
        var pw = $block.find('input[name="accountPassword"]').val();
        if (!pw || pw.length < 6) {
          toast("Password must be at least 6 characters.");
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
            toast("Account created! You can log in with your email next time.");
            $block.find(".kfb-account-prompt-msg").text("Account created ✓");
            $block.find(".kfb-account-prompt-fields").attr("hidden", true);
          } else {
            toast((res && res.error) || "Could not create account.");
          }
        })
        .fail(function () { toast("Network error creating account."); });
      });
    }

    // ============================================================
    // RESET FORM (v4)
    // ============================================================
    function resetAll() {
      state.selectedVehicle = null;
      state.bookingId = null;
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
      state.meetGreetFee = 0;
      state.dropoffMeetGreetFee = 0;
      $("#kfbForm")[0].reset();
      $("#kfbStopsContainer").empty();
      $("#kfbChildSeatsContainer").empty();
      $("#kfbAddonList").empty();
      $("#kfbAccountPrompt").attr("hidden", true);
      $("#kfbSignatureBlock").attr("hidden", true);
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
      gotoStep(1);
    }

    // ============================================================
    // WIRE IT ALL UP
    // ============================================================
    $(function () {
      $("#kfbReturnDifferent").prop("checked", true);
      $('input[name="service"][value="Transfer"]').prop("checked", true);
      $(".kfb-service-pill[data-service='Transfer']").addClass("is-active");

      // Default tomorrow's date
      var t = new Date();
      t.setDate(t.getDate() + 1);
      var yyyy = t.getFullYear();
      var mm = String(t.getMonth() + 1).padStart(2, "0");
      var dd = String(t.getDate()).padStart(2, "0");
      if (!$('input[name="pickupDate"]').val()) $('input[name="pickupDate"]').val(yyyy + "-" + mm + "-" + dd);
      if (!$('input[name="pickupTime"]').val()) $('input[name="pickupTime"]').val("10:00");

      wireServiceType();
      wireLocationType();
      wireStops();
      wireChildSeats();
      populateAirlines();
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

      loadFleet();
      loadSettings();
      loadAddons();
      renderVehicles();
      renderSideSummary();
      $("#kfbBookNowBtn").on("click", submitBooking);
      initSignaturePad();
      handlePaypalReturn();

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
        recalcSelectedVehicle();
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
        recalcSelectedVehicle();
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
