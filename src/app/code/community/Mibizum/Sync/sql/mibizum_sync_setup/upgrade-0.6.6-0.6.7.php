<?php
/**
 * Mibizum_Sync 0.6.6 -> 0.6.7 (no-op DB upgrade).
 *
 * Security hardening only, no DB schema change:
 *   - Every state-changing admin action (Nature / Attribute badge / Attribute
 *     config save, delete and mass actions; System badge save and mass enable/
 *     disable; Reindex full/drain) now enforces POST + a valid form_key on top
 *     of the admin secret URL key (CSRF defense-in-depth).
 *   - The per-badge / per-attribute "Delete" button in the edit forms now
 *     submits via a POST form carrying the form_key instead of a plain GET
 *     link, so a destructive delete can no longer be triggered by a crafted GET.
 *
 * Nothing to migrate in the database; this only closes the version chain in
 * core_resource (data version -> 0.6.7).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
