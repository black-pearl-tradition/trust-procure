<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/messages/index.php');
}
verify_csrf('/admin/messages/index.php');

$id = (int) ($_POST['id'] ?? 0);
$replyMessage = trim($_POST['reply_message'] ?? '');

if ($replyMessage === '') {
    set_flash('error', 'Please enter a reply message.');
    redirect('/admin/messages/view.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT * FROM contact_messages WHERE id = ?');
$stmt->execute([$id]);
$message = $stmt->fetch();

if (!$message) {
    set_flash('error', 'Message not found.');
    redirect('/admin/messages/index.php');
}

// Store the reply in the database
$pdo->prepare('UPDATE contact_messages SET admin_reply = ?, replied_at = NOW(), replied_by = ? WHERE id = ?')
    ->execute([$replyMessage, $_SESSION['user_id'], $id]);

// Create notification for the user (if they have an account)
$userStmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$userStmt->execute([$message['email']]);
$user = $userStmt->fetch();

if ($user) {
    create_notification(
        $pdo,
        (int) $user['id'],
        'Reply from support',
        'You have a new reply from our support team regarding: ' . ($message['subject'] ?: 'Your message'),
        '/user/messages/index.php'
    );
}

// Send email notification
$body = "Hello {$message['name']},\n\n"
    . "Thank you for contacting Trust Procurement. Here is our reply:\n\n"
    . "{$replyMessage}\n\n"
    . "If you have any further questions, please don't hesitate to reach out.\n\n"
    . "Best regards,\nTrust Procurement Team";
send_email($pdo, $message['email'], "Re: " . ($message['subject'] ?: 'Your message to Trust Procurement'), $body);

log_activity($pdo, $_SESSION['user_id'], 'message_replied', "Replied to message #$id");
set_flash('success', 'Reply sent successfully.');
redirect('/admin/messages/view.php?id=' . $id);