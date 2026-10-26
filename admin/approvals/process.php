<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/approvals/index.php');
}

verify_csrf('/admin/approvals/index.php');

$requestId = (int) ($_POST['id'] ?? 0);
$decision = $_POST['decision'] ?? 'approved';
$decision = in_array($decision, ['approved', 'rejected'], true) ? $decision : 'approved';
$comment = trim((string) ($_POST['comment'] ?? ''));

$roleHierarchy = ['user' => 1, 'supplier' => 2, 'staff' => 3, 'admin' => 4, 'super_admin' => 5];
$currentRole = $_SESSION['role'] ?? 'user';
$currentRank = $roleHierarchy[$currentRole] ?? 0;

$stmt = $pdo->prepare(
    "SELECT r.*, o.id AS existing_order_id, u.full_name, u.email, p.name AS product_name, p.price, p.is_high_end, p.deposit_percentage, p.category_id, c.name AS category_name
     FROM requests r
     LEFT JOIN orders o ON o.request_id = r.id
     JOIN users u ON u.id = r.user_id
     JOIN products p ON p.id = r.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE r.id = ? AND r.status = 'pending'"
);
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    set_flash('error', 'Request not found or already processed.');
    redirect('/admin/approvals/index.php');
}

$total = (float) $request['price'] * (int) $request['quantity'];
$requestCategoryId = (int) ($request['category_id'] ?? 0);
$requestDepartment = trim((string) ($request['department'] ?? $request['category_name'] ?? ''));
$matchedRules = matching_approval_rules($pdo, $total, $requestCategoryId, $requestDepartment);
$matchedRule = $matchedRules ? end($matchedRules) : null;

$existingStagesStmt = $pdo->prepare(
    'SELECT DISTINCT stage
     FROM purchase_request_approvals
     WHERE request_id = ? AND decision = "approved"'
);
$existingStagesStmt->execute([$requestId]);
$approvedStages = [];
while ($row = $existingStagesStmt->fetch(PDO::FETCH_ASSOC)) {
    $stageName = trim((string) ($row['stage'] ?? ''));
    if ($stageName !== '') {
        $approvedStages[] = $stageName;
    }
}

$nextRequiredRule = next_required_approval_rule($matchedRules, $approvedStages);
$requiredRoleRank = 0;
foreach ($matchedRules as $rule) {
    $requiredRoleRank = max($requiredRoleRank, (int) ($roleHierarchy[$rule['required_role']] ?? 0));
}

if ($matchedRules && $nextRequiredRule === null) {
    set_flash('error', 'This request already satisfies all active approval rules for its matched workflow stage.');
    redirect('/admin/approvals/index.php');
}

if ($matchedRules && $nextRequiredRule && ($roleHierarchy[$nextRequiredRule['required_role']] ?? 0) > $currentRank) {
    set_flash('error', 'This request requires a higher approval stage before it can be approved by your role.');
    redirect('/admin/approvals/index.php');
}

if ($matchedRules && $nextRequiredRule === null && $requiredRoleRank > $currentRank) {
    set_flash('error', 'Your role does not meet the approval rule requirement for this request.');
    redirect('/admin/approvals/index.php');
}

$decisionStage = $nextRequiredRule ? approval_rule_stage_key($nextRequiredRule) : 'manual_review';
$stageLabel = $decisionStage;
if ($nextRequiredRule && !empty($nextRequiredRule['department'])) {
    $stageLabel .= ' / ' . trim((string) $nextRequiredRule['department']);
} elseif ($matchedRule && !empty($matchedRule['department'])) {
    $stageLabel .= ' / ' . trim((string) $matchedRule['department']);
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO purchase_request_approvals (request_id, approver_id, decision, comments, stage, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    )->execute([
        $requestId,
        $_SESSION['user_id'],
        $decision,
        $comment !== '' ? $comment : null,
        $stageLabel,
    ]);

    if ($decision === 'approved') {
        $depositPct = $request['is_high_end'] ? (int) $request['deposit_percentage'] : 100;
        $deposit = round($total * ($depositPct / 100));
        $balance = $total - $deposit;

        $orderId = (int) ($request['existing_order_id'] ?? 0);
        if ($orderId > 0) {
            $pdo->prepare(
                "UPDATE orders SET status = 'pending_deposit', payment_status = 'unpaid', total_amount = ?, deposit_amount = ?, balance_amount = ?, delivery_address = ?
                 WHERE id = ?"
            )->execute([$total, $deposit, $balance, $request['delivery_address'], $orderId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO orders (request_id, user_id, product_id, quantity, unit_price, total_amount, deposit_amount, balance_amount, status, delivery_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $request['id'],
                $request['user_id'],
                $request['product_id'],
                $request['quantity'],
                $request['price'],
                $total,
                $deposit,
                $balance,
                'pending_deposit',
                $request['delivery_address'],
            ]);
            $orderId = (int) $pdo->lastInsertId();
        }

        reserve_order_stock($pdo, $orderId);

        $pdo->prepare("UPDATE requests SET status = 'converted', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$_SESSION['user_id'], $requestId]);

        $pdo->prepare(
            'INSERT INTO approval_audit_log (target_type, target_id, actor_id, action, details)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            'request',
            $requestId,
            $_SESSION['user_id'],
            'request_approved',
            'Approved via workflow rules: ' . $stageLabel . ($matchedRule && $matchedRule['category_id'] ? ' (category #' . (int) $matchedRule['category_id'] . ')' : '')
        ]);

        create_notification($pdo, (int) $request['user_id'], 'Request approved', "Your request for {$request['product_name']} was approved and is ready for payment.", '/user/orders/view.php?id=' . $orderId);
        record_order_event($pdo, $orderId, $_SESSION['user_id'], 'request_approved', 'Request approved', $comment ?: 'Request approved through the workflow queue.');

        set_flash('success', 'Request approved through the approval workflow.');
    } else {
        $pdo->prepare("UPDATE requests SET status = 'rejected', resolution_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$comment ?: 'Rejected during approval workflow', $_SESSION['user_id'], $requestId]);

        $orderId = (int) ($request['existing_order_id'] ?? 0);
        if ($orderId > 0) {
            $pdo->prepare("UPDATE orders SET status = 'request_rejected', rejection_reason = ? WHERE id = ? AND status = 'request_submitted'")
                ->execute([$comment ?: 'Rejected during approval workflow', $orderId]);
        }

        $pdo->prepare(
            'INSERT INTO approval_audit_log (target_type, target_id, actor_id, action, details)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            'request',
            $requestId,
            $_SESSION['user_id'],
            'request_rejected',
            $comment ?: 'Rejected during approval workflow'
        ]);

        create_notification($pdo, (int) $request['user_id'], 'Request rejected', $comment ?: 'Your request was not approved during the approval review.', '/user/requests/index.php');
        set_flash('success', 'Request rejected and logged in the workflow audit trail.');
    }

    $pdo->commit();
    log_activity($pdo, $_SESSION['user_id'], $decision === 'approved' ? 'request_approved' : 'request_rejected', 'Approval workflow action for request #' . $requestId);
} catch (Throwable $e) {
    $pdo->rollBack();
    set_flash('error', 'The approval workflow could not complete. Please try again.');
    redirect('/admin/approvals/index.php');
}

redirect('/admin/approvals/index.php');
