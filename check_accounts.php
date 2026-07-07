<?php
require 'c:/xampp/htdocs/AIS_PHP/db.php';
$db = get_db();
$stmt = $db->prepare("SELECT name, category FROM accounts WHERE name LIKE '%Revenue%' OR name LIKE '%Receivable%'");
$stmt->execute();
$res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
print_r($res);
