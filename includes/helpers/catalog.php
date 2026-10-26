<?php

/** Generate the next human-readable PO number for the current year, e.g. PO-2026-000001. */
function generate_po_number(PDO $pdo): string
{
    $prefix = 'PO-' . date('Y') . '-';
    $stmt = $pdo->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $next = 1;
    if ($last) {
        $next = (int) substr($last, strlen($prefix)) + 1;
    }

    return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/** Recalculate a draft PO's subtotal and grand total from its line items. */
function recalculate_po_totals(PDO $pdo, int $poId): void
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(line_total), 0) FROM purchase_order_items WHERE purchase_order_id = ?'
    );
    $stmt->execute([$poId]);
    $subtotal = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT additional_costs FROM purchase_orders WHERE id = ?');
    $stmt->execute([$poId]);
    $additionalCosts = (float) $stmt->fetchColumn();

    $grandTotal = $subtotal + $additionalCosts;

    $pdo->prepare('UPDATE purchase_orders SET subtotal = ?, grand_total = ? WHERE id = ?')
        ->execute([$subtotal, $grandTotal, $poId]);
}

/** Human-readable label + badge tone for a product's stock status. */
function stock_status_label(string $status): string
{
    $labels = [
        'in_stock'      => 'In Stock',
        'out_of_stock'  => 'Out of Stock',
        'made_to_order' => 'Made to Order',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function stock_status_badge_class(string $status): string
{
    return [
        'in_stock'      => 'badge-success',
        'out_of_stock'  => 'badge-danger',
        'made_to_order' => 'badge-warning',
    ][$status] ?? 'badge-neutral';
}

/** Average rating + count of approved reviews for a product. */
function get_product_rating(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS review_count
         FROM product_reviews WHERE product_id = ? AND status = 'approved'"
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    return ['avg' => round((float) $row['avg_rating'], 1), 'count' => (int) $row['review_count']];
}

/** Render a static star rating (no interactivity) as plain HTML. */
function render_stars(float $avg, int $count = 0, bool $showCount = true): string
{
    $full = (int) floor($avg);
    $half = ($avg - $full) >= 0.5;
    $html = '<span class="stars" aria-label="' . e((string) $avg) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $full) {
            $html .= '<span class="star star-full">★</span>';
        } elseif ($half && $i === $full + 1) {
            $html .= '<span class="star star-half">★</span>';
        } else {
            $html .= '<span class="star star-empty">★</span>';
        }
    }
    $html .= '</span>';
    if ($showCount) {
        $html .= $count > 0
            ? ' <span class="rating-count">' . number_format($avg, 1) . ' (' . $count . ' review' . ($count === 1 ? '' : 's') . ')</span>'
            : ' <span class="rating-count muted">No reviews yet</span>';
    }
    return $html;
}

/** The list of product IDs currently in the visitor's comparison list. */
function get_compare_list(): array
{
    return array_map('intval', $_SESSION['compare'] ?? []);
}

/** Maximum products allowed in a single comparison. */
define('COMPARE_LIMIT', 4);

/** Current page number from the query string (1-indexed, never below 1). */
function current_page(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

/**
 * Render a row of pagination links. $baseParams are the current query
 * params to preserve (e.g. ['q' => $search, 'tab' => $tab]) — 'page' is
 * added/overwritten automatically for each link.
 */
function render_pagination(int $page, int $totalPages, string $path, array $baseParams = []): void
{
    if ($totalPages <= 1) {
        return;
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    echo '<div class="pagination">';

    if ($page > 1) {
        $params = array_merge($baseParams, ['page' => $page - 1]);
        echo '<a href="' . e(url($path . '?' . http_build_query($params))) . '" class="btn btn-outline btn-sm">Previous</a>';
    }

    if ($start > 1) {
        $params = array_merge($baseParams, ['page' => 1]);
        echo '<a href="' . e(url($path . '?' . http_build_query($params))) . '" class="btn btn-outline btn-sm">1</a>';
        if ($start > 2) echo '<span class="muted">…</span>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $params = array_merge($baseParams, ['page' => $i]);
        $active = $i === $page ? 'primary' : 'outline';
        echo '<a href="' . e(url($path . '?' . http_build_query($params))) . '" class="btn btn-' . $active . ' btn-sm">' . $i . '</a>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo '<span class="muted">…</span>';
        $params = array_merge($baseParams, ['page' => $totalPages]);
        echo '<a href="' . e(url($path . '?' . http_build_query($params))) . '" class="btn btn-outline btn-sm">' . $totalPages . '</a>';
    }

    if ($page < $totalPages) {
        $params = array_merge($baseParams, ['page' => $page + 1]);
        echo '<a href="' . e(url($path . '?' . http_build_query($params))) . '" class="btn btn-outline btn-sm">Next</a>';
    }

    echo '</div>';
}

/**
 * Normalise a staff-entered product code (SKU or barcode) for storage.
 *
 * Returns [value, error]. A blank field becomes null rather than an empty
 * string, because both columns carry a UNIQUE index and MySQL allows any number
 * of NULLs but only one empty string — storing '' would make the second
 * product with no code fail.
 *
 * A code is rejected when it is already used as *either* an SKU or a barcode on
 * another product, not just the same column. The till looks a scanned code up
 * across both columns at once (pos_find_product), so a code that appears in the
 * "wrong" column would make a scan ambiguous and could sell the wrong product.
 *
 * $ignoreProductId is the product being edited, so saving it unchanged is fine.
 *
 * @return array{0: ?string, 1: string}
 */
function normalise_product_code(PDO $pdo, string $raw, string $label, int $ignoreProductId = 0): array
{
    $value = trim($raw);

    if ($value === '') {
        return [null, ''];
    }

    if (mb_strlen($value) > 60) {
        return [null, $label . ' must be 60 characters or fewer.'];
    }

    $sql = 'SELECT id, name FROM products WHERE (sku = ? OR barcode = ?)';
    $params = [$value, $value];
    if ($ignoreProductId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $ignoreProductId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clash = $stmt->fetch();

    if ($clash) {
        return [null, $label . ' "' . $value . '" is already used by "' . $clash['name'] . '".'];
    }

    return [$value, ''];
}

/** True when the SKU/barcode columns exist, so the forms can offer them. */
function product_codes_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
               AND COLUMN_NAME IN ('sku', 'barcode')"
        );
        $available = (int) $stmt->fetchColumn() === 2;
    }

    return $available;
}
