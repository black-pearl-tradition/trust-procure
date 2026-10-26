<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

$id = (int) ($_GET['id'] ?? 0);

$supplier = get_supplier_for_user((int) $_SESSION['user_id']);
if (!$supplier) {
    set_flash('error', 'Supplier profile not found.');
    redirect('/supplier/purchase_orders/index.php');
}

$stmt = $pdo->prepare(
    "SELECT po.*, s.name AS supplier_name, s.email AS supplier_email, s.phone AS supplier_phone,
        s.address AS supplier_address
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     WHERE po.id = ? AND po.supplier_id = ?"
);
$stmt->execute([$id, $supplier['id']]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Purchase order not found.');
    redirect('/supplier/purchase_orders/index.php');
}

$itemsStmt = $pdo->prepare(
    'SELECT poi.*, p.name AS product_name, p.slug AS product_slug
     FROM purchase_order_items poi
     JOIN products p ON p.id = poi.product_id
     WHERE poi.purchase_order_id = ? ORDER BY poi.id'
);
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($po['po_number']) ?> — Purchase Order</title>
    <style>
        :root {
            color: #16233F;
            font-family: Arial, sans-serif;
        }

        body {
            margin: 0;
            background: #EEF2F7;
        }

        .sheet {
            max-width: 900px;
            margin: 32px auto;
            padding: 52px;
            background: #fff;
            box-shadow: 0 12px 40px rgba(22, 35, 63, .12);
        }

        header {
            display: flex;
            justify-content: space-between;
            gap: 32px;
            border-bottom: 3px solid #B8863B;
            padding-bottom: 28px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        h2 {
            margin: 0 0 8px;
            font-size: 16px;
        }

        p {
            margin: 5px 0;
            color: #4B5567;
        }

        .meta {
            text-align: right;
        }

        .eyebrow {
            color: #93692A;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 36px;
        }

        th,
        td {
            padding: 13px 10px;
            border-bottom: 1px solid #E4E8EF;
            text-align: left;
        }

        th {
            color: #4B5567;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .number {
            text-align: right;
        }

        .totals {
            width: 320px;
            margin: 28px 0 0 auto;
        }

        .totals div {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }

        .grand-total {
            border-top: 2px solid #16233F;
            font-size: 18px;
            font-weight: bold;
        }

        .notes {
            margin-top: 36px;
            padding: 18px;
            background: #F6F7F9;
        }

        .actions {
            max-width: 900px;
            margin: 20px auto;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        button,
        a {
            border: 0;
            border-radius: 999px;
            padding: 11px 18px;
            color: #fff;
            background: #16233F;
            text-decoration: none;
            cursor: pointer;
        }

        a {
            background: #B8863B;
        }

        @media print {
            body {
                background: #fff;
            }

            .sheet {
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .actions {
                display: none;
            }
        }

        @media (max-width: 640px) {
            .sheet {
                margin: 0;
                padding: 24px;
            }

            header {
                display: block;
            }

            .meta {
                margin-top: 20px;
                text-align: left;
            }

            .totals {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="actions">
        <a href="<?= url('/supplier/purchase_orders/view.php?id=' . $po['id']) ?>">Back to PO</a>
        <button type="button" onclick="window.print()">Print / Save PDF</button>
    </div>
    <main class="sheet">
        <header>
            <div>
                <div class="eyebrow">Trust Procurement</div>
                <h1>Purchase Order</h1>
                <p><?= e($po['po_number']) ?></p>
            </div>
            <div class="meta">
                <h2><?= e($po['supplier_name']) ?></h2>
                <?php if ($po['supplier_email']): ?><p><?= e($po['supplier_email']) ?></p><?php endif; ?>
                <?php if ($po['supplier_phone']): ?><p><?= e($po['supplier_phone']) ?></p><?php endif; ?>
                <?php if ($po['supplier_address']): ?><p><?= e($po['supplier_address']) ?></p><?php endif; ?>
            </div>
        </header>
        <p style="margin-top: 24px;"><strong>Expected delivery:</strong> <?= $po['expected_delivery_date'] ? e(display_date($po['expected_delivery_date'], 'd M Y')) : 'To be confirmed' ?></p>
        <p style="margin-top: 12px;"><strong>Supplier response:</strong>
            <span style="display:inline-block; margin-left: 8px; padding: 4px 10px; border-radius: 999px; background: <?= $po['supplier_response'] === 'acknowledged' ? '#DFF5E7' : ($po['supplier_response'] === 'declined' ? '#FDE8E8' : '#FFF1D6') ?>; color: <?= $po['supplier_response'] === 'acknowledged' ? '#116530' : ($po['supplier_response'] === 'declined' ? '#8A1F1F' : '#9A6500') ?>; font-weight: 600;">
                <?= e(supplier_response_label($po['supplier_response'] ?? 'pending')) ?>
            </span>
        </p>
        <?php if (!empty($po['supplier_response_notes'])): ?>
            <p style="margin-top: 8px;"><strong>Supplier note:</strong> <?= nl2br(e($po['supplier_response_notes'])) ?></p>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="number">Quantity</th>
                    <th class="number">Unit cost</th>
                    <th class="number">Line total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td class="number"><?= (int) $item['quantity_ordered'] ?></td>
                        <td class="number"><?= money($item['unit_cost']) ?></td>
                        <td class="number"><?= money($item['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="totals">
            <div><span>Subtotal</span><strong><?= money($po['subtotal']) ?></strong></div>
            <div><span>Additional costs</span><strong><?= money($po['additional_costs']) ?></strong></div>
            <div class="grand-total"><span>Grand total</span><strong><?= money($po['grand_total']) ?></strong></div>
        </div>
        <?php if ($po['notes']): ?><div class="notes"><strong>Notes</strong>
                <p><?= nl2br(e($po['notes'])) ?></p>
            </div><?php endif; ?>
    </main>
</body>

</html>