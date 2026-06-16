/* ============================================================
   Kafeh Booking Widget — jQuery
   - 4-step wizard
   - Google Maps: Places Autocomplete on pickup/drop-off/stops,
     Directions service renders the route polyline and updates
     distance/duration live.
   - Talks to your CI3 backend (window.KAFEH_API, default: same origin /api)
   ============================================================ */

(function () {
  "use strict";

  // -------- Google Maps callback stub --------
  // The Google Maps SDK calls window.kfbInitMap as soon as it loads
  // (&callback=kfbInitMap). It may load BEFORE this script finishes
  // parsing (async/defer) or BEFORE jQuery is even available, so we
  // install a safe stub at the very top. boot() later assigns a real
  // implementation to __kfbMapHook that the stub can call. If the
  // callback fires before the hook is ready, we remember it and replay
  // once boot() has wired everything up.
  let __kfbMapHook = null;
  let __kfbMapCallbackFired = false;
  window.kfbInitMap = function () {
    __kfbMapCallbackFired = true;
    if (typeof __kfbMapHook === "function") {
      try { __kfbMapHook(); } catch (e) { console.error("[KafehBooking] initMap failed", e); }
    }
  };

  // -------- Map style (declared early so initMap can use it safely) --------
  const LIGHT_MAP_STYLE = [
    { elementType: "geometry", stylers: [{ color: "#f5f5f5" }] },
    { elementType: "labels.icon", stylers: [{ visibility: "off" }] },
    { elementType: "labels.text.fill", stylers: [{ color: "#616161" }] },
    { elementType: "labels.text.stroke", stylers: [{ color: "#f5f5f5" }] },
    { featureType: "administrative.land_parcel", elementType: "labels.text.fill", stylers: [{ color: "#bdbdbd" }] },
    { featureType: "poi", elementType: "geometry", stylers: [{ color: "#eeeeee" }] },
    { featureType: "poi", elementType: "labels.text.fill", stylers: [{ color: "#757575" }] },
    { featureType: "road", elementType: "geometry", stylers: [{ color: "#ffffff" }] },
    { featureType: "road.arterial", elementType: "labels.text.fill", stylers: [{ color: "#757575" }] },
    { featureType: "road.highway", elementType: "geometry", stylers: [{ color: "#dadada" }] },
    { featureType: "road.highway", elementType: "labels.text.fill", stylers: [{ color: "#616161" }] },
    { featureType: "transit.line", elementType: "geometry", stylers: [{ color: "#e5e5e5" }] },
    { featureType: "transit.station", elementType: "geometry", stylers: [{ color: "#eeeeee" }] },
    { featureType: "water", elementType: "geometry", stylers: [{ color: "#c9c9c9" }] },
  ];

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
  const PAYPAL_CLIENT_ID = window.KAFEH_PAYPAL_CLIENT_ID || "sb";

  // -------- Sample Fleet (fallback) --------
  const DEFAULT_FLEET = [
    { id: "sedan",    name: "Luxury Sedan",     desc: "Mercedes E-Class / BMW 5 — ideal for 1–3 passengers.",   emoji: "🚘", capacity: 3,  luggage: 3,  basePrice: 95,  perMile: 3.5 },
    { id: "suv",      name: "Premium SUV",      desc: "Cadillac Escalade / Chevy Suburban — roomy & elegant.", emoji: "🚙", capacity: 6,  luggage: 6,  basePrice: 145, perMile: 4.5 },
    { id: "sprinter", name: "Luxury Sprinter",  desc: "Executive van — perfect for groups up to 12.",          emoji: "🚐", capacity: 12, luggage: 10, basePrice: 220, perMile: 5.5 },
    { id: "limo",     name: "Stretch Limousine",desc: "Lincoln Stretch — weddings, proms, VIP nights.",        emoji: "🏁", capacity: 10, luggage: 6,  basePrice: 320, perMile: 6.0 },
  ];

  // -------- State --------
  const state = {
    currentStep: 1,
    selectedVehicle: null,
    bookingId: null,
    fleet: DEFAULT_FLEET,
    distanceMiles: 0,
    durationMins: 0,
  };

  // -------- Map + Places (deferred) --------
  let map, directionsService, directionsRenderer;
  let pickupAutocomplete, dropoffAutocomplete;
  const stopAutocompletes = [];
  let stopIndex = 0;

  function initMap() {
    if (typeof google === "undefined" || !google.maps) return;
    const mapEl = document.getElementById("kfbMap");
    if (!mapEl) return; // map div not in DOM yet
    map = new google.maps.Map(mapEl, {
      center: { lat: 40.7128, lng: -74.0060 },
      zoom: 11,
      styles: LIGHT_MAP_STYLE,
    });
    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
      map, suppressMarkers: true, polylineOptions: { strokeColor: "#0a0a0a", strokeWeight: 4 },
    });

    const pickupEl = document.getElementById("kfbPickup");
    const dropoffEl = document.getElementById("kfbDropoff");
    if (pickupEl && google.maps.places) {
      pickupAutocomplete = new google.maps.places.Autocomplete(
        pickupEl,
        { fields: ["place_id", "geometry", "name", "formatted_address"] }
      );
      pickupAutocomplete.addListener("place_changed", onPlaceChanged);
    }
    if (dropoffEl && google.maps.places) {
      dropoffAutocomplete = new google.maps.places.Autocomplete(
        dropoffEl,
        { fields: ["place_id", "geometry", "name", "formatted_address"] }
      );
      dropoffAutocomplete.addListener("place_changed", onPlaceChanged);
    }
  }

  function onPlaceChanged() {
    updateRoute();
  }

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

    const input = $row.find("input")[0];
    if (typeof google !== "undefined" && google.maps && google.maps.places) {
      try {
        const ac = new google.maps.places.Autocomplete(input, { fields: ["place_id", "geometry", "name"] });
        ac.addListener("place_changed", updateRoute);
        stopAutocompletes.push(ac);
      } catch (e) { /* places not ready yet */ }
    }
    $row.find(".kfb-remove-stop").on("click", function () {
      $row.remove();
      reindexStops();
      updateRoute();
    });
    stopIndex++;
  }

  // After a row is removed, renumber the visible badges AND clean up
  // the autocomplete array so future removes target the right index.
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
      const input = $r.find("input")[0];
      if (typeof google !== "undefined" && google.maps && google.maps.places) {
        try {
          const ac = new google.maps.places.Autocomplete(input, { fields: ["place_id", "geometry", "name"] });
          ac.addListener("place_changed", updateRoute);
          stopAutocompletes.push(ac);
        } catch (e) { /* places not ready yet */ }
      }
      stopIndex++;
    });
  }

  function updateRoute() {
    const pickupEl = document.getElementById("kfbPickup");
    const dropoffEl = document.getElementById("kfbDropoff");
    if (!pickupEl || !dropoffEl || typeof google === "undefined" || !google.maps) return;

    const pickup = pickupEl.value;
    const dropoff = dropoffEl.value;
    if (!pickup || !dropoff) return;

    const $stopsEl = $("#kfbStopsContainer");
    const stops = $stopsEl.find('input[name="stop[]"]').map(function () { return { location: this.value }; }).get().filter(s => s.location);
    const waypoints = stops.slice(0, -1).map(s => ({ location: s.location, stopover: true }));

    directionsService.route({
      origin: pickup,
      destination: stops.length ? stops[stops.length - 1].location : dropoff,
      waypoints: waypoints,
      travelMode: google.maps.TravelMode.DRIVING,
      unitSystem: google.maps.UnitSystem.IMPERIAL,
    }, function (result, status) {
      if (status !== "OK") return;
      directionsRenderer.setDirections(result);

      // Compute totals
      let miles = 0, seconds = 0;
      result.routes[0].legs.forEach(leg => {
        miles += leg.distance.value * 0.000621371;
        seconds += leg.duration.value;
      });
      state.distanceMiles = +miles.toFixed(1);
      state.durationMins = Math.round(seconds / 60);

      $("#kfbDistance").text(`${state.distanceMiles} mi`);
      $("#kfbDuration").text(`${state.durationMins} min`);
      $("#kfbRouteInfo").prop("hidden", false);

      // Auto-update price if user is on step 2
      if (state.currentStep === 2) renderVehicles();
    });
  }

  // -------- Pricing --------
  function priceFor(v) {
    const $form = $("#kfbForm");
    const pax = +($form.find('[name="passengers"]').val() || 1);
    const lug = +($form.find('[name="luggage"]').val() || 0);
    return +(v.basePrice + state.distanceMiles * v.perMile + pax * 5 + lug * 2 + 25).toFixed(2);
  }

  // -------- Vehicles --------
  function renderVehicles() {
    const $grid = $("#kfbVehicleGrid");
    if (!$grid.length) return;
    const sortBy = $("#kfbSortVehicles").val();
    let list = state.fleet.slice();
    if (sortBy === "priceAsc")  list.sort((a, b) => priceFor(a) - priceFor(b));
    if (sortBy === "priceDesc") list.sort((a, b) => priceFor(b) - priceFor(a));
    if (sortBy === "capacity")  list.sort((a, b) => b.capacity - a.capacity);

    $grid.empty();
    list.forEach(v => {
      const price = priceFor(v);
      const selected = state.selectedVehicle && state.selectedVehicle.id === v.id;
      const $card = $(`
        <div class="kfb-vehicle-card ${selected ? "is-selected" : ""}" data-id="${v.id}">
          <div class="kfb-vehicle-image">${v.emoji || "🚖"}</div>
          <h4 class="kfb-vehicle-name">${v.name}</h4>
          <p class="kfb-vehicle-desc">${v.desc}</p>
          <div class="kfb-vehicle-meta">
            <span>👥 ${v.capacity}</span>
            <span>🧳 ${v.luggage}</span>
          </div>
          <div class="kfb-vehicle-price">
            <b>$${price.toFixed(2)}</b>
            <small>all-inclusive</small>
          </div>
        </div>`);
      $card.on("click", function () {
        state.selectedVehicle = { ...v, price: priceFor(v) };
        renderVehicles();
      });
      $grid.append($card);
    });
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
    const d = collectForm();
    const route = d.pickup && d.dropoff ? `${d.pickup} → ${d.dropoff}` : "—";
    const when = d.pickupDate && d.pickupTime ? `${d.pickupDate} at ${d.pickupTime}` : "—";
    $("#kfbSumRoute").text(route);
    $("#kfbSumWhen").text(when);
    $("#kfbSumDistance").text(state.distanceMiles ? `${state.distanceMiles} mi · ${state.durationMins} min` : "—");
    $("#kfbSumVehicle").text(state.selectedVehicle ? `${state.selectedVehicle.name} × ${d.passengers || 1}` : "—");
    const total = state.selectedVehicle ? state.selectedVehicle.price : 0;
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

  // -------- Stops & return-location (delegated) --------
  // Suppress default on placeholder links (Terms/Privacy) — keeps the page
  // from jumping to the top when the user clicks them.
  $(document).on("click", "[data-kfb-link]", function (e) {
    e.preventDefault();
  });
  $(document).on("click", "#kfbAddStopBtn", function (e) {
    e.preventDefault();
    addStopRow();
  });
  $(document).on("change", "#kfbReturnDifferent", function () {
    const $wrap = $("#kfbDropoffWrap");
    if (!$wrap.length) return;
    $wrap.toggleClass("is-shown", this.checked);
    const $d = $wrap.find("input");
    if ($d.length) $d.prop("required", this.checked);
  });
  $(document).on("change", "#kfbSortVehicles", renderVehicles);

  // -------- Reset --------
  $(document).on("click", "#kfbResetBtn", function () { window.location.reload(); });

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

  // -------- Map init (callable from Google callback) --------
  // Idempotent — safe to call before or after Google Maps SDK is ready.
  let __kfbMapInited = false;
  __kfbMapHook = function () {
    if (typeof google === "undefined" || !google.maps) return;
    if (__kfbMapInited) return;
    __kfbMapInited = true;
    initMap();
  };
  // If the Google Maps callback already fired while we were waiting for
  // jQuery, replay it now that the hook is installed.
  if (__kfbMapCallbackFired) {
    try { __kfbMapHook(); } catch (e) { console.error("[KafehBooking] initMap failed", e); }
  }

  // -------- Init --------
  function init() {
    renderStepper();
    renderVehicles();
    setMinDate();
    // If Google Maps has already loaded (or its callback already fired
    // before jQuery was ready), kick the map init now. The hook is
    // idempotent so it's safe to call even when nothing happened.
    if (typeof __kfbMapHook === "function") __kfbMapHook();
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
