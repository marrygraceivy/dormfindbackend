<?php

class Create_users_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('users')) {
            return;
        }
        // The full dormfind_db dump defines every FK-linked base table. Refuse
        // to create the unrelated stock LavaLust users(id,username) schema.
        throw new RuntimeException('Import database/dormfind_db.sql into an empty database before running migrations.');
    }

    public function down()
    {
        // Never delete real DormFind accounts during rollback.
    }
}
