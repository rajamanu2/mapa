CREATE TABLE mapa_manual_commands (
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id VARCHAR(255) NOT NULL,
 source_file VARCHAR(64) NOT NULL,
 action_name VARCHAR(40) NOT NULL,
 receipt JSON NULL,
 created_at DATETIME(6) NOT NULL,
 completed_at DATETIME(6) NULL
) ENGINE=InnoDB;
