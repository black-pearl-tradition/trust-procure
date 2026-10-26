<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$type = $_GET['type'] ?? '';
$allowed = ['products', 'orders', 'requests', 'payments', 'suppliers'];

if (!in_array($type, $allowed, true)) {
    set_flash('error', 'Unknown report type.');
    redirect('/admin/reports/index.php');
}

$filename = 'trust-procurement-' . $type . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// UTF-8 BOM so the file opens correctly in Excel.
fwrite($out, "\xEF\xBB\xBF");

switch ($type) {
    case 'products':
        $rows = $pdo->query(
            "SELECT p.id, p.name, p.brand, c.name AS category, p.price, p.is_high_end, p.deposit_percentage, p.status, p.created_at
             FROM products p LEFT JOIN categories c ON c.id = p.category_id
             ORDER BY p.created_at DESC"
        )->fetchAll();
        fputcsv($out, ['ID', 'Name', 'Brand', 'Category', 'Price (UGX)', 'High-end', 'Deposit %', 'Status', 'Created']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['name'], $r['brand'], $r['category'] ?: 'Uncategorised',
                $r['price'], $r['is_high_end'] ? 'Yes' : 'No', $r['deposit_percentage'],
                ucfirst($r['status']), $r['created_at'],
            ]);
        }
        break;

    case 'requests':
        $rows = $pdo->query(
            "SELECT r.id, u.full_name, u.email, p.name AS product, r.quantity, r.status, r.resolution_note, r.created_at
             FROM requests r JOIN users u ON u.id = r.user_id JOIN products p ON p.id = r.product_id
             ORDER BY r.created_at DESC"
        )->fetchAll();
        fputcsv($out, ['ID', 'Customer', 'Email', 'Product', 'Quantity', 'Status', 'Note', 'Submitted']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['full_name'], $r['email'], $r['product'], $r['quantity'],
                request_status_label($r['status']), $r['resolution_note'], $r['created_at'],
            ]);
        }
        break;

    case 'orders':
        $rows = $pdo->query(
            "SELECT o.id, u.full_name, u.email, p.name AS product, o.quantity, o.total_amount, o.deposit_amount, o.balance_amount, o.status, o.created_at
             FROM orders o JOIN users u ON u.id = o.user_id JOIN products p ON p.id = o.product_id
             ORDER BY o.created_at DESC"
        )->fetchAll();
        fputcsv($out, ['Order ID', 'Customer', 'Email', 'Product', 'Quantity', 'Total (UGX)', 'Deposit (UGX)', 'Balance (UGX)', 'Status', 'Created']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['full_name'], $r['email'], $r['product'], $r['quantity'],
                $r['total_amount'], $r['deposit_amount'], $r['balance_amount'],
                order_status_label($r['status']), $r['created_at'],
            ]);
        }
        break;

    case 'payments':
        $rows = $pdo->query(
            "SELECT pay.id, u.full_name, u.email, pay.order_id, pay.payment_type, pay.amount, pay.method, pay.reference_note, pay.status, pay.created_at
             FROM payments pay JOIN users u ON u.id = pay.user_id
             ORDER BY pay.created_at DESC"
        )->fetchAll();
        fputcsv($out, ['ID', 'Customer', 'Email', 'Order ID', 'Type', 'Amount (UGX)', 'Method', 'Reference', 'Status', 'Submitted']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['full_name'], $r['email'], $r['order_id'], ucfirst($r['payment_type']),
                $r['amount'], $r['method'], $r['reference_note'],
                payment_status_label($r['status']), $r['created_at'],
            ]);
        }
        break;

    case 'suppliers':
        $rows = $pdo->query(
            "SELECT s.name, s.status, s.contact_person, s.phone, s.email,
                COUNT(o.id) AS order_count,
                COALESCE(SUM(o.total_amount), 0) AS total_revenue,
                COALESCE(SUM(o.supplier_cost), 0) AS total_cost,
                COALESCE(SUM(o.total_amount - COALESCE(o.supplier_cost, 0)), 0) AS total_margin
             FROM suppliers s
             LEFT JOIN orders o ON o.supplier_id = s.id
             GROUP BY s.id
             ORDER BY total_revenue DESC"
        )->fetchAll();
        fputcsv($out, ['Supplier', 'Status', 'Contact person', 'Phone', 'Email', 'Orders fulfilled', 'Order value (UGX)', 'Supplier cost (UGX)', 'Margin (UGX)']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['name'], ucfirst($r['status']), $r['contact_person'], $r['phone'], $r['email'],
                $r['order_count'], $r['total_revenue'], $r['total_cost'], $r['total_margin'],
            ]);
        }
        break;
}

fclose($out);
log_activity($pdo, $_SESSION['user_id'], 'report_exported', "Exported $type report as CSV");
exit;
