-- Artdon Commercial Center V1 foundation rollback plan.
-- PLAN ONLY: execute only after explicit approval.
-- Drops only objects created by 001_foundation.sql.

DROP TABLE IF EXISTS cc_activity_logs;
DROP TABLE IF EXISTS cc_integration_logs;
DROP TABLE IF EXISTS cc_entity_links;
DROP TABLE IF EXISTS cc_schema_migrations;
