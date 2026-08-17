/* ============================================================
   Booking API — Airlines catalog
   ------------------------------------------------------------
   Used by the V3 widget to populate the <datalist> behind the
   Airline fields (pickup and dropoff). Replaces the previous
   static airports catalog — airport selection is now handled
   by Google Places autocomplete on the text input.

   Global: window.airlines — array of { code, name }
   ============================================================ */
(function () {
  "use strict";

  // Major airlines (IATA code, name)
  window.airlines = [
    { code: "AA",  name: "American Airlines" },
    { code: "AC",  name: "Air Canada" },
    { code: "AF",  name: "Air France" },
    { code: "AI",  name: "Air India" },
    { code: "AM",  name: "Aeromexico" },
    { code: "AR",  name: "Aerolineas Argentinas" },
    { code: "AS",  name: "Alaska Airlines" },
    { code: "AT",  name: "Royal Air Maroc" },
    { code: "AY",  name: "Finnair" },
    { code: "AZ",  name: "ITA Airways" },
    { code: "B6",  name: "JetBlue Airways" },
    { code: "BA",  name: "British Airways" },
    { code: "BR",  name: "EVA Air" },
    { code: "CA",  name: "Air China" },
    { code: "CI",  name: "China Airlines" },
    { code: "CM",  name: "Copa Airlines" },
    { code: "CX",  name: "Cathay Pacific" },
    { code: "DL",  name: "Delta Air Lines" },
    { code: "EK",  name: "Emirates" },
    { code: "ET",  name: "Ethiopian Airlines" },
    { code: "EY",  name: "Etihad Airways" },
    { code: "F9",  name: "Frontier Airlines" },
    { code: "FJ",  name: "Fiji Airways" },
    { code: "G3",  name: "GOL Linhas Aéreas" },
    { code: "GF",  name: "Gulf Air" },
    { code: "HA",  name: "Hawaiian Airlines" },
    { code: "HU",  name: "Hainan Airlines" },
    { code: "JL",  name: "Japan Airlines" },
    { code: "KE",  name: "Korean Air" },
    { code: "KL",  name: "KLM Royal Dutch Airlines" },
    { code: "KQ",  name: "Kenya Airways" },
    { code: "LA",  name: "LATAM Airlines" },
    { code: "LH",  name: "Lufthansa" },
    { code: "LO",  name: "LOT Polish Airlines" },
    { code: "LX",  name: "Swiss International Air Lines" },
    { code: "ME",  name: "Middle East Airlines" },
    { code: "MH",  name: "Malaysia Airlines" },
    { code: "MS",  name: "EgyptAir" },
    { code: "MU",  name: "China Eastern Airlines" },
    { code: "NH",  name: "All Nippon Airways" },
    { code: "NK",  name: "Spirit Airlines" },
    { code: "NZ",  name: "Air New Zealand" },
    { code: "OA",  name: "Olympic Air" },
    { code: "OK",  name: "Czech Airlines" },
    { code: "OS",  name: "Austrian Airlines" },
    { code: "OZ",  name: "Asiana Airlines" },
    { code: "PR",  name: "Philippine Airlines" },
    { code: "PS",  name: "Ukraine International Airlines" },
    { code: "QF",  name: "Qantas" },
    { code: "QR",  name: "Qatar Airways" },
    { code: "RJ",  name: "Royal Jordanian" },
    { code: "SA",  name: "South African Airways" },
    { code: "SK",  name: "SAS Scandinavian Airlines" },
    { code: "SN",  name: "Brussels Airlines" },
    { code: "SQ",  name: "Singapore Airlines" },
    { code: "SU",  name: "Aeroflot" },
    { code: "SV",  name: "Saudia" },
    { code: "TG",  name: "Thai Airways" },
    { code: "TK",  name: "Turkish Airlines" },
    { code: "TP",  name: "TAP Air Portugal" },
    { code: "TR",  name: "Scoot" },
    { code: "UA",  name: "United Airlines" },
    { code: "UL",  name: "SriLankan Airlines" },
    { code: "UX",  name: "Air Europa" },
    { code: "VA",  name: "Virgin Australia" },
    { code: "VS",  name: "Virgin Atlantic" },
    { code: "VY",  name: "Vueling Airlines" },
    { code: "W6",  name: "Wizz Air" },
    { code: "WN",  name: "Southwest Airlines" },
    { code: "WS",  name: "WestJet" },
    { code: "Y4",  name: "Volaris" },
    { code: "YV",  name: "Mesa Airlines" },
  ];
})();
