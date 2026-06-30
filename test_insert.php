<?php
require_once 'db.php';
$db = get_db();

echo "Testing DB connection...<br>";
echo "DB Name: " . DB_NAME . "<br>";

$ins = $db->prepare("INSERT INTO companies (user_id, name, address, business_type, period_type, fiscal_start_month, fiscal_start_date, fiscal_year_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

if (!$ins) {
    echo "Prepare failed: " . $db->error;
} else {
    echo "Prepare succeeded! Database structure is CORRECT.<br>";
}
?>
