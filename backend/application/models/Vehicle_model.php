<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Kafeh Vehicle Model — backend CRUD for the fleet shown in
 * the booking wizard step 2.
 *
 *   kfb_vehicles — one row per vehicle class
 *
 * All numeric columns are DECIMAL(10,2) / TINYINT UNSIGNED and stored
 * in plain numeric form. The model handles:
 *
 *   - list / get / create / update / delete
 *   - field validation (positive numerics, max >= min passengers,
 *     required name, allowed image extensions)
 *   - image upload via CI's upload library (jpg, jpeg, png, webp)
 *   - "front-end shape" → the row reformatted to match what the
 *     embed widget expects (id / name / desc / capacity / luggage /
 *     basePrice / perMile / emoji / image)
 */

class Vehicle_model extends CI_Model
{
    /** Allowed image extensions for uploads. */
    const ALLOWED_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp'];
    /** Max upload size in KB (5 MB). */
    const MAX_IMAGE_KB = 5120;
    /** Subdirectory (relative to FCPATH) where uploaded images live. */
    const UPLOAD_DIR = 'uploads/vehicles';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // ---------------------- CRUD ----------------------

    public function list_all($only_enabled = FALSE)
    {
        $this->db->from('kfb_vehicles');
        if ($only_enabled) $this->db->where('status', 1);
        $this->db->order_by('sort_order', 'ASC');
        $this->db->order_by('id', 'ASC');
        return $this->db->get()->result_array();
    }

    public function get($id)
    {
        if (!$id) return NULL;
        return $this->db
            ->get_where('kfb_vehicles', ['id' => (int)$id])
            ->row_array();
    }

    /** Returns the row id, or FALSE on failure (with $this->db->error()). */
    public function create(array $data, $uploaded_image_name = NULL)
    {
        $row = $this->_build_row($data, $uploaded_image_name);
        if (!$row['__ok']) return FALSE;

        $insert = $row['data'];
        $insert['created_at'] = date('Y-m-d H:i:s');

        $ok = $this->db->insert('kfb_vehicles', $insert);
        if (!$ok) return FALSE;
        return (int)$this->db->insert_id();
    }

    /** Returns TRUE on success, FALSE on failure. */
    public function update($id, array $data, $uploaded_image_name = NULL, $delete_old_image = FALSE)
    {
        if (!$id) return FALSE;
        $existing = $this->get($id);
        if (!$existing) return FALSE;

        $row = $this->_build_row($data, $uploaded_image_name, $existing);
        if (!$row['__ok']) return FALSE;

        $update = $row['data'];
        $update['updated_at'] = date('Y-m-d H:i:s');

        $old_image = $existing['image'] ?? NULL;
        $ok = $this->db
            ->where('id', (int)$id)
            ->update('kfb_vehicles', $update);

        if ($ok && $uploaded_image_name && $delete_old_image && $old_image) {
            $this->_unlink_image($old_image);
        }
        return $ok ? TRUE : FALSE;
    }

    public function delete($id)
    {
        if (!$id) return FALSE;
        $existing = $this->get($id);
        if (!$existing) return FALSE;

        $ok = $this->db
            ->where('id', (int)$id)
            ->delete('kfb_vehicles');

        if ($ok && !empty($existing['image'])) {
            $this->_unlink_image($existing['image']);
        }
        return $ok ? TRUE : FALSE;
    }

    public function toggle_status($id)
    {
        if (!$id) return FALSE;
        $existing = $this->get($id);
        if (!$existing) return FALSE;
        $new = ((int)$existing['status'] === 1) ? 0 : 1;
        return $this->db
            ->where('id', (int)$id)
            ->update('kfb_vehicles', [
                'status'     => $new,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    // ---------------------- Public shape ----------------------

    /**
     * Reformat a DB row to the shape the embed widget expects:
     *   { id, name, desc, capacity, luggage, basePrice, perMile, emoji, image }
     *
     * `basePrice` = the inside-Chicago per-km rate × 10 (fallback approximation
     * to keep the embed widget's "all-inclusive" preview functional even when
     * the embed hasn't been wired to the full rate model yet). `perMile` =
     * inside-Chicago per-km rate × 1.609. These are placeholders — the embed
     * should call the backend for real pricing once pricing APIs land.
     */
    public function to_public($row)
    {
        if (!$row) return NULL;
        $perKm  = (float)($row['per_km_chicago'] ?? 0);
        $hourly = (float)($row['hourly_chicago'] ?? 0);
        return [
            'id'        => $row['code'] ?: ('v' . $row['id']),
            'name'      => $row['name'],
            'desc'      => $row['description'] ?: '',
            'capacity'  => (int)($row['max_passengers'] ?? 0),
            'luggage'   => (int)($row['luggage_capacity'] ?? 0),
            'basePrice' => round($hourly > 0 ? $hourly : ($perKm * 10), 2),
            'perMile'   => round($perKm * 1.609344, 2),
            'emoji'     => $row['emoji'] ?: '🚖',
            'image'     => $row['image'] ?: NULL,
        ];
    }

    public function list_public()
    {
        return array_values(array_filter(array_map(
            function ($r) { return $r ? $this->to_public($r) : NULL; },
            $this->list_all(TRUE)
        )));
    }

    // ---------------------- Validation ----------------------

    /**
     * Validate an incoming payload. Returns an associative array of
     * field => message. Empty array == valid.
     */
    public function validate(array $data, $is_create = TRUE)
    {
        $errors = [];

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') $errors['name'] = 'Name is required.';
        elseif (mb_strlen($name) > 100) $errors['name'] = 'Name must be 100 characters or fewer.';

        $code = trim((string)($data['code'] ?? ''));
        if ($code !== '' && !preg_match('/^[a-z0-9_\-]+$/i', $code)) {
            $errors['code'] = 'Code may contain letters, numbers, dash and underscore only.';
        }

        // Passenger limits — both required, integers, max >= min
        $minP = $data['min_passengers'] ?? NULL;
        $maxP = $data['max_passengers'] ?? NULL;
        if ($minP === '' || $minP === NULL) {
            $errors['min_passengers'] = 'Minimum passengers is required.';
        } elseif (!is_numeric($minP) || (int)$minP < 1 || (int)$minP > 99) {
            $errors['min_passengers'] = 'Minimum passengers must be an integer between 1 and 99.';
        }
        if ($maxP === '' || $maxP === NULL) {
            $errors['max_passengers'] = 'Maximum passengers is required.';
        } elseif (!is_numeric($maxP) || (int)$maxP < 1 || (int)$maxP > 99) {
            $errors['max_passengers'] = 'Maximum passengers must be an integer between 1 and 99.';
        }
        if (empty($errors['min_passengers']) && empty($errors['max_passengers'])) {
            if ((int)$maxP < (int)$minP) {
                $errors['max_passengers'] = 'Maximum passengers must be greater than or equal to minimum passengers.';
            }
        }

        // Numeric rate fields — every region column must be a positive number.
        $rate_fields = [
            'hourly_chicago', 'hourly_america', 'hourly_worldwide',
            'per_km_chicago', 'per_km_america', 'per_km_worldwide',
            'surcharge_chicago', 'surcharge_america', 'surcharge_worldwide',
            'gratuity_chicago', 'gratuity_america', 'gratuity_worldwide',
            'waiting_chicago', 'waiting_america', 'waiting_worldwide',
            'child_seat_chicago', 'child_seat_america', 'child_seat_worldwide',
        ];

        // Min fare (v4) — also must be non-negative. (Meet & Greet fee moved to
        // a global setting in v5 — see Settings_model — so it's no longer a
        // per-vehicle field.)
        $rate_fields[] = 'min_fare';
        foreach ($rate_fields as $f) {
            $v = $data[$f] ?? NULL;
            if ($v === '' || $v === NULL) {
                // Default to 0 — these are not strictly required, but if
                // provided they must be valid numerics.
                continue;
            }
            if (!is_numeric($v)) {
                $errors[$f] = ucfirst(str_replace('_', ' ', $f)) . ' must be a number.';
                continue;
            }
            if ((float)$v < 0) {
                $errors[$f] = ucfirst(str_replace('_', ' ', $f)) . ' must be zero or positive.';
            }
        }

        // Luggage capacity — optional integer
        if (isset($data['luggage_capacity']) && $data['luggage_capacity'] !== '' && $data['luggage_capacity'] !== NULL) {
            if (!is_numeric($data['luggage_capacity']) || (int)$data['luggage_capacity'] < 0) {
                $errors['luggage_capacity'] = 'Luggage capacity must be a non-negative integer.';
            }
        }

        // Emoji — short text
        $emoji = (string)($data['emoji'] ?? '');
        if ($emoji !== '' && mb_strlen($emoji) > 16) {
            $errors['emoji'] = 'Emoji / icon must be 16 characters or fewer.';
        }

        // Description length
        $desc = (string)($data['description'] ?? '');
        if (mb_strlen($desc) > 500) {
            $errors['description'] = 'Description must be 500 characters or fewer.';
        }

        // Status — must be 0 or 1
        if (isset($data['status']) && !in_array((int)$data['status'], [0, 1], TRUE)) {
            $errors['status'] = 'Status must be enabled or disabled.';
        }

        return $errors;
    }

    // ---------------------- Image upload ----------------------

    /**
     * Run an HTTP file upload directly via move_uploaded_file() instead
     * of going through CI's Upload library, which has known quirks on
     * Windows (realpath/slash-mangling that produces confusing
     * "upload path does not appear to be valid" errors even when the
     * directory is correct).
     *
     * Returns the saved filename on success, or NULL on failure (with
     * $error populated).
     */
    public function handle_upload($field = 'image', &$error = NULL)
    {
        $error = NULL;
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) {
            return NULL; // no upload — image stays as-is
        }

        $file = $_FILES[$field];
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $error = $this->_upload_error_message($file['error'] ?? UPLOAD_ERR_NO_FILE);
            return NULL;
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            $error = 'The uploaded file is not a valid HTTP upload.';
            return NULL;
        }

        // Validate extension
        $orig = (string)$file['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_IMAGE_TYPES, TRUE)) {
            $error = 'Only JPG, JPEG, PNG, and WEBP images are allowed.';
            return NULL;
        }

        // Validate size
        $max_bytes = (int)self::MAX_IMAGE_KB * 1024;
        if ((int)$file['size'] > $max_bytes) {
            $error = 'Image is larger than the maximum allowed size of ' . self::MAX_IMAGE_KB . ' KB.';
            return NULL;
        }

        // Validate real image content (defence-in-depth)
        $info = @getimagesize($file['tmp_name']);
        if (!$info || empty($info['mime'])) {
            $error = 'Uploaded file is not a valid image.';
            return NULL;
        }
        $allowed_mimes = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/webp',
        ];
        if (!in_array(strtolower($info['mime']), $allowed_mimes, TRUE)) {
            $error = 'Image MIME type not allowed (' . $info['mime'] . ').';
            return NULL;
        }

        // Make sure the destination directory exists & is writable
        $abs_dir = rtrim(str_replace('\\', '/', FCPATH . self::UPLOAD_DIR), '/') . '/';
        if (!is_dir($abs_dir)) {
            if (!@mkdir($abs_dir, 0755, TRUE) && !is_dir($abs_dir)) {
                $error = 'Upload directory does not exist and could not be created: ' . $abs_dir;
                return NULL;
            }
        }
        if (!is_writable($abs_dir)) {
            $error = 'Upload directory is not writable: ' . $abs_dir;
            return NULL;
        }

        // Generate a unique, encrypted-ish filename
        $safe_name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $abs_path  = $abs_dir . $safe_name;

        if (!@move_uploaded_file($file['tmp_name'], $abs_path)) {
            $error = 'Could not save uploaded file. Check directory permissions on ' . $abs_dir;
            return NULL;
        }

        return $safe_name;
    }

    /** Translate PHP's UPLOAD_ERR_* constants into a human message. */
    protected function _upload_error_message($code)
    {
        switch ((int)$code) {
            case UPLOAD_ERR_INI_SIZE:   return 'File exceeds the upload_max_filesize directive in php.ini.';
            case UPLOAD_ERR_FORM_SIZE:  return 'File exceeds the MAX_FILE_SIZE directive in the form.';
            case UPLOAD_ERR_PARTIAL:    return 'File was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:    return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR: return 'Missing a temporary folder on the server.';
            case UPLOAD_ERR_CANT_WRITE: return 'Failed to write file to disk.';
            case UPLOAD_ERR_EXTENSION:  return 'A PHP extension stopped the upload.';
            default:                    return 'Unknown upload error.';
        }
    }

    /** Best-effort file removal. Swallows errors — DB is the source of truth. */
    protected function _unlink_image($filename)
    {
        if (!$filename) return;
        $abs_dir = rtrim(str_replace('\\', '/', FCPATH . self::UPLOAD_DIR), '/') . '/';
        $abs = $abs_dir . basename($filename);
        if (is_file($abs)) @unlink($abs);
    }

    // ---------------------- Internals ----------------------

    /**
     * Coerce + sanity-check an input payload into the DB column shape.
     * Returns ['__ok' => bool, 'data' => array, 'errors' => array].
     */
    protected function _build_row(array $data, $uploaded_image_name = NULL, $existing = NULL)
    {
        $errors = $this->validate($data);
        if (!empty($errors)) {
            return ['__ok' => FALSE, 'errors' => $errors, 'data' => []];
        }

        $row = [
            'status'           => isset($data['status']) ? ((int)$data['status'] ? 1 : 0) : 1,
            'sort_order'       => isset($data['sort_order']) && $data['sort_order'] !== '' ? (int)$data['sort_order'] : 0,
            'code'             => trim((string)($data['code'] ?? '')) ?: NULL,
            'name'             => trim((string)$data['name']),
            'description'      => trim((string)($data['description'] ?? '')) ?: NULL,
            'emoji'            => trim((string)($data['emoji'] ?? '')) ?: NULL,

            'min_passengers'   => (int)$data['min_passengers'],
            'max_passengers'   => (int)$data['max_passengers'],
            'luggage_capacity' => (isset($data['luggage_capacity']) && $data['luggage_capacity'] !== '')
                                    ? (int)$data['luggage_capacity'] : NULL,

            'hourly_chicago'   => (float)($data['hourly_chicago']   ?? 0),
            'hourly_america'   => (float)($data['hourly_america']   ?? 0),
            'hourly_worldwide' => (float)($data['hourly_worldwide'] ?? 0),

            'per_km_chicago'   => (float)($data['per_km_chicago']   ?? 0),
            'per_km_america'   => (float)($data['per_km_america']   ?? 0),
            'per_km_worldwide' => (float)($data['per_km_worldwide'] ?? 0),

            'surcharge_chicago'   => (float)($data['surcharge_chicago']   ?? 0),
            'surcharge_america'   => (float)($data['surcharge_america']   ?? 0),
            'surcharge_worldwide' => (float)($data['surcharge_worldwide'] ?? 0),

            'gratuity_chicago'   => (float)($data['gratuity_chicago']   ?? 0),
            'gratuity_america'   => (float)($data['gratuity_america']   ?? 0),
            'gratuity_worldwide' => (float)($data['gratuity_worldwide'] ?? 0),

            'waiting_chicago'   => (float)($data['waiting_chicago']   ?? 0),
            'waiting_america'   => (float)($data['waiting_america']   ?? 0),
            'waiting_worldwide' => (float)($data['waiting_worldwide'] ?? 0),

            'child_seat_chicago'   => (float)($data['child_seat_chicago']   ?? 0),
            'child_seat_america'   => (float)($data['child_seat_america']   ?? 0),
            'child_seat_worldwide' => (float)($data['child_seat_worldwide'] ?? 0),

            'min_fare'             => (float)($data['min_fare']             ?? 0),
        ];

        if ($uploaded_image_name) {
            $row['image'] = $uploaded_image_name;
        }

        return ['__ok' => TRUE, 'errors' => [], 'data' => $row];
    }
}