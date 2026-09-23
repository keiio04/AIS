<?php
require_once 'db.php';
$db = get_db();
$result = $db->query('DESCRIBE suppliers');
while ($r = $result->fetch_assoc()) {
    echo $r['Field'] . ' | ' . $r['Type'] . "\n";
}
