<?php

/**
 * Resolve the supplier record for a user.
 *
 * The app links supplier accounts to supplier records by matching the user email
 * to the supplier email. There is no users.supplier_id column in the schema, so
 * code must not assume that relationship exists.
 */
function get_supplier_for_user(int $userId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT s.*
         FROM suppliers s
         JOIN users u ON u.email = s.email
         WHERE u.id = ?
           AND u.role = "supplier"
           AND u.status = "active"
           AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $supplier = $stmt->fetch();

    if ($supplier) {
        return $supplier;
    }

    $stmt = $pdo->prepare(
        'SELECT s.*
         FROM suppliers s
         JOIN users u ON u.email = s.email
         WHERE u.id = ?
           AND u.role = "supplier"
           AND u.status = "active"
           AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$userId]);

    return $stmt->fetch() ?: null;
}

/**
 * Resolve the login account bound to a supplier record.
 *
 * The binding is by email (see get_supplier_for_user), so only an account that
 * really is an active, non-deleted supplier is returned — anything missing,
 * ambiguous, or belonging to another role fails closed with null rather than
 * notifying the wrong person.
 */
function get_supplier_user_id(int $supplierId): ?int
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT u.id
         FROM suppliers s
         JOIN users u ON u.email = s.email
         WHERE s.id = ? AND u.role = "supplier" AND u.status = "active" AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$supplierId]);

    $userId = $stmt->fetchColumn();

    return $userId === false ? null : (int) $userId;
}

/**
 * Send an in-portal notification to a supplier's login account.
 *
 * Does nothing when the supplier has no linked portal account, so callers can
 * always pair it with an email without checking twice.
 */
function notify_supplier(int $supplierId, string $title, string $message, ?string $link = null): void
{
    $userId = get_supplier_user_id($supplierId);
    if ($userId === null) {
        return;
    }

    global $pdo;
    create_notification($pdo, $userId, $title, $message, $link);
}

/**
 * Calculate expected payment due date based on supplier payment terms and an anchor date.
 */
function calculate_po_payment_due_date(string $terms, ?string $anchorDate = null): ?string
{
    $anchor = $anchorDate ? strtotime($anchorDate) : time();
    if (!$anchor) {
        $anchor = time();
    }

    switch ($terms) {
        case 'cod':
            return date('Y-m-d', $anchor);
        case 'net_15':
            return date('Y-m-d', strtotime('+15 days', $anchor));
        case 'net_30':
            return date('Y-m-d', strtotime('+30 days', $anchor));
        case 'net_60':
            return date('Y-m-d', strtotime('+60 days', $anchor));
        case 'split_advance':
        case 'prepaid':
            return date('Y-m-d', $anchor);
        default:
            return date('Y-m-d', strtotime('+30 days', $anchor));
    }
}

/**
 * Recalculate purchase order payment totals and update payment_status.
 */
function recalculate_po_payment_status(PDO $pdo, int $poId): void
{
    $poStmt = $pdo->prepare('SELECT grand_total FROM purchase_orders WHERE id = ?');
    $poStmt->execute([$poId]);
    $grandTotal = (float) $poStmt->fetchColumn();

    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE purchase_order_id = ?');
    $sumStmt->execute([$poId]);
    $amountPaid = (float) $sumStmt->fetchColumn();

    $status = 'unpaid';
    if ($amountPaid >= $grandTotal && $grandTotal > 0) {
        $status = 'paid';
    } elseif ($amountPaid > 0) {
        $status = 'partially_paid';
    }

    $updateStmt = $pdo->prepare('UPDATE purchase_orders SET amount_paid = ?, payment_status = ? WHERE id = ?');
    $updateStmt->execute([$amountPaid, $status, $poId]);
}

function supplier_delivery_barcode_markup(string $code): string
{
    if (!preg_match('/^\d{10}$/', $code)) {
        return '';
    }

    $patterns = [
        '0' => 'nnnwwnwnn',
        '1' => 'wnnnwnnnw',
        '2' => 'nnwnwnnnw',
        '3' => 'wnwnwnnnn',
        '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn',
        '6' => 'nnwwwnnnn',
        '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn',
        '9' => 'nnwwnnwnn',
        '*' => 'nwnnwnwnn',
    ];
    $sequence = '*' . $code . '*';
    $x = 10;
    $bars = '';

    foreach (str_split($sequence) as $character) {
        foreach (str_split($patterns[$character]) as $index => $width) {
            $elementWidth = $width === 'w' ? 4 : 2;
            if ($index % 2 === 0) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $elementWidth . '" height="48"/>';
            }
            $x += $elementWidth;
        }
        $x += 2;
    }

    $width = $x + 8;
    return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Code 39 barcode for receiving code '
        . $code . '" viewBox="0 0 ' . $width . ' 48" width="' . $width . '" height="48">'
        . '<rect width="100%" height="100%" fill="#fff"/><g fill="#111">' . $bars . '</g></svg>';
}

/**
 * Summarize whether verified Goods Received Notes accept every PO line in full.
 */
function po_verified_grn_coverage(PDO $pdo, int $poId): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS line_count,
                COALESCE(SUM(CASE
                    WHEN COALESCE(grn.accepted_quantity, 0) >= poi.quantity_ordered THEN 1
                    ELSE 0
                END), 0) AS fully_accepted_lines
         FROM purchase_order_items poi
         LEFT JOIN (
             SELECT di.purchase_order_item_id, SUM(di.accepted_quantity) AS accepted_quantity
             FROM supplier_delivery_items di
             JOIN supplier_deliveries d ON d.id = di.delivery_id AND d.status = \'verified\'
             WHERE d.purchase_order_id = ?
             GROUP BY di.purchase_order_item_id
         ) grn ON grn.purchase_order_item_id = poi.id
         WHERE poi.purchase_order_id = ?'
    );
    $stmt->execute([$poId, $poId]);
    $coverage = $stmt->fetch();

    return [
        'line_count' => (int) ($coverage['line_count'] ?? 0),
        'fully_accepted_lines' => (int) ($coverage['fully_accepted_lines'] ?? 0),
    ];
}

/**
 * Compare an invoice's PO total and delivery evidence; this is not an itemized
 * invoice match because supplier invoices currently store only a total amount.
 */
function supplier_invoice_match_summary(array $invoice, float $grandTotal, array $grnCoverage): array
{
    if ($invoice['invoice_type'] === 'proforma') {
        return ['label' => 'Proforma — review only', 'class' => 'neutral', 'matched' => false];
    }
    if ($invoice['invoice_type'] === 'credit_note') {
        return ['label' => 'Credit note — manual review', 'class' => 'warning', 'matched' => false];
    }
    if (
        $grnCoverage['line_count'] === 0
        || $grnCoverage['fully_accepted_lines'] < $grnCoverage['line_count']
    ) {
        return ['label' => 'Awaiting full verified GRN', 'class' => 'warning', 'matched' => false];
    }
    if (abs((float) $invoice['total_amount'] - $grandTotal) > 0.01) {
        return ['label' => 'Invoice total differs from PO', 'class' => 'danger', 'matched' => false];
    }

    return ['label' => 'PO / GRN / invoice total match', 'class' => 'success', 'matched' => true];
}
