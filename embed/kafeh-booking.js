/* ============================================================
   Kafeh Booking Widget — jQuery
   - 4-step wizard
   - Talks to your CI3 backend (window.KAFEH_API, default: same origin /api)
   ------------------------------------------------------------
   NOTE: The Google Maps / Places / Directions logic was moved
   out of this file and into test-map.js (used by the local
   test harness). The widget now only handles the wizard:
   stepper, validation, form collection, stops, PayPal.
   ============================================================ */

(function () {
  "use strict";

  // -------- Boot the widget as soon as jQuery is available --------
  // Tolerates a load order where this script runs BEFORE jQuery is injected
  // (common in iframe-based demos). Polls for jQuery for up to 10s.
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
      } else if (attempts > 200) { // ~10s
        clearInterval(timer);
        console.error("[KafehBooking] jQuery never loaded — widget disabled.");
        showDependencyError(
          "jQuery is required for the Kafeh booking widget to work. " +
          "Include jQuery before the widget script in your page. " +
          "See the project README for setup instructions."
        );
      }
    }, 50);
  }

  // Show a visible, in-page error message when a required dependency is
  // missing. Looks for the widget container; falls back to <body> so the
  // error is always visible even if the widget HTML isn't in the DOM.
  function showDependencyError(message) {
    try {
      var host = document.getElementById("kafehBookingWidget") || document.body;
      if (!host) return;
      var box = document.createElement("div");
      box.setAttribute("role", "alert");
      box.style.cssText = [
        "max-width:680px", "margin:24px auto", "padding:16px 20px",
        "border:1px solid #b91c1c", "border-radius:10px",
        "background:#fef2f2", "color:#7f1d1d",
        "font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif"
      ].join(";");
      box.innerHTML = '<strong style="display:block;margin-bottom:6px">Kafeh booking widget — setup error</strong>' +
                      '<span>' + message + '</span>';
      host.parentNode ? host.parentNode.insertBefore(box, host) : host.appendChild(box);
    } catch (e) {
      // Last resort — at least log it
      console.error("[KafehBooking] dependency error:", message);
    }
  }

  function boot($) {
    "use strict";
  // -------- Config --------
  const API_BASE = (window.KAFEH_API || "/api").replace(/\/$/, "");
  const UPLOADS_BASE = (window.KAFEH_UPLOADS || (API_BASE.replace(/\/api$/, "") + "/uploads/vehicles/"));
  const PAYPAL_CLIENT_ID = window.KAFEH_PAYPAL_CLIENT_ID || "sb";

  // -------- Sample Fleet (fallback if backend is unreachable) --------
  const DEFAULT_FLEET = [
    { id: "sedan",    name: "Luxury Sedan",     desc: "Mercedes E-Class / BMW 5 — ideal for 1–3 passengers.",   emoji: "🚘", capacity: 3,  luggage: 3 },
    { id: "suv",      name: "Premium SUV",      desc: "Cadillac Escalade / Chevy Suburban — roomy & elegant.", emoji: "🚙", capacity: 6,  luggage: 6 },
    { id: "sprinter", name: "Luxury Sprinter",  desc: "Executive van — perfect for groups up to 12.",          emoji: "🚐", capacity: 12, luggage: 10 },
    { id: "limo",     name: "Stretch Limousine",desc: "Lincoln Stretch — weddings, proms, VIP nights.",        emoji: "🏁", capacity: 10, luggage: 6 },
  ];

  // -------- Service-type helpers --------
  // Hourly / As-Directed is the only service type that adds an
  // hourly-rate component on top of the per-km base. All other
  // service types (Point-to-Point, Wedding, Tour, From/To Airport,
  // Transfer) use the same formula:
  //
  //   total = km × per_km_<region> + surcharge_<region> + gratuity_<region>
  //
  // (Hourly adds `+ hourly_<region> × hours` on top of that.)
  const HOURLY_KEYS = ["hourly", "as directed", "as-directed", "hourly / as directed"];

  function selectedServiceType() {
    const raw = ($('input[name="service"]:checked').val() || "").toString().trim().toLowerCase();
    return raw;
  }
  function isHourlyService() { return HOURLY_KEYS.indexOf(selectedServiceType()) !== -1; }

  // -------- State --------
  const state = {
    currentStep: 1,
    selectedVehicle: null,
    bookingId: null,
    fleet: DEFAULT_FLEET,
    distanceKm: 0,
    distanceMiles: 0,
    durationMins: 0,
    region: "Worldwide",
  };

  // -------- Stops (plain text fields — autocomplete lives in test-map.js) --------
  const stopAutocompletes = [];
  let stopIndex = 0;

  function addStopRow() {
    if (stopIndex >= 3) { toast("Maximum 3 extra stops."); return; }
    const $stopsEl = $("#kfbStopsContainer");
    if (!$stopsEl.length) return;
    const i = stopIndex;
    const $row = $(
      `<div class="kfb-stop-row" data-stop="${i}">
         <span class="kfb-stop-badge">Stop ${i + 1}</span>
         <input type="text" name="stop[]" class="kfb-stop-input" placeholder="Stop address" autocomplete="off">
         <button type="button" class="kfb-remove-stop" aria-label="Remove stop ${i + 1}">×</button>
       </div>`
    );
    $stopsEl.append($row);

    $row.find(".kfb-remove-stop").on("click", function () {
      $row.remove();
      reindexStops();
    });
    stopIndex++;
  }

  // After a row is removed, renumber the visible badges.
  function reindexStops() {
    stopIndex = 0;
    stopAutocompletes.length = 0;
    $("#kfbStopsContainer .kfb-stop-row").each(function () {
      const $r = $(this);
      const i = stopIndex;
      $r.attr("data-stop", i);
      $r.find(".kfb-stop-badge").text("Stop " + (i + 1));
      $r.find("input").attr("placeholder", "Stop address");
      $r.find(".kfb-remove-stop").attr("aria-label", "Remove stop " + (i + 1));
      stopIndex++;
    });
  }

  // -------- Sync from test-map.js --------
  // Read whatever the map controller published on window.kfbRoute.
  function syncRouteFromMap() {
    const r = window.kfbRoute || {};
    state.distanceKm    = +(r.distanceKm    || 0);
    state.distanceMiles = +(r.distanceMiles || 0);
    state.durationMins  = +(r.durationMins  || 0);
    state.region        = r.region || "Worldwide";
  }
  // Re-pick up route state whenever the map updates it, and re-render
  // anything that depends on it (vehicle cards, summary).
  window.addEventListener("kfb:route-updated", function () {
    syncRouteFromMap();
    renderVehicles();
    renderSummary();
  });

  // No-op kept for compatibility with any external caller. The actual
  // route drawing + distance/time is owned by test-map.js.
  function updateRoute() {
    syncRouteFromMap();
    renderVehicles();
    renderSummary();
  }

  // -------- Pricing --------
  // Read the per-region numeric fields off the vehicle row and combine
  // them with km + service-type to produce a price + breakdown.
  //
  //   All service types:
  //     base     = km × per_km_<region>
  //     + surcharge_<region>
  //     + gratuity_<region>
  //
  //   Hourly / As-Directed additionally adds:
  //     + hourly_<region> × hours (driving minutes / 60, floored at 1 h)
  //
  // Point-to-Point uses the same formula but doesn't add the hourly
  // component (it's a flat km × rate trip).
  function priceBreakdown(v) {
    const km     = state.distanceKm || 0;
    const region = (state.region || "Worldwide").toLowerCase();

    const perKm      = +(v['per_km_'    + region] || 0);
    const surcharge  = +(v['surcharge_' + region] || 0);
    const gratuity   = +(v['gratuity_'  + region] || 0);
    const hourlyRate = +(v['hourly_'    + region] || 0);

    const base = km * perKm;

    const hours = Math.max(1, (state.durationMins || 0) / 60);
    const hourlyAdd = isHourlyService() ? (hourlyRate * hours) : 0;

    const extras = surcharge + gratuity + hourlyAdd;
    const total  = base + extras;

    return {
      km: km,
      perKm: perKm,
      base: base,
      surcharge: surcharge,
      gratuity: gratuity,
      hourlyRate: hourlyRate,
      hours: hours,
      hourlyAdd: hourlyAdd,
      extras: extras,
      total: total,
      region: region,
      serviceType: selectedServiceType(),
    };
  }

  function priceFor(v) {
    return +priceBreakdown(v).total.toFixed(2);
  }

  // -------- Vehicles --------
  function renderVehicles() {
    syncRouteFromMap();
    const $grid = $("#kfbVehicleGrid");
    if (!$grid.length) return;
    const sortBy = $("#kfbSortVehicles").val() || "priceAsc";

    // Defensive copy of each vehicle's passenger capacity so a vehicle
    // missing/zero capacity doesn't break the comparator.
    const capOf = (v) => {
      const n = parseInt(v && (v.capacity ?? v.max_passengers ?? v.passengers), 10);
      return Number.isFinite(n) ? n : 0;
    };

    let list = state.fleet.slice();
    if (sortBy === "priceAsc")  list.sort((a, b) => priceFor(a) - priceFor(b));
    if (sortBy === "priceDesc") list.sort((a, b) => priceFor(b) - priceFor(a));
    if (sortBy === "capacity") {
      // Primary: highest passenger capacity first.
      // Tiebreaker: vehicle id (stable, predictable order).
      list.sort((a, b) => {
        const diff = capOf(b) - capOf(a);
        if (diff !== 0) return diff;
        return String(a.id).localeCompare(String(b.id));
      });
    }

    if (window.console && console.debug) {
      console.debug("[KafehBooking] renderVehicles sortBy=" + sortBy,
        list.map(v => v.id + "(" + capOf(v) + ")").join(", "));
    }

    const region = state.region || "Worldwide";
    $grid.empty();
    if (!list.length) {
      $grid.html('<p class="kfb-empty">No vehicles are currently available. Please check back later.</p>');
      return;
    }
    list.forEach(v => {
      const breakdown = priceBreakdown(v);
      const price = breakdown.total;
      const selected = state.selectedVehicle && state.selectedVehicle.id === v.id;
      const imgSrc = v.image
        ? (v.image.indexOf("http") === 0 ? v.image : (UPLOADS_BASE + v.image))
        : "";
      const imgHtml = imgSrc
        ? `<img src="${imgSrc}" alt="${escapeHtml(v.name)}" loading="lazy">`
        : `<span class="kfb-vehicle-emoji">${escapeHtml(v.emoji || "🚖")}</span>`;

      const rateText = breakdown.perKm > 0
        ? `<small class="kfb-rate-line">$${breakdown.perKm.toFixed(2)}/km · ${region}</small>`
        : "";

      const $card = $(`
        <div class="kfb-vehicle-card ${selected ? "is-selected" : ""}" data-id="${v.id}">
          <div class="kfb-vehicle-image">${imgHtml}</div>
          <h4 class="kfb-vehicle-name">${escapeHtml(v.name)}</h4>
          <p class="kfb-vehicle-desc">${escapeHtml(v.desc)}</p>
          <div class="kfb-vehicle-meta">
            <span>👥 ${v.capacity}</span>
            <span>🧳 ${v.luggage}</span>
          </div>
          <div class="kfb-vehicle-price">
            <b>$${price.toFixed(2)}</b>
            <small>${breakdown.km.toFixed(1)} km · ${region}</small>
            ${rateText}
          </div>
        </div>`);
      $card.on("click", function () {
        state.selectedVehicle = { ...v, price: priceFor(v), breakdown: priceBreakdown(v) };
        renderVehicles();
      });
      $grid.append($card);
    });
  }

  // Escape helper for safe rendering of user-controlled strings.
  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  // -------- Stepper --------
  function renderStepper() {
    $(".kfb-step").each(function () {
      const n = +$(this).data("step");
      $(this).removeClass("is-active is-complete");
      if (n < state.currentStep) $(this).addClass("is-complete");
      else if (n === state.currentStep) $(this).addClass("is-active");
    });
    $("#kfbStepperList").attr("data-progress", state.currentStep);
  }
  function showPanel(step) {
    $(".kfb-panel").removeClass("is-active").filter(`[data-panel="${step}"]`).addClass("is-active");
    state.currentStep = step;
    renderStepper();
    const $w = $(".kfb-widget");
    if ($w.length) $("html, body").animate({ scrollTop: $w.offset().top - 20 }, 300);
  }

  // -------- Validation --------
  function validatePanel(step) {
    const $panel = $(".kfb-panel").filter(`[data-panel="${step}"]`);
    let ok = true;

    $panel.find("[required]").each(function () {
      const $el = $(this);
      if ($el.attr("type") === "radio") return;
      const $f = $el.closest(".kfb-field");
      if (!$f.length) return;
      const v = ($el.val() || "").trim();
      const invalid = !v || ($el.attr("type") === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v));
      if (invalid) { ok = false; $f.addClass("is-invalid"); }
      else $f.removeClass("is-invalid");
    });

    if (step === 1) {
      const hasService = $panel.find('input[name="service"]:checked').length > 0;
      if (!hasService) {
        ok = false;
        $panel.find(".kfb-fs").addClass("is-invalid");
        toast("Please select a service type.");
      } else {
        $panel.find(".kfb-fs").removeClass("is-invalid");
      }
    }
    if (step === 2 && !state.selectedVehicle) {
      ok = false;
      toast("Please choose a vehicle to continue.");
    }
    if (step === 3 && !$panel.find('input[name="terms"]').is(":checked")) {
      ok = false;
      toast("Please accept the terms.");
    }
    return ok;
  }

  // -------- Toast --------
  let $toast;
  function toast(msg) {
    if (!$toast) $toast = $('<div class="kfb-toast" style="display:none"></div>').appendTo("body");
    $toast.text(msg).fadeIn(150);
    clearTimeout(toast._t);
    toast._t = setTimeout(() => $toast.fadeOut(150), 2400);
  }

  // -------- Summary --------
  function renderSummary() {
    syncRouteFromMap();
    const d = collectForm();
    const route = d.pickup && d.dropoff ? `${d.pickup} → ${d.dropoff}` : "—";
    const when = d.pickupDate && d.pickupTime ? `${d.pickupDate} at ${d.pickupTime}` : "—";
    const region = state.region || "Worldwide";

    $("#kfbSumRoute").text(route);
    $("#kfbSumWhen").text(when);
    $("#kfbSumDistance").text(state.distanceKm
      ? `${state.distanceKm.toFixed(1)} km · ${state.durationMins} min`
      : "—");
    $("#kfbSumRegion").text(region);
    $("#kfbSumService").text(selectedServiceType() ? (d.service || "—") : "—");
    $("#kfbSumVehicle").text(state.selectedVehicle ? `${state.selectedVehicle.name} × ${d.passengers || 1}` : "—");

    const v = state.selectedVehicle;
    let breakdown = v && v.breakdown ? v.breakdown : null;
    // Recompute on the fly in case service type / region changed since click
    if (v) breakdown = priceBreakdown(v);

    const $bd = $("#kfbBreakdown");
    if (v && breakdown) {
      $("#kfbSumBase").text(`$${breakdown.base.toFixed(2)}`);
      $("#kfbSumBaseNote").text(`${breakdown.km.toFixed(1)} km × $${breakdown.perKm.toFixed(2)}/km`);
      $("#kfbSumSurcharge").text(`$${breakdown.surcharge.toFixed(2)}`);
      $("#kfbSumGratuity").text(`$${breakdown.gratuity.toFixed(2)}`);
      $("#kfbSumHourly").text(`$${breakdown.hourlyAdd.toFixed(2)}`);
      $("#kfbSumHourlyNote").text(breakdown.hourlyAdd > 0
        ? `$${breakdown.hourlyRate.toFixed(2)}/hr × ${breakdown.hours.toFixed(1)} h`
        : "");

      // Hide rows whose value is 0 so the breakdown stays tidy.
      const $surchargeRow = $("#kfbSumSurcharge").closest(".kfb-row");
      const $gratuityRow  = $("#kfbSumGratuity").closest(".kfb-row");
      const $hourlyRow    = $("#kfbSumHourly").closest(".kfb-row");
      $surchargeRow.toggle(breakdown.surcharge > 0);
      $gratuityRow.toggle(breakdown.gratuity > 0);
      $hourlyRow.toggle(breakdown.hourlyAdd > 0);

      $bd.prop("hidden", false);
    } else {
      $bd.prop("hidden", true);
    }

    const total = v ? breakdown.total : 0;
    $("#kfbSumTotal").text(`$${total.toFixed(2)}`);
    return total;
  }

  function collectForm() {
    const $form = $("#kfbForm");
    const obj = {};
    if ($form.length) $form.serializeArray().forEach(f => { obj[f.name] = f.value; });
    obj.stops = $("#kfbStopsContainer").find('input[name="stop[]"]').map(function () { return this.value; }).get().filter(Boolean);
    return obj;
  }

  // -------- Navigation (delegated) --------
  $(document).on("click", "[data-next]", function () {
    const to = +$(this).data("next");
    if (!validatePanel(state.currentStep)) return;
    if (to === 4) preparePayment();
    else if (to === 3) renderSummary();
    showPanel(to);
  });
  $(document).on("click", "[data-prev]", function () { showPanel(+$(this).data("prev")); });

  // Service-type change → re-price everything.
  $(document).on("change", 'input[name="service"]', function () {
    renderVehicles();
    renderSummary();
  });

  // Passenger / luggage changes → re-price.
  $(document).on("input change", '[name="passengers"],[name="luggage"],[name="childSeats"]', function () {
    renderVehicles();
    renderSummary();
  });

  // Vehicle sort dropdown → re-render the grid in the new order.
  // (The sort logic lives inside renderVehicles(); this just makes
  // sure changing the dropdown actually triggers a re-render.)
  $(document).on("change", "#kfbSortVehicles", function () {
    renderVehicles();
  });

  // -------- Fleet loading (DB-driven via /api/fleet) --------
  // Uses jQuery $.ajax so it integrates with whatever global jQuery
  // the host page provides. Always updates state.fleet on success,
  // and re-renders the vehicle grid (whether it loaded OK or not).
  function loadFleet() {
    const url = API_BASE + "/fleet";
    console.log("[KafehBooking] loading fleet from", url);

    return $.ajax({
      url: url,
      method: "GET",
      dataType: "json",
      cache: false,
    })
    .done(function (data) {
      // /api/fleet returns either a bare array or { success, vehicles }
      const list = Array.isArray(data)
        ? data
        : (data && Array.isArray(data.vehicles) ? data.vehicles : []);

      if (list.length) {
        state.fleet = list;
        console.log("[KafehBooking] loaded " + list.length + " vehicle(s) from backend");
      } else {
        console.warn("[KafehBooking] /api/fleet returned an empty list — keeping fallback");
      }
    })
    .fail(function (xhr, status, err) {
      console.error(
        "[KafehBooking] /api/fleet FAILED (" + status + " " + (xhr && xhr.status) + "):",
        err || (xhr && xhr.responseText) || "no response body",
        "— URL:", url
      );
      toast("Could not load vehicles from backend — using fallback list.");
    })
    .always(function () {
      // Whether the call succeeded or failed, refresh the grid so the
      // user sees whatever list we ended up with.
      renderVehicles();
    });
  }

  // -------- Payment --------
  function preparePayment() {
    const $form = $("#kfbForm");
    const total = renderSummary();
    state.bookingId = "KFH-" + Date.now().toString(36).toUpperCase();
    $("#kfbPayAmount").text(`$${total.toFixed(2)}`);
    $("#kfbPayBookingId").text(state.bookingId);
    $("#kfbPayVehicle").text(state.selectedVehicle ? state.selectedVehicle.name : "—");

    const $host = $("#kfbPaypalButton").empty();
    if (typeof paypal === "undefined") {
      $host.html('<p style="color:#b91c1c;text-align:center;font-size:13px">PayPal SDK not loaded.</p>');
      return;
    }
    paypal.Buttons({
      style: { layout: "vertical", color: "black", shape: "rect", label: "paypal", height: 48 },
      createOrder: async () => {
        const res = await fetch(`${API_BASE}/paypal/create-order`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            amount: total.toFixed(2),
            bookingId: state.bookingId,
            description: `Kafeh booking ${state.bookingId} — ${state.selectedVehicle.name}`,
            customer: {
              firstName: $form.find('[name="firstName"]').val(),
              lastName:  $form.find('[name="lastName"]').val(),
              email:     $form.find('[name="email"]').val(),
              phone:     $form.find('[name="phone"]').val(),
            },
            ride: collectForm(),
            vehicle: state.selectedVehicle,
            distanceMiles: state.distanceMiles,
            durationMins: state.durationMins,
          }),
        });
        if (!res.ok) throw new Error("Order creation failed");
        const data = await res.json();
        return data.id;
      },
      onApprove: async (data) => {
        $("#kfbPaymentStatus").prop("hidden", false);
        const res = await fetch(`${API_BASE}/paypal/capture-order/${data.orderID}`, { method: "POST" });
        const result = await res.json();
        $("#kfbPaymentStatus").prop("hidden", true);
        if (result.success) showSuccess(result);
        else toast("Payment could not be completed.");
      },
      onError: (err) => { console.error(err); toast("PayPal error."); },
      onCancel: () => toast("Payment cancelled."),
    }).render("#kfbPaypalButton");
  }

  function showSuccess(result) {
    $("#kfbForm").hide();
    $(".kfb-stepper, .kfb-hero").hide();
    const $v = $("#kfbSuccess");
    $v.show();
    const $form = $("#kfbForm");
    $("#kfbSuccessName").text($form.find('[name="firstName"]').val() || "rider");
    $("#kfbSuccessEmail").text($form.find('[name="email"]').val() || "—");
    $("#kfbSuccessId").text(result.bookingId || state.bookingId);
  }

  // -------- Min date --------
  function setMinDate() {
    const today = new Date().toISOString().split("T")[0];
    $("#kfbForm").find('[name="pickupDate"]').attr("min", today);
  }

  // -------- Reset --------
  $(document).on("click", "#kfbResetBtn", function () { window.location.reload(); });

  // -------- Init --------
  function init() {
    renderStepper();
    renderVehicles(); // paint DEFAULT_FLEET first, so step 2 isn't empty
    setMinDate();
    // Sync the dropoff field's visibility with the "Return at a different
    // location" checkbox on initial load (the HTML may have it checked
    // by default, or the user may have changed it before jQuery was ready).
    syncDropoffVisibility();
    // Pull the live fleet from the backend (DB-driven). When the call
    // resolves or fails, loadFleet() itself re-renders the grid.
    loadFleet();
    // Map init is handled by test-map.js — it owns the Google Maps
    // callback (kfbTestInitMap) and the map / markers / route.
  }

  // Make sure #kfbDropoffWrap's visibility + required state match the
  // #kfbReturnDifferent checkbox. Called on init and on every change.
  function syncDropoffVisibility() {
    const $cb  = $("#kfbReturnDifferent");
    const $wrap = $("#kfbDropoffWrap");
    if (!$cb.length || !$wrap.length) return;
    const checked = $cb.is(":checked");
    $wrap.toggleClass("is-shown", checked);
    $wrap.find("input").prop("required", checked);
  }
  $(init);

  // -------- Public API --------
  window.KafehBooking = {
    setFleet(f) { state.fleet = f || DEFAULT_FLEET; renderVehicles(); },
    setApi(base) { window.KAFEH_API = base; },
    reset() { window.location.reload(); },
  };
  } // end boot($)

  // -------- Start: boot as soon as jQuery is available --------
  bootWhenReady();
})();
