<?php
require_once 'db.php';
$db = get_db();
try {
    $db->query("ALTER TABLE journal_entries ADD COLUMN tax_type VARCHAR(50) NULL AFTER description");
    echo "Added tax_type.\n";
} catch (Exception $e) {
    echo "Error 1: " . $e->getMessage() . "\n";
}
try {
    $db->query("ALTER TABLE journal_entries ADD COLUMN vendor_name VARCHAR(150) NULL AFTER tax_type");
    echo "Added vendor_name.\n";
} catch (Exception $e) {
    echo "Error 2: " . $e->getMessage() . "\n";
}
echo "Done.";
