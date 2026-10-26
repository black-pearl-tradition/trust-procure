<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM promotional_banners WHERE id = ?');
$stmt->execute([$id]);
$banner = $stmt->fetch();

if (!$banner) {
    set_flash('error', 'Banner not found.');
    redirect('/admin/banners/index.php');
}

$pageTitle = 'Edit Banner';
$errors = [];
$title = $banner['title'];
$message = $banner['message'];
$ctaText = $banner['cta_text'];
$ctaLink = $banner['cta_link'];
$startDate = $banner['start_date'];
$endDate = $banner['end_date'];
$status = $banner['status'];
$sortOrder = $banner['sort_order'];

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

    $imageName = $banner['image'];
    if (!$errors) {
        try {
            $uploaded = handle_upload($_FILES['image'] ?? [], 'banners');
            if ($uploaded) {
                if ($banner['image'] && is_file(__DIR__ . '/../../uploads/banners/' . $banner['image'])) {
                    @unlink(__DIR__ . '/../../uploads/banners/' . $banner['image']);
                }
                $imageName = $uploaded;
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'UPDATE promotional_banners SET title=?, message=?, image=?, cta_text=?, cta_link=?, start_date=?, end_date=?, status=?, sort_order=? WHERE id=?'
        );
        $stmt->execute([
            $title, $message, $imageName, $ctaText ?: null, $ctaLink ?: null,
            $startDate ?: null, $endDate ?: null, $status, $sortOrder, $id,
        ]);
        log_activity($pdo, $_SESSION['user_id'], 'banner_updated', "Updated banner #$id");
        set_flash('success', 'Banner updated.');
        redirect('/admin/banners/index.php');
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>Edit banner</h2></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" style="max-width: 560px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $banner['id'] ?>">
            <?php if ($banner['image']): ?>
            <div class="field">
                <label>Current image</label>
                <div class="pd-image" style="width: 200px; height: 100px;"><img src="<?= url('/uploads/banners/' . e($banner['image'])) ?>" alt=""></div>
            </div>
            <?php endif; ?>
            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="<?= e($title) ?>" required autofocus>
            </div>
            <div class="field">
                <label for="message">Promotional message</label>
                <textarea id="message" name="message"><?= e($message) ?></textarea>
            </div>
            <div class="field">
                <label for="image">Replace image</label>
                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="cta_text">Button text</label>
                    <input type="text" id="cta_text" name="cta_text" value="<?= e($ctaText) ?>">
                </div>
                <div class="field">
                    <label for="cta_link">Button link</label>
                    <input type="text" id="cta_link" name="cta_link" value="<?= e($ctaLink) ?>">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="start_date">Start date</label>
                    <input type="date" id="start_date" name="start_date" value="<?= e($startDate) ?>">
                </div>
                <div class="field">
                    <label for="end_date">End date</label>
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
                </div>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save changes</button>
                <a href="<?= url('/admin/banners/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
