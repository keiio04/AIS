<?php
require_once 'db.php';
$db = get_db();
$result = $db->query('DESCRIBE journal_entries');
while ($r = $result->fetch_assoc()) {
    echo $r['Field'] . ' | ' . $r['Type'] . "\n";
}
