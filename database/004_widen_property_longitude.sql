-- Required for Philippines coordinates; retains column name and all existing rows.
ALTER TABLE properties MODIFY longitude DECIMAL(11,8) NULL DEFAULT NULL;
INSERT INTO migrations (migration) SELECT 4 WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration=4);
