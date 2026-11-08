<?php
class Widen_property_longitude
{
    public function up()
    {
        // DECIMAL(10,8) accepts only +/-99.99999999. Philippine longitude is about 121.
        lava_instance()->db->raw('ALTER TABLE properties MODIFY longitude DECIMAL(11,8) NULL DEFAULT NULL');
    }
    public function down()
    {
        // Keep the wider type: narrowing could destroy existing real-world coordinates.
    }
}
