/* ============================================================
   Booking API Admin Portal — frontend glue
   ============================================================ */
(function () {
  "use strict";

  var K = window.KFB_ADMIN || {};
  var BASE = K.baseUrl || "/";

  // -------- Element helpers --------
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // -------- Mobile sidebar drawer (fixed sidebar goes off-canvas <=900px) --------
  var sidebar = $("#kfbSidebar");
  var navToggle = $("#kfbNavToggle");
  var backdrop = $("#kfbSidebarBackdrop");
  if (sidebar && navToggle && backdrop) {
    var openSidebar = function () {
      sidebar.classList.add("is-open");
      backdrop.classList.add("is-open");
      navToggle.setAttribute("aria-expanded", "true");
    };
    var closeSidebar = function () {
      sidebar.classList.remove("is-open");
      backdrop.classList.remove("is-open");
      navToggle.setAttribute("aria-expanded", "false");
    };
    navToggle.addEventListener("click", function () {
      if (sidebar.classList.contains("is-open")) closeSidebar();
      else openSidebar();
    });
    backdrop.addEventListener("click", closeSidebar);
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") closeSidebar();
    });
    // Reset drawer state if the viewport is resized back to desktop width
    window.addEventListener("resize", function () {
      if (window.innerWidth > 900) closeSidebar();
    });
  }

  // -------- Dashboard stat count-up --------
  var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var statValues = $$(".kfb-stat-value");
  if (statValues.length && !reduceMotion) {
    statValues.forEach(function (el, i) {
      var target = parseInt((el.textContent || "").replace(/[^0-9-]/g, ""), 10);
      if (isNaN(target)) return;
      var duration = 600;
      var startDelay = Math.min(i, 8) * 40;
      var start = null;
      el.textContent = "0";
      var step = function (ts) {
        if (start === null) start = ts;
        var progress = Math.min((ts - start) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
        el.textContent = Math.round(target * eased).toString();
        if (progress < 1) window.requestAnimationFrame(step);
        else el.textContent = target.toString();
      };
      setTimeout(function () { window.requestAnimationFrame(step); }, startDelay);
    });
  }

  // -------- Live image preview --------
  var imgInput = $("#kfbImageInput");
  var imgPreview = $("#kfbImagePreview");
  if (imgInput && imgPreview) {
    imgInput.addEventListener("change", function () {
      var file = imgInput.files && imgInput.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function (e) {
        imgPreview.innerHTML = '<img src="' + e.target.result + '" alt="">';
      };
      reader.readAsDataURL(file);
    });
  }

  // -------- Vehicle list → click loads row into form --------
  $$(".kfb-list-item").forEach(function (item) {
    item.addEventListener("click", function () {
      var id = item.getAttribute("data-id");
      if (!id) return;
      window.location.href = BASE + "index.php/admin/vehicles/" + encodeURIComponent(id);
    });
  });

  // -------- "New" button clears the form --------
  var newBtn = $("#kfbNewVehicle");
  if (newBtn) {
    newBtn.addEventListener("click", function () {
      window.location.href = BASE + "index.php/admin/vehicles";
    });
  }

  // -------- Promo list → click loads row into form --------
  $$("#kfbPromoList .kfb-list-item").forEach(function (item) {
    item.addEventListener("click", function () {
      var id = item.getAttribute("data-id");
      if (!id) return;
      window.location.href = BASE + "index.php/admin/promos/" + encodeURIComponent(id);
    });
  });

  // -------- "New promo" button --------
  var newPromoBtn = $("#kfbNewPromo");
  if (newPromoBtn) {
    newPromoBtn.addEventListener("click", function () {
      window.location.href = BASE + "index.php/admin/promos";
    });
  }

  // -------- Add-on list → click loads row into form --------
  $$("#kfbAddonList .kfb-list-item").forEach(function (item) {
    item.addEventListener("click", function () {
      var id = item.getAttribute("data-id");
      if (!id) return;
      window.location.href = BASE + "index.php/admin/addons/" + encodeURIComponent(id);
    });
  });

  // -------- "New add-on" button --------
  var newAddonBtn = $("#kfbNewAddon");
  if (newAddonBtn) {
    newAddonBtn.addEventListener("click", function () {
      window.location.href = BASE + "index.php/admin/addons";
    });
  }

  // -------- Surcharge list → click loads row into form --------
  $$("#kfbSurchargeList .kfb-list-item").forEach(function (item) {
    item.addEventListener("click", function () {
      var id = item.getAttribute("data-id");
      if (!id) return;
      window.location.href = BASE + "index.php/admin/surcharges/" + encodeURIComponent(id);
    });
  });

  // -------- "New surcharge" button --------
  var newSurchargeBtn = $("#kfbNewSurcharge");
  if (newSurchargeBtn) {
    newSurchargeBtn.addEventListener("click", function () {
      window.location.href = BASE + "index.php/admin/surcharges";
    });
  }

  // -------- Promo form: live swap of discount value unit (% vs $) --------
  // Default layout (percent): unit sits on the RIGHT of the input.
  // For fixed ($): unit sits on the LEFT.
  var promoTypeSel = $("select[name='discount_type']");
  var discountUnit = $("#kfbDiscountUnit");
  var discountInput = $("input[name='discount_value']");
  if (promoTypeSel && discountUnit) {
    var updateUnit = function () {
      var isPercent = promoTypeSel.value === "percent";
      discountUnit.textContent = isPercent ? "%" : "$";
      // percent → right side (default); fixed → left side
      if (isPercent) {
        discountUnit.classList.add("kfb-money-suffix");
      } else {
        discountUnit.classList.remove("kfb-money-suffix");
      }
      if (discountInput) {
        discountInput.setAttribute("max", isPercent ? "100" : "");
      }
    };
    promoTypeSel.addEventListener("change", updateUnit);
    updateUnit();
  }

  // -------- Promo code: auto-uppercase on type --------
  var codeInput = $("input[name='code']");
  if (codeInput) {
    codeInput.addEventListener("input", function () {
      var pos = codeInput.selectionStart;
      codeInput.value = codeInput.value.toUpperCase().replace(/[^A-Z0-9_\-]/g, "");
      try { codeInput.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
    });
  }

  // -------- Promo delete button --------
  // Uses a custom confirm modal that returns a Promise<boolean>.
  // Falls back to the native confirm() if the modal helper isn't loaded.
  var delBtn = $("#kfbDeletePromo");
  if (delBtn) {
    delBtn.addEventListener("click", function (ev) {
      ev.preventDefault();
      var proceed = function () {
        doPromoDelete();
      };
      var msg = "Delete this promo code? Existing bookings keep the code on their receipt, but it will no longer be valid for new bookings.";
      if (window.KFB && typeof window.KFB.confirm === "function") {
        Promise.resolve(window.KFB.confirm(msg)).then(function (ok) {
          if (ok) proceed();
        });
      } else if (!window.confirm(msg)) {
        return; // user cancelled
      } else {
        proceed();
      }
    });
  }
  function doPromoDelete() {
    if (!delBtn) return;
    var endpoint = delBtn.getAttribute("data-endpoint");
    if (!endpoint) {
      alert("Delete endpoint not configured — missing data-endpoint attribute on the delete button.");
      return;
    }
    // Disable the button while the request is in flight so the user
    // can't double-click and fire two delete requests.
    delBtn.disabled = true;
    var oldLabel = delBtn.textContent;
    delBtn.textContent = "Deleting…";

    var fd = new FormData();
    fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; }); })
      .then(function (j) {
        if (j && j.success) {
          window.location.href = BASE + "index.php/admin/promos";
        } else {
          alert((j && j.error) || "Delete failed.");
          delBtn.disabled = false;
          delBtn.textContent = oldLabel;
        }
      })
      .catch(function (err) {
        alert("Network error: " + (err && err.message ? err.message : err));
        delBtn.disabled = false;
        delBtn.textContent = oldLabel;
      });
  }

  // -------- Vehicle form (create / update via fetch + FormData) --------
  var form = $("#kfbVehicleForm");
  var errBox = $("#kfbFormErrors");
  if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      clearErrors();

      // Client-side validation pass before sending
      var clientErrors = validateClient(form);
      if (Object.keys(clientErrors).length) {
        showErrors(clientErrors);
        return;
      }

      var data = new FormData(form);
      var submitBtn = form.querySelector('button[type="submit"]');
      var oldLabel = submitBtn ? submitBtn.textContent : null;
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "Saving…"; }

      fetch(form.action, {
        method: "POST",
        body: data,
        credentials: "same-origin",
      })
      .then(function (r) {
        return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; })
          .then(function (j) { return { status: r.status, body: j }; });
      })
      .then(function (res) {
        if (res.body && res.body.success) {
          // Reload page so the list reflects the change
          window.location.href = BASE + "index.php/admin/vehicles";
        } else if (res.body && res.body.fields) {
          showErrors(res.body.fields);
        } else {
          showErrors({ _all: (res.body && res.body.error) || "Save failed." });
        }
      })
      .catch(function (err) {
        showErrors({ _all: "Network error: " + (err && err.message ? err.message : err) });
      })
      .finally(function () {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = oldLabel; }
      });
    });
  }

  // -------- Vehicle delete button --------
  var delVehicleBtn = $("#kfbDeleteVehicle");
  if (delVehicleBtn) {
    delVehicleBtn.addEventListener("click", function (ev) {
      ev.preventDefault();
      var msg = "Delete this vehicle? Customers will no longer be able to book it, and its uploaded image will be removed. This can't be undone.";
      var proceed = function () { doVehicleDelete(); };
      if (window.KFB && typeof window.KFB.confirm === "function") {
        Promise.resolve(window.KFB.confirm(msg)).then(function (ok) { if (ok) proceed(); });
      } else if (window.confirm(msg)) {
        proceed();
      }
    });
  }
  function doVehicleDelete() {
    if (!delVehicleBtn) return;
    var endpoint = delVehicleBtn.getAttribute("data-endpoint");
    if (!endpoint) { alert("Delete endpoint not configured."); return; }
    delVehicleBtn.disabled = true;
    var oldLabel = delVehicleBtn.textContent;
    delVehicleBtn.textContent = "Deleting…";
    var fd = new FormData();
    fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; }); })
      .then(function (j) {
        if (j && j.success) {
          window.location.href = BASE + "index.php/admin/vehicles";
        } else {
          alert((j && j.error) || "Delete failed.");
          delVehicleBtn.disabled = false;
          delVehicleBtn.textContent = oldLabel;
        }
      })
      .catch(function (err) {
        alert("Network error: " + (err && err.message ? err.message : err));
        delVehicleBtn.disabled = false;
        delVehicleBtn.textContent = oldLabel;
      });
  }

  // -------- Promo form (create / update via fetch + FormData) --------
  // Same pattern as the vehicle form — prevents the browser from
  // navigating to the API endpoint and showing raw JSON, and
  // reloads the list page on success.
  var promoForm = $("#kfbPromoForm");
  if (promoForm) {
    promoForm.addEventListener("submit", function (e) {
      e.preventDefault();
      clearErrors();

      // Client-side validation pass
      var codeEl = promoForm.elements["code"];
      var codeVal = (codeEl && codeEl.value || "").trim().toUpperCase();
      var typeEl = promoForm.elements["discount_type"];
      var valEl  = promoForm.elements["discount_value"];
      var minEl  = promoForm.elements["min_amount"];
      var maxEl  = promoForm.elements["max_uses"];
      var clientErrors = {};
      if (!codeVal) clientErrors["code"] = "Code is required.";
      else if (!/^[A-Z0-9_\-]+$/.test(codeVal)) clientErrors["code"] = "Use uppercase letters, numbers, dash, underscore only.";
      if (valEl && (valEl.value === "" || isNaN(parseFloat(valEl.value)) || parseFloat(valEl.value) < 0)) {
        clientErrors["discount_value"] = "Discount value must be a positive number.";
      }
      if (typeEl && typeEl.value === "percent" && valEl && parseFloat(valEl.value) > 100) {
        clientErrors["discount_value"] = "Percent discount cannot exceed 100.";
      }
      if (minEl && minEl.value !== "" && (isNaN(parseFloat(minEl.value)) || parseFloat(minEl.value) < 0)) {
        clientErrors["min_amount"] = "Min amount must be zero or positive.";
      }
      if (maxEl && maxEl.value !== "" && (isNaN(parseInt(maxEl.value, 10)) || parseInt(maxEl.value, 10) < 0)) {
        clientErrors["max_uses"] = "Max uses must be zero (unlimited) or a positive integer.";
      }
      if (Object.keys(clientErrors).length) {
        showErrors(clientErrors);
        return;
      }

      var data = new FormData(promoForm);
      // Normalize the code to uppercase before sending
      if (data.has("code")) data.set("code", codeVal);

      var submitBtn = promoForm.querySelector('button[type="submit"]');
      var oldLabel = submitBtn ? submitBtn.textContent : null;
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "Saving…"; }

      fetch(promoForm.action, {
        method: "POST",
        body: data,
        credentials: "same-origin",
      })
      .then(function (r) {
        return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; })
          .then(function (j) { return { status: r.status, body: j }; });
      })
      .then(function (res) {
        if (res.body && res.body.success) {
          // Reload the promos list so the new/updated row shows up
          window.location.href = BASE + "index.php/admin/promos";
        } else if (res.body && res.body.fields) {
          showErrors(res.body.fields);
        } else {
          showErrors({ _all: (res.body && res.body.error) || "Save failed." });
        }
      })
      .catch(function (err) {
        showErrors({ _all: "Network error: " + (err && err.message ? err.message : err) });
      })
      .finally(function () {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = oldLabel; }
      });
    });
  }

  // -------- Add-on form (create / update via fetch + FormData) --------
  var addonForm = $("#kfbAddonForm");
  if (addonForm) {
    addonForm.addEventListener("submit", function (e) {
      e.preventDefault();
      clearErrors();

      var data = new FormData(addonForm);
      var submitBtn = addonForm.querySelector('button[type="submit"]');
      var oldLabel = submitBtn ? submitBtn.textContent : null;
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "Saving…"; }

      fetch(addonForm.action, {
        method: "POST", body: data, credentials: "same-origin",
      })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; })
        .then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.body && res.body.success) {
          window.location.href = BASE + "index.php/admin/addons";
        } else if (res.body && res.body.fields) {
          showErrors(res.body.fields);
        } else {
          showErrors({ _all: (res.body && res.body.error) || "Save failed." });
        }
      })
      .catch(function (err) { showErrors({ _all: "Network error: " + (err && err.message ? err.message : err) }); })
      .finally(function () {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = oldLabel; }
      });
    });
  }

  // -------- Surcharge form (create / update via fetch + FormData) --------
  var surchargeForm = $("#kfbSurchargeForm");
  if (surchargeForm) {
    surchargeForm.addEventListener("submit", function (e) {
      e.preventDefault();
      clearErrors();

      var data = new FormData(surchargeForm);
      var submitBtn = surchargeForm.querySelector('button[type="submit"]');
      var oldLabel = submitBtn ? submitBtn.textContent : null;
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "Saving…"; }

      fetch(surchargeForm.action, {
        method: "POST", body: data, credentials: "same-origin",
      })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; })
        .then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.body && res.body.success) {
          window.location.href = BASE + "index.php/admin/surcharges";
        } else if (res.body && res.body.fields) {
          showErrors(res.body.fields);
        } else {
          showErrors({ _all: (res.body && res.body.error) || "Save failed." });
        }
      })
      .catch(function (err) { showErrors({ _all: "Network error: " + (err && err.message ? err.message : err) }); })
      .finally(function () {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = oldLabel; }
      });
    });
  }

  // -------- Surcharge delete button --------
  var delSurchargeBtn = $("#kfbDeleteSurcharge");
  if (delSurchargeBtn) {
    delSurchargeBtn.addEventListener("click", function (ev) {
      ev.preventDefault();
      var msg = "Delete this surcharge? It will no longer be applied to new bookings.";
      var proceed = function () { doSurchargeDelete(); };
      if (window.KFB && typeof window.KFB.confirm === "function") {
        Promise.resolve(window.KFB.confirm(msg)).then(function (ok) { if (ok) proceed(); });
      } else if (window.confirm(msg)) {
        proceed();
      }
    });
  }
  function doSurchargeDelete() {
    if (!delSurchargeBtn) return;
    var endpoint = delSurchargeBtn.getAttribute("data-endpoint");
    if (!endpoint) { alert("Delete endpoint not configured."); return; }
    delSurchargeBtn.disabled = true;
    var oldLabel = delSurchargeBtn.textContent;
    delSurchargeBtn.textContent = "Deleting…";
    var fd = new FormData();
    fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; }); })
      .then(function (j) {
        if (j && j.success) {
          window.location.href = BASE + "index.php/admin/surcharges";
        } else {
          alert((j && j.error) || "Delete failed.");
          delSurchargeBtn.disabled = false;
          delSurchargeBtn.textContent = oldLabel;
        }
      })
      .catch(function (err) {
        alert("Network error: " + (err && err.message ? err.message : err));
        delSurchargeBtn.disabled = false;
        delSurchargeBtn.textContent = oldLabel;
      });
  }

  // -------- Settings form (Meet & Greet fee) --------
  var settingsForm = $("#kfbSettingsForm");
  if (settingsForm) {
    settingsForm.addEventListener("submit", function (e) {
      e.preventDefault();
      clearErrors();
      var savedNote = $("#kfbSettingsSaved");
      if (savedNote) savedNote.hidden = true;

      var data = new FormData(settingsForm);
      var submitBtn = settingsForm.querySelector('button[type="submit"]');
      var oldLabel = submitBtn ? submitBtn.textContent : null;
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "Saving…"; }

      fetch(settingsForm.action, {
        method: "POST", body: data, credentials: "same-origin",
      })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; })
        .then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.body && res.body.success) {
          if (savedNote) { savedNote.hidden = false; }
        } else if (res.body && res.body.fields) {
          showErrors(res.body.fields);
        } else {
          showErrors({ _all: (res.body && res.body.error) || "Save failed." });
        }
      })
      .catch(function (err) { showErrors({ _all: "Network error: " + (err && err.message ? err.message : err) }); })
      .finally(function () {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = oldLabel; }
      });
    });
  }

  // -------- Add-on delete button --------
  var delAddonBtn = $("#kfbDeleteAddon");
  if (delAddonBtn) {
    delAddonBtn.addEventListener("click", function (ev) {
      ev.preventDefault();
      var msg = "Delete this add-on? Customers will no longer be able to add it to their bookings.";
      var proceed = function () { doAddonDelete(); };
      if (window.KFB && typeof window.KFB.confirm === "function") {
        Promise.resolve(window.KFB.confirm(msg)).then(function (ok) { if (ok) proceed(); });
      } else if (window.confirm(msg)) {
        proceed();
      }
    });
  }
  function doAddonDelete() {
    if (!delAddonBtn) return;
    var endpoint = delAddonBtn.getAttribute("data-endpoint");
    if (!endpoint) { alert("Delete endpoint not configured."); return; }
    delAddonBtn.disabled = true;
    var oldLabel = delAddonBtn.textContent;
    delAddonBtn.textContent = "Deleting…";
    var fd = new FormData();
    fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; }); })
      .then(function (j) {
        if (j && j.success) {
          window.location.href = BASE + "index.php/admin/addons";
        } else {
          alert((j && j.error) || "Delete failed.");
          delAddonBtn.disabled = false;
          delAddonBtn.textContent = oldLabel;
        }
      })
      .catch(function (err) {
        alert("Network error: " + (err && err.message ? err.message : err));
        delAddonBtn.disabled = false;
        delAddonBtn.textContent = oldLabel;
      });
  }

  // -------- Reservation Accept / Reject --------
  function wireReservationDecisionButton(id, confirmMsg, failMsg) {
    var btn = $(id);
    if (!btn) return;
    btn.addEventListener("click", function (ev) {
      ev.preventDefault();
      var proceed = function () {
        var endpoint = btn.getAttribute("data-endpoint");
        if (!endpoint) return;
        btn.disabled = true;
        var oldLabel = btn.textContent;
        btn.textContent = "Working…";
        fetch(endpoint, { method: "POST", credentials: "same-origin" })
          .then(function (r) { return r.json().catch(function () { return { success: false, error: "Invalid JSON response" }; }); })
          .then(function (j) {
            if (j && j.success) {
              window.location.reload();
            } else {
              alert((j && j.error) || failMsg);
              btn.disabled = false;
              btn.textContent = oldLabel;
            }
          })
          .catch(function (err) {
            alert("Network error: " + (err && err.message ? err.message : err));
            btn.disabled = false;
            btn.textContent = oldLabel;
          });
      };
      if (window.KFB && typeof window.KFB.confirm === "function") {
        Promise.resolve(window.KFB.confirm(confirmMsg)).then(function (ok) { if (ok) proceed(); });
      } else if (window.confirm(confirmMsg)) {
        proceed();
      }
    });
  }
  wireReservationDecisionButton(
    "#kfbAcceptBtn",
    "Accept this reservation? The held amount will be captured from the customer's card immediately.",
    "Accept failed."
  );
  wireReservationDecisionButton(
    "#kfbRejectBtn",
    "Reject this reservation? The authorization hold will be released — nothing will be charged.",
    "Reject failed."
  );

  function clearErrors() {
    if (!errBox) return;
    errBox.hidden = true;
    errBox.innerHTML = "";
    // Remove per-field error highlights
    $$(".kfb-form [data-error-for]").forEach(function (el) { el.remove(); });
  }

  function showErrors(map) {
    if (!errBox) return;
    var keys = Object.keys(map || {});
    if (!keys.length) return;
    errBox.hidden = false;
    var html = "<strong>Could not save:</strong>";
    if (keys.length === 1 && keys[0] === "_all") {
      html += "<div>" + escapeHtml(map._all) + "</div>";
    } else {
      html += "<ul>";
      keys.forEach(function (k) {
        html += "<li><b>" + escapeHtml(k) + "</b>: " + escapeHtml(map[k]) + "</li>";
      });
      html += "</ul>";
    }
    errBox.innerHTML = html;
    // Scroll into view
    errBox.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function validateClient(form) {
    var errors = {};
    var name = (form.elements["name"] || {}).value || "";
    if (!name.trim()) errors["name"] = "Name is required.";

    var minP = parseInt((form.elements["min_passengers"] || {}).value || "0", 10);
    var maxP = parseInt((form.elements["max_passengers"] || {}).value || "0", 10);
    if (!minP || minP < 1) errors["min_passengers"] = "Minimum passengers must be at least 1.";
    if (!maxP || maxP < 1) errors["max_passengers"] = "Maximum passengers must be at least 1.";
    if (minP && maxP && maxP < minP) {
      errors["max_passengers"] = "Maximum passengers must be ≥ minimum passengers.";
    }

    var rateNames = [
      "local_per_mile_rate", "local_hourly_rate", "local_hourly_min_hours", "local_min_fare",
    ];
    rateNames.forEach(function (n) {
      var el = form.elements[n];
      if (!el) return;
      var raw = (el.value || "").trim();
      if (raw === "") return;
      var num = Number(raw);
      if (!isFinite(num) || num < 0) errors[n] = "Must be zero or a positive number.";
    });
    return errors;
  }

  // -------- Confirm modal for destructive actions --------
  // Returns a Promise that resolves with true (confirm) or false (cancel).
  // The backdrop element is removed inside the click handler so it's
  // always in scope and we never leak DOM nodes.
  window.KFB = window.KFB || {};
  window.KFB.confirm = function (message) {
    return new Promise(function (resolve) {
      var backdrop = document.createElement("div");
      backdrop.className = "kfb-modal-backdrop is-open";
      backdrop.innerHTML =
        '<div class="kfb-modal">' +
          '<h3>Confirm</h3>' +
          '<p>' + escapeHtml(message) + '</p>' +
          '<div class="kfb-modal-actions">' +
            '<button type="button" class="kfb-btn kfb-btn--ghost" data-act="no">Cancel</button>' +
            '<button type="button" class="kfb-btn kfb-btn--danger" data-act="yes">Confirm</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(backdrop);

      var done = function (result) {
        backdrop.remove();
        resolve(result);
      };

      backdrop.addEventListener("click", function (e) {
        var act = e.target && e.target.getAttribute("data-act");
        if (act === "yes") done(true);
        else if (act === "no") done(false);
        else if (e.target === backdrop) done(false);
      });

      // Also dismiss on Escape key
      document.addEventListener("keydown", function escHandler(e) {
        if (e.key === "Escape") {
          document.removeEventListener("keydown", escHandler);
          done(false);
        }
      });
    });
  };
})();