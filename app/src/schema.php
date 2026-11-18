<?php
declare(strict_types=1);
if(!defined('PLATI_ENTRY')&&PHP_SAPI!=='cli'){http_response_code(404);exit;}
return <<<'PLATI_SQL'
-- Local reconstruction schema. Applied and tested locally; production unchanged.
-- Target: InnoDB MySQL/MariaDB. Verify exact server version before implementation.
-- All current writes will go through one domain service in SQL transactions.

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(100) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 display_name VARCHAR(190) NOT NULL,
 role VARCHAR(20) NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE import_files (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source VARCHAR(20) NOT NULL,
 filename VARCHAR(255) NOT NULL,
 file_sha256 CHAR(64) NOT NULL,
 private_archive_reference VARCHAR(255) NOT NULL,
 contract_version VARCHAR(40) NOT NULL,
 status VARCHAR(30) NOT NULL,
 summary_json LONGTEXT NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_source_file(source,file_sha256),
 FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE import_rows (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 import_id BIGINT UNSIGNED NOT NULL,
 sheet_name VARCHAR(100) NOT NULL,
 source_row_number INT UNSIGNED NOT NULL,
 source_key VARCHAR(190) NULL,
 content_hash CHAR(64) NOT NULL,
 result VARCHAR(30) NOT NULL,
 issue_json LONGTEXT NOT NULL,
 UNIQUE KEY uq_import_position(import_id,sheet_name,source_row_number),
 FOREIGN KEY(import_id) REFERENCES import_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE invoices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 invoice_number VARCHAR(190) NOT NULL UNIQUE,
 invoice_date DATE NULL,
 client VARCHAR(500) NOT NULL,
 normalized_name VARCHAR(500) NOT NULL,
 name_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 amount_cents BIGINT NOT NULL,
 balance_cents BIGINT NOT NULL,
 comisionar VARCHAR(500) NOT NULL,
 workplace VARCHAR(190) NOT NULL,
 observation TEXT NOT NULL,
 scope VARCHAR(30) NOT NULL,
 scope_reason VARCHAR(50) NOT NULL,
 source_hash CHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 import_row_id BIGINT UNSIGNED NOT NULL,
 KEY ix_invoice_candidates(name_hash,invoice_date,amount_cents),
 KEY ix_invoice_report(scope,invoice_date),
 FOREIGN KEY(import_row_id) REFERENCES import_rows(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE site_orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_number VARCHAR(30) NOT NULL UNIQUE,
 order_date DATE NULL,
 payment_date DATE NULL,
 amount_cents BIGINT NOT NULL,
 awb VARCHAR(30) NULL,
 customer_type VARCHAR(100) NOT NULL,
 client VARCHAR(500) NOT NULL,
 company VARCHAR(500) NOT NULL,
 payment_method VARCHAR(190) NOT NULL,
 transaction_identifier VARCHAR(190) NULL,
 source_hash CHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 import_row_id BIGINT UNSIGNED NOT NULL,
 KEY ix_order_awb(awb),
 FOREIGN KEY(import_row_id) REFERENCES import_rows(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE fan_shipments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 awb VARCHAR(30) NOT NULL UNIQUE,
 awb_date DATE NULL,
 recipient VARCHAR(500) NOT NULL,
 normalized_name VARCHAR(500) NOT NULL,
 name_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 content TEXT NOT NULL,
 order_reference VARCHAR(30) NULL,
 delivery_status_raw VARCHAR(190) NOT NULL,
 delivery_status VARCHAR(30) NOT NULL,
 cash_due_cents BIGINT NULL,
 transfer_date DATE NULL,
 service_raw VARCHAR(190) NOT NULL,
 service_type VARCHAR(30) NOT NULL,
 source_hash CHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 import_row_id BIGINT UNSIGNED NOT NULL,
 KEY ix_shipment_candidates(name_hash,awb_date,cash_due_cents),
 KEY ix_shipment_order(order_reference),
 FOREIGN KEY(import_row_id) REFERENCES import_rows(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE netopia_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 canonical_external_id VARCHAR(190) NOT NULL UNIQUE,
 client VARCHAR(500) NOT NULL,
 normalized_name VARCHAR(500) NOT NULL,
 name_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 operation_date DATE NULL,
 transfer_date DATE NULL,
 operation_number VARCHAR(100) NOT NULL,
 gross_amount_cents BIGINT NOT NULL,
 commission DECIMAL(20,5) NOT NULL,
 vat DECIMAL(20,5) NOT NULL,
 source_hash CHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 import_row_id BIGINT UNSIGNED NOT NULL,
 KEY ix_payment_candidates(name_hash,operation_date,gross_amount_cents),
 KEY ix_payment_operation(operation_number),
 FOREIGN KEY(import_row_id) REFERENCES import_rows(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Every canonical ID ALSO has an entry here. This is the shared namespace for
-- original IDs and approved aliases. Unverified aliases cannot be used for matching.
CREATE TABLE payment_identifiers (
 provider VARCHAR(20) NOT NULL,
 external_id VARCHAR(190) NOT NULL,
 payment_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(30) NOT NULL,
 proof_json LONGTEXT NOT NULL,
 PRIMARY KEY(provider,external_id),
 FOREIGN KEY(payment_id) REFERENCES netopia_payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE provider_adjustments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 import_row_id BIGINT UNSIGNED NOT NULL UNIQUE,
 adjustment_type VARCHAR(40) NOT NULL,
 operation_number VARCHAR(100) NULL,
 source_amount DECIMAL(20,5) NULL,
 source_details_json LONGTEXT NOT NULL,
 review_status VARCHAR(30) NOT NULL,
 FOREIGN KEY(import_row_id) REFERENCES import_rows(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE decisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 idempotency_key VARCHAR(100) NOT NULL UNIQUE,
 action VARCHAR(40) NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 invoice_id BIGINT UNSIGNED NULL,
 order_id BIGINT UNSIGNED NULL,
 shipment_id BIGINT UNSIGNED NULL,
 payment_id BIGINT UNSIGNED NULL,
 source_hashes_json LONGTEXT NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 accepted_difference_cents BIGINT NULL,
 actor_id BIGINT UNSIGNED NULL,
 legacy_actor VARCHAR(190) NULL,
 legacy_reference VARCHAR(190) NULL,
 supersedes_decision_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(invoice_id) REFERENCES invoices(id),
 FOREIGN KEY(order_id) REFERENCES site_orders(id),
 FOREIGN KEY(shipment_id) REFERENCES fan_shipments(id),
 FOREIGN KEY(payment_id) REFERENCES netopia_payments(id),
 FOREIGN KEY(actor_id) REFERENCES users(id),
 FOREIGN KEY(supersedes_decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE invoice_orders (
 invoice_id BIGINT UNSIGNED PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 document_role VARCHAR(30) NOT NULL,
 decision_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(invoice_id) REFERENCES invoices(id),
 FOREIGN KEY(order_id) REFERENCES site_orders(id),
 FOREIGN KEY(decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE shipment_assignments (
 shipment_id BIGINT UNSIGNED PRIMARY KEY,
 invoice_id BIGINT UNSIGNED NOT NULL,
 primary_slot TINYINT NULL,
 assignment_status VARCHAR(30) NOT NULL,
 decision_id BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_primary_tracking(invoice_id,primary_slot),
 CHECK(primary_slot IS NULL OR primary_slot=1),
 FOREIGN KEY(shipment_id) REFERENCES fan_shipments(id),
 FOREIGN KEY(invoice_id) REFERENCES invoices(id),
 FOREIGN KEY(decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE payment_resolutions (
 payment_id BIGINT UNSIGNED PRIMARY KEY,
 invoice_id BIGINT UNSIGNED NULL UNIQUE,
 resolution VARCHAR(40) NOT NULL,
 decision_id BIGINT UNSIGNED NOT NULL,
 CHECK((resolution IN ('associated','historical') AND invoice_id IS NOT NULL)
       OR (resolution IN ('closed_user_reported','refunded_user_reported') AND invoice_id IS NULL)),
 FOREIGN KEY(payment_id) REFERENCES netopia_payments(id),
 FOREIGN KEY(invoice_id) REFERENCES invoices(id),
 FOREIGN KEY(decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE source_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_type VARCHAR(30) NOT NULL,
 source_key VARCHAR(190) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 content_hash CHAR(64) NOT NULL,
 source_json LONGTEXT NOT NULL,
 import_row_id BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME NOT NULL,
 UNIQUE KEY uq_source_revision(source_type,source_key,revision),
 FOREIGN KEY(import_row_id) REFERENCES import_rows(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE review_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(30) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 result_json LONGTEXT NOT NULL,
 FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE review_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL,
 item_key VARCHAR(100) NOT NULL,
 proposal_json LONGTEXT NOT NULL,
 UNIQUE KEY uq_review_item(batch_id,item_key),
 FOREIGN KEY(batch_id) REFERENCES review_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE gpd_drivers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE gpd_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 invoice_id BIGINT UNSIGNED NULL,
 order_id BIGINT UNSIGNED NULL,
 driver_id BIGINT UNSIGNED NOT NULL,
 delivery_date DATE NOT NULL,
 decision_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(invoice_id) REFERENCES invoices(id),
 FOREIGN KEY(order_id) REFERENCES site_orders(id),
 FOREIGN KEY(driver_id) REFERENCES gpd_drivers(id),
 FOREIGN KEY(decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE shipment_returns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shipment_id BIGINT UNSIGNED NOT NULL UNIQUE,
 payment_id BIGINT UNSIGNED NULL,
 status VARCHAR(30) NOT NULL,
 decision_id BIGINT UNSIGNED NULL,
 FOREIGN KEY(shipment_id) REFERENCES fan_shipments(id),
 FOREIGN KEY(payment_id) REFERENCES netopia_payments(id),
 FOREIGN KEY(decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE refunds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 return_id BIGINT UNSIGNED NULL,
 payment_id BIGINT UNSIGNED NOT NULL,
 refund_amount_cents BIGINT NULL,
 refund_date DATE NULL,
 reference VARCHAR(190) NULL,
 evidence_type VARCHAR(40) NOT NULL,
 decision_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(return_id) REFERENCES shipment_returns(id),
 FOREIGN KEY(payment_id) REFERENCES netopia_payments(id),
 FOREIGN KEY(decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE cash_circuits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 original_invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
 storno_invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
 reissued_invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
 status VARCHAR(30) NOT NULL,
 decision_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(original_invoice_id) REFERENCES invoices(id),
 FOREIGN KEY(storno_invoice_id) REFERENCES invoices(id),
 FOREIGN KEY(reissued_invoice_id) REFERENCES invoices(id),
 FOREIGN KEY(decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_type VARCHAR(80) NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 decision_id BIGINT UNSIGNED NULL,
 details_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(actor_id) REFERENCES users(id),
 FOREIGN KEY(decision_id) REFERENCES decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Migration provenance, never an alternate matching engine.
CREATE TABLE migration_map (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 snapshot_sha256 CHAR(64) NOT NULL,
 legacy_table VARCHAR(64) NOT NULL,
 legacy_key VARCHAR(190) NOT NULL,
 target_table VARCHAR(64) NOT NULL,
 target_id BIGINT UNSIGNED NOT NULL,
 legacy_payload_sha256 CHAR(64) NOT NULL,
 UNIQUE KEY uq_migrated_record(snapshot_sha256,legacy_table,legacy_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Preferences only. Financial confirmations belong in decisions/resolutions.
CREATE TABLE application_settings (
 setting_key VARCHAR(100) PRIMARY KEY,
 value_json LONGTEXT NOT NULL,
 revision INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Further mandatory service invariants (tested before any cutover):
-- - Only assigned/historical payment resolutions may have invoice_id NOT NULL.
-- - A rejected pair is never turned into an allocation without explicit reconsideration.
-- - Native and alias payment identifiers are registered together in the same transaction.
-- - A delivery needs invoice_id or order_id and the correct ownership/driver permissions.
-- - One source batch revision and one idempotency key protect concurrent/retried writes.
-- - Source-version and current-source writes happen atomically; no FK uses ON DELETE CASCADE.
-- - Cash-circuit sources must be internal/proven and cannot override FAN/NETOPIA evidence.

CREATE TABLE invoice_preferences(invoice_id BIGINT UNSIGNED PRIMARY KEY,payment_method VARCHAR(20) NOT NULL,reason VARCHAR(500) NOT NULL,decision_id BIGINT UNSIGNED NULL,FOREIGN KEY(invoice_id) REFERENCES invoices(id),FOREIGN KEY(decision_id) REFERENCES decisions(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

PLATI_SQL;
