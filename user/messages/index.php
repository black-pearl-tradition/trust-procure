<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$pageTitle = 'My Messages';
$userId = $_SESSION['user_id'];

// Get user's email to find related contact messages
$stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if ($user) {
    $pdo->prepare(
        'UPDATE contact_messages
         SET is_read = 1
         WHERE email = ? AND admin_reply IS NOT NULL AND is_read = 0'
    )->execute([$user['email']]);
}

// Get all contact messages from this user that have admin replies
$stmt = $pdo->prepare(
    "SELECT * FROM contact_messages
     WHERE email = ? AND admin_reply IS NOT NULL
     ORDER BY replied_at DESC"
);
$stmt->execute([$user['email']]);
$messages = $stmt->fetchAll();

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>Messages from support</h2></div>
    <div class="panel-body">
        <?php if ($messages): ?>
            <?php foreach ($messages as $msg): ?>
                <div class="message-card">
                    <div class="message-header">
                        <div>
                            <strong><?= e($msg['subject'] ?: '(no subject)') ?></strong>
                            <span class="badge bg-secondary ms-2"><?= e(ucfirst($msg['department'])) ?></span>
                        </div>
                        <small class="text-muted"><?= display_date($msg['created_at']) ?></small>
                    </div>
                    <div class="message-body">
                        <div class="message-original">
                            <div class="label">Your message:</div>
                            <p><?= nl2br(e($msg['message'])) ?></p>
                        </div>
                        <?php if ($msg['admin_reply']): ?>
                            <div class="message-reply">
                                <div class="label"><i class="bi bi-arrow-return-right me-1"></i>Support reply:</div>
                                <p><?= nl2br(e($msg['admin_reply'])) ?></p>
                                <small class="text-muted">Replied on <?= display_date($msg['replied_at']) ?></small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-chat-dots" style="font-size: 2rem; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                No messages yet. When you contact support and receive a reply, it will appear here.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>