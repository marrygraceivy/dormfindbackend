<?php
// Additive migration; the supplied seven tables and all their columns are preserved.
class Create_property_amenities
{
    public function up()
    {
        lava_instance()->db->raw('CREATE TABLE IF NOT EXISTS property_amenities (property_id INT NOT NULL, amenity VARCHAR(50) NOT NULL, PRIMARY KEY (property_id,amenity), CONSTRAINT fk_amenity_property FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    public function down() { lava_instance()->db->raw('DROP TABLE IF EXISTS property_amenities'); }
}
