<?php
/**
 * Customer wallets — balances, top-ups and the ledger behind them.
 *
 * A wallet is prepaid credit the customer hands over at the counter and spends
 * at the till. Balances live in customer_wallets and every movement is appended
 * to wallet_transactions, so this page is a view over that ledger rather than a
 * place to hand-edit a number: the only thing it can do is add credit, and it
 * does that through pos_wallet_move() so the row lock, the balance check and
 * the ledger entry all happen together.
 *
 * Staff can top up — taking money at the counter is what they already do to
 * complete a sale, and the till's own "Top up wallet" link points here. Every
 * top-up is written to the audit trail, because it moves real money.
 *
 * Until config/migrations.sql has been run the wallet tables do not exist, so
 * the page explains that instead of erroring.
 */
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Customer Wallets';

$walletsReady = pos_wallets_ready($pdo);
$staffId = (int) $_SESSION['user_id'];
$focusId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

/** Load a customer who may hold a wallet: real, active, not deleted. */
$loadCustomer = static function (PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name, u.email, u.phone, u.credit_limit, u.credit_terms_days,
                w.balance, w.updated_at AS wallet_updated_at
         FROM users u
         LEFT JOIN customer_wallets w ON w.user_id = u.id
         WHERE u.id = ? AND u.role = 'user' AND u.status = 'active' AND u.deleted_at IS NULL"
    );
    $stmt->execute([$userId]);

    return $stmt->fetch() ?: null;
};

// --- Top up -----------------------------------------------------------------
// Handled before any output so the browser gets a redirect (POST/redirect/GET)
// and a refresh can never top the same customer up twice.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/pos/wallets.php' . ($focusId > 0 ? '?user_id=' . $focusId : ''));

    $backTo = '/admin/pos/wallets.php' . ($focusId > 0 ? '?user_id=' . $focusId : '');

    // Crediting a wallet moves the customer's money onto their balance, so it is
    // an administrator's job. Staff can open these pages — the till links here —
    // but the POST itself is refused for them, whatever the form on screen said.
    if (!is_admin()) {
        set_flash('error', 'Only an administrator can top up a wallet.');
        redirect($backTo);
    }

    if (!$walletsReady) {
        set_flash('error', 'Wallets are not available yet. Run config/migrations.sql first.');
        redirect($backTo);
    }

    $customer = $focusId > 0 ? $loadCustomer($pdo, $focusId) : null;
    if (!$customer) {
        set_flash('error', 'That customer could not be found.');
        redirect($backTo);
    }

    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 200);

    // Only credit is added here. Taking money back off a wallet is a refund and
    // belongs on the order, so a negative amount is refused rather than flipped.
    if ($amount <= 0.005) {
        set_flash('error', 'Enter a top-up amount greater than zero.');
        redirect($backTo);
    }

    try {
        $newBalance = pos_wallet_move(
            $pdo,
            (int) $customer['id'],
            $amount,
            'topup',
            null,
            null,
            $note !== '' ? $note : null,
            $staffId
        );
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
        redirect($backTo);
    }

    log_activity(
        $pdo,
        $staffId,
        'wallet_topup',
        'Topped up ' . $customer['full_name'] . ' by ' . money($amount) . ' (balance now ' . money($newBalance) . ')'
    );

    create_notification(
        $pdo,
        (int) $customer['id'],
        'Wallet topped up',
        money($amount) . ' was added to your wallet. Your new balance is ' . money($newBalance) . '.',
        '/user/dashboard.php'
    );

    set_flash('success', 'Added ' . money($amount) . ' to ' . $customer['full_name'] . "'s wallet. New balance " . money($newBalance) . '.');
    redirect($backTo);
}

// --- The customer in focus --------------------------------------------------
$focusCustomer = null;
$focusLedger = [];
if ($focusId > 0 && $walletsReady) {
    $focusCustomer = $loadCustomer($pdo, $focusId);
    if (!$focusCustomer) {
        set_flash('error', 'That customer could not be found.');
        redirect('/admin/pos/wallets.php');
    }

    $ledgerStmt = $pdo->prepare(
        "SELECT wt.*, s.full_name AS staff_name
         FROM wallet_transactions wt
         LEFT JOIN users s ON s.id = wt.created_by
         WHERE wt.user_id = ?
         ORDER BY wt.created_at DESC, wt.id DESC
         LIMIT 50"
    );
    $ledgerStmt->execute([$focusId]);
    $focusLedger = $ledgerStmt->fetchAll();
}


// --- Wallet list ------------------------------------------------------------
$search = trim((string) ($_GET['q'] ?? ''));
$wallets = [];
$filteredTotal = 0;
$totalHeld = 0.0;
$totalPages = 1;
$page = current_page();

if ($walletsReady) {
    $whereClauses = ["u.role = 'user'", 'u.deleted_at IS NULL'];
    $params = [];

    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $whereClauses[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
        array_push($params, $like, $like, $like);
    }

    $whereSql = ' WHERE ' . implode(' AND ', $whereClauses);
    $fromSql = ' FROM customer_wallets w JOIN users u ON u.id = w.user_id';

    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
    $countStmt->execute($params);
    $filteredTotal = (int) $countStmt->fetchColumn();

    $heldStmt = $pdo->prepare('SELECT COALESCE(SUM(w.balance), 0)' . $fromSql . $whereSql);
    $heldStmt->execute($params);
    $totalHeld = (float) $heldStmt->fetchColumn();

    $perPage = 20;
    $totalPages = max(1, (int) ceil($filteredTotal / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $stmt = $pdo->prepare(
        'SELECT w.user_id, w.balance, w.updated_at, u.full_name, u.email, u.phone' . $fromSql . $whereSql
        . ' ORDER BY w.balance DESC, u.full_name ASC LIMIT ? OFFSET ?'
    );
    // Positional placeholders throughout: PDO refuses to mix these with named
    // ones, and a filtered search is exactly when both would otherwise appear.
    $index = 1;
    foreach ($params as $value) {
        $stmt->bindValue($index++, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue($index++, (int) $perPage, PDO::PARAM_INT);
    $stmt->bindValue($index++, (int) ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $wallets = $stmt->fetchAll();
}

$paginationParams = $search !== '' ? ['q' => $search] : [];

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Prepaid credit</div>
            <div>
                <div class="section-kicker">Point of sale</div>
                <h2>Customer wallets</h2>
                <p>Credit customers have paid for up front, spendable at the till.</p>
            </div>
        </div>
        <a href="<?= url('/admin/pos/index.php') ?>" class="btn btn-primary"><i class="bi bi-upc-scan me-1" aria-hidden="true"></i>Open the till</a>
    </div>

    <?php if (!$walletsReady): ?>
        <div class="alert alert-warning">
            Customer wallets are not available yet. Run <code>php config/run_migrations.php</code>
            (or import <code>config/migrations.sql</code>) to create the
            <code>customer_wallets</code> and <code>wallet_transactions</code> tables.
        </div>
    <?php else: ?>

    <?php if ($focusCustomer): ?>
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2><?= e($focusCustomer['full_name']) ?></h2>
                    <p class="admin-list-subtitle">
                        <?= e($focusCustomer['email'] ?: $focusCustomer['phone'] ?: 'No contact details') ?>
                    </p>
                </div>
                <a href="<?= url('/admin/pos/wallets.php') ?>" class="btn btn-outline btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>All wallets
                </a>
            </div>
            <div class="panel-body">
                <div class="request-stat-grid" style="margin-bottom: 0;">
                    <div class="request-stat-card is-success">
                        <div class="label">Wallet balance</div>
                        <div class="value"><?= money((float) ($focusCustomer['balance'] ?? 0)) ?></div>
                    </div>
                    <div class="request-stat-card is-alert">
                        <div class="label">Owing on credit</div>
                        <div class="value"><?= money(pos_outstanding_credit($pdo, (int) $focusCustomer['id'])) ?></div>
                    </div>
                    <div class="request-stat-card">
                        <div class="label">Credit terms</div>
                        <div class="value"><?= (int) pos_credit_terms_days($pdo, (int) $focusCustomer['id']) ?> days</div>
                    </div>
                    <div class="request-stat-card">
                        <div class="label">Credit limit</div>
                        <div class="value"><?= $focusCustomer['credit_limit'] !== null ? money($focusCustomer['credit_limit']) : 'None' ?></div>
                    </div>
                </div>
            </div>
            <div class="panel-body" style="border-top: 1px solid var(--border);">
                <h3 style="font-size: 1rem; margin-bottom: 10px;">Top up this wallet</h3>
                <?php if (is_admin()): ?>
                    <form method="post" action="<?= url('/admin/pos/wallets.php?user_id=' . (int) $focusCustomer['id']) ?>"
                          style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int) $focusCustomer['id'] ?>">
                        <div class="field" style="margin-bottom: 0;">
                            <label for="walletAmount">Amount (UGX)</label>
                            <input type="number" id="walletAmount" name="amount" min="1" step="1000" required
                                   placeholder="<?= (int) max(1000, (float) ($focusCustomer['balance'] ?? 0)) ?>" style="min-width: 180px;">
                        </div>
                        <div class="field" style="margin-bottom: 0; flex: 1; min-width: 220px;">
                            <label for="walletNote">Note (optional)</label>
                            <input type="text" id="walletNote" name="note" maxlength="200" placeholder="Cash at counter, mobile money...">
                        </div>
                        <button type="submit" class="btn btn-accent">
                            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add credit
                        </button>
                    </form>
                    <p class="muted" style="font-size: .8rem; margin: 10px 0 0;">
                        Crediting a wallet takes the customer's money, so each top-up is recorded in the
                        audit trail with your name against it.
                    </p>
                <?php else: ?>
                    <?php /* The form is withheld rather than shown disabled, so there is
                            no control on screen that the server would refuse. */ ?>
                    <p class="muted" style="font-size: .85rem; margin: 0;">
                        Adding credit takes the customer's money, so only an administrator can do it.
                        Ask an administrator to top this customer up; the balance and history below are
                        yours to read.
                    </p>
                <?php endif; ?>
            </div>
        </div>


        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Wallet history</h2>
                    <p class="admin-list-subtitle">The last <?= count($focusLedger) ?> movement<?= count($focusLedger) === 1 ? '' : 's' ?>.</p>
                </div>
            </div>
            <div class="panel-body flush">
                <table>
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Balance after</th>
                            <th>Reference</th>
                            <th>Note</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($focusLedger as $entry): ?>
                            <tr>
                                <td><?= display_date($entry['created_at']) ?></td>
                                <td><span class="badge badge-<?= (float) $entry['amount'] >= 0 ? 'success' : 'neutral' ?>"><?= e(ucfirst($entry['type'])) ?></span></td>
                                <td><?= (float) $entry['amount'] >= 0 ? '+' : '−' ?><?= money(abs((float) $entry['amount'])) ?></td>
                                <td><?= money($entry['balance_after']) ?></td>
                                <td>
                                    <?php if ($entry['order_id']): ?>
                                        <a href="<?= url('/admin/pos/receipt.php?order=' . (int) $entry['order_id']) ?>"><?= e($entry['reference'] ?: ('#' . $entry['order_id'])) ?></a>
                                    <?php else: ?>
                                        <?= e($entry['reference'] ?: '—') ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($entry['note'] ?: '—') ?></td>
                                <td><?= e($entry['staff_name'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$focusLedger): ?>
                            <tr>
                                <td colspan="7" class="empty-state">This wallet has no movements yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>


    <div class="panel">
        <div class="panel-head">
            <div>
                <h2><?= $focusCustomer ? 'All wallets' : 'Wallet balances' ?></h2>
                <p class="admin-list-subtitle"><?= $filteredTotal ?> wallet<?= $filteredTotal === 1 ? '' : 's' ?><?= $search !== '' ? ' matching your search' : '' ?>.</p>
            </div>
        </div>
        <div class="panel-body">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div class="request-stat-card is-success" style="min-width: 180px;">
                    <div class="label">Held in wallets</div>
                    <div class="value"><?= money($totalHeld) ?></div>
                </div>
                <form method="get" action="<?= url('/admin/pos/wallets.php') ?>" style="display: flex; gap: 8px; align-items: center;">
                    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, email or phone..." class="form-input form-input-sm" style="min-width: 240px;">
                    <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="<?= url('/admin/pos/wallets.php') ?>" class="btn btn-outline btn-sm">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="panel-body flush">
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Balance</th>
                        <th>Last movement</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wallets as $wallet): ?>
                        <tr>
                            <td><strong><?= e($wallet['full_name']) ?></strong></td>
                            <td><?= e($wallet['email'] ?: $wallet['phone'] ?: '—') ?></td>
                            <td>
                                <?php if ((float) $wallet['balance'] > 0.005): ?>
                                    <strong><?= money($wallet['balance']) ?></strong>
                                <?php else: ?>
                                    <span class="muted"><?= money($wallet['balance']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= display_date($wallet['updated_at']) ?></td>
                            <td class="payment-actions-cell">
                                <a href="<?= url('/admin/pos/wallets.php?user_id=' . (int) $wallet['user_id']) ?>" class="btn btn-outline btn-sm">History &amp; top up</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$wallets): ?>
                        <tr>
                            <td colspan="5" class="empty-state">
                                <?= $search !== '' ? 'No wallet matches that search.' : 'No customer holds a wallet balance yet. Open one above to start a wallet.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="panel-body">
                <?php render_pagination($page, $totalPages, '/admin/pos/wallets.php', $paginationParams); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>

