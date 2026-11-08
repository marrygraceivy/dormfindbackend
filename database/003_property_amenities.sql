-- Apply ONLY to an existing dormfind_db. No existing table/data is replaced.
CREATE TABLE IF NOT EXISTS property_amenities (
  property_id INT NOT NULL,
  amenity VARCHAR(50) NOT NULL,
  PRIMARY KEY (property_id, amenity),
  CONSTRAINT fk_amenity_property FOREIGN KEY (property_id)
    REFERENCES properties(property_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO migrations (migration) SELECT 3 WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration=3);
