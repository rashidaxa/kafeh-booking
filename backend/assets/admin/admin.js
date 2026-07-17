/* ============================================================
   Kafeh Admin Portal — frontend glue
   ============================================================ */
(function () {
  "use strict";

  var K = window.KFB_ADMIN || {};
  var BASE = K.baseUrl || "/";

  // -------- Element helpers --------
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

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
  var delBtn = $("#kfbDeletePromo");
  if (delBtn) {
    delBtn.addEventListener("click", function () {
      if (!window.KFB || !window.KFB.confirm) {
        if (!confirm("Delete this promo code?")) return;
        return doPromoDelete();
      }
      window.KFB.confirm("Delete this promo code? Existing bookings keep the code on their receipt, but it will no longer be valid for new bookings.").then(function (ok) {
        if (ok) doPromoDelete();
      });
    });
  }
  function doPromoDelete() {
    if (!delBtn) return;
    var endpoint = delBtn.getAttribute("data-endpoint");
    if (!endpoint) return;
    var fd = new FormData();
    fetch(endpoint, { method: "POST", body: fd, credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.success) {
          window.location.href = BASE + "index.php/admin/promos";
        } else {
          alert((j && j.error) || "Delete failed.");
        }
      })
      .catch(function (err) { alert("Network error: " + err); });
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
      "hourly_chicago","hourly_america","hourly_worldwide",
      "per_km_chicago","per_km_america","per_km_worldwide",
      "surcharge_chicago","surcharge_america","surcharge_worldwide",
      "gratuity_chicago","gratuity_america","gratuity_worldwide",
      "waiting_chicago","waiting_america","waiting_worldwide",
      "child_seat_chicago","child_seat_america","child_seat_worldwide",
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

  // -------- Confirm modal for destructive actions (kept for future use) --------
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
      backdrop.addEventListener("click", function (e) {
        var act = e.target && e.target.getAttribute("data-act");
        if (act === "yes") resolve(true);
        else if (act === "no") resolve(false);
        else if (e.target === backdrop) resolve(false);
      });
    }).then(function (ok) {
      backdrop.remove();
      return ok;
    });
  };
})();