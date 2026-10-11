<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The single, editable Terms & Conditions document (kfb_terms, row id 1) —
 * shown in the widget's "Master Terms & Conditions" popup, edited under
 * Admin > Terms & Conditions. One set for every company; saving overwrites
 * the text (no version history). HTML is sanitized on the way in
 * (Html_sanitizer) because it is displayed on client websites.
 */
class Terms_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->library('html_sanitizer');
    }

    /** ['html' => string, 'updated_at' => ?string, 'updated_by' => ?string] — empty html if never set. */
    public function get()
    {
        $row = $this->db->get_where('kfb_terms', ['id' => 1])->row_array();
        return $row ?: ['html' => '', 'updated_at' => NULL, 'updated_by' => NULL];
    }

    /** Sanitize + store. Returns the saved HTML, or NULL if there is no actual text content. */
    public function save($html, $by = NULL)
    {
        $clean = $this->html_sanitizer->clean($html);
        if (trim(strip_tags($clean)) === '') return NULL;

        $row = ['html' => $clean, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $by ? substr((string)$by, 0, 64) : NULL];
        if ($this->db->get_where('kfb_terms', ['id' => 1])->row_array()) {
            $this->db->where('id', 1)->update('kfb_terms', $row);
        } else {
            $this->db->insert('kfb_terms', array_merge(['id' => 1], $row));
        }
        return $clean;
    }
}
