-- Reviewed source deployment only. No patient facts, automatic expiry or operational account creation.
CREATE TABLE mapa_dispatch_reservations (
 company_id CHAR(36) NOT NULL,
 journey_id VARCHAR(64) NOT NULL,
 ambulance_id INT NOT NULL,
 driver_id INT NOT NULL,
 status VARCHAR(24) NOT NULL,
 version INT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 terminal_at DATETIME(6) NULL,
 active_ambulance_id INT GENERATED ALWAYS AS (CASE WHEN terminal_at IS NULL THEN ambulance_id ELSE NULL END) STORED,
 active_driver_id INT GENERATED ALWAYS AS (CASE WHEN terminal_at IS NULL THEN driver_id ELSE NULL END) STORED,
 PRIMARY KEY(company_id,journey_id),
 UNIQUE KEY one_live_vehicle(active_ambulance_id),
 UNIQUE KEY one_live_driver(active_driver_id)
) ENGINE=InnoDB;
