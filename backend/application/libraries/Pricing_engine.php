<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pricing_engine — the single, server-side authoritative implementation
 * of the company's distance-from-garage rate model (v19).
 *
 * Three zones, decided purely by real driving distance from the garage
 * (no state check anymore — see classify_zone()):
 *
 *   - "local" (inside the local service radius, both pickup AND
 *     dropoff): the vehicle's own per-mile/hourly rate for the actual
 *     reservation, PLUS two per-vehicle garage deadhead fees — one for
 *     the distance the vehicle has to drive from the garage to reach
 *     the pickup, one for the distance back to the garage after the
 *     dropoff (kfb_vehicles.garage_pickup_fee_per_mile /
 *     garage_dropoff_fee_per_mile — "Travel Fee Till Pickup" / "Back To
 *     Garage Fee" in the admin). Different vehicles have different fuel
 *     costs, so these are per-vehicle, not a global setting. A customer
 *     50 miles from the garage requesting a 2-mile ride now actually
 *     pays for the vehicle's real ~102-mile garage-to-garage trip, not
 *     just the 2 reservation miles.
 *   - "long_distance" (outside the radius, pickup AND dropoff both in
 *     the US): the vehicle's per-mile/hourly rate x an editable
 *     multiplier, applied to the reservation distance/hours only — no
 *     garage fees out here.
 *   - "worldwide" (outside the radius, not entirely in the US): same
 *     shape as long_distance with its own multiplier; trips beyond a
 *     configurable distance auto-flag requiresQuote instead of pricing.
 *
 * "regional" as a distinct state-based zone no longer exists (removed
 * v19) — the old pricing_regional_travel_fee_per_mile global setting
 * that used to bill the beyond-radius distance is gone with it, replaced
 * by the two per-vehicle garage fees for the local zone, and by nothing
 * (just the multiplier) outside it.
 *
 * All other knobs (radius, multipliers, hourly minimums, gratuity,
 * garage coordinates, worldwide quote threshold) live in kfb_settings
 * via Settings_model::pricing_settings().
 *
 * Called identically from Api.php (customer-facing quote + the
 * authoritative recompute at reservation-create time), so there is
 * exactly one implementation of the formulas — the frontend never
 * reimplements this math, it just calls POST /api/pricing/quote.
 *
 * The customer-facing UI never shows this breakdown — the two garage
 * fees are folded directly into the single `transportation` figure
 * (shown as "Base Rate"), same as they'd feed into "applicable
 * transportation charges" for gratuity/surcharges either way. Only the
 * embed widget's internal debug alert (testing only) itemizes them
 * separately — see garage_pickup_fee_amount/garage_dropoff_fee_amount
 * below.
 */
class Pricing_engine
{
    /** The garage's fixed country — not admin-editable (it's a physical address, not a rate). */
    const GARAGE_COUNTRY = 'US';

    /** Earth radius in miles, for the Haversine distance formula. */
    const EARTH_RADIUS_MILES = 3958.8;

    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model('Vehicle_model');
        $this->CI->load->model('Settings_model');
        $this->CI->load->model('Surcharge_model');
    }

    // ---------------------- Public entry points ----------------------

    /**
     * Price a single vehicle. $input keys:
     *   vehicle_id (required — a kfb_vehicles.code, numeric id, or 'v'+id)
     *   pickup_lat, pickup_lng, dropoff_lat, dropoff_lng (float)
     *   pickup_country, dropoff_country (string, e.g. 'US')
     *   pickup_distance_miles, dropoff_distance_miles (float — real
     *     driving distance garage->pickup/dropoff, computed client-side;
     *     falls back to straight-line if missing — see classify_zone())
     *   route_miles (float — Google road distance pickup->dropoff)
     *   service_type ('point_to_point' | 'hourly')
     *   hours (float, required if hourly)
     *   is_return_trip (bool)
     *   pickup_is_airport, dropoff_is_airport (bool)
     *   pickup_type_detail, dropoff_type_detail (string, e.g. 'Meet & Greet')
     *   pickup_time (string 'HH:MM' — drives the late-night/early-morning
     *     pickup surcharge auto-trigger)
     *   child_seats (int — count of child seats requested; priced at
     *     pricing_child_seat_fee per seat, same rate regardless of type)
     *   selected_surcharge_codes (array of manually-selected surcharge codes)
     *
     * Returns ['success'=>false,'error'=>'vehicle_not_found'] or the full
     * quote breakdown (see _compute()).
     */
    public function quote(array $input)
    {
        $vehicle = $this->_find_vehicle($input['vehicle_id'] ?? NULL);
        if (!$vehicle) {
            return ['success' => FALSE, 'error' => 'vehicle_not_found'];
        }
        $settings = $this->CI->Settings_model->pricing_settings();
        $zoneInfo = $this->classify_zone(
            (float)($input['pickup_lat'] ?? 0), (float)($input['pickup_lng'] ?? 0),
            (float)($input['dropoff_lat'] ?? 0), (float)($input['dropoff_lng'] ?? 0),
            (string)($input['pickup_country'] ?? ''), (string)($input['dropoff_country'] ?? ''),
            $settings,
            $input['pickup_distance_miles'] ?? NULL, $input['dropoff_distance_miles'] ?? NULL
        );
        return $this->_compute($vehicle, $settings, $zoneInfo, $input);
    }

    /**
     * Price every enabled vehicle for the same trip — used by the
     * customer-facing vehicle-selection step so every card shows a real
     * price without one round trip per vehicle. Returns
     * [vehicle_id => quote_result, ...].
     */
    public function quote_all(array $input)
    {
        $settings = $this->CI->Settings_model->pricing_settings();
        $zoneInfo = $this->classify_zone(
            (float)($input['pickup_lat'] ?? 0), (float)($input['pickup_lng'] ?? 0),
            (float)($input['dropoff_lat'] ?? 0), (float)($input['dropoff_lng'] ?? 0),
            (string)($input['pickup_country'] ?? ''), (string)($input['dropoff_country'] ?? ''),
            $settings,
            $input['pickup_distance_miles'] ?? NULL, $input['dropoff_distance_miles'] ?? NULL
        );
        $out = [];
        foreach ($this->CI->Vehicle_model->list_all(TRUE) as $vehicle) {
            $vid = $vehicle['code'] ?: ('v' . $vehicle['id']);
            $out[$vid] = $this->_compute($vehicle, $settings, $zoneInfo, $input);
        }
        return $out;
    }

    // ---------------------- Distance / zone ----------------------

    /** Great-circle distance between two lat/lng points, in miles. */
    public function haversine_miles($lat1, $lng1, $lat2, $lng2)
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return self::EARTH_RADIUS_MILES * $c;
    }

    /**
     * Real driving distance (v19) from the garage to one point (pickup
     * or dropoff), in miles. $submittedMiles is what the browser
     * computed via Google's DistanceMatrixService (test-map.js) and sent
     * up with the quote/reservation request — trusted at face value,
     * the same way route_miles (pickup<->dropoff) already is. Falls
     * back to straight-line (haversine_miles()) only when nothing
     * usable was submitted (missing, non-numeric, zero, or negative —
     * e.g. an older client that predates this field, or the browser-side
     * Google call failed), so a client-side hiccup still gets a price
     * instead of an error.
     */
    protected function _garage_distance_miles($submittedMiles, $pointLat, $pointLng, $garageLat, $garageLng)
    {
        $miles = is_numeric($submittedMiles) ? (float)$submittedMiles : 0.0;
        if ($miles > 0) return $miles;
        return $this->haversine_miles($pointLat, $pointLng, $garageLat, $garageLng);
    }

    /**
     * Classify a trip into local | long_distance | worldwide. No state
     * check (v19) — purely: inside the radius, or outside it and which
     * country. Garage->pickup and garage->dropoff distance is real
     * driving distance submitted by the browser — see
     * _garage_distance_miles() — with a straight-line fallback.
     */
    public function classify_zone(
        $pickupLat, $pickupLng, $dropoffLat, $dropoffLng,
        $pickupCountry, $dropoffCountry,
        array $settings = NULL,
        $pickupRoadMiles = NULL, $dropoffRoadMiles = NULL
    ) {
        $settings = $settings ?: $this->CI->Settings_model->pricing_settings();
        $garageLat = $settings['pricing_garage_lat'];
        $garageLng = $settings['pricing_garage_lng'];
        $radius    = $settings['pricing_local_service_radius_miles'];

        $pickupDist  = $this->_garage_distance_miles($pickupRoadMiles, $pickupLat, $pickupLng, $garageLat, $garageLng);
        $dropoffDist = $this->_garage_distance_miles($dropoffRoadMiles, $dropoffLat, $dropoffLng, $garageLat, $garageLng);
        $maxDist     = max($pickupDist, $dropoffDist);

        if ($maxDist <= $radius) {
            $zone = 'local';
        } elseif (strtoupper($pickupCountry) === self::GARAGE_COUNTRY && strtoupper($dropoffCountry) === self::GARAGE_COUNTRY) {
            $zone = 'long_distance';
        } else {
            $zone = 'worldwide';
        }

        return [
            'zone'                   => $zone,
            'pickup_distance_miles'  => $pickupDist,
            'dropoff_distance_miles' => $dropoffDist,
            'miles_outside_radius'   => max(0, $maxDist - $radius),
        ];
    }

    // ---------------------- Formula helpers (one per spec rule) ----------------------

    /**
     * Local zone, point-to-point: garage-pickup fee + reservation charge
     * + garage-dropoff fee, ALL THREE summed first, THEN floored at the
     * vehicle's minimum fare as one combined amount — a very short ride
     * can still be priced below the minimum even with real garage fees
     * added, so the floor has to see the whole picture, not just the
     * reservation-mile piece.
     */
    protected function _local_point_to_point($routeMiles, $perMileRate, $minFare, $pickupFeeAmount, $dropoffFeeAmount)
    {
        $combined = $pickupFeeAmount + ($routeMiles * $perMileRate) + $dropoffFeeAmount;
        return max($combined, $minFare);
    }

    /**
     * Local zone, hourly: garage fees added unconditionally on top of
     * the hours charge — there's no dollar-minimum concept for hourly
     * service (only the hours floor below), so nothing to swallow the
     * fees into.
     */
    protected function _local_hourly($hours, $hourlyRate, $minHours, $pickupFeeAmount, $dropoffFeeAmount)
    {
        return $pickupFeeAmount + (max($hours, $minHours) * $hourlyRate) + $dropoffFeeAmount;
    }

    /** Outside-radius point-to-point (long_distance or worldwide) — rate x multiplier, no garage fees. */
    protected function _point_to_point_with_multiplier($routeMiles, $perMileRate, $multiplier, $minFare)
    {
        return max($routeMiles * ($perMileRate * $multiplier), $minFare);
    }

    /** Outside-radius hourly (long_distance or worldwide) — rate x multiplier, no garage fees. */
    protected function _hourly_with_multiplier($hours, $hourlyRate, $multiplier, $minHours)
    {
        return max($hours, $minHours) * ($hourlyRate * $multiplier);
    }

    /**
     * True for a pickup time of 11:00 PM through 5:00 AM inclusive, per
     * the customer's spec ("the fee should apply to any pickup time
     * within the specified window, including 11:00 PM through 5:00 AM").
     * $pickupTime is 'HH:MM' (24h), the same format the pickup-time
     * <input type="time"> already submits.
     */
    protected function _is_late_night_pickup($pickupTime)
    {
        if (!$pickupTime || !preg_match('/^(\d{1,2}):(\d{2})/', (string)$pickupTime, $m)) {
            return FALSE;
        }
        $minutesSinceMidnight = ((int)$m[1]) * 60 + (int)$m[2];
        return $minutesSinceMidnight >= (23 * 60) || $minutesSinceMidnight <= (5 * 60);
    }

    /**
     * Combines the trip's pickup date ('YYYY-MM-DD') and time ('HH:MM') into
     * a single Unix timestamp for comparing against a surcharge's active
     * window. Returns NULL if no usable date was given (e.g. an old caller
     * that never learned about pickup_date) — callers must treat NULL as
     * "unknown", not as "matches everything".
     */
    protected function _parse_pickup_datetime($date, $time)
    {
        $date = trim((string)$date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) return NULL;
        $time = trim((string)$time);
        if (!preg_match('/^\d{1,2}:\d{2}/', $time)) $time = '00:00';
        $ts = strtotime($date . ' ' . $time);
        return $ts !== FALSE ? $ts : NULL;
    }

    /**
     * A surcharge with both starts_at and ends_at NULL (the only state
     * possible before v18, and the default for a new one) is unrestricted —
     * applies regardless of date, identical to pre-v18 behavior. Once
     * either side is set, a trip with no resolvable pickup date/time is
     * kept OUT rather than guessed in.
     */
    protected function _within_surcharge_window(array $row, $pickupTimestamp)
    {
        $starts = $row['starts_at'] ?? NULL;
        $ends   = $row['ends_at'] ?? NULL;
        if (!$starts && !$ends) return TRUE;
        if (!$pickupTimestamp) return FALSE;
        if ($starts && $pickupTimestamp < strtotime($starts)) return FALSE;
        if ($ends && $pickupTimestamp > strtotime($ends)) return FALSE;
        return TRUE;
    }

    /**
     * Evaluate every enabled surcharge against this trip's context.
     * Percent-type surcharges and gratuity both compute off the same
     * base ($base = transportation + travel fee — "applicable
     * transportation charges" per the spec; travel fee is always 0 now,
     * see _compute(), so in practice this is just transportation, which
     * already has the local zone's garage fees folded into it).
     * Returns [line_items[], total].
     */
    protected function _apply_surcharges($base, array $context)
    {
        $selected = array_map('strtoupper', array_map('strval', (array)($context['selected_surcharge_codes'] ?? [])));
        $pickupAirport    = !empty($context['pickup_is_airport']);
        $dropoffAirport   = !empty($context['dropoff_is_airport']);
        $pickupMeetGreet  = $pickupAirport  && (($context['pickup_type_detail']  ?? '') === 'Meet & Greet');
        $dropoffMeetGreet = $dropoffAirport && (($context['dropoff_type_detail'] ?? '') === 'Meet & Greet');
        $lateNightPickup  = $this->_is_late_night_pickup($context['pickup_time'] ?? NULL);
        $pickupDateTime   = $this->_parse_pickup_datetime($context['pickup_date'] ?? NULL, $context['pickup_time'] ?? NULL);

        $items = [];
        $total = 0.0;
        foreach ($this->CI->Surcharge_model->list_all(TRUE) as $row) {
            $trigger = $row['auto_trigger'];
            if ($trigger === 'always') {
                $applies = TRUE;
            } elseif ($trigger === 'airport') {
                $applies = $pickupAirport || $dropoffAirport;
            } elseif ($trigger === 'airport_meet_greet') {
                $applies = $pickupMeetGreet || $dropoffMeetGreet;
            } elseif ($trigger === 'late_night_pickup') {
                $applies = $lateNightPickup;
            } elseif ($trigger) {
                // A recognized-looking but unhandled trigger key somehow
                // stored on a row (e.g. Surcharge_model::KNOWN_TRIGGERS grew
                // a value this method hasn't been taught yet) — never silently
                // auto-apply an unevaluated condition.
                $applies = FALSE;
            } else {
                $applies = in_array(strtoupper($row['code']), $selected, TRUE);
            }
            if (!$applies) continue;
            // v18: an optional active window (starts_at/ends_at) — e.g. a
            // Christmas surcharge created ahead of time that should only
            // land on trips actually picking up during that window. NULL on
            // both sides (the default, and the only state before v18) means
            // "no restriction", so every existing surcharge keeps applying
            // exactly as it did before this check existed.
            if (!$this->_within_surcharge_window($row, $pickupDateTime)) continue;

            $amount = $this->CI->Surcharge_model->resolve_amount($row, $base);
            $items[] = [
                'code'         => $row['code'],
                'name'         => $row['name'],
                // Formatted STRING, not a float — see the $fmt() comment in
                // _compute() for why (serialize_precision=100 on this server).
                'amount'       => number_format($amount, 2, '.', ''),
                'pricing_type' => $row['pricing_type'],
            ];
            $total += $amount;
        }
        return [$items, round($total, 2)];
    }

    protected function _apply_gratuity($base, $pct)
    {
        return round($base * ($pct / 100), 2);
    }

    // ---------------------- Internals ----------------------

    protected function _find_vehicle($vehicleId)
    {
        if ($vehicleId === NULL || $vehicleId === '') return NULL;
        $row = $this->CI->db->get_where('kfb_vehicles', ['code' => $vehicleId, 'status' => 1])->row_array();
        if ($row) return $row;

        $numericId = NULL;
        if (is_numeric($vehicleId)) {
            $numericId = (int)$vehicleId;
        } elseif (preg_match('/^v(\d+)$/', (string)$vehicleId, $m)) {
            $numericId = (int)$m[1];
        }
        if ($numericId) {
            return $this->CI->db->get_where('kfb_vehicles', ['id' => $numericId, 'status' => 1])->row_array();
        }
        return NULL;
    }

    /** Shared computation used by both quote() and quote_all() once vehicle/settings/zone are resolved. */
    protected function _compute(array $vehicle, array $settings, array $zoneInfo, array $input)
    {
        $zone         = $zoneInfo['zone'];
        $serviceType  = (($input['service_type'] ?? 'point_to_point') === 'hourly') ? 'hourly' : 'point_to_point';
        $isReturnTrip = !empty($input['is_return_trip']);
        $routeMiles   = (float)($input['route_miles'] ?? 0);
        $hours        = (float)($input['hours'] ?? 0);
        $pickupMiles  = $zoneInfo['pickup_distance_miles'];
        $dropoffMiles = $zoneInfo['dropoff_distance_miles'];

        $perMileRate = (float)$vehicle['local_per_mile_rate'];
        $hourlyRate  = (float)$vehicle['local_hourly_rate'];
        $minFare     = (float)$vehicle['local_min_fare'];
        $vehicleHourlyMin = isset($vehicle['local_hourly_min_hours']) && $vehicle['local_hourly_min_hours'] !== NULL
            ? (float)$vehicle['local_hourly_min_hours'] : NULL;
        $pickupFeeRate  = (float)($vehicle['garage_pickup_fee_per_mile']  ?? 0);
        $dropoffFeeRate = (float)($vehicle['garage_dropoff_fee_per_mile'] ?? 0);

        $rateUsed  = $serviceType === 'hourly' ? $hourlyRate : $perMileRate;
        $requiresQuote = FALSE;
        $requiresQuoteReason = NULL;
        $minFareApplied = 0.00; // point-to-point only — see below

        // Garage deadhead fees (v19) — local zone only, same per-mile
        // rates used by both service types.
        $garagePickupFeeAmount  = 0.0;
        $garageDropoffFeeAmount = 0.0;
        if ($zone === 'local') {
            $garagePickupFeeAmount  = $pickupMiles  * $pickupFeeRate;
            $garageDropoffFeeAmount = $dropoffMiles * $dropoffFeeRate;
        }

        if ($serviceType === 'hourly') {
            if ($zone === 'local') {
                $minHours = $vehicleHourlyMin ?? $settings['pricing_local_hourly_minimum_hours'];
                $transportation = $this->_local_hourly($hours, $hourlyRate, $minHours, $garagePickupFeeAmount, $garageDropoffFeeAmount);
            } elseif ($zone === 'worldwide') {
                $minHours = $settings['pricing_worldwide_hourly_minimum_hours'];
                $rateUsed = $hourlyRate * $settings['pricing_worldwide_multiplier'];
                $transportation = $this->_hourly_with_multiplier($hours, $hourlyRate, $settings['pricing_worldwide_multiplier'], $minHours);
            } else { // long_distance — outside radius, inside USA
                $minHours = $settings['pricing_regional_hourly_minimum_hours'];
                $rateUsed = $hourlyRate * $settings['pricing_long_distance_multiplier'];
                $transportation = $this->_hourly_with_multiplier($hours, $hourlyRate, $settings['pricing_long_distance_multiplier'], $minHours);
            }
        } else {
            if ($zone === 'local') {
                $transportation = $this->_local_point_to_point($routeMiles, $perMileRate, $minFare, $garagePickupFeeAmount, $garageDropoffFeeAmount);
                // The minimum floors the WHOLE combined total (fees included),
                // not just the reservation-mile piece.
                $combinedPreFloor = $garagePickupFeeAmount + ($routeMiles * $perMileRate) + $garageDropoffFeeAmount;
                if ($combinedPreFloor < $minFare) $minFareApplied = round($minFare, 2);
            } elseif ($zone === 'long_distance') {
                $rateUsed = $perMileRate * $settings['pricing_long_distance_multiplier'];
                $transportation = $this->_point_to_point_with_multiplier($routeMiles, $perMileRate, $settings['pricing_long_distance_multiplier'], $minFare);
                if (($routeMiles * $rateUsed) < $minFare) $minFareApplied = round($minFare, 2);
            } else { // worldwide
                $rateUsed = $perMileRate * $settings['pricing_worldwide_multiplier'];
                $transportation = $this->_point_to_point_with_multiplier($routeMiles, $perMileRate, $settings['pricing_worldwide_multiplier'], $minFare);
                if ($routeMiles > $settings['pricing_worldwide_quote_threshold_miles']) {
                    $requiresQuote = TRUE;
                    $requiresQuoteReason = 'This trip exceeds our automatic worldwide pricing distance — please request a custom quote.';
                }
                if (($routeMiles * $rateUsed) < $minFare) $minFareApplied = round($minFare, 2);
            }
        }

        $transportation = round($transportation, 2);
        $garagePickupFeeAmount  = round($garagePickupFeeAmount, 2);
        $garageDropoffFeeAmount = round($garageDropoffFeeAmount, 2);
        // Retired (v19) — the global per-mile travel fee this used to hold
        // is gone, replaced by the two garage fees above (already folded
        // into $transportation for the local zone). Kept as an always-zero
        // field so nothing reading `travel_fee`/`travelFee` has to change.
        $travelFee = 0.00;

        [$surcharges, $surchargesTotal] = $this->_apply_surcharges($transportation + $travelFee, $input);

        $gratuityPct    = $settings['pricing_default_gratuity_pct'];
        $gratuityAmount = $this->_apply_gratuity($transportation + $travelFee, $gratuityPct);

        // Child seats (v17) — one flat rate regardless of seat type, times
        // however many the customer requested. Not part of the gratuity or
        // percent-surcharge base (same treatment as add-ons — not
        // "transportation charges" per the spec's gratuity definition).
        $childSeatCount = max(0, (int)($input['child_seats'] ?? 0));
        $childSeatFee   = $settings['pricing_child_seat_fee'];
        $childSeatsTotal = round($childSeatFee * $childSeatCount, 2);

        $taxesFees = 0.00; // No tax concept exists anywhere in this system yet — explicit placeholder line.

        $oneWayTotal = round($transportation + $travelFee + $surchargesTotal + $gratuityAmount + $childSeatsTotal + $taxesFees, 2);
        $total       = $isReturnTrip ? round($oneWayTotal * 2, 2) : $oneWayTotal;

        // Formatted STRINGs, not floats — this server's php.ini has
        // serialize_precision=100 (non-default; should be -1), so
        // json_encode() expands any float that isn't exactly representable
        // in binary out to ~100 digits regardless of round(). number_format()
        // sidesteps the float serializer entirely — same fix already used by
        // Api::reservation_update() and Promo_model::validate(). PHP's loose
        // typing coerces these back to numbers wherever this array's values
        // are used in further arithmetic (e.g. Api::_apply_quote_to_payload()),
        // so returning strings here doesn't break any internal caller.
        $fmt = function ($n) { return number_format((float)$n, 2, '.', ''); };

        return [
            'success'                => TRUE,
            'vehicle_id'             => $vehicle['code'] ?: ('v' . $vehicle['id']),
            'zone'                   => $zone,
            'requiresQuote'          => $requiresQuote,
            'requiresQuoteReason'    => $requiresQuoteReason,
            'pickup_distance_miles'  => $fmt($zoneInfo['pickup_distance_miles']),
            'dropoff_distance_miles' => $fmt($zoneInfo['dropoff_distance_miles']),
            'miles_outside_radius'   => $fmt($zoneInfo['miles_outside_radius']),
            // Radius setting itself, live — so any UI (e.g. the embed
            // widget's testing debug alert) never has to hardcode "75
            // miles" and go stale when this setting changes.
            'local_service_radius_miles' => $fmt($settings['pricing_local_service_radius_miles']),
            'route_miles'            => $fmt($routeMiles),
            'rate_per_mile_used'     => $fmt($rateUsed),
            'minimum_fare_used'      => $fmt($minFare),
            'transportation'         => $fmt($transportation),
            'min_fare_applied'       => $fmt($minFareApplied),
            'travel_fee'             => $fmt($travelFee),
            // Itemized (v19) for internal/debug use only — never shown to
            // the customer, who only ever sees the combined `transportation`
            // figure as "Base Rate". Always 0 outside the local zone.
            'garage_pickup_fee_amount'  => $fmt($garagePickupFeeAmount),
            'garage_dropoff_fee_amount' => $fmt($garageDropoffFeeAmount),
            'surcharges'             => $surcharges,
            'surcharges_total'       => $fmt($surchargesTotal),
            'gratuity_pct'           => $fmt($gratuityPct),
            'gratuity_amount'        => $fmt($gratuityAmount),
            'child_seats_count'      => $childSeatCount,
            'child_seat_fee_used'    => $fmt($childSeatFee),
            'child_seats_total'      => $fmt($childSeatsTotal),
            'taxes_fees'             => $fmt($taxesFees),
            'subtotal'               => $fmt($oneWayTotal),
            'is_return_trip'         => $isReturnTrip,
            'one_way_total'          => $fmt($oneWayTotal),
            'total'                  => $fmt($total),
        ];
    }
}
