<?php
session_start();
require_once 'db.php';
$db = get_db();
$result = $db->query("SELECT id, name, tax_registered, tax_type FROM companies");
while ($r = $result->fetch_assoc()) {
    echo "ID: {$r['id']} | Name: {$r['name']} | Tax Registered: {$r['tax_registered']} | Tax Type: {$r['tax_type']}\n";
}
