<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Roommate_model extends Model {

    public function get_preference_by_user($user_id) {
        return $this->db->table('roommate_preferences')->where('user_id', $user_id)->get();
    }

    public function save_preferences($data) {
        return $this->db->table('roommate_preferences')->insert($data);
    }
}