<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pageTitle = 'Get a recommendation';
$budget = isset($_GET['budget']) ? (float) $_GET['budget'] : 0;
$categoryId = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$minRam = isset($_GET['min_ram']) ? (int) $_GET['min_ram'] : 0;
$minStorage = isset($_GET['min_storage']) ? (int) $_GET['min_storage'] : 0;
$brand = trim($_GET['brand'] ?? '');
$priority = $_GET['priority'] ?? 'balanced';
$errors = [];
$recommendations = [];

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$brands = $pdo->query("SELECT DISTINCT brand FROM products WHERE status = 'active' AND brand IS NOT NULL ORDER BY brand")->fetchAll();

$selectedCategoryName = 'All categories';
foreach ($categories as $category) {
    if ($categoryId === (int) $category['id']) {
        $selectedCategoryName = $category['name'];
        break;
    }
}
$selectedBrand = '';
foreach ($brands as $b) {
    if ($brand === $b['brand']) {
        $selectedBrand = $b['brand'];
        break;
    }
}
$supportUrl = url('/contact.php?' . http_build_query([
    'subject' => 'Detailed product recommendation',
    'message' => $budget > 0
        ? "I would like a detailed recommendation. My maximum budget is " . money($budget) . ". Preferred category: " . $selectedCategoryName . ". Preferred brand: " . ($selectedBrand ?: 'Any') . ". Please advise on the best specifications and trade-offs."
        : 'I would like a detailed product recommendation. Please help me compare the best specifications for my needs and budget.',
]));

function recommendation_spec_score(array $product, float $budget, int $minRam, int $minStorage, string $brand, string $priority): float
{
    $score = 0.0;
    $text = strtolower($product['specifications'] ?? '');
    $price = (float) $product['price'];

    // Price fit scoring based on priority
    $budgetUsage = $price / $budget;
    switch ($priority) {
        case 'budget':
            // Prefer products that use less of the budget (more savings)
            $score += (1 - $budgetUsage) * 40;
            break;
        case 'performance':
            // Prefer products that use more of the budget (better specs)
            $score += $budgetUsage * 40;
            break;
        default: // balanced
            // Prefer products that use 60-80% of the budget
            $optimalUsage = 0.7;
            $deviation = abs($budgetUsage - $optimalUsage);
            $score += max(0, (1 - $deviation * 2)) * 40;
            break;
    }

    // RAM scoring
    if (preg_match('/(\d+(?:\.\d+)?)\s*gb\s*(?:ram|memory)/i', $text, $match)) {
        $ram = (float) $match[1];
        if ($minRam > 0) {
            if ($ram >= $minRam) {
                $score += 20;
                $score += min(($ram - $minRam) / $minRam * 10, 10); // Bonus for exceeding
            } else {
                $score -= 10; // Penalty for not meeting minimum
            }
        } else {
            $score += min($ram, 32) * 1.5;
        }
    }

    // Storage scoring
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:tb|gb)\s*(?:ssd|storage|rom|hdd)/i', $text, $match)) {
        $storage = (float) $match[1] * (stripos($match[0], 'tb') !== false ? 1024 : 1);
        if ($minStorage > 0) {
            if ($storage >= $minStorage) {
                $score += 15;
                $score += min(($storage - $minStorage) / $minStorage * 5, 5);
            } else {
                $score -= 8;
            }
        } else {
            $score += min($storage, 1024) / 64;
        }
    }

    // Processor scoring
    if (preg_match('/(?:i[79]|ryzen\s*[79]|snapdragon\s*[89]|apple\s*m[234])/i', $text)) {
        $score += 15;
    } elseif (preg_match('/(?:i[5]|ryzen\s*[5]|snapdragon\s*[67]|apple\s*m[1])/i', $text)) {
        $score += 10;
    } elseif (preg_match('/(?:i[3]|ryzen\s*[3]|snapdragon\s*[456])/i', $text)) {
        $score += 5;
    }

    // Graphics scoring
    if (preg_match('/(?:rtx|radeon\s*rx|arc|dedicated\s+graphics)/i', $text)) {
        $score += 10;
    }

    // Brand match bonus
    if ($brand !== '' && stripos($product['brand'] ?? '', $brand) !== false) {
        $score += 10;
    }

    // High-end bonus for performance priority
    if ($priority === 'performance' && !empty($product['is_high_end'])) {
        $score += 10;
    }

    return $score;
}

if (isset($_GET['budget'])) {
    if ($budget <= 0) {
        $errors[] = 'Enter a budget greater than zero.';
    } else {
        $sql = "SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE p.status = 'active' AND p.price <= ?";
        $params = [$budget];

        if ($categoryId > 0) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        if ($brand !== '') {
            $sql .= ' AND p.brand LIKE ?';
            $params[] = '%' . $brand . '%';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();

        foreach ($candidates as $product) {
            $product['recommendation_score'] = recommendation_spec_score($product, $budget, $minRam, $minStorage, $brand, $priority);
            $recommendations[] = $product;
        }
        usort($recommendations, static function (array $left, array $right): int {
            return $right['recommendation_score'] <=> $left['recommendation_score'];
        });
        $recommendations = array_slice($recommendations, 0, 6);
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="page-banner recommendation-page-banner">
    <div class="container">
        <div class="page-kicker"><i class="bi bi-stars" aria-hidden="true"></i> Personalized guidance</div>
        <div class="recommendation-banner-copy">
            <i class="bi bi-stars" aria-hidden="true"></i>
            <span>Tell us what matters and we'll rank the strongest available fit.</span>
        </div>
        <h1>Find the best fit for your needs</h1>
        <p>Tell us your budget, preferred specs and priorities, then we'll rank the best matches for you.</p>
    </div>
</section>

<section class="section-tight recommendation-section">
    <div class="container recommendation-container">
        <div class="row g-4 align-items-start">
            <div class="col-12 col-lg-4">
                <div class="recommendation-form-launch">
                    <div class="section-kicker">Step 1</div>
                    <h2>Your requirements</h2>
                    <p class="text-muted">Tell us your budget and preferences to find the strongest available fit.</p>
                    <button type="button" class="btn btn-primary w-100" id="openRecommendationForm" aria-controls="recommendationFormModal" aria-expanded="false">
                        <?= isset($_GET['budget']) ? 'Update requirements' : 'Set your preferences' ?>
                    </button>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <?php if (isset($_GET['budget']) && !$errors && $recommendations): ?>
                    <div class="recommendation-results-head">
                        <div>
                            <div class="section-kicker">Step 2</div>
                            <h2 class="h3 mb-1">Recommended for you</h2>
                            <p class="text-muted mb-0">Best available matches within <?= money($budget) ?>.</p>
                        </div>
                        <span class="badge text-bg-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i><?= count($recommendations) ?> matches</span>
                    </div>
                    <div class="recommendation-support-panel">
                        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                            <div>
                                <h2 class="h5 mb-1">Need a more detailed comparison?</h2>
                                <p class="text-muted mb-0">Our support team can review your needs, budget, and specification priorities.</p>
                            </div>
                            <a href="<?= e($supportUrl) ?>" class="btn btn-outline-primary flex-shrink-0">Talk to support</a>
                        </div>
                    </div>
                    <div class="row g-4">
                        <?php foreach ($recommendations as $recommendation): ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <?php $product = $recommendation;
                                include __DIR__ . '/includes/product_card.php'; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (isset($_GET['budget']) && !$errors): ?>
                    <div class="recommendation-empty alert alert-warning d-flex gap-2 align-items-start" role="alert">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>No products matched your criteria. Try increasing your budget, adjusting the filters, or setting the priority to "Balanced".</span>
                    </div>
                <?php else: ?>
                    <div class="recommendation-intro-panel">
                        <div class="recommendation-intro-icon"><i class="bi bi-lightbulb" aria-hidden="true"></i></div>
                        <h2>A clearer way to choose</h2>
                        <p class="mb-0">We prioritize products that use more of your budget well, then compare the specification details recorded by our procurement team.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="recommendation-modal" id="recommendationFormModal" hidden>
            <div class="recommendation-modal-dialog">
                <div class="recommendation-form-panel recommendation-modal-content" role="dialog" aria-modal="true" aria-labelledby="recommendationFormTitle">
                    <div class="recommendation-modal-head recommendation-form-head">
                        <div>
                            <div class="section-kicker">Step 1</div>
                            <h2 class="modal-title fs-5" id="recommendationFormTitle">Your requirements</h2>
                            <p class="text-muted mb-0">We score products based on your budget, preferred specifications, brand and priority settings.</p>
                        </div>
                        <button type="button" class="recommendation-modal-close" aria-label="Close">Close</button>
                    </div>
                    <div class="recommendation-modal-body">
                        <?php foreach ($errors as $error): ?>
                            <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span><?= e($error) ?></span></div>
                        <?php endforeach; ?>
                        <form method="get" action="<?= url('/recommend.php') ?>">
                            <div class="mb-3">
                                <label for="budget" class="form-label fw-semibold">Maximum budget (UGX)</label>
                                <input type="number" class="form-control" id="budget" name="budget" value="<?= $budget > 0 ? e($budget) : '' ?>" min="0" step="1" placeholder="e.g. 2500000" required>
                            </div>
                            <div class="mb-3">
                                <label for="category" class="form-label fw-semibold">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="0">All categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="brand" class="form-label fw-semibold">Brand</label>
                                <select class="form-select" id="brand" name="brand">
                                    <option value="">Any brand</option>
                                    <?php foreach ($brands as $b): ?>
                                        <option value="<?= e($b['brand']) ?>" <?= $brand === $b['brand'] ? 'selected' : '' ?>><?= e($b['brand']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <label for="min_ram" class="form-label fw-semibold">Min RAM</label>
                                    <select class="form-select" id="min_ram" name="min_ram">
                                        <option value="0">Any</option>
                                        <option value="4" <?= $minRam === 4 ? 'selected' : '' ?>>4 GB+</option>
                                        <option value="8" <?= $minRam === 8 ? 'selected' : '' ?>>8 GB+</option>
                                        <option value="12" <?= $minRam === 12 ? 'selected' : '' ?>>12 GB+</option>
                                        <option value="16" <?= $minRam === 16 ? 'selected' : '' ?>>16 GB+</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label for="min_storage" class="form-label fw-semibold">Min Storage</label>
                                    <select class="form-select" id="min_storage" name="min_storage">
                                        <option value="0">Any</option>
                                        <option value="64" <?= $minStorage === 64 ? 'selected' : '' ?>>64 GB+</option>
                                        <option value="128" <?= $minStorage === 128 ? 'selected' : '' ?>>128 GB+</option>
                                        <option value="256" <?= $minStorage === 256 ? 'selected' : '' ?>>256 GB+</option>
                                        <option value="512" <?= $minStorage === 512 ? 'selected' : '' ?>>512 GB+</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-4 priority-field">
                                <label class="form-label fw-semibold d-block">Priority</label>
                                <div class="d-flex gap-2">
                                    <div class="form-check flex-fill">
                                        <input class="form-check-input" type="radio" name="priority" id="priorityBudget" value="budget" <?= $priority === 'budget' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="priorityBudget">Save money</label>
                                    </div>
                                    <div class="form-check flex-fill">
                                        <input class="form-check-input" type="radio" name="priority" id="priorityBalanced" value="balanced" <?= $priority === 'balanced' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="priorityBalanced">Balanced</label>
                                    </div>
                                    <div class="form-check flex-fill">
                                        <input class="form-check-input" type="radio" name="priority" id="priorityPerformance" value="performance" <?= $priority === 'performance' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="priorityPerformance">Best specs</label>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Get recommendations</button>
                        </form>
                        <a href="<?= e($supportUrl) ?>" class="btn btn-outline-secondary w-100 mt-3">Talk to support for detailed analysis</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    (function () {
        const modal = document.getElementById('recommendationFormModal');
        const opener = document.getElementById('openRecommendationForm');
        if (!modal || !opener) return;

        const closeButton = modal.querySelector('.recommendation-modal-close');
        const firstField = modal.querySelector('#budget');
        let closeTimeout;

        function openModal() {
            window.clearTimeout(closeTimeout);
            modal.hidden = false;
            modal.classList.add('is-open');
            document.body.classList.add('recommendation-modal-open');
            opener.setAttribute('aria-expanded', 'true');
            firstField?.focus();
        }

        function closeModal() {
            modal.classList.remove('is-open');
            document.body.classList.remove('recommendation-modal-open');
            opener.setAttribute('aria-expanded', 'false');
            closeTimeout = window.setTimeout(function () {
                modal.hidden = true;
                opener.focus();
            }, 180);
        }

        opener.addEventListener('click', openModal);
        closeButton.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (modal.hidden) return;
            if (event.key === 'Escape') {
                closeModal();
                return;
            }
            if (event.key === 'Tab') {
                const focusable = Array.from(modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'));
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        <?php if ($errors): ?>
        openModal();
        <?php endif; ?>
    })();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>