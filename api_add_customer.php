<?php
require_once 'config.php';
require_once 'db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = get_db();
$company_id = $_SESSION['active_company_id'] ?? null;

if (!$company_id) {
    echo json_encode(['success' => false, 'error' => 'No active company']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Customer name is required']);
        exit;
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO customers (company_id, name) VALUES (?, ?)");
        $stmt->bind_param('is', $company_id, $name);
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to create customer']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
}
?>
