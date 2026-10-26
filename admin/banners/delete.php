<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/banners/index.php');
}
verify_csrf('/admin/banners/index.php');

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT title, image FROM promotional_banners WHERE id = ?');
$stmt->execute([$id]);
$banner = $stmt->fetch();

if ($banner) {
    $pdo->prepare('DELETE FROM promotional_banners WHERE id = ?')->execute([$id]);
    if ($banner['image']) {
        $path = __DIR__ . '/../../uploads/banners/' . $banner['image'];
        if (is_file($path)) {
            @unlink($path);
        }
    }
    log_activity($pdo, $_SESSION['user_id'], 'banner_deleted', "Deleted banner: {$banner['title']}");
    set_flash('success', 'Banner deleted.');
} else {
    set_flash('error', 'Banner not found.');
}

redirect('/admin/banners/index.php');
