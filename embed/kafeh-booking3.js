/* ============================================================
   Kafeh Booking Widget — V3.1 ("Empire CLS" style)
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
    var API_BASE      = (window.KAFEH_API || "/api").replace(/\/$/, "");
    var UPLOADS_BASE  = (window.KAFEH_UPLOADS || (API_BASE.replace(/\/api$/, "") + "/uploads/vehicles/"));
    var PAYPAL_CLIENT = window.KAFEH_PAYPAL_CLIENT_ID || "sb";
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
      pickupTypeDetail: "Curbside", // Curbside | Meet & Greet | Private Terminal (FBO)
      tailNumber: "",         // for FBO / private terminal pickups
      signatureData: null,   // base64 PNG of customer signature (>$500)
      minFareApplied: 0,     // 0 if subtotal > min, else the min amount
      meetGreetFee: 0,       // added to total if pickup type = Meet & Greet
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
      });
    }

    // -------- Populate the airline datalists from the catalog --------
    function populateAirlines() {
      var airlines = (window.KAFEH_AIRLINES || []);
      var $lists = $("#kfbAirlinesList, #kfbAirlinesListDropoff");
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
      if (stopIndex >= 3) { toast("Maximum 3 extra stops."); return; }
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
    function makeStepper(sel) {
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
      if (typeof renderSideSummary === "function") renderSideSummary();
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
      if (typeof renderSideSummary === "function") renderSideSummary();
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
      var meetChicago  = +(v.meet_greet_chicago   || 65);
      var meetOther    = +(v.meet_greet_elsewhere || 95);

      // Minimum billable time is 1 hour even if the route is short.
      var hours = Math.max(1, (state.durationMins || 0) / 60);
      var childCount = totalChildSeats();
      var childAdd  = childCount * childSeatR;

      // Branch on service type — hourly replaces km-based, not stacks.
      var base, hourlyAdd, baseLabel;
      if (isHourlyService()) {
        base      = hourlyRate * hours;
        hourlyAdd = 0;
        baseLabel = "Hourly (" + hours.toFixed(1) + "h × $" + hourlyRate.toFixed(2) + ")";
      } else {
        base      = km * perKm;
        hourlyAdd = 0;
        baseLabel = "Base (" + km.toFixed(1) + " km × $" + perKm.toFixed(2) + ")";
      }

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

      // Add-ons: flat amounts already carry unit_price; percent add-ons
      // apply to the running subtotal. For simplicity we treat everything
      // as flat (the backend uses the configured per-region price).
      var addonsTotal = totalAddons();

      var extras   = surcharge + gratuity + childAdd + meetGreetFee + addonsTotal;
      var subtotal = base + extras;
      var discount = (state.promo && state.promo.ok) ? +(state.promo.discount || 0) : 0;
      var afterDiscount = Math.max(0, subtotal - discount);

      // Enforce minimum fare
      var minFareApplied = 0;
      var total = afterDiscount;
      if (afterDiscount < minFare) {
        minFareApplied = minFare;
        total = minFare;
        state.minFareApplied = minFareApplied;
      } else {
        state.minFareApplied = 0;
      }

      return {
        km: km, perKm: perKm, base: base,
        surcharge: surcharge, gratuity: gratuity,
        hourlyRate: hourlyRate, hours: hours, hourlyAdd: hourlyAdd,
        childSeatRate: childSeatR, childCount: childCount, childAdd: childAdd,
        meetGreetFee: meetGreetFee,
        addonsTotal: addonsTotal,
        minFare: minFare, minFareApplied: minFareApplied,
        subtotal: subtotal, discount: discount, total: total,
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
        $list.append($row);

        var $cb    = $row.find(".kfb-addon-cb");
        var $qty   = $row.find(".kfb-addon-qty");
        var $qtyIn = $row.find(".kfb-addon-qty-input");

        $cb.on("change", function () {
          if ($cb.is(":checked")) {
            $qty.removeAttr("hidden");
            $qtyIn.val(Math.max(parseInt($qtyIn.val(), 10) || 1, 1));
            addAddonToCart(a, parseInt($qtyIn.val(), 10) || 1);
          } else {
            $qty.attr("hidden", true);
            removeAddonFromCart(a.id);
          }
          renderSideSummary();
        });
        $qtyIn.on("input", function () {
          var n = Math.max(parseInt($qtyIn.val(), 10) || 1, 1);
          if (n !== parseInt($qtyIn.val(), 10)) $qtyIn.val(n);
          if ($cb.is(":checked")) {
            updateAddonQuantity(a.id, n);
            renderSideSummary();
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

    function renderVehicles() {
      syncRouteFromMap();
      var $grid = $("#kfbVehicleGrid");
      if (!$grid.length) return;
      var sortBy = $("#kfbSortVehicles").val() || "priceAsc";
      var capOf = function (v) {
        var n = parseInt(v && (v.capacity ?? v.max_passengers ?? v.passengers), 10);
        return isFinite(n) ? n : 0;
      };
      var list = state.fleet.slice();
      if (sortBy === "priceAsc")  list.sort(function (a, b) { return priceFor(a) - priceFor(b); });
      if (sortBy === "priceDesc") list.sort(function (a, b) { return priceFor(b) - priceFor(a); });
      if (sortBy === "capacity")  list.sort(function (a, b) { return capOf(b) - capOf(a); });

      var region = state.region || "Worldwide";
      $grid.empty();
      if (!list.length) {
        $grid.html('<p class="kfb-empty">No vehicles are currently available. Please check back later.</p>');
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
              ) + '</small></div>' +
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
        ($('input[name="pickupDate"]').val() || "—") + " " +
        ($('input[name="pickupTime"]').val() || "")
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
        // Base row: show the amount + a small "how we got there" note.
        //   - Hourly: "(2.0h × $95.00)"
        //   - Non-hourly: "(18.4 km × $2.50)"
        $("#kfbSumBase").text(fmtMoney(bd.base));
        var $baseNote = $("#kfbSumBaseNote");
        if ($baseNote.length) {
          $baseNote.text(isHourlyService()
            ? "(" + bd.hours.toFixed(1) + "h × $" + bd.hourlyRate.toFixed(2) + ")"
            : "(" + bd.km.toFixed(1) + " km × $" + bd.perKm.toFixed(2) + ")"
          );
        }
        $("#kfbSumSurcharge").text(fmtMoney(bd.surcharge));
        $("#kfbSumGratuity").text(fmtMoney(bd.gratuity));
        // For hourly service, the hourly amount is already the "Base" line
        // — the separate Hourly line is hidden. For non-hourly, the hourly
        // line stays as "—" since the customer didn't book hourly.
        if (isHourlyService()) {
          $("#kfbSumHourlyRow").hide();
        } else {
          $("#kfbSumHourlyRow").show();
          $("#kfbSumHourly").text("—");
        }
        $("#kfbSumChildSeats").text(bd.childAdd > 0 ? fmtMoney(bd.childAdd) : "—");
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
        // Add-ons total
        var $addonsRow = $("#kfbSumAddonsRow");
        if ($addonsRow.length) {
          if (bd.addonsTotal > 0) {
            $addonsRow.show();
            $("#kfbSumAddons").text(fmtMoney(bd.addonsTotal));
          } else {
            $addonsRow.hide();
          }
        }
        // Minimum fare (only shows when applied)
        var $minRow = $("#kfbSumMinFareRow");
        if ($minRow.length) {
          if (bd.minFareApplied > 0) {
            $minRow.show();
            $("#kfbSumMinFare").text(
              "Min fare " + fmtMoney(bd.minFare) + " applied"
            );
          } else {
            $minRow.hide();
          }
        }
        $("#kfbSumDiscount").text(
          bd.discount > 0
            ? '− ' + fmtMoney(bd.discount) + (state.promo ? ' (' + escapeHtml(state.promo.code) + ')' : '')
            : "—"
        );
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
      if (state.selectedVehicle) {
        state.selectedVehicle = $.extend({}, state.selectedVehicle, {
          price: priceFor(state.selectedVehicle),
          breakdown: priceBreakdown(state.selectedVehicle),
        });
      }
      renderSideSummary();
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
      var v = state.selectedVehicle;
      if (!v) { toast("No vehicle selected."); return $.Deferred().reject().promise(); }
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
        pickupPoint:     $('select[name="pickupPoint"]').val()  || null,
        pickupTypeDetail: state.pickupTypeDetail,
        tailNumber:      state.tailNumber || null,
        dropoff:         dropoffValue,
        dropoffType:     $('#kfbReturnDifferent').is(":checked") ? dropoffType : pickupType,
        dropoffAirline:        $('input[name="dropoffAirline"]').val()       || null,
        dropoffFlightNumber:   $('input[name="dropoffFlightNumber"]').val()  || null,
        dropoffArrivalTime:    $('input[name="dropoffArrivalTime"]').val()   || null,
        dropoffPickupPoint:    $('select[name="dropoffPickupPoint"]').val()  || null,
        stops:           stops,
        isReturnTrip:    state.isReturnTrip,
        returnDate:      state.returnDate,
        returnTime:      state.returnTime,
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
    function createPaypalOrder() {
      var v = state.selectedVehicle;
      if (!v) return $.Deferred().reject().promise();
      return $.ajax({
        url: API_BASE + "/paypal/create-order",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify({
          amount:    v.breakdown.total.toFixed(2),
          bookingId: state.bookingId,
          description: "Chauffeur booking " + state.bookingId + " — " + v.name,
          customer:  { name: $('input[name="firstName"]').val(), email: $('input[name="email"]').val() },
          ride:      { from: $('input[name="pickup"]').val(), to: $('input[name="dropoff"]').val() },
          vehicle:   { id: v.id, name: v.name },
          distanceMiles: state.distanceMiles,
          durationMins:  state.durationMins,
        }),
        dataType: "json",
        timeout: 10000,
      });
    }

    function renderPaypalButton() {
      if (typeof paypal === "undefined") {
        var tries = 0;
        var timer = setInterval(function () {
          tries++;
          if (typeof paypal !== "undefined") { clearInterval(timer); renderPaypalButton(); }
          else if (tries > 200) {
            clearInterval(timer);
            $("#kfbPaypalButton").html('<p class="kfb-pay-error">PayPal SDK did not load. Please refresh.</p>');
          }
        }, 100);
        return;
      }
      paypal.Buttons({
        createOrder: function () {
          var required = ["firstName", "lastName", "email", "phone"];
          var missing = [];
          required.forEach(function (n) {
            if (!($('input[name="' + n + '"]').val() || "").trim()) missing.push(n);
          });
          if (missing.length) { toast("Please fill: " + missing.join(", ")); return Promise.reject("validation"); }
          $("#kfbPaymentStatus").show().find("p").text("Creating your reservation…");
          return createReservation()
            .then(function (res) {
              state.bookingId = res.booking_id;
              return createPaypalOrder();
            })
            .then(function (order) { $("#kfbPaymentStatus").hide(); return order.id; })
            .catch(function (err) {
              $("#kfbPaymentStatus").hide();
              toast("Could not start payment: " + (err && err.message ? err.message : err), "error");
              return Promise.reject(err);
            });
        },
        onApprove: function (data) {
          $("#kfbPaymentStatus").show().find("p").text("Processing your payment…");
          return fetch(API_BASE + "/paypal/capture-order/" + encodeURIComponent(data.orderID), {
            method: "POST", credentials: "same-origin",
          })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            $("#kfbPaymentStatus").hide();
            if (res && res.success) {
              // After successful capture: save the signature if any
              saveSignature().always(function () {
                showSuccess(res.bookingId || state.bookingId);
                promptAccountCreation(res.bookingId || state.bookingId);
              });
            } else {
              toast("Payment could not be completed. Please try again.", "error");
            }
          })
          .catch(function () {
            $("#kfbPaymentStatus").hide();
            toast("Network error while confirming payment.", "error");
          });
        },
        onError: function (err) {
          $("#kfbPaymentStatus").hide();
          console.error("PayPal error", err);
          toast("PayPal reported an error. Please try again.", "error");
        },
      }).render("#kfbPaypalButton");
    }

    function showSuccess(bookingId) {
      $(".kfb-panel, .kfb-stepper, .kfb-actions, .kfb-side-col").hide();
      var $ok = $("#kfbSuccess").show();
      $("#kfbSuccessId").text(bookingId || state.bookingId || "—");
      $("#kfbSuccessName").text($('input[name="firstName"]').val() || "rider");
      $("#kfbSuccessEmail").text($('input[name="email"]').val() || "—");
      $("html, body").animate({ scrollTop: 0 }, 200);
    }
    function resetAll() {
      state.selectedVehicle = null;
      state.bookingId = null;
      state.promo = null;
      state.childSeats = {};
      $("#kfbForm")[0].reset();
      $("#kfbStopsContainer").empty();
      $("#kfbChildSeatsContainer").empty();
      $("#kfbReturnDifferent").prop("checked", true).trigger("change");
      $(".kfb-airport-extras").hide();
      $(".kfb-panel, .kfb-stepper, .kfb-actions, .kfb-side-col").show();
      $("#kfbSuccess").hide();
      // Clear v4 state
      state.addons = [];
      state.stops = [];
      state.isReturnTrip = false;
      state.returnDate = null;
      state.returnTime = null;
      state.pickupTypeDetail = "Curbside";
      state.tailNumber = "";
      state.signatureData = null;
      state.minFareApplied = 0;
      state.meetGreetFee = 0;
      gotoStep(1);
    }

    // ============================================================
    // FLIGHT VALIDATION (v4)
    // ============================================================
    /**
     * Call Aviationstack via the backend. Falls back to manual entry on
     * any failure (no API key, network error, flight not found).
     * Updates the airline / flight # / arrival time fields from the
     * result so the customer doesn't have to re-type them.
     */
    function validateFlight(group) {
      var flightInput = $('input[name="' + (group === "pickup" ? "flightNumber" : "dropoffFlightNumber") + '"]');
      var dateInput   = $('input[name="pickupDate"]');
      var flight = (flightInput.val() || "").trim().toUpperCase();
      var date   = (dateInput.val() || "").trim();
      if (!flight || !date) {
        toast("Enter flight number and pickup date first.");
        return;
      }
      var $btn = $("#kfbValidateFlightPickup, #kfbValidateFlightDropoff").filter(":visible").first();
      var oldLabel = $btn.text();
      $btn.prop("disabled", true).text("Checking…");
      $.ajax({
        url: API_BASE + "/flights/validate",
        data: { flight: flight, date: date },
        dataType: "json",
        timeout: 10000,
      })
      .done(function (res) {
        if (res && res.ok && res.flight) {
          var f = res.flight;
          // Auto-fill the airline + arrival time if available
          if (group === "pickup") {
            if (f.airline_name) $('input[name="airline"]').val(f.airline_name);
            if (f.arrival_scheduled) {
              // Aviationstack returns ISO timestamps like "2026-08-01T14:30:00.000+05:00"
              var m = (f.arrival_scheduled || "").match(/T(\d{2}):(\d{2})/);
              if (m) $('input[name="arrivalTime"]').val(m[1] + ":" + m[2]);
            }
            toast("Flight " + f.flight_number + " verified — " + (f.departure_iata || "?") + " → " + (f.arrival_iata || "?"));
          } else {
            if (f.airline_name) $('input[name="dropoffAirline"]').val(f.airline_name);
            toast("Flight " + f.flight_number + " verified.");
          }
        } else {
          var reason = (res && res.reason) || "unavailable";
          var msg = reason === "not_found"
            ? "No flight found for that number + date. Please enter details manually."
            : "Flight API unavailable — please enter details manually.";
          toast(msg);
        }
      })
      .fail(function () { toast("Could not reach flight API — please enter manually."); })
      .always(function () { $btn.prop("disabled", false).text(oldLabel); });
    }

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
      } else {
        $panel.attr("hidden", true);
        // Clear any return-trip data
        state.returnDate = null;
        state.returnTime = null;
        $('input[name="returnDate"]').val("");
        $('input[name="returnTime"]').val("");
      }
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
      function end() { drawing = false; }
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

      // Before submit, snapshot the canvas to base64 PNG
      var $form = $("#kfbForm");
      if ($form.length) {
        $form.on("submit", function () {
          if (hasInk && !state.signatureData) {
            state.signatureData = canvas.toDataURL("image/png");
          }
        });
      }
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
     * Called from PayPal onApprove (right after capture).
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
      state.pickupTypeDetail = "Curbside";
      state.tailNumber = "";
      state.signatureData = null;
      state.minFareApplied = 0;
      state.meetGreetFee = 0;
      $("#kfbForm")[0].reset();
      $("#kfbStopsContainer").empty();
      $("#kfbChildSeatsContainer").empty();
      $("#kfbAddonList").empty();
      $("#kfbAccountPrompt").attr("hidden", true);
      $("#kfbSignatureBlock").attr("hidden", true);
      $("#kfbReturnDifferent").prop("checked", true).trigger("change");
      $('input[name="returnDate"], input[name="returnTime"], input[name="tailNumber"]').val("");
      $(".kfb-airport-extras").hide();
      $("#kfbTailNumberWrap").attr("hidden", true);
      $("#kfbReturnTripPanel").attr("hidden", true);
      $("#kfbReturnTripToggle").prop("checked", false);
      $('input[name="pickupTypeDetail"][value="Curbside"]').prop("checked", true);
      $(".kfb-panel, .kfb-stepper, .kfb-actions, .kfb-side-col").show();
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
      makeStepper("#kfbStepperPassengers");
      makeStepper("#kfbStepperBags");

      $("#kfbReturnDifferent").on("change", function () {
        var on = $(this).is(":checked");
        if (on) $("#kfbDropoffWrap").slideDown(120);
        else    $("#kfbDropoffWrap").slideUp(120);
      });

      window.addEventListener("kfb:route-updated", function () {
        syncRouteFromMap();
        renderVehicles();
        renderSideSummary();
      });

      $(document).on("click", "[data-next]", function () { gotoStep(parseInt($(this).attr("data-next"), 10)); });
      $(document).on("click", "[data-prev]", function () { gotoStep(parseInt($(this).attr("data-prev"), 10)); });

      $("#kfbPromoApply").on("click", applyPromo);
      $("#kfbPromoInput").on("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); applyPromo(); }
      });
      $("#kfbResetBtn").on("click", function (e) { e.preventDefault(); resetAll(); });
      $("#kfbSortVehicles").on("change", renderVehicles);

      // Live recompute summary on contact-info edits
      $(document).on("input", 'input[name="firstName"], input[name="lastName"], input[name="email"], input[name="phone"]', renderSideSummary);

      loadFleet();
      loadAddons();
      renderVehicles();
      renderSideSummary();
      renderPaypalButton();
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

      // Pickup type detail (Curbside / Meet & Greet / Private Terminal)
      $('input[name="pickupTypeDetail"]').on("change", function () {
        state.pickupTypeDetail = $(this).val();
        var $tail = $("#kfbTailNumberWrap");
        if (state.pickupTypeDetail === "Private Terminal (FBO)") {
          $tail.removeAttr("hidden").find("input").prop("required", true);
        } else {
          $tail.attr("hidden", true).find("input").prop("required", false);
        }
        if (state.selectedVehicle) {
          state.selectedVehicle = $.extend({}, state.selectedVehicle, {
            price: priceFor(state.selectedVehicle),
            breakdown: priceBreakdown(state.selectedVehicle),
          });
          renderSideSummary();
        }
      });
      $('input[name="tailNumber"]').on("input", function () {
        state.tailNumber = $(this).val().toUpperCase().replace(/[^A-Z0-9\-]/g, "");
        $(this).val(state.tailNumber);
      });

      // Flight validation buttons
      $("#kfbValidateFlightPickup").on("click", function () { validateFlight("pickup"); });
      $("#kfbValidateFlightDropoff").on("click", function () { validateFlight("dropoff"); });

      // Initial state of side column
      gotoStep(1);
    });
  }

  bootWhenReady();
})();
