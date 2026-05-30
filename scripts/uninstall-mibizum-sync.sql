-- ============================================================================
-- Mibizum_Sync (DB uninstall (idempotent and safe))
-- ============================================================================
-- Removes ALL trace of the module from the database: its own tables, the config
-- in core_config_data and the version row in core_resource. Leaves no junk.
--
-- @PREFIX@ = the store's `table_prefix` (empty on most). The
-- uninstall-mibizum-sync.sh script substitutes it automatically by reading
-- local.xml. If you run this by hand and you do NOT use a prefix, replace
-- @PREFIX@ with nothing.
--
-- Safe to run multiple times (DROP TABLE IF EXISTS + DELETE).
-- Run it with the module already DISABLED (activator removed) so Magento does
-- not recreate the tables or the defaults when it reloads the config.
-- ============================================================================

-- Disable FK checks so the DROP order does not matter (there are bridge tables
-- with FKs to the badge tables and to catalog_category_entity).
SET FOREIGN_KEY_CHECKS = 0;

-- Bridge tables (FK to badges / categories) and transient ones first.
DROP TABLE IF EXISTS `@PREFIX@mibizum_sync_attribute_badge_categories`;
DROP TABLE IF EXISTS `@PREFIX@mibizum_sync_nature_badge_categories`;
DROP TABLE IF EXISTS `@PREFIX@mibizum_sync_badge_palette`;

-- Main module tables.
DROP TABLE IF EXISTS `@PREFIX@mibizum_sync_attribute_badges`;
DROP TABLE IF EXISTS `@PREFIX@mibizum_sync_nature_badges`;
DROP TABLE IF EXISTS `@PREFIX@mibizum_sync_system_badge_overrides`;
DROP TABLE IF EXISTS `@PREFIX@mibizum_sync_attribute_config`;
DROP TABLE IF EXISTS `@PREFIX@mibizum_sync_index_queue`;

SET FOREIGN_KEY_CHECKS = 1;

-- Module configuration (includes connection/*, sync/*, frontend/*, badges/*,
-- testing/*, general/*, reindex/* and the mibizum_sync_badges/* section).
DELETE FROM `@PREFIX@core_config_data`
 WHERE path LIKE 'mibizum_sync/%'
    OR path LIKE 'mibizum_sync_badges/%';

-- Setup resource version row (if it is not removed, Magento thinks the module
-- is still installed at a specific version).
DELETE FROM `@PREFIX@core_resource`
 WHERE code = 'mibizum_sync_setup';
