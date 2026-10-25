<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Companies ("tenants") — one per website/brand that embeds the widget.
 *
 *   kfb_companies        — name, logo, contact numbers, address, emails,
 *                          widget page URL, legacy company id
 *   kfb_company_domains  — normalized host -> company (several per company)
 *
 * The public API resolves the company from the website the widget is
 * running on (the browser's Origin, or Referer, header) via
 * resolve_from_request(). Unknown or inactive domains resolve to NULL and
 * the API refuses the request — an admin must add the company + domains
 * first. domain -> company lookups are cached (file cache, 1 hour, cleared
 * whenever an admin saves a company) so the widget doesn't hit the
 * database for it on every call.
 */
#[\AllowDynamicProperties]
class Company_model extends CI_Model
{
    const DEFAULT_COMPANY_ID = 1;
    const CACHE_TTL          = 3600;
    const LOGO_DIR           = 'uploads/companies/';
    const MAX_LOGO_KB        = 1024;
    const ALLOWED_LOGO_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    protected $cache_ok = NULL;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // ---------------------- Host / request resolution ----------------------

    /**
     * "https://www.Example.com:8080/x" or "www.example.com" -> "example.com"
     * ('' if not a host). IPv6 addresses are kept in brackets, compressed
     * and lower-case, so "http://[::1]:8000/x", "[::1]" and "::1" all
     * normalize to "[::1]".
     */
    public function normalize_host($value)
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') return '';
        if (strpos($value, '://') !== FALSE) {
            $value = (string)parse_url($value, PHP_URL_HOST);   // "[::1]" for IPv6, brackets included
        } elseif (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $value = '[' . $value . ']';                         // bare IPv6 address, no port possible
        } else {
            $value = preg_replace('#[/?\#].*$#', '', $value);   // path/query/fragment
            if (strpos($value, '[') !== 0) $value = preg_replace('#:\d+$#', '', $value);
            elseif (preg_match('#^(\[[^\]]*\])(?::\d+)?$#', $value, $m)) $value = $m[1]; // "[::1]:8000" -> "[::1]"
        }

        if (strpos($value, '[') === 0 || substr($value, -1) === ']') {
            if (!preg_match('#^\[([0-9a-f:.]+)\]$#', $value, $m)) return '';   // exactly one pair of brackets
            $inner = $m[1];
            if (!filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return '';
            $packed = inet_pton($inner);   // already validated by filter_var above
            return $packed === FALSE ? '' : '[' . inet_ntop($packed) . ']';
        }

        $value = rtrim($value, '.');
        if (strpos($value, 'www.') === 0) $value = substr($value, 4);
        return preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $value) ? $value : '';
    }

    /**
     * Website the request came from, from the browser-set Origin header
     * (falling back to Referer for same-origin GETs, which omit Origin) —
     * never from the request body. The X-Company-Domain override exists
     * only for testing a second company from a local server and is only
     * honoured when the API itself is being served from localhost.
     */
    public function host_from_request()
    {
        if ($this->_is_local_server() && !empty($_SERVER['HTTP_X_COMPANY_DOMAIN'])) {
            $override = $this->normalize_host($_SERVER['HTTP_X_COMPANY_DOMAIN']);
            if ($override !== '') return $override;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '' || $origin === 'null') $origin = $_SERVER['HTTP_REFERER'] ?? '';
        return $this->normalize_host($origin);
    }

    /** TRUE when this API is being served from the developer's own machine (localhost, 127.0.0.1, [::1]). */
    protected function _is_local_server()
    {
        return in_array($this->normalize_host($_SERVER['HTTP_HOST'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], TRUE);
    }

    /** Active company for this request's website, or NULL if unknown/inactive. */
    public function resolve_from_request()
    {
        $host = $this->host_from_request();
        if ($host === '') {
            // No Origin/Referer at all (an address-bar visit or Postman) —
            // refused, except on a developer's own local server, where it is
            // treated as the default company so API URLs can be tried by hand.
            if (!$this->_is_local_server()) return NULL;
            $default = $this->get(self::DEFAULT_COMPANY_ID);
            if (!$default || $default['status'] !== 'active') return NULL;
            $default['_origin_host'] = NULL;
            return $default;
        }
        $company = $this->get_by_domain($host);
        if (!$company || $company['status'] !== 'active') return NULL;
        $company['_origin_host'] = $host;
        return $company;
    }

    public function get_by_domain($host)
    {
        $host = $this->normalize_host($host);
        if ($host === '') return NULL;

        $key = 'kfbco_' . $this->_cache_version() . '_' . md5($host);
        $hit = $this->_cache_get($key);
        if ($hit !== FALSE) return $hit ?: NULL; // '' = cached "unknown domain"

        $row = $this->db
            ->select('c.*')
            ->from('kfb_company_domains d')
            ->join('kfb_companies c', 'c.id = d.company_id')
            ->where('d.domain', $host)
            ->get()->row_array();

        $this->_cache_set($key, $row ?: '');
        return $row ?: NULL;
    }

    // ---------------------- Reads ----------------------

    public function get($id)
    {
        if (!$id) return NULL;
        return $this->db->get_where('kfb_companies', ['id' => (int)$id])->row_array() ?: NULL;
    }

    public function list_all()
    {
        $rows = $this->db->order_by('name', 'ASC')->get('kfb_companies')->result_array();
        if (!$rows) return [];
        $domains = [];
        foreach ($this->db->order_by('domain', 'ASC')->get('kfb_company_domains')->result_array() as $d) {
            $domains[(int)$d['company_id']][] = $d['domain'];
        }
        foreach ($rows as &$r) $r['domains'] = $domains[(int)$r['id']] ?? [];
        unset($r);
        return $rows;
    }

    public function domains_for($company_id)
    {
        $rows = $this->db->select('domain')->order_by('domain', 'ASC')
            ->get_where('kfb_company_domains', ['company_id' => (int)$company_id])->result_array();
        return array_column($rows, 'domain');
    }

    public function logo_url($company)
    {
        if (empty($company['logo'])) return NULL;
        return base_url(self::LOGO_DIR . $company['logo']);
    }

    // ---------------------- Writes ----------------------

    /**
     * Create (id NULL) or update a company and replace its domain list.
     * $domains is an array of raw host/URL strings. $errors is filled with
     * field => message on validation failure (nothing is saved then).
     * Returns the company id, or NULL on failure.
     */
    public function save($id, array $data, array $domains, $new_logo = NULL, &$errors = [])
    {
        $errors = [];
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') $errors['name'] = 'Company name is required.';

        foreach (['contact_email', 'notification_email'] as $f) {
            $v = trim((string)($data[$f] ?? ''));
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$f] = 'Enter a valid email address.';
        }
        $url = trim((string)($data['widget_page_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $errors['widget_page_url'] = 'Must start with http:// or https://';

        $clean = [];
        foreach ($domains as $raw) {
            $h = $this->normalize_host($raw);
            if ($h === '') {
                if (trim((string)$raw) !== '') $errors['domains'] = 'Not a valid domain: ' . trim((string)$raw);
                continue;
            }
            $clean[$h] = TRUE;
        }
        $clean = array_keys($clean);
        if (!$clean && !isset($errors['domains'])) $errors['domains'] = 'Add at least one domain.';

        foreach ($clean as $h) {
            $this->db->where('domain', $h);
            if ($id) $this->db->where('company_id !=', (int)$id);
            $taken = $this->db->get('kfb_company_domains')->row_array();
            if ($taken) { $errors['domains'] = $h . ' already belongs to another company.'; break; }
        }
        if ($errors) return NULL;

        $row = [
            'name'               => $name,
            'phone_1'            => $this->_nullable($data['phone_1'] ?? ''),
            'phone_2'            => $this->_nullable($data['phone_2'] ?? ''),
            'address_line_1'     => $this->_nullable($data['address_line_1'] ?? ''),
            'address_line_2'     => $this->_nullable($data['address_line_2'] ?? ''),
            'contact_email'      => $this->_nullable($data['contact_email'] ?? ''),
            'notification_email' => $this->_nullable($data['notification_email'] ?? ''),
            'widget_page_url'    => $this->_nullable($url),
            'legacy_company_id'  => (isset($data['legacy_company_id']) && $data['legacy_company_id'] !== '') ? (int)$data['legacy_company_id'] : NULL,
            'status'             => (($data['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active',
        ];
        if ($new_logo) $row['logo'] = $new_logo;

        $this->db->trans_start();
        if ($id) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $this->db->where('id', (int)$id)->update('kfb_companies', $row);
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
            $this->db->insert('kfb_companies', $row);
            $id = (int)$this->db->insert_id();
        }
        $this->db->where('company_id', (int)$id)->delete('kfb_company_domains');
        foreach ($clean as $h) {
            $this->db->insert('kfb_company_domains', ['company_id' => (int)$id, 'domain' => $h, 'created_at' => date('Y-m-d H:i:s')]);
        }
        $this->db->trans_complete();
        if (!$this->db->trans_status()) { $errors['general'] = 'Could not save the company.'; return NULL; }

        $this->_cache_bump();
        return (int)$id;
    }

    /** Delete a stored logo file by name; logs (instead of warning) if it can't be removed. */
    public function delete_logo_file($filename)
    {
        $path = FCPATH . self::LOGO_DIR . basename((string)$filename);
        if (is_file($path) && !unlink($path)) log_message('error', 'Could not delete logo file ' . $path);
    }

    public function remove_logo($company_id)
    {
        $c = $this->get($company_id);
        if (!$c || empty($c['logo'])) return;
        $this->delete_logo_file($c['logo']);
        $this->db->where('id', (int)$company_id)->update('kfb_companies', ['logo' => NULL, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->_cache_bump();
    }

    /**
     * Validate + store an uploaded logo from $_FILES[$field]. Returns the
     * stored file name, or NULL (with $error set when a file WAS sent but
     * rejected; $error stays NULL when no file was sent at all).
     */
    public function handle_logo_upload($field = 'logo', &$error = NULL)
    {
        $error = NULL;
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) return NULL;

        $file = $_FILES[$field];
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) { $error = 'The logo upload failed (error ' . (int)($file['error'] ?? -1) . ').'; return NULL; }
        if (!is_uploaded_file($file['tmp_name'])) { $error = 'The uploaded file is not a valid HTTP upload.'; return NULL; }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_LOGO_TYPES, TRUE)) { $error = 'Logo must be a JPG, PNG or WEBP image.'; return NULL; }
        if ((int)$file['size'] > self::MAX_LOGO_KB * 1024) { $error = 'Logo is larger than ' . self::MAX_LOGO_KB . ' KB.'; return NULL; }
        $info = (is_file($file['tmp_name']) && is_readable($file['tmp_name'])) ? getimagesize($file['tmp_name']) : FALSE;
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], TRUE)) { $error = 'The file is not a valid image.'; return NULL; }

        $dir = FCPATH . self::LOGO_DIR;
        if (!is_dir($dir) && (!is_writable(dirname($dir)) || !mkdir($dir, 0755, TRUE)) && !is_dir($dir)) { $error = 'Could not create the logo folder on the server.'; return NULL; }

        $name = bin2hex(random_bytes(8)) . '_' . time() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $dir . $name)) { $error = 'Could not save the uploaded logo.'; return NULL; }
        return $name;
    }

    // ---------------------- Cache ----------------------

    protected function _nullable($v)
    {
        $v = trim((string)$v);
        return $v === '' ? NULL : $v;
    }

    protected function _cache_enabled()
    {
        if ($this->cache_ok !== NULL) return $this->cache_ok;
        try {
            $this->load->driver('cache', ['adapter' => 'file']);
            $this->cache_ok = (bool)$this->cache->file->is_supported();
        } catch (Throwable $e) {
            $this->cache_ok = FALSE;
        }
        return $this->cache_ok;
    }

    protected function _cache_get($key)
    {
        if (!$this->_cache_enabled()) return FALSE;
        try { return $this->cache->file->get($key); } catch (Throwable $e) { return FALSE; }
    }

    protected function _cache_set($key, $value)
    {
        if (!$this->_cache_enabled()) return;
        try { $this->cache->file->save($key, $value, self::CACHE_TTL); } catch (Throwable $e) { /* cache is an optimisation only */ }
    }

    /** Version number baked into every cache key — bumping it invalidates all cached lookups at once. */
    protected function _cache_version()
    {
        $v = $this->_cache_get('kfbco_ver');
        return ($v === FALSE || $v === NULL) ? 1 : (int)$v;
    }

    protected function _cache_bump()
    {
        if (!$this->_cache_enabled()) return;
        try { $this->cache->file->save('kfbco_ver', $this->_cache_version() + 1, 315360000); } catch (Throwable $e) { /* ignore */ }
    }
}
