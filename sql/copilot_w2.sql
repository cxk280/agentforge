-- AgentForge Co-Pilot — Week 2 schema additions.
--
-- Side-tables that hold the agent's extracted-facts and bbox-citation
-- output. Lives next to OpenEMR's `documents` table; each row in
-- cp_extracted_facts links back via document_id (FK to documents.id),
-- so the source PDF round-trips through OpenEMR's native FHIR
-- DocumentReference path with no parallel storage.
--
-- Why side-tables (not FHIR Observation): OpenEMR's FHIR R4 layer does
-- not expose POST handlers for Binary or Observation, and only the
-- `$docref` operation for DocumentReference. Validated 2026-05-05.
-- Spec explicitly allows "FHIR resources OR OpenEMR records" — we use
-- DocumentReference for the source (read-side, automatic) and these
-- side-tables for derived facts.
--
-- Idempotent: safe to re-apply.

CREATE TABLE IF NOT EXISTS cp_extracted_facts (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    document_id        INT NOT NULL,
    patient_id         INT NOT NULL,
    doc_type           VARCHAR(32) NOT NULL,
    fact_type          VARCHAR(64) NOT NULL,
    fact_json          JSON NOT NULL,
    confidence         DECIMAL(4,3) NULL,
    source_quote       TEXT NULL,
    extraction_run_id  VARCHAR(64) NOT NULL,
    model              VARCHAR(64) NOT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_cp_facts_doc (document_id),
    KEY ix_cp_facts_patient (patient_id),
    KEY ix_cp_facts_doc_type (doc_type),
    KEY ix_cp_facts_run (extraction_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cp_extraction_citations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    fact_id     INT NOT NULL,
    page        INT NOT NULL,
    bbox_x      DECIMAL(8,4) NOT NULL,
    bbox_y      DECIMAL(8,4) NOT NULL,
    bbox_w      DECIMAL(8,4) NOT NULL,
    bbox_h      DECIMAL(8,4) NOT NULL,
    field_path  VARCHAR(128) NOT NULL,
    quote       TEXT NULL,
    KEY ix_cp_cit_fact (fact_id),
    KEY ix_cp_cit_page (page),
    CONSTRAINT fk_cp_cit_fact FOREIGN KEY (fact_id)
        REFERENCES cp_extracted_facts (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Run summary: one row per attach_and_extract call. Lets us track
-- extraction-level metadata (latency, token spend, model version)
-- separately from per-fact rows, and gives a single anchor point for
-- the eval gate's `schema_valid` rubric (run failed validation -> all
-- facts from that run are flagged).
CREATE TABLE IF NOT EXISTS cp_extraction_runs (
    run_id             VARCHAR(64) PRIMARY KEY,
    document_id        INT NOT NULL,
    patient_id         INT NOT NULL,
    doc_type           VARCHAR(32) NOT NULL,
    status             VARCHAR(24) NOT NULL DEFAULT 'in_progress',
    fact_count         INT NOT NULL DEFAULT 0,
    schema_valid       TINYINT(1) NOT NULL DEFAULT 0,
    latency_ms         INT NULL,
    input_tokens       INT NULL,
    output_tokens      INT NULL,
    model              VARCHAR(64) NOT NULL,
    error_message      TEXT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at       TIMESTAMP NULL,
    KEY ix_cp_runs_doc (document_id),
    KEY ix_cp_runs_patient (patient_id),
    KEY ix_cp_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
