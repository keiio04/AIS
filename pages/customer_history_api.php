<?php
// ============================================================
// customer_history_api.php
// Returns one customer's Sales Invoices + Payments as JSON.
// This endpoint also makes sure the AR invoice-tracking schema
// exists so the Customers page can work even before the user
// opens the Sales Journal / Cash Receipts Journal pages.
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// This is a JSON endpoint. PHP warnings/notices must not be printed
// into the response because they would make JSON.parse() fail.
ini_set('display_errors', '0');

function history_json_error(string $message, int $status = 500): void
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error'   => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = get_db();

    $company_id  = (int)($_SESSION['active_company_id'] ?? 0);
    $customer_id = (int)($_GET['id'] ?? 0);

    if (!$company_id) {
        history_json_error('No active company selected.', 400);
    }

    if (!$customer_id) {
        history_json_error('Missing customer id.', 400);
    }

    // ------------------------------------------------------------
    // Defensive schema sync
    // ------------------------------------------------------------
    // Older installs may have the first/basic version of
    // sales_invoices. The history API needs the newer AR fields too.
    try {
        $db->query("\n            CREATE TABLE IF NOT EXISTS sales_invoices (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                company_id INT NOT NULL,\n                journal_entry_id INT NOT NULL,\n                customer_id INT NOT NULL,\n                invoice_seq INT NULL,\n                invoice_no VARCHAR(60) NOT NULL,\n                invoice_date DATE NOT NULL,\n                amount DECIMAL(15,2) NOT NULL DEFAULT 0,\n                amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,\n                status ENUM('Open','Partially Paid','Paid','Cancelled') NOT NULL DEFAULT 'Open',\n                cancellation_reason VARCHAR(255) NULL,\n                cancelled_at TIMESTAMP NULL,\n                reversal_entry_id INT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_company (company_id),\n                INDEX idx_customer (customer_id),\n                INDEX idx_journal (journal_entry_id)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");
    } catch (Throwable $e) {}

    try { $db->query("ALTER TABLE journal_entries ADD COLUMN invoice_id INT NULL DEFAULT NULL AFTER entity_type"); } catch (Throwable $e) {}
    try { $db->query("ALTER TABLE sales_invoices ADD COLUMN invoice_seq INT NULL AFTER customer_id"); } catch (Throwable $e) {}
    try { $db->query("ALTER TABLE sales_invoices ADD COLUMN cancellation_reason VARCHAR(255) NULL AFTER status"); } catch (Throwable $e) {}
    try { $db->query("ALTER TABLE sales_invoices ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER cancellation_reason"); } catch (Throwable $e) {}
    try { $db->query("ALTER TABLE sales_invoices ADD COLUMN reversal_entry_id INT NULL AFTER cancelled_at"); } catch (Throwable $e) {}
    try { $db->query("ALTER TABLE sales_invoices MODIFY COLUMN status ENUM('Open','Partially Paid','Paid','Cancelled') NOT NULL DEFAULT 'Open'"); } catch (Throwable $e) {}

    // ------------------------------------------------------------
    // Customer + current AR balance
    // ------------------------------------------------------------
    $stmt = $db->prepare("\n        SELECT\n            c.id,\n            c.code,\n            c.name,\n            c.opening_balance,\n            (\n                c.opening_balance +\n                COALESCE((\n                    SELECT SUM(l.debit - l.credit)\n                    FROM journal_entry_lines l\n                    JOIN journal_entries e ON l.journal_entry_id = e.id\n                    JOIN accounts a ON l.account_id = a.id\n                    WHERE e.entity_id = c.id\n                      AND e.entity_type = 'customer'\n                      AND e.deleted_at IS NULL\n                      AND a.name LIKE '%receivable%'\n                ), 0)\n            ) AS current_balance\n        FROM customers c\n        WHERE c.id = ? AND c.company_id = ?\n        LIMIT 1\n    ");

    $stmt->bind_param('ii', $customer_id, $company_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();

    if (!$customer) {
        history_json_error('Customer not found.', 404);
    }

    // ------------------------------------------------------------
    // Keep invoice paid amounts/status in sync with actual CRJ
    // collections linked through journal_entries.invoice_id.
    // ------------------------------------------------------------
    try {
        $syncStmt = $db->prepare("\n            UPDATE sales_invoices si\n            SET\n                amount_paid = COALESCE((\n                    SELECT SUM(l.credit)\n                    FROM journal_entries e\n                    JOIN journal_entry_lines l ON l.journal_entry_id = e.id\n                    JOIN accounts a ON l.account_id = a.id\n                    WHERE e.invoice_id = si.id\n                      AND e.journal_id = 'CRJ'\n                      AND e.deleted_at IS NULL\n                      AND a.name LIKE '%receivable%'\n                ), 0),\n                status = CASE\n                    WHEN si.status = 'Cancelled' THEN 'Cancelled'\n                    WHEN COALESCE((\n                        SELECT SUM(l.credit)\n                        FROM journal_entries e\n                        JOIN journal_entry_lines l ON l.journal_entry_id = e.id\n                        JOIN accounts a ON l.account_id = a.id\n                        WHERE e.invoice_id = si.id\n                          AND e.journal_id = 'CRJ'\n                          AND e.deleted_at IS NULL\n                          AND a.name LIKE '%receivable%'\n                    ), 0) >= si.amount AND si.amount > 0 THEN 'Paid'\n                    WHEN COALESCE((\n                        SELECT SUM(l.credit)\n                        FROM journal_entries e\n                        JOIN journal_entry_lines l ON l.journal_entry_id = e.id\n                        JOIN accounts a ON l.account_id = a.id\n                        WHERE e.invoice_id = si.id\n                          AND e.journal_id = 'CRJ'\n                          AND e.deleted_at IS NULL\n                          AND a.name LIKE '%receivable%'\n                    ), 0) > 0 THEN 'Partially Paid'\n                    ELSE 'Open'\n                END\n            WHERE si.company_id = ?\n              AND si.customer_id = ?\n        ");
        $syncStmt->bind_param('ii', $company_id, $customer_id);
        $syncStmt->execute();
    } catch (Throwable $e) {
        // History should still load even if an older DB cannot sync yet.
    }

    $invoices = [];
    $payments = [];

    // ------------------------------------------------------------
    // 1. Sales Invoices
    // ------------------------------------------------------------
    $stmtInv = $db->prepare("\n        SELECT\n            si.id,\n            si.invoice_no,\n            si.invoice_date,\n            si.amount,\n            si.amount_paid,\n            si.status,\n            si.cancellation_reason,\n            je.description,\n            je.deleted_at\n        FROM sales_invoices si\n        LEFT JOIN journal_entries je\n            ON si.journal_entry_id = je.id\n        WHERE si.customer_id = ?\n          AND si.company_id = ?\n        ORDER BY si.invoice_date ASC, si.id ASC\n    ");

    $stmtInv->bind_param('ii', $customer_id, $company_id);
    $stmtInv->execute();
    $invoiceRows = $stmtInv->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($invoiceRows as $inv) {
        $amount     = (float)$inv['amount'];
        $amountPaid = (float)$inv['amount_paid'];
        $remaining  = max(0, round($amount - $amountPaid, 2));

        $status = !empty($inv['deleted_at'])
            ? 'Voided'
            : ($inv['status'] ?: 'Open');

        $description = trim((string)($inv['description'] ?? ''));
        if ($description === '') {
            $description = 'Sales Invoice';
        }

        if ($status === 'Cancelled' && !empty($inv['cancellation_reason'])) {
            $description .= ' - ' . $inv['cancellation_reason'];
        }

        $invoices[] = [
            'date'         => date('M d, Y', strtotime($inv['invoice_date'])),
            'reference_no' => $inv['invoice_no'],
            'description'  => $description,
            'amount'       => $amount,
            'amount_paid'  => $amountPaid,
            'remaining'    => $remaining,
            'status'       => $status,
        ];
    }

    // ------------------------------------------------------------
    // 2. Legacy SJ entries not yet represented in sales_invoices
    // ------------------------------------------------------------
    $stmtLegacy = $db->prepare("\n        SELECT\n            e.id,\n            e.reference_no,\n            e.date,\n            e.description,\n            e.deleted_at,\n            COALESCE((\n                SELECT SUM(l.debit)\n                FROM journal_entry_lines l\n                JOIN accounts a ON l.account_id = a.id\n                WHERE l.journal_entry_id = e.id\n                  AND a.name LIKE '%receivable%'\n            ), 0) AS total_amount\n        FROM journal_entries e\n        LEFT JOIN sales_invoices si\n            ON si.journal_entry_id = e.id\n        WHERE e.entity_id = ?\n          AND e.entity_type = 'customer'\n          AND e.company_id = ?\n          AND e.journal_id = 'SJ'\n          AND e.reference_no NOT LIKE 'CN-%'\n          AND si.id IS NULL\n        ORDER BY e.date ASC, e.id ASC\n    ");

    $stmtLegacy->bind_param('ii', $customer_id, $company_id);
    $stmtLegacy->execute();
    $legacyRows = $stmtLegacy->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($legacyRows as $row) {
        $amount = (float)$row['total_amount'];

        $invoices[] = [
            'date'         => date('M d, Y', strtotime($row['date'])),
            'reference_no' => $row['reference_no'],
            'description'  => $row['description'] ?: 'Sales Invoice',
            'amount'       => $amount,
            'amount_paid'  => 0.0,
            'remaining'    => $amount,
            'status'       => !empty($row['deleted_at']) ? 'Voided' : 'Open',
        ];
    }

    // ------------------------------------------------------------
    // 3. Customer Collections / Payments
    // ------------------------------------------------------------
    $stmtPay = $db->prepare("\n        SELECT\n            e.id,\n            e.reference_no,\n            e.date,\n            e.description,\n            e.deleted_at,\n            e.invoice_id,\n            COALESCE((\n                SELECT SUM(l.credit)\n                FROM journal_entry_lines l\n                JOIN accounts a ON l.account_id = a.id\n                WHERE l.journal_entry_id = e.id\n                  AND a.name LIKE '%receivable%'\n            ), (\n                SELECT SUM(l.debit)\n                FROM journal_entry_lines l\n                WHERE l.journal_entry_id = e.id\n            ), 0) AS total_amount,\n            si.invoice_no AS applied_invoice_no\n        FROM journal_entries e\n        LEFT JOIN sales_invoices si\n            ON e.invoice_id = si.id\n        WHERE e.entity_id = ?\n          AND e.entity_type = 'customer'\n          AND e.company_id = ?\n          AND e.journal_id = 'CRJ'\n        ORDER BY e.date ASC, e.id ASC\n    ");

    $stmtPay->bind_param('ii', $customer_id, $company_id);
    $stmtPay->execute();
    $paymentRows = $stmtPay->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($paymentRows as $payment) {
        $description = trim((string)($payment['description'] ?? ''));
        if ($description === '') {
            $description = 'Customer Collection';
        }

        if (!empty($payment['applied_invoice_no']) && stripos($description, $payment['applied_invoice_no']) === false) {
            $description .= ' - Invoice ' . $payment['applied_invoice_no'];
        }

        $payments[] = [
            'date'               => date('M d, Y', strtotime($payment['date'])),
            'reference_no'       => $payment['reference_no'],
            'description'        => $description,
            'amount'             => (float)$payment['total_amount'],
            'applied_invoice_no' => $payment['applied_invoice_no'],
            'status'             => !empty($payment['deleted_at']) ? 'Voided' : 'Paid',
        ];
    }

    // Keep invoice list chronological even when legacy rows were appended.
    usort($invoices, static function (array $a, array $b): int {
        $ta = strtotime($a['date']) ?: 0;
        $tb = strtotime($b['date']) ?: 0;
        return $ta <=> $tb;
    });

    echo json_encode([
        'success' => true,
        'customer' => [
            'id'   => (int)$customer['id'],
            'code' => $customer['code'],
            'name' => $customer['name'],
        ],
        'invoices'            => $invoices,
        'payments'            => $payments,
        'outstanding_balance' => (float)$customer['current_balance'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('customer_history_api.php: ' . $e->getMessage());
    history_json_error('Unable to load transaction history. Please refresh and try again.', 500);
}
