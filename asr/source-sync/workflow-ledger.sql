-- Apply only during reviewed source deployment; never executed by the endpoint.
CREATE TABLE mapa_source_workflow_commands (
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action_name VARCHAR(40) NOT NULL,
 record_id VARCHAR(64) NOT NULL,
 receipt JSON NULL,
 created_at DATETIME(6) NOT NULL,
 completed_at DATETIME(6) NULL
) ENGINE=InnoDB;
