<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Add Banner';
$errors = [];
$title = $message = $ctaText = $ctaLink = $startDate = $endDate = '';
$status = 'active';
$sortOrder = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/banners/index.php');
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $ctaText = trim($_POST['cta_text'] ?? '');
    $ctaLink = trim($_POST['cta_link'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($title === '') $errors[] = 'Please enter a title.';
    if ($startDate && $endDate && $startDate > $endDate) $errors[] = 'The start date must be before the end date.';

    $imageName = null;
    if (!$errors) {
        try {
            $imageName = handle_upload($_FILES['image'] ?? [], 'banners');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO promotional_banners (title, message, image, cta_text, cta_link, start_date, end_date, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title, $message, $imageName, $ctaText ?: null, $ctaLink ?: null,
            $startDate ?: null, $endDate ?: null, $status, $sortOrder,
        ]);
        log_activity($pdo, $_SESSION['user_id'], 'banner_created', "Created banner: $title");
        set_flash('success', 'Banner added.');
        redirect('/admin/banners/index.php');
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>Add banner</h2></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" style="max-width: 560px;">
            <?= csrf_field() ?>
            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="<?= e($title) ?>" required autofocus>
            </div>
            <div class="field">
                <label for="message">Promotional message</label>
                <textarea id="message" name="message"><?= e($message) ?></textarea>
            </div>
            <div class="field">
                <label for="image">Banner image (optional)</label>
                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="cta_text">Button text</label>
                    <input type="text" id="cta_text" name="cta_text" value="<?= e($ctaText) ?>" placeholder="e.g. Shop the sale">
                </div>
                <div class="field">
                    <label for="cta_link">Button link</label>
                    <input type="text" id="cta_link" name="cta_link" value="<?= e($ctaLink) ?>" placeholder="/products.php">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="start_date">Start date (optional)</label>
                    <input type="date" id="start_date" name="start_date" value="<?= e($startDate) ?>">
                </div>
                <div class="field">
                    <label for="end_date">End date (optional)</label>
                    <input type="date" id="end_date" name="end_date" value="<?= e($endDate) ?>">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="field">
                    <label for="sort_order">Display order</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= (int) $sortOrder ?>" min="0">
                    <div class="hint">Lower numbers show first.</div>
                </div>
            </div>
            <div class="hint" style="margin-bottom: 14px;">Leave dates blank to run indefinitely. A banner only shows on the homepage when its status is Active and today falls within its date range.</div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save banner</button>
                <a href="<?= url('/admin/banners/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
