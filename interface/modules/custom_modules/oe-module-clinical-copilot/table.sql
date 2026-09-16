--
-- Clinical Co-Pilot module schema.
--
-- Uses OpenEMR's conditional DDL preprocessor directives (#IfNotTable) rather
-- than Doctrine Migrations: db/README.md states the Doctrine system is not yet
-- integrated and must not be used for schema changes.
--

#IfNotTable clinical_copilot_log
CREATE TABLE `clinical_copilot_log` (
  `id`               BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `correlation_id`   VARCHAR(36)      NULL COMMENT 'per-request correlation id shared with the audit log and PHP error log',
  `pid`              BIGINT(20)   NOT NULL COMMENT 'patient the question was asked about',
  `user`             VARCHAR(255) NOT NULL COMMENT 'authUser who asked',
  `asked_at`         DATETIME     NOT NULL,
  `question`         TEXT         NOT NULL,
  `reply`            MEDIUMTEXT       NULL,
  `tools_used`       VARCHAR(255)     NULL COMMENT 'comma-separated tool names invoked for this turn',
  `model`            VARCHAR(64)      NULL,
  `success`          TINYINT(1)   NOT NULL DEFAULT 0,
  `latency_ms`       INT UNSIGNED     NULL COMMENT 'wall-clock time for CopilotService::ask() to return',
  PRIMARY KEY (`id`),
  KEY `pid_asked_at` (`pid`, `asked_at`),
  KEY `correlation_id` (`correlation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

-- Upgrade path for an install that already created the table before
-- correlation_id/latency_ms existed (see PUNCH_LIST.md Tier 0.1/0.3).
#IfMissingColumn clinical_copilot_log correlation_id
ALTER TABLE `clinical_copilot_log`
  ADD `correlation_id` VARCHAR(36) NULL
      COMMENT 'per-request correlation id shared with the audit log and PHP error log'
      AFTER `id`;
#EndIf

#IfMissingColumn clinical_copilot_log latency_ms
ALTER TABLE `clinical_copilot_log`
  ADD `latency_ms` INT UNSIGNED NULL
      COMMENT 'wall-clock time for CopilotService::ask() to return'
      AFTER `success`;
#EndIf

#IfNotIndex clinical_copilot_log correlation_id
ALTER TABLE `clinical_copilot_log` ADD KEY `correlation_id` (`correlation_id`);
#EndIf
