<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

// Make sure a token exists before any form on the page is rendered.
csrf_token();

$search = trim((string) ($_GET['q'] ?? ''));
$categoryId = (int) ($_GET['category'] ?? 0);
$customerQuery = trim((string) ($_GET['cust_q'] ?? ''));

$categories = pos_categories($pdo);
$products = pos_search_products($pdo, $search, $categoryId, 60);
$posState = pos_cart_state($pdo);
$posCustomerResults = $customerQuery !== '' ? pos_search_customers($pdo, $customerQuery) : [];

$schemaReady = pos_schema_ready($pdo);
$walletsReady = pos_wallets_ready($pdo);

$pageTitle = 'Point of Sale';

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<style>
    .pos-shell {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 380px;
        gap: 20px;
        align-items: start;
    }

    .pos-toolbar {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 14px;
    }

    .pos-toolbar form {
        display: flex;
        gap: 8px;
        flex: 1;
        min-width: 260px;
    }

    .pos-toolbar input[type="text"],
    .pos-toolbar input[type="search"] {
        flex: 1;
    }

    /* Scanning is what a till does all day, so the scan box keeps a fixed share
       of the toolbar and the search box takes whatever is left. Without this
       both forms would split the row evenly and the scan input would be too
       narrow to read a barcode comfortably. */
    .pos-toolbar .pos-scan-form {
        flex: 0 1 340px;
        min-width: 230px;
    }

    .pos-toolbar .pos-search-form {
        flex: 1 1 260px;
        min-width: 210px;
    }

    .pos-chips {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .pos-chip {
        padding: 6px 12px;
        border: 1px solid var(--border, #e5e7eb);
        border-radius: 999px;
        font-size: 0.82rem;
        background: #fff;
        text-decoration: none;
        color: inherit;
    }

    .pos-chip.is-active {
        background: var(--accent, #111827);
        color: #fff;
        border-color: var(--accent, #111827);
    }

    .pos-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
        gap: 12px;
    }

    .pos-card {
        border: 1px solid var(--border, #e5e7eb);
        border-radius: 10px;
        padding: 10px;
        background: #fff;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .pos-card.is-out {
        opacity: 0.6;
    }

    .pos-card-name {
        font-weight: 600;
        font-size: 0.9rem;
        line-height: 1.3;
        min-height: 2.4em;
    }

    .pos-card-meta {
        display: flex;
        justify-content: space-between;
        font-size: 0.78rem;
        color: var(--muted, #6b7280);
    }

    .pos-card-price {
        font-weight: 700;
        font-size: 0.95rem;
    }

    .pos-cart-panel {
        position: sticky;
        top: 16px;
    }

    /* Live feedback from assets/js/pos.js, which swaps the panel in place
       instead of reloading the page. Empty and invisible when there is
       nothing to say. */
    .pos-contact-form {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid var(--border, #e5e7eb);
    }

    .pos-contact-form .field {
        margin-bottom: 8px;
    }

    .pos-contact-form .field-row {
        gap: 8px;
    }

    .pos-contact-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    /* --------------------------------------------------------------
       Smaller screens. A till is often a phone or a small tablet at the
       counter, so the two-column shell stacks and the controls grow to a
       comfortable thumb target rather than shrinking into the corner.
       -------------------------------------------------------------- */
    @media (max-width: 900px) {
        .pos-shell {
            grid-template-columns: minmax(0, 1fr);
        }

        .pos-cart-panel {
            position: static;
        }
    }

    @media (max-width: 640px) {
        /* .field-row is a fixed 1fr 1fr grid with no breakpoint of its own, so
           the phone and email fields sat side by side in a narrow panel. */
        .pos-contact-form .field-row {
            grid-template-columns: minmax(0, 1fr);
        }

        .pos-toolbar,
        .pos-toolbar .pos-scan-form,
        .pos-toolbar .pos-search-form {
            min-width: 0;
            flex: 1 1 100%;
        }

        .pos-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        /* Comfortable tap targets for a counter touchscreen. */
        .pos-contact-form input,
        .pos-contact-actions .btn,
        .pos-inline-form input,
        .pos-inline-form .btn {
            min-height: 44px;
        }

        .pos-contact-form input,
        .pos-inline-form input {
            width: 100%;
        }
    }

    @media (max-width: 420px) {
        .pos-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .pos-contact-actions {
            flex-direction: column;
            align-items: stretch;
        }
    }

    .pos-status {
        display: none;
        margin-bottom: 10px;
        padding: 8px 10px;
        border-radius: 8px;
        font-size: 0.82rem;
        border: 1px solid var(--border, #e5e7eb);
        background: var(--bg-alt, #f9fafb);
    }

    .pos-status.is-ok {
        display: block;
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #14532d;
    }

    .pos-status.is-error {
        display: block;
        border-color: #fecaca;
        background: #fef2f2;
        color: #7f1d1d;
    }

    .pos-customer-chip {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--border, #e5e7eb);
        border-radius: 8px;
        padding: 8px 10px;
    }

    .pos-customer-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
        margin-top: 8px;
    }

    .pos-stat {
        background: var(--bg-alt, #f9fafb);
        border-radius: 6px;
        padding: 6px;
        text-align: center;
    }

    .pos-stat .label {
        display: block;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: var(--muted, #6b7280);
    }

    .pos-stat .value {
        font-weight: 700;
        font-size: 0.82rem;
    }

    .pos-customer-results {
        list-style: none;
        margin: 8px 0 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .pos-customer-results li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--border, #e5e7eb);
        border-radius: 6px;
        padding: 6px 8px;
        font-size: 0.85rem;
    }

    .pos-inline-form {
        display: flex;
        gap: 6px;
        align-items: center;
    }

    .pos-inline-form input[type="text"],
    .pos-inline-form input[type="number"] {
        flex: 1;
        min-width: 0;
    }

    .pos-lines {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-top: 4px;
    }

    .pos-line {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto auto auto;
        gap: 6px;
        align-items: center;
        padding: 6px 0;
        border-bottom: 1px dashed var(--border, #e5e7eb);
    }

    .pos-line-main {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .pos-line-main strong {
        font-size: 0.86rem;
    }

    .pos-line-price,
    .pos-line-total {
        font-size: 0.8rem;
        color: var(--muted, #6b7280);
        white-space: nowrap;
    }

    .pos-line-total {
        font-weight: 700;
        color: inherit;
    }

    .pos-line-qty {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .pos-qty-form {
        display: inline;
    }

    .pos-qty-value {
        min-width: 20px;
        text-align: center;
        font-weight: 700;
    }

    .pos-totals {
        margin: 12px 0;
        padding: 8px 10px;
        background: var(--bg-alt, #f9fafb);
        border-radius: 8px;
    }

    .pos-total-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.9rem;
        padding: 2px 0;
    }

    .pos-total-grand {
        font-weight: 800;
        font-size: 1.05rem;
        border-top: 1px solid var(--border, #e5e7eb);
        margin-top: 4px;
        padding-top: 6px;
    }

    .pos-methods {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
        gap: 6px;
    }

    .pos-method-form {
        display: contents;
    }

    .pos-method {
        border: 1px solid var(--border, #e5e7eb);
        background: #fff;
        border-radius: 8px;
        padding: 8px 6px;
        font-size: 0.8rem;
        cursor: pointer;
    }

    .pos-method.is-active {
        background: var(--accent, #111827);
        color: #fff;
        border-color: var(--accent, #111827);
        font-weight: 700;
    }

    .pos-wallet-box {
        border: 1px dashed var(--border, #e5e7eb);
        border-radius: 8px;
        padding: 8px 10px;
        font-size: 0.86rem;
        background: var(--bg-alt, #f9fafb);
    }

    .pos-wallet-box.is-short {
        border-color: #dc2626;
        background: #fef2f2;
    }

    .btn-block {
        width: 100%;
    }

    @media (max-width: 1100px) {
        .pos-shell {
            grid-template-columns: 1fr;
        }

        .pos-cart-panel {
            position: static;
        }
    }
</style>

<?php if (!$schemaReady || !$walletsReady): ?>
    <div class="alert alert-warning">
        Some point-of-sale tables are missing. Run <code>php config/run_migrations.php</code>
        (or import <code>config/migrations.sql</code>) so products can carry SKUs, sales carry
        invoice numbers and customer wallets can be used.
    </div>
<?php endif; ?>

<div class="pos-shell">
    <div class="pos-main">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Scan or search</h2>
                    <p class="muted mb-0">Barcode, SKU, product name or brand</p>
                </div>
                <div class="table-actions">
                    <a href="<?= url('/admin/pos/sales.php') ?>" class="btn btn-outline btn-sm"><i class="bi bi-receipt me-1"></i>Sales</a>
                    <a href="<?= url('/admin/pos/wallets.php') ?>" class="btn btn-outline btn-sm"><i class="bi bi-wallet2 me-1"></i>Wallets</a>
                    <a href="<?= url('/admin/inventory/index.php') ?>" class="btn btn-outline btn-sm"><i class="bi bi-clipboard-plus me-1"></i>Inventory</a>
                </div>
            </div>
            <div class="panel-body">
                <div class="pos-toolbar">
                    <?php /* The scan box comes first and takes the focus: a barcode
                            scanner types into whatever is focused and presses Enter,
                            so this is the control a till spends its day in. */ ?>
                    <form method="post" action="<?= url('/admin/pos/cart.php') ?>" id="posScanForm" class="pos-scan-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add">
                        <input type="text" id="posQuickAdd" name="code" aria-label="Scan a barcode or type a product code to add it to the sale"
                            placeholder="Scan barcode or code to add…" autocomplete="off" autofocus>
                        <button type="submit" class="btn btn-accent">Add</button>
                    </form>
                    <form method="get" action="<?= url('/admin/pos/index.php') ?>" class="pos-search-form">
                        <input type="search" id="posSearch" name="q" value="<?= e($search) ?>" aria-label="Search the product list"
                            placeholder="Search products…" autocomplete="off">
                        <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= (int) $categoryId ?>"><?php endif; ?>
                        <button type="submit" class="btn btn-outline">Search</button>
                    </form>
                </div>

                <div class="pos-chips">
                    <a class="pos-chip <?= $categoryId === 0 ? 'is-active' : '' ?>" href="<?= url('/admin/pos/index.php' . ($search !== '' ? '?q=' . urlencode($search) : '')) ?>">All</a>
                    <?php foreach ($categories as $category): ?>
                        <a class="pos-chip <?= $categoryId === (int) $category['id'] ? 'is-active' : '' ?>"
                            href="<?= url('/admin/pos/index.php?category=' . (int) $category['id'] . ($search !== '' ? '&q=' . urlencode($search) : '')) ?>">
                            <?= e($category['name']) ?> (<?= (int) $category['product_count'] ?>)
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($products): ?>
                    <div class="pos-grid">
                        <?php foreach ($products as $product):
                            $available = pos_available_quantity($product);
                            $madeToOrder = pos_is_made_to_order($product);
                            $isOut = !$madeToOrder && $available <= 0;
                        ?>
                            <div class="pos-card <?= $isOut ? 'is-out' : '' ?>">
                                <div class="pos-card-name"><?= e($product['name']) ?></div>
                                <div class="pos-card-meta">
                                    <span><?= e($product['brand'] ?: $product['category_name'] ?: 'General') ?></span>
                                    <span>
                                        <?php if ($madeToOrder): ?>
                                            Made to order
                                        <?php elseif ($available <= 0): ?>
                                            Out of stock
                                        <?php else: ?>
                                            <?= $available ?> left
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="pos-card-price"><?= money($product['price']) ?></div>
                                <form method="post" action="<?= url('/admin/pos/cart.php') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm btn-block" <?= $isOut ? 'disabled' : '' ?>>
                                        <?= $isOut ? 'No stock' : 'Add to sale' ?>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-search" style="font-size: 2rem;"></i>
                        <p>No products match<?= $search !== '' ? ' “' . e($search) . '”' : '' ?>.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <aside class="pos-cart-panel" id="pos-cart-panel">
        <div class="panel">
            <?php include __DIR__ . '/../../includes/partials/pos_cart.php'; ?>
        </div>
    </aside>
</div>

<script src="<?= url('/assets/js/pos.js?v=20260927-1') ?>"></script>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>