/* ============================================================
   Kafeh Booking — Test page map controller
   ------------------------------------------------------------
   Owns the Google Map, the pickup/stops/dropoff markers, the
   route line, and the distance/time readout for the test
   harness.

   The embed widget (embed/kafeh-booking.js) intentionally
   does NOT init the map anymore — that lives here. The Google
   Maps <script> tag in test.html uses callback=kfbTestInitMap
   so this file is the single source of truth for the map.

   Features (per user spec):
     1. Selecting a pickup place  → drops marker A
     2. Selecting a stop place   → drops marker B / C / D …
     3. Selecting a dropoff place → drops the last marker
     4. All places selected      → draws pickup → stops → dropoff
                                   route on the map
     5. Each route update        → console.log AND alert the
                                   total km + minutes for a
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

  // Each entry: { row, input, ac, marker }
  // row   = the .kfb-stop-row DOM node
  // input = the <input.kfb-stop-input> inside it
  // ac    = the google.maps.places.Autocomplete attached to it
  // marker = the google.maps.Marker dropped for this stop (null until selected)
  let stopEntries = [];

  // Letter labels for markers, in render order: A=pickup, B..=stops, last=dropoff
  const LABEL_POOL = ["A", "B", "C", "D", "E", "F", "G", "H"];

  // -------- Public callback: called by Google Maps when SDK ready --------
  window.kfbTestInitMap = function () {
    if (typeof google === "undefined" || !google.maps) {
      console.warn("[Kafeh Test] Google Maps SDK did not load — map disabled.");
      return;
    }
    boot();
  };

  function boot() {
    var mapEl = document.getElementById("kfbMap");
    if (!mapEl) {
      console.warn("[Kafeh Test] #kfbMap not found in DOM — map disabled.");
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
  function attachPickupAutocomplete() {
    var el = document.getElementById("kfbPickup");
    if (!el || !google.maps.places) return;
    try {
      pickupAC = new google.maps.places.Autocomplete(el, {
        fields: ["place_id", "geometry", "name", "formatted_address"],
      });
      pickupAC.addListener("place_changed", function () {
        var place = pickupAC.getPlace();
        if (place && place.geometry && place.geometry.location) {
          dropPickupMarker(place.geometry.location, place.name || "Pickup");
        }
        updateRoute();
      });
    } catch (e) {
      console.warn("[Kafeh Test] pickup autocomplete unavailable:", e && e.message);
    }
  }

  // -------- Autocomplete: dropoff --------
  function attachDropoffAutocomplete() {
    var el = document.getElementById("kfbDropoff");
    if (!el || !google.maps.places) return;
    try {
      dropoffAC = new google.maps.places.Autocomplete(el, {
        fields: ["place_id", "geometry", "name", "formatted_address"],
      });
      dropoffAC.addListener("place_changed", function () {
        var place = dropoffAC.getPlace();
        if (place && place.geometry && place.geometry.location) {
          dropDropoffMarker(place.geometry.location, place.name || "Dropoff");
        }
        updateRoute();
      });
    } catch (e) {
      console.warn("[Kafeh Test] dropoff autocomplete unavailable:", e && e.message);
    }
  }

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
        fields: ["place_id", "geometry", "name", "formatted_address"],
      });
      ac.addListener("place_changed", function () {
        var place = ac.getPlace();
        if (place && place.geometry && place.geometry.location) {
          updateStopMarker(stopRow, place.geometry.location, place.name || "Stop");
        }
        renumberMarkers();
        updateRoute();
      });
      stopEntries.push({ row: stopRow, input: input, ac: ac, marker: null });
    } catch (e) {
      console.warn("[Kafeh Test] stop autocomplete unavailable:", e && e.message);
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
        unitSystem: google.maps.UnitSystem.METRIC,  // km
      },
      function (result, status) {
        if (status !== "OK") {
          console.warn("[Kafeh Test] directions request failed:", status);
          return;
        }
        directionsRenderer.setDirections(result);

        // Sum every leg so waypoints are reflected in the totals
        var km = 0, seconds = 0;
        result.routes[0].legs.forEach(function (leg) {
          km      += leg.distance.value / 1000;   // metres → km
          seconds += leg.duration.value;          // seconds
        });
        var minutes = Math.round(seconds / 60);
        var kmText   = km.toFixed(1) + " km";
        var timeText = minutes + " min";

        // Update the on-page badge if it's there
        var distEl = document.getElementById("kfbDistance");
        var durEl  = document.getElementById("kfbDuration");
        if (distEl) distEl.textContent = kmText;
        if (durEl)  durEl.textContent  = timeText;
        var info = document.getElementById("kfbRouteInfo");
        if (info) info.hidden = false;

        // Log + alert, per spec. Include the stop count so the user
        // can see that waypoints were actually taken into account.
        var stopCount = waypoints.length;
        var line = "Best route (car / SUV): " + kmText + " · " + timeText;
        if (stopCount > 0) {
          line += " (via " + stopCount + " stop" + (stopCount > 1 ? "s" : "") + ")";
        }
        console.log("[Kafeh Test]", line);
        try { window.alert(line); } catch (e) { /* alert blocked — console is enough */ }
      }
    );
  }

  // Expose for debugging from the console
  window.KafehTestMap = {
    getMap: function () { return map; },
    updateRoute: updateRoute,
    getStops: function () { return stopEntries.slice(); },
  };
})();
