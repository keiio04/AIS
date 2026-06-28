<?php
require_once 'db.php';
$db = get_db();
try {
    $db->query("ALTER TABLE companies ADD COLUMN is_tax_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER fiscal_year_end");
    echo "Added is_tax_registered.\n";
} catch (Exception $e) {
    echo "Error 1: " . $e->getMessage() . "\n";
}
try {
    $db->query("ALTER TABLE companies ADD COLUMN tax_type ENUM('VAT', 'Percentage') NULL AFTER is_tax_registered");
    echo "Added tax_type.\n";
} catch (Exception $e) {
    echo "Error 2: " . $e->getMessage() . "\n";
}
try {
    $db->query("ALTER TABLE journal_entries ADD COLUMN journal_id VARCHAR(5) NOT NULL DEFAULT 'GJ' AFTER type");
    echo "Added journal_id.\n";
} catch (Exception $e) {
    echo "Error 3: " . $e->getMessage() . "\n";
}
echo "Done.";
