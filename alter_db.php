<?php
require_once 'db.php';
$db = get_db();

try {
    $db->query("ALTER TABLE journal_entries ADD COLUMN journal_id VARCHAR(5) NOT NULL DEFAULT 'GJ' AFTER type");
    echo "Added journal_id.\n";
} catch (Exception $e) {
    echo "Error 3: " . $e->getMessage() . "\n";
}
echo "Done.";
