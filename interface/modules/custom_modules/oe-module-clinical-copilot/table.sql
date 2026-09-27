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
  `verification_passed` TINYINT(1)   NULL COMMENT 'NULL when the request failed before an answer was verified; see PUNCH_LIST.md 1.3',
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

-- Upgrade path for an install that already created the table before
-- verification_passed existed (see PUNCH_LIST.md Tier 1.3).
#IfMissingColumn clinical_copilot_log verification_passed
ALTER TABLE `clinical_copilot_log`
  ADD `verification_passed` TINYINT(1) NULL
      COMMENT 'NULL when the request failed before an answer was verified; see PUNCH_LIST.md 1.3'
      AFTER `latency_ms`;
#EndIf

-- Multi-turn conversation state (PUNCH_LIST.md Tier 4.1 / ARCHITECTURE.md's
-- Persistent State Management section). Keyed by session + patient id so a
-- follow-up question in the same visit resolves against what was just
-- discussed, and so a different patient opened in the same browser session
-- never sees another patient's conversation. `last_updated` backs
-- SqlConversationStore's TTL eviction, mirroring session_tracker's own
-- inactivity-timeout pattern.
#IfNotTable clinical_copilot_conversation
CREATE TABLE `clinical_copilot_conversation` (
  `id`             BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `session_uuid`   VARCHAR(36)  NOT NULL COMMENT 'session_tracker.uuid identifying the browser session',
  `pid`            BIGINT(20)   NOT NULL COMMENT 'patient this conversation is scoped to',
  `turns_json`     MEDIUMTEXT   NOT NULL COMMENT 'JSON list of {role, content} turns, oldest first',
  `created_at`     DATETIME     NOT NULL,
  `last_updated`   DATETIME     NOT NULL COMMENT 'drives TTL eviction; see SqlConversationStore',
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_pid` (`session_uuid`, `pid`),
  KEY `last_updated` (`last_updated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

-- Document ingestion (AgentForge2 Core Requirement #1: attach_and_extract).
-- One row per document extraction that passed SchemaValidator's
-- schema_valid check -- DocumentIngestionPipeline never persists a
-- schema-invalid extraction, so every row here already satisfies the
-- lab_pdf/intake_form field contract. `document_id` links back to the
-- `documents` row DocumentAttachmentService creates via
-- documents.foreign_reference_id/foreign_reference_table (nullable: it is
-- set in a second write after this row exists, per DocumentIngestionPipeline's
-- documented two-phase insert).
#IfNotTable clinical_copilot_extracted_document
CREATE TABLE `clinical_copilot_extracted_document` (
  `id`             BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `pid`            BIGINT(20)   NOT NULL COMMENT 'patient this extraction belongs to',
  `document_id`    BIGINT(20)       NULL COMMENT 'documents.id of the stored source file; set after DocumentAttachmentService::store()',
  `doc_type`       VARCHAR(32)  NOT NULL COMMENT 'lab_pdf or intake_form, per SchemaDocType',
  `fields_json`    MEDIUMTEXT   NOT NULL COMMENT '{"doc_type": ..., "fields": {...}}, the validated ExtractedDocument envelope',
  `created_at`     DATETIME     NOT NULL,
  `last_updated`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pid` (`pid`),
  KEY `doc_type` (`doc_type`),
  KEY `document_id` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

-- Small clinical-guideline evidence corpus for EvidenceRetrieverWorker's
-- hybrid RAG (AgentForge2 Core Requirement #3): dense brute-force cosine
-- search over embedding_json plus a FULLTEXT keyword search, fused via
-- Reciprocal Rank Fusion, reranked via Voyage. Populated only by
-- bin/seed-guideline-corpus.php, never at request time -- see that script's
-- own docblock for the source data and chunking strategy.
#IfNotTable clinical_copilot_guideline_chunk
CREATE TABLE `clinical_copilot_guideline_chunk` (
  `id`              BIGINT(20)    NOT NULL AUTO_INCREMENT,
  `source_id`       VARCHAR(64)   NOT NULL COMMENT 'stable slug for the source document, e.g. metformin-hcl-label',
  `source_label`    VARCHAR(255)  NOT NULL COMMENT 'human-readable label, e.g. "Metformin Hydrochloride Tablets -- FDA Label"',
  `section`         VARCHAR(64)   NOT NULL COMMENT 'e.g. warnings, contraindications, drug_interactions, dosage_and_administration',
  `chunk_index`     INT UNSIGNED  NOT NULL COMMENT 'ordinal within (source_id, section) -- see chunking strategy in bin/seed-guideline-corpus.php',
  `chunk_text`      MEDIUMTEXT    NOT NULL,
  `embedding_json`  MEDIUMTEXT    NOT NULL COMMENT 'JSON array of floats, Voyage embedding of chunk_text',
  `embedding_model` VARCHAR(64)   NOT NULL COMMENT 'Voyage model name the embedding was produced with, so a model change can be detected/reseeded',
  `created_at`      DATETIME      NOT NULL,
  `last_updated`    DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_section_chunk` (`source_id`, `section`, `chunk_index`),
  FULLTEXT KEY `chunk_text_fulltext` (`chunk_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf
