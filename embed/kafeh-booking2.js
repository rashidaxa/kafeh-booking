/* ============================================================
   Kafeh Booking Widget — V2 jQuery controller
   ------------------------------------------------------------
   Companion to embed/kafeh-booking2.css (Indigo Modern design).
   All event handlers are explicitly bound — fixes two bugs
   in v1's embed/kafeh-booking.js where
     #kfbAddStopBtn and #kfbReturnDifferent were never wired.

   Same DOM contract as v1 so test-map.js (Google Maps controller)
   plugs in unchanged:
     #kfbMap, #kfbPickup, #kfbDropoff, #kfbStopsContainer,
     .kfb-stop-row (added dynamically), #kfbDistance,
     #kfbDuration, #kfbRouteInfo, window.kfbRoute, and the
     "kfb:route-updated" CustomEvent.
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
      } else if (attempts > 200) { // ~10s
        clearInterval(timer);
        console.error("[KafehBooking V2] jQuery never loaded — widget disabled.");
      }
    }, 50);
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

    // -------- Stops --------
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
           <button type="button" class="kfb-remove-stop" aria-label="Remove stop ${i + 1}" type="button">&times;</button>
         </div>`
      );
      $stopsEl.append($row);

      // Wire the remove button — bound here, in the same scope, so
      // it works regardless of any event delegation config.
      $row.find(".kfb-remove-stop").on("click", function () {
        $row.remove();
        reindexStops();
      });
      stopIndex++;
    }

    function reindexStops() {
      stopIndex = 0;
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
    function syncRouteFromMap() {
      const r = window.kfbRoute || {};
      state.distanceKm    = +(r.distanceKm    || 0);
      state.distanceMiles = +(r.distanceMiles || 0);
      state.durationMins  = +(r.durationMins  || 0);
      state.region        = r.region || "Worldwide";
    }

    window.addEventListener("kfb:route-updated", function () {
      syncRouteFromMap();
      renderVehicles();
      renderSummary();
    });

    // -------- Pricing --------
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
        km: km, perKm: perKm, base: base,
        surcharge: surcharge, gratuity: gratuity,
        hourlyRate: hourlyRate, hours: hours, hourlyAdd: hourlyAdd,
        extras: extras, total: total, region: region,
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

      // Defensive copies of each vehicle's sort key so a vehicle with a
      // missing/zero capacity doesn't blow up the comparator.
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
        console.debug("[KafehBooking V2] renderVehicles sortBy=" + sortBy,
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

      // ---- V2 fix: also update the hero progress dots ----
      // The .kfb-hero-progress dots live OUTSIDE the panels so the
      // stepper code above doesn't touch them. Sync them here so the
      // indicator in the hero follows the user through the wizard.
      renderHeroProgress();
    }

    function renderHeroProgress() {
      const $dots = $(".kfb-hero-progress .kfb-progress-dot");
      if (!$dots.length) return;
      // 4 dots, one per step. state.currentStep is 1..4.
      $dots.removeClass("is-active").removeClass("is-complete");
      $dots.each(function (idx) {
        const step = idx + 1;
        if (step < state.currentStep) $(this).addClass("is-complete");
        else if (step === state.currentStep) $(this).addClass("is-active");
      });
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

    // ===============================================================
    // V2 FIX: bind Add Stop and Return-to-different-location here.
    // These handlers were missing from v1, which is why the buttons
    // appeared to do nothing.
    // ===============================================================

    // Add Stop — delegated so the button works even if the DOM
    // is rebuilt by something else.
    $(document).on("click", "#kfbAddStopBtn", function (e) {
      e.preventDefault();
      addStopRow();
    });

    // Return at a different location — toggle dropoff field.
    function syncDropoffVisibility() {
      const $cb   = $("#kfbReturnDifferent");
      const $wrap = $("#kfbDropoffWrap");
      if (!$cb.length || !$wrap.length) return;
      const checked = $cb.is(":checked");
      $wrap.toggleClass("is-shown", checked);
      $wrap.find("input").prop("required", checked);
    }
    $(document).on("change", "#kfbReturnDifferent", function () {
      syncDropoffVisibility();
    });

    // -------- Fleet loading --------
    function loadFleet() {
      const url = API_BASE + "/fleet";
      console.log("[KafehBooking V2] loading fleet from", url);

      return $.ajax({
        url: url,
        method: "GET",
        dataType: "json",
        cache: false,
      })
      .done(function (data) {
        const list = Array.isArray(data)
          ? data
          : (data && Array.isArray(data.vehicles) ? data.vehicles : []);

        if (list.length) {
          state.fleet = list;
          console.log("[KafehBooking V2] loaded " + list.length + " vehicle(s) from backend");
        } else {
          console.warn("[KafehBooking V2] /api/fleet returned an empty list — keeping fallback");
        }
      })
      .fail(function (xhr, status, err) {
        console.error(
          "[KafehBooking V2] /api/fleet FAILED (" + status + " " + (xhr && xhr.status) + "):",
          err || (xhr && xhr.responseText) || "no response body",
          "— URL:", url
        );
        toast("Could not load vehicles from backend — using fallback list.");
      })
      .always(function () {
        renderVehicles();
      });
    }

    // -------- Payment --------
    // Track PayPal SDK load state. The <script> tag in test2.html
    // dispatches one of these custom events as soon as the SDK
    // resolves (success or network failure) so we can give clear
    // console feedback and a useful in-page message instead of the
    // generic "PayPal SDK not loaded" fallback.
    let paypalReady = (typeof paypal !== "undefined" && paypal && paypal.Buttons);
    let paypalFailed = false;

    window.addEventListener("kfb:paypal-ready", function () {
      paypalReady = true;
      console.log("[KafehBooking V2] PayPal SDK loaded OK.");
    });
    window.addEventListener("kfb:paypal-failed", function (e) {
      paypalFailed = true;
      console.error("[KafehBooking V2] PayPal SDK failed to load:", e && e.detail);
    });
    // Last-resort: if no event arrived within 8s, assume failure so
    // we don't sit on a blank step 4 forever.
    setTimeout(function () {
      if (!paypalReady && !paypalFailed) {
        console.warn("[KafehBooking V2] PayPal SDK load timeout — check network/CSP.");
        paypalFailed = true;
      }
    }, 8000);

    function preparePayment() {
      const $form = $("#kfbForm");
      const total = renderSummary();
      state.bookingId = "KFH-" + Date.now().toString(36).toUpperCase();
      $("#kfbPayAmount").text(`$${total.toFixed(2)}`);
      $("#kfbPayBookingId").text(state.bookingId);
      $("#kfbPayVehicle").text(state.selectedVehicle ? state.selectedVehicle.name : "—");

      const $host = $("#kfbPaypalButton").empty();
      if (!paypalReady && !paypal) {
        $host.html(
          '<div style="text-align:center;padding:18px;font-size:13px;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">' +
          '<strong style="display:block;margin-bottom:4px;">PayPal SDK not loaded</strong>' +
          '<span>The PayPal SDK did not finish loading. Check your network connection, browser console, and any ad-blocker / content-blocker.</span>' +
          '</div>'
        );
        return;
      }
      if (!paypalReady) {
        // SDK still loading — show a quick spinner and retry on load.
        $host.html('<div class="kfb-payment-loading" style="text-align:center;padding:24px;font-size:13px;color:#475569;">Loading PayPal…</div>');
        const onReady = function () {
          window.removeEventListener("kfb:paypal-ready", onReady);
          renderPayPalButton($host, $form, total);
        };
        window.addEventListener("kfb:paypal-ready", onReady);
        // If it never loads, swap the spinner for an error.
        const onFail = function () {
          window.removeEventListener("kfb:paypal-failed", onFail);
          $host.html(
            '<div style="text-align:center;padding:18px;font-size:13px;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">' +
            '<strong style="display:block;margin-bottom:4px;">PayPal SDK failed to load</strong>' +
            '<span>Network blocked the PayPal SDK from loading. Disable any content blocker for this page and refresh.</span>' +
            '</div>'
          );
        };
        window.addEventListener("kfb:paypal-failed", onFail);
        return;
      }

      renderPayPalButton($host, $form, total);
    }

    function renderPayPalButton($host, $form, total) {
      try {
        paypal.Buttons({
          style: { layout: "vertical", color: "black", shape: "rect", label: "paypal", height: 48 },
          createOrder: async () => {
            const res = await fetch(`${API_BASE}/paypal/create-order`, {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                amount: total.toFixed(2),
                bookingId: state.bookingId,
                description: `Chauffeur booking ${state.bookingId} — ${state.selectedVehicle.name}`,
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
          onError: (err) => { console.error("[KafehBooking V2] PayPal error:", err); toast("PayPal error."); },
          onCancel: () => toast("Payment cancelled."),
        }).render("#kfbPaypalButton");
      } catch (e) {
        console.error("[KafehBooking V2] renderPayPalButton threw:", e);
        $host.html(
          '<div style="text-align:center;padding:18px;font-size:13px;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">' +
          '<strong style="display:block;margin-bottom:4px;">Could not initialize PayPal</strong>' +
          '<span>' + (e && e.message ? e.message : 'Unknown error') + '</span>' +
          '</div>'
        );
      }
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
      renderVehicles();
      setMinDate();
      syncDropoffVisibility(); // initial sync
      loadFleet();
    }
    $(init);

    // -------- Public API --------
    window.KafehBookingV2 = {
      setFleet(f) { state.fleet = f || DEFAULT_FLEET; renderVehicles(); },
      setApi(base) { window.KAFEH_API = base; },
      reset() { window.location.reload(); },
    };
  } // end boot($)

  // -------- Start --------
  bootWhenReady();
})();
