<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Audit Logs';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

$total = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$totalPages = max(1, (int) ceil($total / $perPage));

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>System activity</h2></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>User</th><th>Action</th><th>Details</th><th>IP address</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= e($log['full_name'] ?? 'System') ?></td>
                    <td><span class="badge badge-neutral"><?= e(str_replace('_', ' ', $log['action'])) ?></span></td>
                    <td class="muted"><?= e($log['description'] ?: '—') ?></td>
                    <td class="muted"><?= e($log['ip_address'] ?: '—') ?></td>
                    <td><?= display_date($log['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr><td colspan="5" class="empty-state">No activity recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="panel-body" style="display:flex; justify-content: center; gap: 8px;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="<?= url('/admin/audit_logs/index.php?page=' . $i) ?>" class="btn btn-<?= $i === $page ? 'primary' : 'outline' ?> btn-sm"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
