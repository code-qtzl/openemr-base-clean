--
-- Clinical Co-Pilot module schema.
--
-- Uses OpenEMR's conditional DDL preprocessor directives (#IfNotTable) rather
-- than Doctrine Migrations: db/README.md states the Doctrine system is not yet
-- integrated and must not be used for schema changes.
--

#IfNotTable clinical_copilot_log
CREATE TABLE `clinical_copilot_log` (
  `id`          BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `pid`         BIGINT(20)   NOT NULL COMMENT 'patient the question was asked about',
  `user`        VARCHAR(255) NOT NULL COMMENT 'authUser who asked',
  `asked_at`    DATETIME     NOT NULL,
  `question`    TEXT         NOT NULL,
  `reply`       MEDIUMTEXT       NULL,
  `tools_used`  VARCHAR(255)     NULL COMMENT 'comma-separated tool names invoked for this turn',
  `model`       VARCHAR(64)      NULL,
  `success`     TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `pid_asked_at` (`pid`, `asked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf
