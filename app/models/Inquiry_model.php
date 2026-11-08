<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Inquiry_model extends Model {

    public function get_inquiries_for_property($property_id) {
        return $this->db->table('inquiries_applications')->where('property_id', $property_id)->get_all();
    }

    public function send_inquiry($data) {
        return $this->db->table('inquiries_applications')->insert($data);
    }

    public function update_status($inquiry_id, $status) {
        // Gawing ucfirst() upang maging 'Pending', 'Approved', o 'Rejected'
        $clean_status = ucfirst(strtolower(trim($status)));
        
        $sql = "UPDATE inquiries_applications SET status = ? WHERE inquiry_id = ?";
        return $this->db->raw($sql, [$clean_status, $inquiry_id]);
    }
}