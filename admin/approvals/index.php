<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Approval workflow';
$errors = [];
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/approvals/index.php');

    $name = trim($_POST['name'] ?? '');
    $minAmount = (float) ($_POST['min_amount'] ?? 0);
    $maxAmountRaw = trim((string) ($_POST['max_amount'] ?? ''));
    $maxAmount = $maxAmountRaw === '' ? null : (float) $maxAmountRaw;
    $requiredRole = in_array($_POST['required_role'] ?? 'admin', ['staff', 'admin', 'super_admin'], true)
        ? $_POST['required_role']
        : 'admin';
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $sequence = (int) ($_POST['sequence'] ?? 1);

    if ($name === '') {
        $errors[] = 'Please name the approval rule.';
    }
    if ($maxAmount !== null && $maxAmount < $minAmount) {
        $errors[] = 'Maximum amount must be greater than or equal to the minimum amount.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO approval_rules (name, min_amount, max_amount, required_role, department, category_id, sequence, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $name,
            $minAmount,
            $maxAmount,
            $requiredRole,
            trim((string) ($_POST['department'] ?? '')) ?: null,
            $categoryId > 0 ? $categoryId : null,
            $sequence,
        ]);
        log_activity($pdo, $_SESSION['user_id'], 'approval_rule_created', "Created approval rule: $name");
        set_flash('success', 'Approval rule saved.');
        redirect('/admin/approvals/index.php');
    }
}

$rules = $pdo->query(
    'SELECT ar.*, c.name AS category_name
     FROM approval_rules ar
     LEFT JOIN categories c ON c.id = ar.category_id
     WHERE ar.is_active = 1
     ORDER BY ar.min_amount ASC, ar.sequence ASC'
)->fetchAll();

$pendingRequests = $pdo->query(
    "SELECT r.*, u.full_name, p.name AS product_name, p.price, c.id AS category_id, c.name AS category_name, (p.price * r.quantity) AS total_amount
     FROM requests r
     JOIN users u ON u.id = r.user_id
     JOIN products p ON p.id = r.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE r.status = 'pending'
     ORDER BY r.created_at DESC"
)->fetchAll();

foreach ($pendingRequests as &$request) {
    $request['total_amount'] = (float) ($request['total_amount'] ?? 0);
    $categoryId = (int) ($request['category_id'] ?? 0);
    $requestDepartment = trim((string) ($request['category_name'] ?? ''));
    $request['approval_rules'] = matching_approval_rules($pdo, $request['total_amount'], $categoryId, $requestDepartment);
    $request['approval_rule'] = $request['approval_rules'] ? end($request['approval_rules']) : null;
}
unset($request);

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Approval workflow</h2>
            <p class="muted mb-0">Start establishing policy-based approval stages for procurement requests.</p>
        </div>
        <div class="table-actions">
            <a href="<?= url('/admin/orders/index.php?tab=requests') ?>" class="btn btn-outline btn-sm">Request queue</a>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Add approval rule</h3></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" style="max-width: 640px;">
            <?= csrf_field() ?>
            <div class="field-row">
                <div class="field">
                    <label for="name">Rule name</label>
                    <input type="text" id="name" name="name" value="" placeholder="e.g. Large procurement approval" required>
                </div>
                <div class="field">
                    <label for="sequence">Sequence</label>
                    <input type="number" id="sequence" name="sequence" min="1" value="1" required>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="min_amount">Minimum amount</label>
                    <input type="number" id="min_amount" name="min_amount" min="0" step="0.01" value="0" required>
                </div>
                <div class="field">
                    <label for="max_amount">Maximum amount</label>
                    <input type="number" id="max_amount" name="max_amount" min="0" step="0.01" value="" placeholder="Optional">
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="required_role">Required role</label>
                    <select id="required_role" name="required_role">
                        <option value="staff">Staff</option>
                        <option value="admin" selected>Admin</option>
                        <option value="super_admin">Super admin</option>
                    </select>
                </div>
                <div class="field">
                    <label for="category_id">Category (optional)</label>
                    <select id="category_id" name="category_id">
                        <option value="0">Any category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="department">Department / policy bucket (optional)</label>
                    <input type="text" id="department" name="department" value="" placeholder="Operations, Finance, IT ...">
                </div>
            </div>

            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save rule</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Active approval rules</h3></div>
    <div class="panel-body flush">
        <?php if (!$rules): ?>
            <div class="empty-state">No approval rules exist yet. Create the first rule to activate procurement review policies.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Rule</th>
                        <th>Amount range</th>
                        <th>Required role</th>
                        <th>Category</th>
                        <th>Department</th>
                        <th>Sequence</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td><strong><?= e($rule['name']) ?></strong></td>
                            <td><?= money($rule['min_amount']) ?><?= $rule['max_amount'] !== null ? ' - ' . money($rule['max_amount']) : ' and above' ?></td>
                            <td><?= ucfirst(e($rule['required_role'])) ?></td>
                            <td><?= e($rule['category_name'] ?: 'Any') ?></td>
                            <td><?= e($rule['department'] ?: 'Any') ?></td>
                            <td><?= (int) $rule['sequence'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Pending request queue</h3></div>
    <div class="panel-body flush">
        <?php if (!$pendingRequests): ?>
            <div class="empty-state">No pending procurement requests at the moment.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Amount</th>
                        <th>Required rule</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRequests as $request): ?>
                        <tr>
                            <td><?= e($request['full_name']) ?></td>
                            <td><?= e($request['product_name']) ?> (x<?= (int) $request['quantity'] ?>)</td>
                            <td><?= money((float) $request['total_amount']) ?></td>
                            <td>
                                <?php if (!empty($request['approval_rules'])): ?>
                                    <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                                        <span class="badge badge-warning"><?= e(end($request['approval_rules'])['name']) ?></span>
                                        <?php if (count($request['approval_rules']) > 1): ?>
                                            <small class="muted"><?= e(approval_rule_chain_label($request['approval_rules'])) ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">No rule matched</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= url('/admin/approvals/process.php') ?>" style="display:flex; flex-direction:column; gap:8px; min-width:240px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
                                    <textarea name="comment" rows="2" placeholder="Optional approval note or rejection reason"></textarea>
                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                        <button type="submit" name="decision" value="approved" class="btn btn-accent btn-sm">Approve</button>
                                        <button type="submit" name="decision" value="rejected" class="btn btn-outline btn-sm">Reject</button>
                                        <a href="<?= url('/admin/approvals/view.php?id=' . (int) $request['id']) ?>" class="btn btn-outline btn-sm">History</a>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
