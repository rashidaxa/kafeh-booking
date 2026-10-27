<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Builds the view vars for emails/booking_confirmation — shared by the
 * confirmation email (Admin_api::_send_confirmation) and the customer
 * receipt (Api::customer_receipt), which are the same layout in two modes
 * ('confirmation' / 'receipt'; see that view). Keeping one builder means the
 * email, the receipt and the dispatch portal entry always show the same
 * figures and times.
 */
#[\AllowDynamicProperties]
class Booking_presenter
{
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->library('legacy_reservations');
        $this->CI->load->model('Company_model');
    }

    /**
     * View vars for emails/booking_confirmation — one entry in `legs` per
     * reservation (outbound + return for a round trip). Pricing comes from
     * Legacy_reservations::leg_pricing() — the same lines the dispatch portal
     * receives — so the customer's email and the portal never disagree.
     */
    public function confirmation_vars($booking, $company, $mode = 'confirmation')
    {
        $passengerName = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
        $clientName    = trim(($booking['cc_first_name'] ?? '') . ' ' . ($booking['cc_last_name'] ?? ''));
        if ($clientName === '') $clientName = $passengerName;
        $clientPhone = (string)(!empty($booking['cc_phone']) ? $booking['cc_phone'] : ($booking['phone'] ?? ''));

        $isRound = !empty($booking['is_return_trip']) && !empty($booking['return_leg']);

        // Same price lines the legacy portal receives (one source of truth).
        $pricing = $this->CI->legacy_reservations->leg_pricing($booking);
        $extra = [];
        foreach ($pricing['prices'] as $name => $amount) $extra[] = ['name' => $name, 'amount' => $amount];
        $transport = $pricing['base'];
        $gratuity  = $pricing['gratuity'];
        $discount  = $pricing['discount'];
        $grand     = $pricing['grand'];

        // PayPal bookings show PayPal; with PayPal switched off the card is
        // taken manually, so show Credit Card (masked last 4 when known).
        if (($booking['payment_mode'] ?? 'paypal') === 'none') {
            $payment = 'Credit Card' . (!empty($booking['card_last4']) ? ' XXXX ' . $booking['card_last4'] : '');
        } else {
            $payment = 'PayPal';
        }

        $build = function (array $row, $isReturn) use ($booking, $clientName, $passengerName, $clientPhone, $grand, $transport, $gratuity, $extra, $discount, $payment) {
            $pickupLines = array_map('trim', explode("\n", (string)($row['pickup'] ?? '')));
            if (!$isReturn) {
                foreach (($booking['stops'] ?? []) as $i => $stop) $pickupLines[] = 'Stop ' . ($i + 1) . ': ' . $stop['address'];
            }
            if (!empty($row['airline'])) {
                $pickupLines[] = $row['airline'];
                if (!empty($row['flight_number'])) $pickupLines[] = 'Flight Number ' . $row['flight_number'];
            }
            $dropoffLines = array_map('trim', explode("\n", (string)($row['dropoff'] ?? '')));
            if (!empty($row['dropoff_airline'])) {
                $dropoffLines[] = $row['dropoff_airline'];
                if (!empty($row['dropoff_flight_number'])) $dropoffLines[] = 'Flight Number ' . $row['dropoff_flight_number'];
            }

            $dropoffTime = $this->CI->legacy_reservations->dropoff_time($booking, $row);

            return [
                'reference'       => $row['booking_id'] ?? $booking['booking_id'],
                'status_label'    => $this->_status_label($row['status'] ?? ($booking['status'] ?? ''), $booking['payment_mode'] ?? 'paypal'),
                'client_name'     => $clientName,
                'pickup_date'     => $row['pickup_date'],
                'pickup_time'     => $row['pickup_time'],
                'dropoff_time'    => $dropoffTime,
                'trip_type'       => $this->CI->legacy_reservations->trip_type($booking['service_type'] ?? '', $row),
                'passenger_name'  => $passengerName,
                'client_ref'      => '',
                'client_phone'    => $clientPhone,
                'ordered_by'      => $clientName,
                'passenger_count' => $booking['passengers'] ?? '',
                'vehicle_name'    => $booking['vehicle_name'] ?? '',
                'pickup_lines'    => $pickupLines,
                'dropoff_lines'   => $dropoffLines,
                'notes'           => trim((string)($booking['notes'] ?? '')), // only what the customer typed — dispatch-only detail stays in the legacy entry
                'transport'       => $transport,
                'gratuity'        => $gratuity,
                'extra_charges'   => $extra,
                'payment_method'  => $payment,
                'discount'        => $discount,
                'grand_total'     => $grand,
            ];
        };

        $legs = [$build($booking, FALSE)];
        if ($isRound) $legs[] = $build($booking['return_leg'], TRUE);

        // Company contact details; falls back to A1's published numbers when the company has none set.
        $withLabel = function ($label, $v) { return stripos($v, rtrim($label, ': ')) === FALSE ? $label . $v : $v; };
        $phone1 = trim((string)($company['phone_1'] ?? ''));
        $phone2 = trim((string)($company['phone_2'] ?? ''));
        $csLine = $phone1 !== '' ? $withLabel('Toll: ', $phone1) : 'Toll: +1 (800) 781-4700';
        $footer = array_values(array_filter([
            trim((string)($company['address_line_1'] ?? '')),
            trim((string)($company['address_line_2'] ?? '')),
            $phone1 !== '' ? $withLabel('Toll: ', $phone1) : '',
            $phone2 !== '' ? $withLabel('Local: ', $phone2) : '',
            !empty($company['contact_email']) ? 'Email: ' . $company['contact_email'] : '',
        ], 'strlen'));
        if (!$footer) {
            $footer = ['Worldwide Chauffeured Services', 'Toll: +1 (800) 781-4700', 'Local: +1 (224) 781-4700', 'Email: info@a1limousines.com', 'www.A1Limousines.com'];
        }

        $companyLogo = $company ? $this->CI->Company_model->logo_url($company) : NULL;
        $a1Header    = 'https://grounddispatch.com/a1-limousine-logo.png';
        return [
            'mode'            => $mode,
            'legs'            => $legs,
            'cs_line'         => $csLine,
            'footer_lines'    => $footer,
            'logo_header_url' => (!$companyLogo || (int)($booking['company_id'] ?? 1) === 1) ? $a1Header : $companyLogo,
            'logo_footer_url' => $companyLogo ?: 'https://grounddispatch.com/works/uploads/companies/4364051633297127logo.jpg',
        ];
    }

    /** Customer-facing status wording (same labels the old receipt used). */
    protected function _status_label($status, $paymentMode)
    {
        $labels = [
            'paid' => $paymentMode === 'none' ? 'Confirmed' : 'Paid', 'awaiting_approval' => 'Awaiting approval',
            'awaiting_payment' => 'Awaiting payment', 'pending' => 'Pending', 'cancelled' => 'Cancelled',
            'payment_failed' => 'Payment failed', 'refunded' => 'Refunded',
        ];
        return $labels[$status] ?? ucfirst(str_replace('_', ' ', (string)$status));
    }
}
