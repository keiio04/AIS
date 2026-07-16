-- Tax snapshot fields for all journal entry modules.
-- Safe to run more than once on MySQL 8+/MariaDB versions supporting IF NOT EXISTS.

ALTER TABLE `journal_entries`
    ADD COLUMN IF NOT EXISTS `is_taxable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `description`,
    ADD COLUMN IF NOT EXISTS `tax_status` ENUM('taxable','non_taxable') NOT NULL DEFAULT 'non_taxable' AFTER `is_taxable`,
    ADD COLUMN IF NOT EXISTS `base_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `tax_status`,
    ADD COLUMN IF NOT EXISTS `vat_rate` DECIMAL(6,4) NOT NULL DEFAULT 0.0000 AFTER `base_amount`,
    ADD COLUMN IF NOT EXISTS `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `vat_rate`,
    ADD COLUMN IF NOT EXISTS `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`;
