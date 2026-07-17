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
          "jQuery is required for the Kafeh booking widget to work. " +
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
        '<strong style="display:block;margin-bottom:6px">Kafeh booking widget — setup error</strong>' +
        '<span>' + message + '</span>';
      host.parentNode ? host.parentNode.insertBefore(box, host) : host.appendChild(box);
    } catch (e) {
      console.error("[KafehBooking] dependency error:", message);
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

    // -------- Child seat catalog --------
    var CHILD_SEAT_TYPES = [
      { key: "infant",     label: "Rear Facing (Infant)" },
      { key: "toddler",    label: "Forward Facing (Toddler)" },
      { key: "all_in_one", label: "All-in-One (Convertible)" },
      { key: "booster",    label: "Booster Seat" },
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
      distanceKm: 0,
      distanceMiles: 0,
      durationMins: 0,
      region: "Worldwide",
      promo: null,
      childSeats: {},
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

      // Pickup: text input OR airport SELECT
      var pickupType = getLocType("pickup");
      if (pickupType === "Airport") {
        if (!$('select[name="pickupAirport"]').val()) missing.push("Pickup airport");
      } else {
        if (!$('input[name="pickup"]').val().trim()) missing.push("Pickup location");
      }

      // Dropoff: text input OR airport SELECT, only if "return at different" is on
      if ($("#kfbReturnDifferent").is(":checked")) {
        var dropoffType = getLocType("dropoff");
        if (dropoffType === "Airport") {
          if (!$('select[name="dropoffAirport"]').val()) missing.push("Drop-off airport");
        } else {
          if (!$('input[name="dropoff"]').val().trim()) missing.push("Drop-off location");
        }
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
      if (missing.length) {
        toast("Please fill: " + missing.join(", "));
        var first = null;
        if (!$('input[name="pickupDate"]').val()) first = $('input[name="pickupDate"]');
        else if (!$('input[name="pickupTime"]').val()) first = $('input[name="pickupTime"]');
        else if (pickupType === "Airport" && !$('select[name="pickupAirport"]').val()) first = $('select[name="pickupAirport"]');
        else if (pickupType !== "Airport" && !$('input[name="pickup"]').val().trim()) first = $('input[name="pickup"]');
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

        // Toggle the right field for this group:
        //   - Text input (default) for Search All / Address / Landmark
        //   - SELECT dropdown (replaces text input) for Airport
        toggleLocField(group, value);

        // Show / hide airport extras (airline / flight # / etc.)
        var airportBlock = $('.kfb-airport-extras[data-group="' + group + '"]');
        if (value === "Airport") airportBlock.show(); else airportBlock.hide();

        // When switching AWAY from Airport, clear the airport's hidden pickup text
        // so the form doesn't accidentally submit the airport code as the pickup
        if (group === "pickup" && value !== "Airport") {
          $("#kfbPickupAirportHidden").val("");
        }
        if (group === "dropoff" && value !== "Airport") {
          $("#kfbDropoffAirportHidden").val("");
        }

        // Notify test-map.js to re-attach autocomplete with the new type filter
        window.dispatchEvent(new CustomEvent("kfb:loc-type-changed", {
          detail: { group: group, value: value }
        }));
      });
    }

    // Show / hide the right input/select pair for a location-type group.
    function toggleLocField(group, value) {
      if (group === "pickup") {
        if (value === "Airport") {
          $("#kfbPickupWrap").attr("hidden", true).find("input").prop("required", false);
          $("#kfbPickupAirportWrap").removeAttr("hidden").find("select").prop("required", true);
        } else {
          $("#kfbPickupAirportWrap").attr("hidden", true).find("select").prop("required", false);
          $("#kfbPickupWrap").removeAttr("hidden").find("input").prop("required", true);
        }
      } else if (group === "dropoff") {
        if (value === "Airport") {
          $("#kfbDropoffWrap").attr("hidden", true).find("input").prop("required", false);
          $("#kfbDropoffAirportWrap").removeAttr("hidden").find("select").prop("required", true);
        } else {
          $("#kfbDropoffAirportWrap").attr("hidden", true).find("select").prop("required", false);
          $("#kfbDropoffWrap").removeAttr("hidden").find("input").prop("required", true);
        }
      }
    }

    // -------- Populate airport SELECTs + airline datalist from the catalog --------
    function populateAirportsAndAirlines() {
      var airports = (window.KAFEH_AIRPORTS || []);
      var airlines = (window.KAFEH_AIRLINES || []);
      // Airport SELECTs (grouped by region for readability)
      var $selects = $("#kfbPickupAirport, #kfbDropoffAirport");
      if ($selects.length) {
        // Group by region: North America, Europe, Asia, Oceania, Africa, S.America, Middle East
        var groups = {
          "North America": [], Europe: [], "South & Southeast Asia": [],
          "East Asia": [], Oceania: [], Africa: [], "South America": [],
          "Middle East": []
        };
        var regionOf = function (c) {
          if (["USA","Canada","Mexico"].indexOf(c) >= 0) return "North America";
          if (["UK","France","Germany","Netherlands","Spain","Italy","Switzerland","Austria","Denmark","Sweden","Norway","Finland","Ireland","Portugal","Greece","Turkey"].indexOf(c) >= 0) return "Europe";
          if (["UAE","Qatar","Saudi Arabia","Israel"].indexOf(c) >= 0) return "Middle East";
          if (["India","Singapore","Malaysia","Thailand","Hong Kong","Taiwan","Philippines","Indonesia"].indexOf(c) >= 0) return "South & Southeast Asia";
          if (["Japan","South Korea","China"].indexOf(c) >= 0) return "East Asia";
          if (["Australia","New Zealand"].indexOf(c) >= 0) return "Oceania";
          if (["South Africa","Egypt"].indexOf(c) >= 0) return "Africa";
          if (["Brazil","Argentina"].indexOf(c) >= 0) return "South America";
          return "Other";
        };
        airports.forEach(function (a) {
          var r = regionOf(a.country);
          if (!groups[r]) groups[r] = [];
          groups[r].push(a);
        });
        $selects.each(function () {
          var $s = $(this);
          // Reset (keep the placeholder option)
          $s.find("option:not(:first)").remove();
          Object.keys(groups).forEach(function (region) {
            if (!groups[region] || !groups[region].length) return;
            var $og = $("<optgroup></optgroup>").attr("label", region);
            groups[region].forEach(function (a) {
              $("<option></option>")
                .attr("value", a.code + " — " + a.name + " (" + a.city + ", " + a.country + ")")
                .attr("data-code", a.code)
                .text(a.code + " — " + a.city + " (" + a.country + ")")
                .appendTo($og);
            });
            $og.appendTo($s);
          });
        });

        // Mirror selection to the hidden pickup text field so the rest of the
        // widget (map, route, etc.) can read it from #kfbPickup like before.
        $selects.on("change", function () {
          var $s = $(this);
          var val = $s.val();
          // If the matching hidden field exists, mirror the value into it
          if ($s.attr("id") === "kfbPickupAirport") {
            $("#kfbPickupAirportHidden").val(val);
            // Drop a marker at the airport's geocoded location (test-map.js can
            // expose a helper, but most users will pick the airport via the
            // Google Places autocomplete on the original #kfbPickup — so we
            // only trigger the route update).
            if (val && typeof window.kfbUpdatePickup === "function") {
              window.kfbUpdatePickup(val);
            }
          } else if ($s.attr("id") === "kfbDropoffAirport") {
            $("#kfbDropoffAirportHidden").val(val);
            if (val && typeof window.kfbUpdateDropoff === "function") {
              window.kfbUpdateDropoff(val);
            }
          }
        });
      }

      // Airline datalist
      var $list = $("#kfbAirlinesList");
      if ($list.length) {
        $list.empty();
        airlines.forEach(function (a) {
          $("<option></option>")
            .attr("value", a.name)
            .text(a.name + " (" + a.code + ")")
            .appendTo($list);
        });
      }
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
    // total = km × per_km_<region>
    //       + surcharge_<region> + gratuity_<region>
    //       + (hourly_<region> × hours) IF service is hourly
    //       + (child_seat_<region> × childSeats)
    //       - promoDiscount
    function priceBreakdown(v) {
      syncRouteFromMap();
      var km = state.distanceKm || 0;
      var region = (state.region || "Worldwide").toLowerCase();
      var perKm       = +(v['per_km_'    + region] || 0);
      var surcharge   = +(v['surcharge_' + region] || 0);
      var gratuity    = +(v['gratuity_'  + region] || 0);
      var hourlyRate  = +(v['hourly_'    + region] || 0);
      var childSeatR  = +(v['child_seat_' + region] || 0);

      var base = km * perKm;
      var hours = Math.max(1, (state.durationMins || 0) / 60);
      var hourlyAdd = isHourlyService() ? (hourlyRate * hours) : 0;
      var childCount = totalChildSeats();
      var childAdd = childCount * childSeatR;

      var extras = surcharge + gratuity + hourlyAdd + childAdd;
      var subtotal = base + extras;
      var discount = (state.promo && state.promo.ok) ? +(state.promo.discount || 0) : 0;
      var total = Math.max(0, subtotal - discount);

      return {
        km: km, perKm: perKm, base: base,
        surcharge: surcharge, gratuity: gratuity,
        hourlyRate: hourlyRate, hours: hours, hourlyAdd: hourlyAdd,
        childSeatRate: childSeatR, childCount: childCount, childAdd: childAdd,
        subtotal: subtotal, discount: discount, total: total,
        region: region, serviceType: selectedServiceType(),
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
            '<div class="kfb-vehicle-price"><b>' + fmtMoney(price) + '</b><small>' + fmtKm(bd.km) + ' km · ' + region + '</small></div>' +
          '</div>'
        );
        $card.on("click", function () {
          state.selectedVehicle = $.extend({}, v, {
            price: priceFor(v),
            breakdown: priceBreakdown(v),
          });
          renderVehicles();
          renderSideSummary();
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
      $("#kfbSumPickup").text(
        getLocType("pickup") === "Airport"
          ? ($("#kfbPickupAirportHidden").val() || $('select[name="pickupAirport"]').val() || "—")
          : ($('input[name="pickup"]').val() || "—")
      );
      $("#kfbSumDropoff").text(
        $('#kfbReturnDifferent').is(":checked")
          ? (getLocType("dropoff") === "Airport"
              ? ($("#kfbDropoffAirportHidden").val() || $('select[name="dropoffAirport"]').val() || "—")
              : ($('input[name="dropoff"]').val() || "—"))
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
        $("#kfbSumHourly").text(bd.hourlyAdd > 0 ? fmtMoney(bd.hourlyAdd) : "—");
        $("#kfbSumChildSeats").text(bd.childAdd > 0 ? fmtMoney(bd.childAdd) : "—");
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

      // When airport is selected, the actual pickup value lives in the
      // SELECT's hidden mirror (#kfbPickupAirportHidden). Otherwise it's
      // the regular text input.
      var pickupType = getLocType("pickup");
      var pickupValue = pickupType === "Airport"
        ? ($("#kfbPickupAirportHidden").val() || $('select[name="pickupAirport"]').val() || "")
        : $('input[name="pickup"]').val();
      var dropoffType = getLocType("dropoff");
      var dropoffValue = $('#kfbReturnDifferent').is(":checked")
        ? (dropoffType === "Airport"
            ? ($("#kfbDropoffAirportHidden").val() || $('select[name="dropoffAirport"]').val() || "")
            : $('input[name="dropoff"]').val())
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
        dropoff:         dropoffValue,
        dropoffType:     $('#kfbReturnDifferent').is(":checked") ? dropoffType : pickupType,
        stops:           stops,
        passengers:      parseInt($('input[name="passengers"]').val(), 10) || 1,
        luggage:         parseInt($('input[name="bags"]').val(), 10) || 0,
        childSeats:      totalChildSeats(),
        childSeatsBreakdown: childBreakdown,
        notes:           $('textarea[name="notes"]').val() || null,
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
          description: "Kafeh booking " + state.bookingId + " — " + v.name,
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
              showSuccess(res.bookingId || state.bookingId);
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
      populateAirportsAndAirlines();
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
      renderVehicles();
      renderSideSummary();
      renderPaypalButton();
      // Initial state of side column
      gotoStep(1);
    });
  }

  bootWhenReady();
})();
