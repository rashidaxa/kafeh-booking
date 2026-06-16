/* ============================================================
   Kafeh Booking — Test page map controller
   ------------------------------------------------------------
   Owns the Google Map, the pickup/dropoff markers, the route
   line, and the distance/time readout for the test harness.

   The embed widget (embed/kafeh-booking.js) intentionally
   does NOT init the map anymore — that lives here. The Google
   Maps <script> tag in test.html uses callback=kfbTestInitMap
   so this file is the single source of truth for the map.

   Features (per user spec):
     1. Selecting a pickup place  → drops a pickup marker
     2. Selecting a dropoff place → drops a dropoff marker
     3. Both selected            → draws the best driving route
     4. Each route update         → console.log AND alert the
        kilometres + minutes for a car / SUV
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
      suppressMarkers: true, // we draw our own custom markers (A → B)
      polylineOptions: { strokeColor: "#0a0a0a", strokeWeight: 4 },
    });

    attachPickupAutocomplete();
    attachDropoffAutocomplete();

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

  // -------- Markers --------
  function dropPickupMarker(location, title) {
    if (pickupMarker) pickupMarker.setMap(null);
    pickupMarker = new google.maps.Marker({
      map: map,
      position: location,
      title: title,
      label: { text: "A", color: "#fff", fontWeight: "700" },
      animation: google.maps.Animation.DROP,
    });
    map.panTo(location);
  }

  function dropDropoffMarker(location, title) {
    if (dropoffMarker) dropoffMarker.setMap(null);
    dropoffMarker = new google.maps.Marker({
      map: map,
      position: location,
      title: title,
      label: { text: "B", color: "#fff", fontWeight: "700" },
      animation: google.maps.Animation.DROP,
    });
    map.panTo(location);
  }

  // -------- Route calculation --------
  function updateRoute() {
    if (!directionsService || !directionsRenderer) return;

    var pickupEl  = document.getElementById("kfbPickup");
    var dropoffEl = document.getElementById("kfbDropoff");
    if (!pickupEl || !dropoffEl) return;

    var origin      = pickupEl.value.trim();
    var destination = dropoffEl.value.trim();
    if (!origin || !destination) return; // need both before drawing

    // Prefer the actual place geometry if we have it (more accurate than
    // re-geocoding the typed string), but fall back to the typed text.
    var originLoc      = (pickupAC  && pickupAC.getPlace()  && pickupAC.getPlace().geometry)  ? pickupAC.getPlace().geometry.location  : origin;
    var destinationLoc = (dropoffAC && dropoffAC.getPlace() && dropoffAC.getPlace().geometry) ? dropoffAC.getPlace().geometry.location : destination;

    directionsService.route(
      {
        origin: originLoc,
        destination: destinationLoc,
        travelMode: google.maps.TravelMode.DRIVING, // car / SUV
        unitSystem: google.maps.UnitSystem.METRIC,  // km
      },
      function (result, status) {
        if (status !== "OK") {
          console.warn("[Kafeh Test] directions request failed:", status);
          return;
        }
        directionsRenderer.setDirections(result);

        // Sum every leg (works for routes with waypoints too).
        var km = 0, seconds = 0;
        result.routes[0].legs.forEach(function (leg) {
          km      += leg.distance.value / 1000;   // metres → km
          seconds += leg.duration.value;          // seconds
        });
        var minutes = Math.round(seconds / 60);
        var kmText  = km.toFixed(1) + " km";
        var timeText = minutes + " min";

        // Update the on-page badge if it's there.
        var distEl = document.getElementById("kfbDistance");
        var durEl  = document.getElementById("kfbDuration");
        if (distEl) distEl.textContent = kmText;
        if (durEl)  durEl.textContent  = timeText;
        var info = document.getElementById("kfbRouteInfo");
        if (info) info.hidden = false;

        // 4) Log AND alert — per user spec
        var line = "Best route (car / SUV): " + kmText + " · " + timeText;
        console.log("[Kafeh Test]", line);
        try { window.alert(line); } catch (e) { /* alert blocked — console is enough */ }
      }
    );
  }

  // Expose for debugging from the console
  window.KafehTestMap = {
    getMap: function () { return map; },
    updateRoute: updateRoute,
  };
})();
