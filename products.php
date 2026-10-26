<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pageTitle = 'Products';

$categoryId = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$sortOptions = [
    'newest' => 'Newest first',
    'price_low' => 'Price: low to high',
    'price_high' => 'Price: high to low',
    'name' => 'Name: A to Z',
];
if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'newest';
}

$sql = "SELECT p.*, c.name AS category_name FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active'";
$params = [];

if ($categoryId) {
    $sql .= " AND p.category_id = ?";
    $params[] = $categoryId;
}
if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.brand LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sortSql = [
    'newest' => 'p.created_at DESC',
    'price_low' => 'p.price ASC, p.created_at DESC',
    'price_high' => 'p.price DESC, p.created_at DESC',
    'name' => 'p.name ASC',
][$sort];
$sql .= " ORDER BY $sortSql";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$compareCount = count(get_compare_list());

if (($_GET['ajax'] ?? '') === '1') {
    ob_start();
    include __DIR__ . '/includes/product_grid.php';
    $gridHtml = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'count' => count($products),
        'html' => $gridHtml,
    ]);
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<section class="page-banner products-page-banner">
    <div class="container">
        <div class="page-kicker">Procurement catalog</div>
        <h1>Products</h1>
        <p>Compare specifications and pricing across every device we currently support for procurement.</p>
    </div>
</section>

<section class="catalog-flow">
    <div class="container">
        <div class="catalog-flow-grid">
            <div class="flow-step active">
                <span class="flow-number">01</span>
                <div>
                    <strong>Browse</strong>
                    <small>Review available devices and categories</small>
                </div>
            </div>
            <div class="flow-step">
                <span class="flow-number">02</span>
                <div>
                    <strong>Compare</strong>
                    <small>Match specifications and pricing to your needs</small>
                </div>
            </div>
            <div class="flow-step">
                <span class="flow-number">03</span>
                <div>
                    <strong>Request</strong>
                    <small>Submit for approval and procurement follow-up</small>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-tight catalog-section">
    <div class="container catalog-container">
        <div class="catalog-intro">
            <div>
                <div class="section-kicker">Procurement catalog</div>
                <h2>Browse and compare approved devices</h2>
                <p>Search by category, compare specifications, and request the most suitable option for your team.</p>
            </div>
            <a href="<?= url('/recommend.php') ?>" class="btn btn-primary"><i class="bi bi-stars me-2" aria-hidden="true"></i>Get a recommendation</a>
        </div>
        <nav class="catalog-category-chips" aria-label="Product categories">
            <a href="<?= url('/products.php') ?>" class="catalog-chip<?= !$categoryId ? ' active' : '' ?>" <?= !$categoryId ? 'aria-current="page"' : '' ?>>All products</a>
            <?php foreach ($categories as $cat): ?>
                <a href="<?= url('/products.php?category=' . $cat['id']) ?>" class="catalog-chip<?= $categoryId === (int) $cat['id'] ? ' active' : '' ?>" <?= $categoryId === (int) $cat['id'] ? 'aria-current="page"' : '' ?>><?= e($cat['name']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="catalog-filter-panel">
            <div class="filter-panel-heading">
                <div>
                    <strong>Search products</strong>
                    <span>Choose a category above or search by name or brand.</span>
                </div>
            </div>
            <form method="get" action="<?= url('/products.php') ?>" class="catalog-filter-form row g-2 align-items-end">
                <input type="hidden" name="category" value="<?= (int) $categoryId ?>">
                <div class="col-12 col-md-10 catalog-search-field">
                    <label for="catalogSearch" class="form-label small fw-semibold">Search catalog</label>
                    <input type="search" id="catalogSearch" name="q" class="form-control" placeholder="Search by name or brand" value="<?= e($search) ?>">
                </div>
                <div class="col-12 col-md-2 d-grid catalog-filter-action">
                    <button type="submit" class="btn btn-primary catalog-search-button"><i class="bi bi-search me-2" aria-hidden="true"></i>Search</button>
                </div>
            </form>
        </div>

        <div class="catalog-results-head">
            <div>
                <div class="section-kicker">Available now</div>
                <h2 id="catalog-result-count" role="status" aria-live="polite" aria-atomic="true"><?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?></h2>
            </div>
            <div class="catalog-results-tools">
                <form method="get" class="catalog-sort-form">
                    <input type="hidden" name="q" value="<?= e($search) ?>">
                    <input type="hidden" name="category" value="<?= (int) $categoryId ?>">
                    <label for="catalogSort">Sort</label>
                    <select id="catalogSort" name="sort" onchange="this.form.submit()">
                        <?php foreach ($sortOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($compareCount > 0): ?>
                    <a href="<?= url('/compare.php') ?>" class="catalog-compare-link"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Compare <?= $compareCount ?></a>
                <?php endif; ?>
                <?php if ($search !== '' || $categoryId || $sort !== 'newest'): ?>
                    <a href="<?= url('/products.php') ?>" class="catalog-clear-link">Clear</a>
                <?php endif; ?>
            </div>
        </div>

        <div id="catalog-results" aria-live="polite">
            <?php include __DIR__ . '/includes/product_grid.php'; ?>
        </div>
    </div>
</section>

<script src="<?= url('/assets/js/script.js?v=20261026-catalog-aria-current') ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>