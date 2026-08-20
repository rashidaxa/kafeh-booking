/* ============================================================
   Booking API — Test page map controller
   ------------------------------------------------------------
   Owns the Google Map, the pickup/stops/dropoff markers, the
   route line, and the distance/time readout for the test
   harness.

   The embed widget (embed/booking.js) intentionally
   does NOT init the map anymore — that lives here. The Google
   Maps <script> tag in test3.html uses callback=kfbTestInitMap
   so this file is the single source of truth for the map.

   Features (per user spec):
     1. Selecting a pickup place  → drops marker A
     2. Selecting a stop place   → drops marker B / C / D …
     3. Selecting a dropoff place → drops the last marker
     4. All places selected      → draws pickup → stops → dropoff
                                   route on the map
     5. Each route update        → console.log AND alert the
                                   total miles + minutes for a
                                   car / SUV (sum of all legs,
                                   so waypoints are included)
   ============================================================ */

(function () {
  "use strict";

  // -------- Lazy state --------
  let map = null;
  let directionsService = null;
  let directionsRenderer = null;
  let pickupMarker = null;
  let dropoffMarker = null;
  let pickupAC = null;
  let dropoffAC = null;

  // -------- Public route state --------
  // Read by the booking widget (price calc, summary, etc).
  // Initialised to zeros — every successful DirectionsService call below
  // refreshes these so the embed widget always sees the latest values.
  window.kfbRoute = {
    distanceMiles: 0,
    durationMins:  0,
    stopCount:     0,
    region:        "Worldwide", // "Chicago" | "America" | "Worldwide"
  };

  // Each entry: { row, input, ac, marker }
  // row   = the .kfb-stop-row DOM node
  // input = the <input.kfb-stop-input> inside it
  // ac    = the google.maps.places.Autocomplete attached to it
  // marker = the google.maps.Marker dropped for this stop (null until selected)
  let stopEntries = [];

  // Letter labels for markers, in render order: A=pickup, B..=stops, last=dropoff
  const LABEL_POOL = ["A", "B", "C", "D", "E", "F", "G", "H"];

  // Chicago city-limits bounding box (approximate). Used as a fallback
  // for region detection when a Places result's address_components
  // does not include a `locality` of "Chicago".
  const CHICAGO_BOUNDS = {
    north: 42.023,
    south: 41.644,
    west: -87.940,
    east: -87.524,
  };

  // -------- Public callback: called by Google Maps when SDK ready --------
  window.kfbTestInitMap = function () {
    if (typeof google === "undefined" || !google.maps) {
      console.warn("[BookingMap] Google Maps SDK did not load — map disabled.");
      return;
    }
    boot();
  };

  function boot() {
    var mapEl = document.getElementById("kfbMap");
    if (!mapEl) {
      console.warn("[BookingMap] #kfbMap not found in DOM — map disabled.");
      return;
    }

    map = new google.maps.Map(mapEl, {
      center: { lat: 40.7128, lng: -74.0060 }, // New York — neutral default
      zoom: 12,
    });

    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
      map: map,
      suppressMarkers: true, // we draw our own A/B/C/... markers
      polylineOptions: { strokeColor: "#0a0a0a", strokeWeight: 4 },
    });

    attachPickupAutocomplete();
    attachDropoffAutocomplete();
    watchStops();

    // Also react to manual edits of the text fields (no Places dropdown)
    // so the route still updates when the user types a full address.
    var pickupEl  = document.getElementById("kfbPickup");
    var dropoffEl = document.getElementById("kfbDropoff");
    if (pickupEl)  pickupEl.addEventListener("change", updateRoute);
    if (dropoffEl) dropoffEl.addEventListener("change", updateRoute);
  }

  // -------- Autocomplete: pickup --------
  // Filter the autocomplete predictions by the active "Location Type" button.
  // If no filter is requested (or the type is "Search All"), we omit the
  // `types` field entirely so all categories are returned.
  function buildAutocompleteOptions(group) {
    var fields = ["place_id", "geometry", "name", "formatted_address", "address_components", "types"];
    var typeFilter = getLocationTypeFilter(group);
    if (typeFilter) {
      return { fields: fields, types: typeFilter };
    }
    return { fields: fields };
  }
  function getLocationTypeFilter(group) {
    var btn = document.querySelector('.kfb-loc-type-btn.is-active[data-group="' + group + '"]');
    if (!btn) return null;
    var v = btn.getAttribute("data-value");
    switch (v) {
      case "Airport":    return ["airport"];
      // "geocode" still includes bare cities/regions/countries — "address"
      // is the Places API's dedicated precise-street-address restriction.
      case "Address":    return ["address"];
      case "Landmark":   return ["establishment"];
      case "Search All":
      default:           return null;
    }
  }

  // A selected place is "too vague" for a pickup/dropoff/stop point if
  // Google's own classification of it is a city, state, country, or
  // postal code and nothing more specific (no street_address/route/
  // premise/establishment/airport component). "Search All" and "Landmark"
  // deliberately don't restrict `types` up front (the Places API only
  // allows one type-collection filter per request, and there's no
  // "everything except cities" option) — so precise-address enforcement
  // happens here instead, after a place is actually selected.
  var VAGUE_PLACE_TYPES = [
    "locality", "sublocality", "administrative_area_level_1",
    "administrative_area_level_2", "administrative_area_level_3",
    "country", "postal_code", "postal_town",
  ];
  function isPlaceTooVague(place) {
    var types = (place && place.types) || [];
    if (!types.length) return false; // no type data — don't block on it
    return types.some(function (t) { return VAGUE_PLACE_TYPES.indexOf(t) !== -1; });
  }
  // If the selection is too vague, clear it (so it can't silently pass as
  // "good enough" free text either) and tell the customer why. Returns
  // true when the selection was rejected.
  function rejectIfTooVague(el, place, label) {
    if (!isPlaceTooVague(place)) return false;
    el.value = "";
    try {
      window.dispatchEvent(new CustomEvent("kfb:toast", {
        detail: { message: "Please choose a specific " + label + " address, not just a city or region.", type: "error" },
      }));
    } catch (e) { /* old browsers — the cleared field is still the important part */ }
    return true;
  }

  function attachPickupAutocomplete() {
    var el = document.getElementById("kfbPickup");
    if (!el || !google.maps.places) return;
    try {
      pickupAC = new google.maps.places.Autocomplete(el, buildAutocompleteOptions("pickup"));
      pickupAC.addListener("place_changed", function () {
        var place = pickupAC.getPlace();
        if (rejectIfTooVague(el, place, "pickup")) return;
        if (place && place.geometry && place.geometry.location) {
          dropPickupMarker(place.geometry.location, place.name || "Pickup");
        }
        updateRoute();
      });
    } catch (e) {
      console.warn("[BookingMap] pickup autocomplete unavailable:", e && e.message);
    }
    // Fallback for customers who type an address and tab/click away
    // without picking a dropdown suggestion — place_changed never fires
    // in that case, so the route (and therefore the price) would
    // otherwise silently stay at zero. updateRoute() already falls back
    // to the raw typed text when no place geometry is available.
    el.addEventListener("blur", debouncedUpdateRoute);
  }

  // -------- Autocomplete: dropoff --------
  function attachDropoffAutocomplete() {
    var el = document.getElementById("kfbDropoff");
    if (!el || !google.maps.places) return;
    try {
      dropoffAC = new google.maps.places.Autocomplete(el, buildAutocompleteOptions("dropoff"));
      dropoffAC.addListener("place_changed", function () {
        var place = dropoffAC.getPlace();
        if (rejectIfTooVague(el, place, "drop-off")) return;
        if (place && place.geometry && place.geometry.location) {
          dropDropoffMarker(place.geometry.location, place.name || "Dropoff");
        }
        updateRoute();
      });
    } catch (e) {
      console.warn("[BookingMap] dropoff autocomplete unavailable:", e && e.message);
    }
    // Same fallback as pickup — see comment there.
    el.addEventListener("blur", debouncedUpdateRoute);
  }

  // -------- Update autocomplete filter when location type changes --------
  // The widget dispatches "kfb:loc-type-changed" with detail.group in
  // {"pickup","dropoff"} when the user clicks a location-type button.
  // Instead of tearing down and recreating the Autocomplete (which
  // leaks DOM listeners and can leave two instances fighting on the
  // same input), we just call .setOptions() on the existing instance
  // with the new `types` filter — the dropdown re-renders live.
  //
  // Type mapping:
  //   Airport    → ["airport"]      (only airports)
  //   Address    → ["geocode"]      (only street addresses)
  //   Landmark   → ["establishment"] (only businesses / points of interest)
  //   Search All → omitted          (no filter, all categories)
  function updateAutocompleteForGroup(group) {
    if (!google.maps.places) return;
    var typeFilter = getLocationTypeFilter(group);
    var options = {
      fields: ["place_id", "geometry", "name", "formatted_address", "address_components"]
    };
    if (typeFilter) options.types = typeFilter;

    var ac = (group === "pickup") ? pickupAC : (group === "dropoff") ? dropoffAC : null;
    if (ac && typeof ac.setOptions === "function") {
      ac.setOptions(options);
    }
  }
  window.addEventListener("kfb:loc-type-changed", function (e) {
    var group = e && e.detail && e.detail.group;
    if (group) updateAutocompleteForGroup(group);
  });

  // -------- Stops: watch the container for added / removed rows --------
  // The embed widget's "+ Add Stop" button dynamically inserts
  // .kfb-stop-row nodes into #kfbStopsContainer. We use a
  // MutationObserver to attach Places Autocomplete to each new
  // row and clean up when one is removed.
  function watchStops() {
    var container = document.getElementById("kfbStopsContainer");
    if (!container || typeof MutationObserver === "undefined") return;

    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (isStopRow(node)) attachStopAutocomplete(node);
        });
        m.removedNodes.forEach(function (node) {
          if (isStopRow(node)) removeStopEntry(node);
        });
      });
    });
    observer.observe(container, { childList: true });

    // Attach to any rows that already exist when the map boots
    container.querySelectorAll(".kfb-stop-row").forEach(attachStopAutocomplete);
  }

  function isStopRow(node) {
    return node && node.nodeType === 1 && node.classList && node.classList.contains("kfb-stop-row");
  }

  function attachStopAutocomplete(stopRow) {
    if (!google.maps.places) return;
    var input = stopRow.querySelector("input.kfb-stop-input");
    if (!input) return;

    // Skip if we already wired this row (observer can fire twice in edge cases)
    if (stopEntries.some(function (e) { return e.row === stopRow; })) return;

    try {
      var ac = new google.maps.places.Autocomplete(input, {
        fields: ["place_id", "geometry", "name", "formatted_address", "address_components", "types"],
      });
      ac.addListener("place_changed", function () {
        var place = ac.getPlace();
        if (rejectIfTooVague(input, place, "stop")) return;
        if (place && place.geometry && place.geometry.location) {
          updateStopMarker(stopRow, place.geometry.location, place.name || "Stop");
        }
        renumberMarkers();
        updateRoute();
      });
      stopEntries.push({ row: stopRow, input: input, ac: ac, marker: null });
    } catch (e) {
      console.warn("[BookingMap] stop autocomplete unavailable:", e && e.message);
    }

    // Manual edit → just redraw the route (marker will appear once a
    // place is selected from the dropdown, or not at all if typed-only)
    input.addEventListener("change", updateRoute);
  }

  function removeStopEntry(stopRow) {
    var idx = stopEntries.findIndex(function (e) { return e.row === stopRow; });
    if (idx === -1) return;
    var entry = stopEntries[idx];
    if (entry.marker) entry.marker.setMap(null);
    stopEntries.splice(idx, 1);
    renumberMarkers();
    updateRoute();
  }

  function updateStopMarker(stopRow, location, title) {
    var entry = stopEntries.find(function (e) { return e.row === stopRow; });
    if (!entry) return;
    if (entry.marker) entry.marker.setMap(null);
    entry.marker = new google.maps.Marker({
      map: map,
      position: location,
      title: title,
      animation: google.maps.Animation.DROP,
    });
  }

  // Re-apply letter labels to every visible marker so the sequence
  // (A=pickup, B/C/D...=stops, last=dropoff) stays correct whenever
  // a stop is added / removed / re-ordered.
  function renumberMarkers() {
    var idx = 0;
    if (pickupMarker) {
      pickupMarker.setLabel(makeLabel(LABEL_POOL[idx++]));
    }
    stopEntries.forEach(function (entry) {
      if (entry.marker) entry.marker.setLabel(makeLabel(LABEL_POOL[idx++]));
    });
    if (dropoffMarker) {
      dropoffMarker.setLabel(makeLabel(LABEL_POOL[idx++]));
    }
  }

  function makeLabel(text) {
    return { text: text, color: "#fff", fontWeight: "700" };
  }

  // -------- Region detection (Chicago / America / Worldwide) --------
  // Used after a route is computed to tell the user whether both
  // endpoints sit inside the Chicago service area, elsewhere in the
  // USA, or somewhere else in the world.
  function getLatLng(loc) {
    if (!loc) return null;
    if (typeof loc.lat === "function") return { lat: loc.lat(), lng: loc.lng() };
    if (typeof loc.lat === "number") return { lat: loc.lat, lng: loc.lng };
    return null;
  }

  function isLatLngInChicago(latLng) {
    if (!latLng) return false;
    return (
      latLng.lat >= CHICAGO_BOUNDS.south &&
      latLng.lat <= CHICAGO_BOUNDS.north &&
      latLng.lng >= CHICAGO_BOUNDS.west &&
      latLng.lng <= CHICAGO_BOUNDS.east
    );
  }

  // True if the place resolves to somewhere inside the city of Chicago,
  // either by `locality === "Chicago"` in its address_components or by
  // its geometry falling inside the Chicago bounding box.
  function isPlaceInChicago(place) {
    if (!place) return false;
    if (Array.isArray(place.address_components)) {
      for (var i = 0; i < place.address_components.length; i++) {
        var comp = place.address_components[i];
        if (
          comp &&
          Array.isArray(comp.types) &&
          comp.types.indexOf("locality") !== -1 &&
          (comp.long_name === "Chicago" || comp.short_name === "Chicago")
        ) {
          return true;
        }
      }
    }
    var ll = getLatLng(place.geometry && place.geometry.location);
    return isLatLngInChicago(ll);
  }

  // True if the place's country component is the United States.
  function isPlaceInUSA(place) {
    if (!place || !Array.isArray(place.address_components)) return false;
    for (var i = 0; i < place.address_components.length; i++) {
      var comp = place.address_components[i];
      if (
        comp &&
        Array.isArray(comp.types) &&
        comp.types.indexOf("country") !== -1 &&
        (comp.short_name === "US" || comp.long_name === "United States")
      ) {
        return true;
      }
    }
    return false;
  }

  // Combine pickup + dropoff into one of the three buckets the user
  // asked for. Stops are intentionally NOT considered — only the two
  // endpoints gate the service region per spec.
  function classifyRegion(pickupPlace, dropoffPlace) {
    var pickupInChicago  = isPlaceInChicago(pickupPlace);
    var dropoffInChicago = isPlaceInChicago(dropoffPlace);
    if (pickupInChicago && dropoffInChicago) return "Chicago";

    var pickupInUSA  = isPlaceInUSA(pickupPlace);
    var dropoffInUSA = isPlaceInUSA(dropoffPlace);
    if (pickupInUSA && dropoffInUSA) return "America";

    return "Worldwide";
  }

  // -------- Markers: pickup & dropoff --------
  function dropPickupMarker(location, title) {
    if (pickupMarker) pickupMarker.setMap(null);
    pickupMarker = new google.maps.Marker({
      map: map,
      position: location,
      title: title,
      label: makeLabel("A"),
      animation: google.maps.Animation.DROP,
    });
    map.panTo(location);
    renumberMarkers();
  }

  function dropDropoffMarker(location, title) {
    if (dropoffMarker) dropoffMarker.setMap(null);
    dropoffMarker = new google.maps.Marker({
      map: map,
      position: location,
      title: title,
      label: makeLabel("B"),
      animation: google.maps.Animation.DROP,
    });
    map.panTo(location);
    renumberMarkers();
  }

  // -------- Route calculation --------
  var routeDebounceTimer = null;
  function debouncedUpdateRoute() {
    clearTimeout(routeDebounceTimer);
    routeDebounceTimer = setTimeout(updateRoute, 400);
  }

  // Reads pickup, every non-empty stop, and dropoff. Sends them to
  // the Directions service as origin / waypoints[] / destination so
  // the polyline runs pickup → stop1 → stop2 → ... → dropoff.
  function updateRoute() {
    if (!directionsService || !directionsRenderer) return;

    var pickupEl  = document.getElementById("kfbPickup");
    var dropoffEl = document.getElementById("kfbDropoff");
    if (!pickupEl || !dropoffEl) return;

    var origin      = pickupEl.value.trim();
    var destination = dropoffEl.value.trim();
    if (!origin || !destination) return; // need both before drawing

    // Collect stops in DOM order, skip blank rows
    var waypoints = stopEntries
      .map(function (e) { return { entry: e, value: e.input.value.trim() }; })
      .filter(function (x) { return x.value; })
      .map(function (x) {
        // Prefer the place geometry if the user picked it from the dropdown
        var place = x.entry.ac && x.entry.ac.getPlace();
        return {
          location: (place && place.geometry) ? place.geometry.location : x.value,
          stopover: true,
        };
      });

    // Prefer the actual place geometry for endpoints too
    var originLoc      = (pickupAC  && pickupAC.getPlace()  && pickupAC.getPlace().geometry)  ? pickupAC.getPlace().geometry.location  : origin;
    var destinationLoc = (dropoffAC && dropoffAC.getPlace() && dropoffAC.getPlace().geometry) ? dropoffAC.getPlace().geometry.location : destination;

    directionsService.route(
      {
        origin: originLoc,
        destination: destinationLoc,
        waypoints: waypoints,
        travelMode: google.maps.TravelMode.DRIVING, // car / SUV
        unitSystem: google.maps.UnitSystem.IMPERIAL, // miles
      },
      function (result, status) {
        if (status !== "OK") {
          console.warn("[BookingMap] directions request failed:", status);
          return;
        }
        directionsRenderer.setDirections(result);

        // Sum every leg so waypoints are reflected in the totals.
        // leg.distance.value is always metres from the API regardless of
        // unitSystem (that option only affects leg.distance.text) — miles
        // is our source of truth for distance, computed straight from it.
        var miles = 0, seconds = 0;
        result.routes[0].legs.forEach(function (leg) {
          miles   += leg.distance.value / 1609.344; // metres → miles
          seconds += leg.duration.value;             // seconds
        });
        var minutes  = Math.round(seconds / 60);
        var milesText = miles.toFixed(1) + " mi";
        var timeText  = minutes + " min";

        // Determine service region from pickup + dropoff place objects.
        var pickupPlace  = pickupAC  ? pickupAC.getPlace()  : null;
        var dropoffPlace = dropoffAC ? dropoffAC.getPlace() : null;
        var region = classifyRegion(pickupPlace, dropoffPlace);
        var stopCount = waypoints.length;

        // Publish to the global route state so the booking widget can
        // read the latest distance / region without polling.
        if (!window.kfbRoute) window.kfbRoute = {};
        window.kfbRoute.distanceMiles = miles;
        window.kfbRoute.durationMins  = minutes;
        window.kfbRoute.stopCount     = stopCount;
        window.kfbRoute.region        = region;

        // Notify any listeners (the embed widget uses this to re-render
        // step 2 prices and the step 3 summary in real time).
        try {
          window.dispatchEvent(new CustomEvent("kfb:route-updated", {
            detail: Object.assign({}, window.kfbRoute),
          }));
        } catch (e) { /* old browsers — fine, the embed widget polls */ }

        // Update the on-page badge if it's there
        var distEl = document.getElementById("kfbDistance");
        var durEl  = document.getElementById("kfbDuration");
        if (distEl) distEl.textContent = milesText;
        if (durEl)  durEl.textContent  = timeText;
        var info = document.getElementById("kfbRouteInfo");
        if (info) info.hidden = false;

        // Log + alert, per spec. Include the stop count so the user
        // can see that waypoints were actually taken into account, and
        // the service region so they know whether the trip is inside
        // Chicago, elsewhere in the USA, or worldwide.
        var line = "Best route (car / SUV): " + milesText + " · " + timeText;
        if (stopCount > 0) {
          line += " (via " + stopCount + " stop" + (stopCount > 1 ? "s" : "") + ")";
        }
        var regionLine = "Service region: " + region;

        console.log("[BookingMap]", line, "—", regionLine);
        // try { window.alert(line + "\n" + regionLine); } catch (e) { /* alert blocked — console is enough */ }
      }
    );
  }

  // Expose for debugging from the console
  window.KafehTestMap = {
    getMap: function () { return map; },
    updateRoute: updateRoute,
    getStops: function () { return stopEntries.slice(); },
    getRoute: function () { return Object.assign({}, window.kfbRoute || {}); },
  };
})();
