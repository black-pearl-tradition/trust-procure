<?php

/**
 * Live database smoke test.
 *
 * Runs the statements the supplier portal (and the paginated admin lists)
 * depend on against the real schema, inside a transaction that is always rolled
 * back. This is what catches SQL that does not match the database — wrong column
 * names, mixed placeholder styles — which the string-based tests cannot see.
 * Skips itself when no database is reachable.
 */

$dsnHost = getenv('DB_HOST') ?: 'localhost';
$dsnName = getenv('DB_NAME') ?: 'tr';
$dsnUser = getenv('DB_USER') ?: 'root';
$dsnPass = getenv('DB_PASS') ?: '';

try {
    new PDO(
        'mysql:host=' . $dsnHost . ';port=3306;dbname=' . $dsnName . ';charset=utf8mb4',
        $dsnUser,
        $dsnPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo 'SKIP: no database reachable (' . $e->getMessage() . ')' . PHP_EOL;
    exit(0);
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

$failures = 0;

function ok(string $label, callable $fn)
{
    global $failures;

    try {
        $value = $fn();
        echo 'PASS  ' . $label . PHP_EOL;
        return $value;
    } catch (Throwable $e) {
        $failures++;
        echo 'FAIL  ' . $label . ' -> ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
        return null;
    }
}

$pdo->beginTransaction();

$poId = (int) $pdo->query('SELECT id FROM purchase_orders ORDER BY id LIMIT 1')->fetchColumn();
$supplierId = (int) $pdo->query('SELECT supplier_id FROM purchase_orders ORDER BY id LIMIT 1')->fetchColumn();
$userId = (int) $pdo->query("SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
echo "fixtures: po=$poId supplier=$supplierId user=$userId" . PHP_EOL;

ok('supplier dashboard counts', function () use ($pdo, $supplierId) {
    $pdo->prepare('SELECT COUNT(*) FROM supplier_products WHERE supplier_id = ?')->execute([$supplierId]);
    $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status = "received"')->execute([$supplierId]);
    $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status IN ("submitted", "approved", "sent", "partially_received")')->execute([$supplierId]);
    $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status = "sent" AND supplier_response = "pending"')->execute([$supplierId]);
    $pdo->prepare('SELECT po.*, (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count FROM purchase_orders po WHERE po.supplier_id = ? ORDER BY po.created_at DESC LIMIT 5')->execute([$supplierId]);
});

ok('supplier products query (reworked join)', function () use ($pdo, $supplierId) {
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, sp.supplier_price, sp.warranty_covered, sp.lead_time_days, sp.notes AS supply_notes,
            (SELECT COUNT(*) FROM purchase_order_items poi
             JOIN purchase_orders po ON po.id = poi.purchase_order_id
             WHERE poi.product_id = p.id AND po.supplier_id = ?) AS times_ordered
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         JOIN supplier_products sp ON sp.product_id = p.id AND sp.supplier_id = ?
         ORDER BY p.name");
    $stmt->execute([$supplierId, $supplierId]);
    return $stmt->fetchAll();
});

ok('supplier profile update', function () use ($pdo, $supplierId) {
    $pdo->prepare('UPDATE suppliers SET name = ?, contact_person = ?, phone = ?, address = ?, notes = ? WHERE id = ?')
        ->execute(['Live Check Ltd', 'Contact', '0700', 'Address', 'Notes', $supplierId]);
});

ok('PO list: pending-response preset', function () use ($pdo, $supplierId) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = ? AND po.status = "sent" AND po.supplier_response = "pending"');
    $stmt->execute([$supplierId]);
});

ok('supplier PO list: search + date range + paging', function () use ($pdo, $supplierId) {
    $where = ['po.supplier_id = ?', 'po.po_number LIKE ?', 'po.created_at >= ?', 'po.created_at <= ?'];
    $params = [$supplierId, '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], 'PO') . '%', '2000-01-01 00:00:00', '2999-12-31 23:59:59'];
    $stmt = $pdo->prepare("SELECT po.*,
            (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count,
            (SELECT COALESCE(SUM(poi.quantity_received), 0) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS total_received
         FROM purchase_orders po WHERE " . implode(' AND ', $where) . ' ORDER BY po.created_at DESC LIMIT ? OFFSET ?');
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue(count($params) + 1, 15, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, 0, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
});

ok('admin products list: search + paging', function () use ($pdo) {
    $params = ['%a%', '%a%'];
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.name LIKE ? OR p.brand LIKE ? ORDER BY p.created_at DESC LIMIT ? OFFSET ?');
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue(count($params) + 1, 15, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, 0, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
});

ok('admin PO list: status + supplier filter + paging', function () use ($pdo, $supplierId) {
    $params = ['sent', $supplierId];
    $stmt = $pdo->prepare('SELECT po.*, s.name AS supplier_name,
            (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count
         FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
         WHERE po.status = ? AND po.supplier_id = ? ORDER BY po.created_at DESC LIMIT ? OFFSET ?');
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue(count($params) + 1, 15, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, 0, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
});

ok('admin users list: search + role filter + paging', function () use ($pdo) {
    $params = ['%a%', '%a%', 'staff'];
    $stmt = $pdo->prepare('SELECT * FROM users WHERE deleted_at IS NULL AND (full_name LIKE ? OR email LIKE ?) AND role = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue(count($params) + 1, 20, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, 0, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
});

$GLOBALS['docId'] = 0;

ok('acknowledge update (with proposed delivery date)', function () use ($pdo, $poId) {
    $pdo->prepare("UPDATE purchase_orders
         SET supplier_response = 'acknowledged',
             supplier_response_at = NOW(),
             supplier_response_notes = ?,
             supplier_proposed_delivery_date = ?
         WHERE id = ?")->execute(['live check note', '2026-12-01', $poId]);
});

ok('decline update (clears the proposed date)', function () use ($pdo, $poId) {
    $pdo->prepare("UPDATE purchase_orders
         SET supplier_response = 'declined',
             supplier_response_at = NOW(),
             supplier_response_notes = ?,
             supplier_proposed_delivery_date = NULL
         WHERE id = ?")->execute(['live check decline', $poId]);
});

ok('withdraw update (resets everything)', function () use ($pdo, $poId) {
    $pdo->prepare("UPDATE purchase_orders
         SET supplier_response = 'pending',
             supplier_response_at = NULL,
             supplier_response_notes = NULL,
             supplier_proposed_delivery_date = NULL
         WHERE id = ?")->execute([$poId]);
});

ok('supplier PO view: po, items and response columns', function () use ($pdo, $poId, $supplierId) {
    $stmt = $pdo->prepare('SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = ? AND po.supplier_id = ?');
    $stmt->execute([$poId, $supplierId]);
    $po = $stmt->fetch();
    if (!$po) {
        throw new RuntimeException('purchase order not found');
    }
    foreach (['supplier_proposed_delivery_date', 'supplier_response', 'supplier_response_at', 'supplier_response_notes', 'expected_delivery_date'] as $column) {
        if (!array_key_exists($column, $po)) {
            throw new RuntimeException('missing column ' . $column);
        }
    }
    $pdo->prepare('SELECT poi.*, p.name AS product_name, p.slug AS product_slug FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE poi.purchase_order_id = ? ORDER BY poi.id')->execute([$poId]);
    return $po;
});

ok('document insert (exact statement from upload_document.php)', function () use ($pdo, $poId, $userId) {
    $pdo->prepare('INSERT INTO purchase_order_documents
            (purchase_order_id, uploaded_by, document_type, original_name, stored_name, mime_type, file_size)
         VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$poId, $userId, 'quote', 'live-check.pdf', str_repeat('a', 32) . '.pdf', 'application/pdf', 1234]);
    $GLOBALS['docId'] = (int) $pdo->lastInsertId();
});

ok('document listing (supplier + admin panel)', function () use ($poId) {
    if ((int) $GLOBALS['docId'] === 0) {
        throw new RuntimeException('no document was inserted');
    }
    if (count(po_documents_for_order($poId)) < 1) {
        throw new RuntimeException('document was not listed');
    }
});

ok('find_po_document + ownership check', function () use ($supplierId) {
    $document = find_po_document((int) $GLOBALS['docId']);
    if (!$document) {
        throw new RuntimeException('document not resolved');
    }
    if ((int) $document['supplier_id'] !== $supplierId) {
        throw new RuntimeException('document resolved to the wrong supplier');
    }
});

ok('link supplier to a login account + notify_supplier', function () use ($pdo, $supplierId, $userId, $poId) {
    $pdo->prepare("UPDATE users SET role = 'supplier' WHERE id = ?")->execute([$userId]);
    $email = (string) $pdo->query('SELECT email FROM users WHERE id = ' . $userId)->fetchColumn();
    $pdo->prepare('UPDATE suppliers SET email = ? WHERE id = ?')->execute([$email, $supplierId]);
    if (get_supplier_user_id($supplierId) !== $userId) {
        throw new RuntimeException('linked account did not resolve');
    }
    notify_supplier($supplierId, 'Live check', 'Live check notification', '/supplier/purchase_orders/view.php?id=' . $poId);
    $created = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . $userId . " AND title = 'Live check'")->fetchColumn();
    if ($created !== 1) {
        throw new RuntimeException('notification was not created');
    }
});

ok('supplier notifications page query', function () use ($pdo, $userId) {
    $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50')->execute([$userId]);
});

ok('accept proposed delivery date (admin)', function () use ($pdo, $poId) {
    $stmt = $pdo->prepare("SELECT id, supplier_id, po_number, supplier_proposed_delivery_date FROM purchase_orders WHERE id = ? AND status IN ('sent', 'partially_received')");
    $stmt->execute([$poId]);
    $stmt->fetch();
    $pdo->prepare('UPDATE purchase_orders SET expected_delivery_date = ? WHERE id = ?')->execute([null, $poId]);
});

ok('audit log columns', function () use ($pdo, $userId) {
    log_activity($pdo, $userId, 'live_check', 'Live check activity entry');
});

ok('admin supplier detail page queries', function () use ($pdo, $supplierId) {
    $pdo->prepare('SELECT * FROM suppliers WHERE id = ?')->execute([$supplierId]);
    $pdo->prepare('SELECT email FROM users WHERE id = ?')->execute([1]);
    $pdo->prepare('SELECT sp.*, p.name AS product_name FROM supplier_products sp JOIN products p ON p.id = sp.product_id WHERE sp.supplier_id = ? ORDER BY p.name')->execute([$supplierId]);
    $pdo->prepare('SELECT po.*, (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count FROM purchase_orders po WHERE po.supplier_id = ? ORDER BY po.created_at DESC')->execute([$supplierId]);
});

$pdo->rollBack();

if ($failures === 0) {
    echo 'All live database checks passed (transaction rolled back).' . PHP_EOL;
    exit(0);
}

echo 'FAILURES: ' . $failures . PHP_EOL;
exit(1);
