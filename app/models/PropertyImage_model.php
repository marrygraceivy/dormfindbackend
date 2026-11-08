<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PropertyImage_model extends Model {

    public function get_images_by_property($property_id) {
        return $this->db->table('property_images')->where('property_id', $property_id)->get_all();
    }

    public function add_image($data) {
        return $this->db->table('property_images')->insert($data);
    }
}