<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pricing_engine — the single, server-side authoritative implementation
 * of the company's distance-from-garage rate model (v16).
 *
 * Replaces the old Chicago/America/Worldwide per-vehicle rate matrix
 * (region classified by Google Places, math done only in the browser)
 * with:
 *
 *   - a 75-mile "local" radius measured by straight-line (Haversine)
 *     distance from the company garage to the pickup AND the dropoff
 *   - "regional" (beyond 75mi, same state as the garage — Illinois):
 *     local rate + a per-mile travel fee for the distance beyond 75mi
 *   - "long_distance" (beyond 75mi, different US state): local rate x
 *     an editable multiplier, no separate travel fee
 *   - "worldwide" (outside the US): local rate x an editable
 *     multiplier; trips beyond a configurable distance threshold are
 *     flagged requiresQuote instead of auto-priced
 *
 * All knobs (radius, travel fee, multipliers, hourly minimums,
 * gratuity, garage coordinates, worldwide quote threshold) live in
 * kfb_settings via Settings_model::pricing_settings() — nothing here is
 * hard-coded except the garage's own state/country, which are fixed
 * properties of its physical address, not admin-tunable rates.
 *
 * Called identically from Api.php (customer-facing quote + the
 * authoritative recompute at reservation-create time) and Admin_api.php
 * (an admin-side price preview), so there is exactly one implementation
 * of the formulas — the frontend never reimplements this math, it just
 * calls POST /api/pricing/quote.
 *
 * NOTE ON THE TRAVEL FEE: spec item 5 ("Trips Beyond 75 Miles") is
 * unconditional — it applies whenever pickup or dropoff is beyond the
 * local radius, with no exception for the `long_distance` zone. So the
 * $/mi travel fee STACKS with `long_distance`'s 3x rate multiplier
 * (item 8) rather than being replaced by it — item 8 only changes the
 * transportation rate, not whether the travel fee applies. Only
 * `worldwide` (item 9) skips the travel fee entirely, per that item's
 * own wording (rate multiplier only, no travel fee mentioned).
 *
 * NOTE ON HOURLY ZONES: the customer's spec defines three hourly
 * buckets — Local, "Beyond 75 Miles" (5hr minimum + travel fee, at the
 * plain local hourly rate — no distance multiplier), and Worldwide (5hr
 * minimum, local hourly rate x the worldwide multiplier). It does not
 * define a distinct long-distance hourly formula, so both the
 * `regional` and `long_distance` zones use the same "beyond 75 miles"
 * hourly bucket here (and, like point-to-point, both get the travel fee).
 */
class Pricing_engine
{
    /** The garage's fixed state/country — not admin-editable (it's a physical address, not a rate). */
    const GARAGE_STATE   = 'IL';
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
     *   pickup_state, pickup_country, dropoff_state, dropoff_country (string, e.g. 'IL', 'US')
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
            (string)($input['pickup_state'] ?? ''), (string)($input['pickup_country'] ?? ''),
            (string)($input['dropoff_state'] ?? ''), (string)($input['dropoff_country'] ?? ''),
            $settings
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
            (string)($input['pickup_state'] ?? ''), (string)($input['pickup_country'] ?? ''),
            (string)($input['dropoff_state'] ?? ''), (string)($input['dropoff_country'] ?? ''),
            $settings
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
     * Classify a trip into local | regional | long_distance | worldwide,
     * per the garage-distance + state/country rules described in the
     * class docblock.
     */
    public function classify_zone(
        $pickupLat, $pickupLng, $dropoffLat, $dropoffLng,
        $pickupState, $pickupCountry, $dropoffState, $dropoffCountry,
        array $settings = NULL
    ) {
        $settings = $settings ?: $this->CI->Settings_model->pricing_settings();
        $garageLat = $settings['pricing_garage_lat'];
        $garageLng = $settings['pricing_garage_lng'];
        $radius    = $settings['pricing_local_service_radius_miles'];

        $pickupDist  = $this->haversine_miles($pickupLat, $pickupLng, $garageLat, $garageLng);
        $dropoffDist = $this->haversine_miles($dropoffLat, $dropoffLng, $garageLat, $garageLng);
        $maxDist     = max($pickupDist, $dropoffDist);

        if ($maxDist <= $radius) {
            $zone = 'local';
        } elseif (strtoupper($pickupState) === self::GARAGE_STATE && strtoupper($dropoffState) === self::GARAGE_STATE) {
            $zone = 'regional';
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

    protected function _local_point_to_point($routeMiles, $perMileRate, $minFare)
    {
        return max($routeMiles * $perMileRate, $minFare);
    }

    protected function _local_hourly($hours, $hourlyRate, $minHours)
    {
        return max($hours, $minHours) * $hourlyRate;
    }

    /** Same base formula as local point-to-point — the travel fee is added separately by the caller. */
    protected function _regional_point_to_point($routeMiles, $perMileRate, $minFare)
    {
        return $this->_local_point_to_point($routeMiles, $perMileRate, $minFare);
    }

    /** Same base formula as local hourly — the travel fee is added separately by the caller. */
    protected function _regional_hourly($hours, $hourlyRate, $minHours)
    {
        return $this->_local_hourly($hours, $hourlyRate, $minHours);
    }

    protected function _long_distance($routeMiles, $perMileRate, $multiplier, $minFare)
    {
        return max($routeMiles * ($perMileRate * $multiplier), $minFare);
    }

    protected function _worldwide_point_to_point($routeMiles, $perMileRate, $multiplier, $minFare)
    {
        return max($routeMiles * ($perMileRate * $multiplier), $minFare);
    }

    protected function _worldwide_hourly($hours, $hourlyRate, $multiplier, $minHours)
    {
        return max($hours, $minHours) * ($hourlyRate * $multiplier);
    }

    protected function _travel_fee($milesOutsideRadius, $feePerMile)
    {
        return $milesOutsideRadius * $feePerMile;
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
     * Evaluate every enabled surcharge against this trip's context.
     * Percent-type surcharges and gratuity both compute off the same
     * base ($base = transportation + travel fee — "applicable
     * transportation charges" per the spec).
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

        $perMileRate = (float)$vehicle['local_per_mile_rate'];
        $hourlyRate  = (float)$vehicle['local_hourly_rate'];
        $minFare     = (float)$vehicle['local_min_fare'];
        $vehicleHourlyMin = isset($vehicle['local_hourly_min_hours']) && $vehicle['local_hourly_min_hours'] !== NULL
            ? (float)$vehicle['local_hourly_min_hours'] : NULL;

        $travelFee = 0.0;
        $rateUsed  = $perMileRate;
        $requiresQuote = FALSE;
        $requiresQuoteReason = NULL;
        $minFareApplied = 0.00; // point-to-point only — see below

        if ($serviceType === 'hourly') {
            $rateUsed = $hourlyRate;
            if ($zone === 'local') {
                $minHours = $vehicleHourlyMin ?? $settings['pricing_local_hourly_minimum_hours'];
                $transportation = $this->_local_hourly($hours, $hourlyRate, $minHours);
            } elseif ($zone === 'worldwide') {
                $minHours = $settings['pricing_worldwide_hourly_minimum_hours'];
                $rateUsed = $hourlyRate * $settings['pricing_worldwide_multiplier'];
                $transportation = $this->_worldwide_hourly($hours, $hourlyRate, $settings['pricing_worldwide_multiplier'], $minHours);
            } else { // regional or long_distance — see class docblock
                $minHours = $settings['pricing_regional_hourly_minimum_hours'];
                $transportation = $this->_regional_hourly($hours, $hourlyRate, $minHours);
                $travelFee = $this->_travel_fee($zoneInfo['miles_outside_radius'], $settings['pricing_regional_travel_fee_per_mile']);
            }
        } else {
            if ($zone === 'local') {
                $transportation = $this->_local_point_to_point($routeMiles, $perMileRate, $minFare);
            } elseif ($zone === 'regional') {
                $transportation = $this->_regional_point_to_point($routeMiles, $perMileRate, $minFare);
                $travelFee = $this->_travel_fee($zoneInfo['miles_outside_radius'], $settings['pricing_regional_travel_fee_per_mile']);
            } elseif ($zone === 'long_distance') {
                // Spec item 5 ("Trips Beyond 75 Miles") is unconditional — the
                // $/mi travel fee applies whenever pickup or dropoff is beyond
                // the local radius, with no zone carve-out. long_distance's 3x
                // multiplier (item 8) governs the transportation rate only;
                // it doesn't replace the travel fee, it stacks with it — same
                // as the hourly branch above already does for this zone.
                $rateUsed = $perMileRate * $settings['pricing_long_distance_multiplier'];
                $transportation = $this->_long_distance($routeMiles, $perMileRate, $settings['pricing_long_distance_multiplier'], $minFare);
                $travelFee = $this->_travel_fee($zoneInfo['miles_outside_radius'], $settings['pricing_regional_travel_fee_per_mile']);
            } else { // worldwide
                $rateUsed = $perMileRate * $settings['pricing_worldwide_multiplier'];
                $transportation = $this->_worldwide_point_to_point($routeMiles, $perMileRate, $settings['pricing_worldwide_multiplier'], $minFare);
                if ($routeMiles > $settings['pricing_worldwide_quote_threshold_miles']) {
                    $requiresQuote = TRUE;
                    $requiresQuoteReason = 'This trip exceeds our automatic worldwide pricing distance — please request a custom quote.';
                }
            }
            // Whether the per-mile calculation was floored up to the vehicle's
            // minimum — maps straight to the existing kfb_bookings.min_fare_applied
            // column. Not meaningful for hourly service (a different, hours-based
            // floor applies there, tracked separately as minHours above).
            if (($routeMiles * $rateUsed) < $minFare) {
                $minFareApplied = round($minFare, 2);
            }
        }

        $transportation = round($transportation, 2);
        $travelFee      = round($travelFee, 2);

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
            'route_miles'            => $fmt($routeMiles),
            'rate_per_mile_used'     => $fmt($rateUsed),
            'minimum_fare_used'      => $fmt($minFare),
            'transportation'         => $fmt($transportation),
            'min_fare_applied'       => $fmt($minFareApplied),
            'travel_fee'             => $fmt($travelFee),
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
