<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM contact_messages WHERE id = ?');
$stmt->execute([$id]);
$message = $stmt->fetch();

if (!$message) {
    set_flash('error', 'Message not found.');
    redirect('/admin/messages/index.php');
}

if (!$message['is_read']) {
    $pdo->prepare('UPDATE contact_messages SET is_read = 1 WHERE id = ?')->execute([$id]);
    $message['is_read'] = 1;
}

$pageTitle = 'Message from ' . $message['name'];
include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<a href="<?= url('/admin/messages/index.php') ?>" class="btn btn-outline btn-sm" style="margin-bottom: 20px;">Back to messages</a>

<div class="panel">
    <div class="panel-head">
        <h2><?= e($message['subject'] ?: '(no subject)') ?></h2>
        <form method="post" action="<?= url('/admin/messages/delete.php') ?>" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $message['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this message?">Delete</button>
        </form>
    </div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">From</div><div class="value"><?= e($message['name']) ?></div></div>
            <div class="detail-item"><div class="label">Email</div><div class="value"><a href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a></div></div>
            <div class="detail-item"><div class="label">Department</div><div class="value"><?= e(ucfirst($message['department'] ?? 'general')) ?></div></div>
            <div class="detail-item"><div class="label">Received</div><div class="value"><?= display_date($message['created_at']) ?></div></div>
        </div>
        <hr class="divider">
        <div class="detail-item">
            <div class="label">Message</div>
            <div class="value"><?= nl2br(e($message['message'])) ?></div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Reply to customer</h3></div>
    <div class="panel-body">
        <form method="post" action="<?= url('/admin/messages/reply.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $message['id'] ?>">
            <div class="field">
                <label for="reply_message">Your reply</label>
                <textarea id="reply_message" name="reply_message" placeholder="Type your reply to the customer..." required></textarea>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent"><i class="bi bi-send me-2" aria-hidden="true"></i>Send reply</button>
                <a href="mailto:<?= e($message['email']) ?>?subject=Re: <?= e($message['subject'] ?: 'Your message to Trust Procurement') ?>" class="btn btn-outline">Reply by email instead</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
