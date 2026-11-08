
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class User_model extends Model
{
    public function get_all_users()
    {
        return $this->db->table('users')->get_all();
    }

    public function get_user_by_id($id)
    {
        return $this->db->table('users')
                        ->where('user_id', $id)
                        ->get();
    }

    public function get_user_by_email($email)
    {
        return $this->db->table('users')
                        ->where('email', $email)
                        ->get();
    }

    public function insert_user($data)
    {
        return $this->db->table('users')->insert($data);
    }
}
