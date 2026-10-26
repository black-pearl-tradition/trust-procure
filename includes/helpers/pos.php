<?php

/**
 * Point of Sale helpers.
 *
 * The till is a thin front end over the existing catalogue, order, payment and
 * inventory tables: a counter sale is an ordinary order (channel "pos") with
 * order_items, a verified payment row and stock movements. Nothing downstream —
 * reporting, fulfillment, notifications — needs to special-case it beyond
 * looking at the channel.
 *
 * Every schema addition the till relies on is probed once and degraded safely,
 * so the pages stay usable (with a warning) until config/migrations.sql has
 * been run on the database.
 *
 * Walk-in customers are not special-cased in the orders table: they are booked
 * against one shared placeholder account (POS_WALKIN_EMAIL, role "user"),
 * created on first use, with the name typed at the counter kept in
 * orders.walk_in_customer_name. Use pos_sale_customer_name() to show who a sale
 * was really to, rather than reading orders.user_id directly.
 */

const POS_WALKIN_EMAIL = 'walkin@tps.local';
const POS_WALKIN_NAME = 'Walk-in Customer';
const POS_CART_SESSION_KEY = 'pos_cart';

/** Sales channels an order can come from. */
function order_channel_labels(): array
{
    return ['online' => 'Online', 'pos' => 'Point of Sale'];
}

function order_channel_label(string $channel): string
{
    return order_channel_labels()[$channel] ?? ucfirst($channel);
}

function order_channel_badge_class(string $channel): string
{
    return $channel === 'pos' ? 'info' : 'neutral';
}

/** Payment methods offered at the counter. */
function pos_payment_methods(): array
{
    return [
        'cash'          => 'Cash',
        'mobile_money'  => 'Mobile Money',
        'card'          => 'Card',
        'wallet'        => 'Customer Wallet',
        'credit'        => 'Credit (pay later)',
    ];
}

function pos_payment_method_label(string $method): string
{
    return pos_payment_methods()[$method] ?? ucfirst(str_replace('_', ' ', $method));
}

/**
 * Payment methods this installation can actually accept — the wallet is hidden
 * until its tables exist, so the till never offers a payment it cannot record.
 */
function pos_available_methods(PDO $pdo): array
{
    $methods = pos_payment_methods();
    if (!pos_wallets_ready($pdo)) {
        unset($methods['wallet']);
    }

    return $methods;
}

/** Receipt number for a till sale, derived from the order's own primary key. */
function pos_order_number(int $orderId, ?string $year = null): string
{
    return 'POS-' . ($year ?? date('Y')) . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
}

/** True once the POS columns exist (products.sku/barcode, orders.channel, ...). */
function pos_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
               AND COLUMN_NAME IN ('sku', 'barcode')"
        );
        $ready = (int) $stmt->fetchColumn() === 2;
    }

    return $ready;
}

/** True when the payments.payment_type enum accepts the POS value. */
function pos_payments_support_sale_type(PDO $pdo): bool
{
    static $supports = null;
    if ($supports === null) {
        $stmt = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_type'"
        );
        $columnType = (string) $stmt->fetchColumn();
        $supports = str_contains($columnType, "'pos'");
    }

    return $supports;
}

/**
 * The payment_type to store for counter takings, falling back to "deposit" when
 * the enum has not been widened yet so the sale is still recorded.
 */
function pos_payment_record_type(PDO $pdo): string
{
    return pos_payments_support_sale_type($pdo) ? 'pos' : 'deposit';
}

/** Column list for product queries, omitting scanning columns when absent. */
function pos_product_columns(PDO $pdo): string
{
    $scan = pos_schema_ready($pdo) ? 'p.sku, p.barcode' : 'NULL AS sku, NULL AS barcode';

    return 'p.id, p.name, p.brand, p.slug, p.image, p.price, p.category_id,
            p.stock_quantity, p.reserved_quantity, p.stock_status, p.low_stock_threshold,
            ' . $scan . ', c.name AS category_name';
}

/** Units a customer could actually buy right now. */
function pos_available_quantity(array $product): int
{
    if (($product['stock_status'] ?? '') === 'made_to_order') {
        return PHP_INT_MAX;
    }

    return max(0, (int) $product['stock_quantity'] - (int) $product['reserved_quantity']);
}

/** True when the product may be sold when out of stock (built/ordered on demand). */
function pos_is_made_to_order(array $product): bool
{
    return ($product['stock_status'] ?? '') === 'made_to_order';
}

/**
 * The shared account used when the buyer is not a registered customer.
 * Created on demand with an unguessable password: the account exists only to
 * satisfy the orders.user_id foreign key.
 */
function pos_walk_in_user_id(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([POS_WALKIN_EMAIL]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, "user", "active")')
        ->execute([POS_WALKIN_NAME, POS_WALKIN_EMAIL, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);

    return (int) $pdo->lastInsertId();
}

/**
 * True when a sale was booked against the shared walk-in placeholder account
 * rather than a registered customer. The order row must carry the joined
 * customer_email for this to tell.
 */
function pos_is_walk_in_sale(array $order): bool
{
    return strcasecmp((string) ($order['customer_email'] ?? ''), POS_WALKIN_EMAIL) === 0;
}

/**
 * The name a till sale should be shown under.
 *
 * The till snapshots who it served onto the sale (pos_contact_name), which is
 * what a receipt should print. Older sales predate that column, so they fall
 * back to the walk-in name and then to the linked account.
 */
function pos_sale_customer_name(array $order): string
{
    $captured = trim((string) ($order['pos_contact_name'] ?? ''));
    if ($captured !== '') {
        return $captured;
    }

    if (pos_is_walk_in_sale($order)) {
        $typed = trim((string) ($order['walk_in_customer_name'] ?? ''));

        return $typed !== '' ? $typed : POS_WALKIN_NAME;
    }

    return (string) ($order['customer_name'] ?? '');
}

/**
 * The phone captured at the till.
 *
 * A walk-in is booked against one shared placeholder account, so its phone is
 * nobody's — falling back to it would print the shop's own placeholder number on
 * a customer's receipt. Only account customers fall back.
 */
function pos_sale_contact_phone(array $order): string
{
    $captured = trim((string) ($order['pos_contact_phone'] ?? ''));
    if ($captured !== '') {
        return $captured;
    }

    return pos_is_walk_in_sale($order) ? '' : (string) ($order['customer_phone'] ?? '');
}

/** The email captured at the till, with the same walk-in caveat as the phone. */
function pos_sale_contact_email(array $order): string
{
    $captured = trim((string) ($order['pos_contact_email'] ?? ''));
    if ($captured !== '') {
        return $captured;
    }

    return pos_is_walk_in_sale($order) ? '' : (string) ($order['customer_email'] ?? '');
}

/**
 * Resolve a scanned or typed code to a sellable product.
 * Barcode first (a scan is the common case), then SKU, then a numeric product id.
 */
function pos_find_product(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    $columns = pos_product_columns($pdo);
    $base = "SELECT $columns FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status = 'active'";

    if (pos_schema_ready($pdo)) {
        $stmt = $pdo->prepare($base . ' AND (p.barcode = ? OR p.sku = ?) ORDER BY p.id LIMIT 1');
        $stmt->execute([$code, $code]);
        $product = $stmt->fetch();
        if ($product) {
            return $product;
        }
    }

    if (ctype_digit($code)) {
        $stmt = $pdo->prepare($base . ' AND p.id = ? LIMIT 1');
        $stmt->execute([(int) $code]);

        return $stmt->fetch() ?: null;
    }

    return null;
}

/**
 * Products offered on the till: a keyword search across name, brand, SKU and
 * barcode, optionally limited to one category.
 */
function pos_search_products(PDO $pdo, string $term = '', int $categoryId = 0, int $limit = 30): array
{
    $columns = pos_product_columns($pdo);
    $sql = "SELECT $columns FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status = 'active'";
    $params = [];

    $term = trim($term);
    if ($term !== '') {
        // LIKE wildcards typed by the operator are escaped so "50%" is literal.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $searchable = 'p.name LIKE ? OR p.brand LIKE ?';
        $params[] = $like;
        $params[] = $like;
        if (pos_schema_ready($pdo)) {
            $searchable .= ' OR p.sku LIKE ? OR p.barcode LIKE ?';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= " AND ($searchable)";
    }

    if ($categoryId > 0) {
        $sql .= ' AND p.category_id = ?';
        $params[] = $categoryId;
    }

    // Sellable lines first, then alphabetical, so the grid leads with stock.
    $sql .= " ORDER BY (p.stock_status = 'out_of_stock') ASC, p.name ASC LIMIT " . max(1, min(200, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/** Categories that currently hold at least one active product. */
function pos_categories(PDO $pdo): array
{
    return $pdo->query(
        "SELECT c.id, c.name, COUNT(p.id) AS product_count
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
         GROUP BY c.id, c.name
         HAVING product_count > 0
         ORDER BY c.name"
    )->fetchAll();
}

/** Live cart meta (discount, customer, note, payment method) plus item ids. */
function pos_cart(): array
{
    $cart = $_SESSION[POS_CART_SESSION_KEY] ?? [];
    if (!is_array($cart)) {
        $cart = [];
    }
    $cart += [
        'items' => [],
        'discount' => 0.0,
        'customer_id' => 0,
        // Who is being served. Kept as a sale-level snapshot rather than only a
        // customer id, so a walk-in can be recorded and an account customer's
        // details can be corrected for this sale alone.
        'contact_name' => '',
        'contact_phone' => '',
        'contact_email' => '',
        'note' => '',
        'method' => 'cash',
        'tendered' => 0.0,
        'reference' => '',
    ];
    if (!is_array($cart['items'])) {
        $cart['items'] = [];
    }

    return $cart;
}

function pos_cart_save(array $cart): void
{
    $_SESSION[POS_CART_SESSION_KEY] = $cart;
}

/** Set one meta field on the cart (only the known keys are accepted). */
function pos_cart_set(string $field, $value): void
{
    if (!in_array($field, [
        'discount', 'customer_id', 'contact_name', 'contact_phone', 'contact_email',
        'note', 'method', 'tendered', 'reference',
    ], true)) {
        return;
    }

    $cart = pos_cart();
    $cart[$field] = $value;
    pos_cart_save($cart);
}

/**
 * Add (or top up) a product in the cart, never beyond what is on the shelf.
 * Returns [product, added] so the till can report what actually happened.
 */
function pos_cart_add(PDO $pdo, int $productId, int $quantity = 1): array
{
    $quantity = max(1, $quantity);
    $columns = pos_product_columns($pdo);
    $stmt = $pdo->prepare("SELECT $columns FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.status = 'active'");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        return [null, 0];
    }

    $cart = pos_cart();
    $current = (int) ($cart['items'][$productId] ?? 0);
    $madeToOrder = pos_is_made_to_order($product);
    $max = pos_available_quantity($product);

    if (!$madeToOrder && $max <= 0) {
        return [$product, 0];
    }

    $target = $current + $quantity;
    if (!$madeToOrder) {
        $target = min($target, $max);
    }

    $added = $target - $current;
    $cart['items'][$productId] = $target;
    pos_cart_save($cart);

    return [$product, $added];
}

/** Set an exact quantity (0 or less removes the line). */
function pos_cart_update(PDO $pdo, int $productId, int $quantity): void
{
    $cart = pos_cart();
    if (!isset($cart['items'][$productId])) {
        return;
    }

    if ($quantity <= 0) {
        unset($cart['items'][$productId]);
        pos_cart_save($cart);

        return;
    }

    $columns = pos_product_columns($pdo);
    // The column list carries c.name, so this query needs the categories join too.
    $stmt = $pdo->prepare("SELECT $columns FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if ($product && !pos_is_made_to_order($product)) {
        $quantity = min($quantity, max(1, pos_available_quantity($product)));
    }

    $cart['items'][$productId] = $quantity;
    pos_cart_save($cart);
}

function pos_cart_remove(int $productId): void
{
    $cart = pos_cart();
    unset($cart['items'][$productId]);
    pos_cart_save($cart);
}

function pos_cart_clear(): void
{
    unset($_SESSION[POS_CART_SESSION_KEY]);
}

/**
 * The whole cart, priced and validated against live catalogue data.
 * Products that were deleted, archived or sold out since being added are
 * dropped (or trimmed) here rather than at checkout.
 */
function pos_cart_state(PDO $pdo, bool $pruneUnavailable = true): array
{
    $cart = pos_cart();
    $lines = [];
    $subtotal = 0.0;
    $changed = false;

    if ($cart['items']) {
        $ids = array_map('intval', array_keys($cart['items']));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $columns = pos_product_columns($pdo);
        $stmt = $pdo->prepare("SELECT $columns FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id IN ($placeholders)");
        $stmt->execute($ids);
        $products = [];
        foreach ($stmt->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        foreach ($cart['items'] as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = max(1, (int) $quantity);
            $product = $products[$productId] ?? null;

            if (!$product) {
                unset($cart['items'][$productId]);
                $changed = true;
                continue;
            }

            $madeToOrder = pos_is_made_to_order($product);
            $available = pos_available_quantity($product);
            $short = !$madeToOrder && $available < $quantity;

            if ($short && $pruneUnavailable) {
                $quantity = $available;
                if ($quantity <= 0) {
                    unset($cart['items'][$productId]);
                    $changed = true;
                    continue;
                }
                $cart['items'][$productId] = $quantity;
                $changed = true;
            }

            $unitPrice = (float) $product['price'];
            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;

            $lines[] = [
                'product_id' => $productId,
                'name' => (string) $product['name'],
                'brand' => $product['brand'],
                'sku' => $product['sku'] ?? null,
                'barcode' => $product['barcode'] ?? null,
                'image' => $product['image'],
                'category_name' => $product['category_name'],
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'available' => $madeToOrder ? null : $available,
                'made_to_order' => $madeToOrder,
                'short' => $short,
            ];
        }
    }

    if ($changed) {
        pos_cart_save($cart);
    }

    $discount = max(0.0, (float) $cart['discount']);
    if ($discount > $subtotal) {
        $discount = $subtotal;
    }

    $customer = null;
    if ((int) $cart['customer_id'] > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name, email, phone FROM users WHERE id = ? AND role = 'user' AND deleted_at IS NULL");
        $stmt->execute([(int) $cart['customer_id']]);
        $customer = $stmt->fetch() ?: null;
    }

    $method = (string) $cart['method'];
    if (!array_key_exists($method, pos_available_methods($pdo))) {
        $method = 'cash';
    }

    return [
        'lines' => $lines,
        'item_count' => (int) array_sum(array_column($lines, 'quantity')),
        'line_count' => count($lines),
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => max(0.0, $subtotal - $discount),
        'customer' => $customer,
        'customer_id' => (int) $cart['customer_id'],
        'contact_name' => (string) $cart['contact_name'],
        'contact_phone' => (string) $cart['contact_phone'],
        'contact_email' => (string) $cart['contact_email'],
        'note' => (string) $cart['note'],
        'method' => $method,
        'tendered' => max(0.0, (float) $cart['tendered']),
        'reference' => (string) $cart['reference'],
    ];
}

/** Registered customers matching a name, email, phone or id. */
function pos_search_customers(PDO $pdo, string $term, int $limit = 8): array
{
    $term = trim($term);
    if ($term === '') {
        return [];
    }

    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, phone
         FROM users
         WHERE role = 'user' AND status = 'active' AND deleted_at IS NULL
           AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR CAST(id AS CHAR) = ?)
         ORDER BY full_name
         LIMIT " . max(1, min(50, $limit))
    );
    $stmt->execute([$like, $like, $like, $term]);

    return $stmt->fetchAll();
}

/** Invoice number for a till sale, derived from the order's own primary key. */
function pos_invoice_number(int $orderId, ?string $year = null): string
{
    return 'INV-' . ($year ?? date('Y')) . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
}

/** True once the wallet tables exist (config/migrations.sql has been run). */
function pos_wallets_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('customer_wallets', 'wallet_transactions')"
        );
        $ready = (int) $stmt->fetchColumn() === 2;
    }

    return $ready;
}

/** The wallet row for a customer, created on first read. */
function pos_wallet(PDO $pdo, int $userId): array
{
    if (!pos_wallets_ready($pdo)) {
        return ['user_id' => $userId, 'balance' => 0.0];
    }

    $stmt = $pdo->prepare('SELECT user_id, balance FROM customer_wallets WHERE user_id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();

    if ($wallet) {
        return $wallet;
    }

    $pdo->prepare('INSERT INTO customer_wallets (user_id, balance) VALUES (?, 0.00)')->execute([$userId]);

    return ['user_id' => $userId, 'balance' => 0.0];
}

/** Current wallet balance (0.00 when the customer has no wallet yet). */
function pos_wallet_balance(PDO $pdo, int $userId): float
{
    if (!pos_wallets_ready($pdo)) {
        return 0.0;
    }

    $stmt = $pdo->prepare('SELECT balance FROM customer_wallets WHERE user_id = ?');
    $stmt->execute([$userId]);

    return (float) ($stmt->fetchColumn() ?: 0);
}

/**
 * Credit (+) or debit (-) a wallet and append a ledger entry in one go.
 *
 * The row is locked for the whole read-modify-write so two tills can never
 * spend the same balance twice. Debits that would take the balance below zero
 * are refused unless $allowNegative is set (refunds of money already taken).
 * Returns the resulting balance; throws RuntimeException when refused.
 */
function pos_wallet_move(
    PDO $pdo,
    int $userId,
    float $amount,
    string $type,
    ?string $reference = null,
    ?int $orderId = null,
    ?string $note = null,
    ?int $createdBy = null,
    bool $allowNegative = false
): float {
    if (!pos_wallets_ready($pdo)) {
        throw new RuntimeException('Wallets are not available yet. Run config/migrations.sql first.');
    }
    if (!in_array($type, ['topup', 'sale', 'refund', 'adjustment'], true)) {
        throw new RuntimeException('Unknown wallet transaction type.');
    }
    if (abs($amount) < 0.005) {
        throw new RuntimeException('Enter an amount greater than zero.');
    }

    $wallet = pos_wallet($pdo, $userId); // SELECT ... FOR UPDATE
    $newBalance = round((float) $wallet['balance'] + $amount, 2);

    if ($newBalance < -0.005 && !$allowNegative) {
        throw new RuntimeException('The wallet balance of ' . money($wallet['balance']) . ' cannot cover ' . money(abs($amount)) . '.');
    }

    $pdo->prepare('UPDATE customer_wallets SET balance = ? WHERE user_id = ?')
        ->execute([max(0.0, $newBalance), $userId]);

    $pdo->prepare(
        'INSERT INTO wallet_transactions (user_id, type, amount, balance_after, reference, order_id, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $type, $amount, max(0.0, $newBalance), $reference, $orderId, $note, $createdBy]);

    return max(0.0, $newBalance);
}

/** Verified amount already paid against one order. */
function pos_order_paid_amount(PDO $pdo, int $orderId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'verified'");
    $stmt->execute([$orderId]);

    return (float) $stmt->fetchColumn();
}

/** Total amount still owed by a customer on orders not yet fully paid. */
function pos_outstanding_credit(PDO $pdo, int $userId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(o.balance_amount), 0)
         FROM orders o
         WHERE o.user_id = ?
           AND o.payment_status IN ('unpaid', 'partially_paid')
           AND o.status NOT IN ('cancelled', 'request_rejected')"
    );
    $stmt->execute([$userId]);

    return (float) $stmt->fetchColumn();
}

/** Days after the sale that a customer's credit is due (30 by default). */
function pos_credit_terms_days(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT credit_terms_days FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $terms = $stmt->fetchColumn();

    return $terms === false || $terms === null ? 30 : max(1, (int) $terms);
}

/** Credit ceiling for a customer; null means "no ceiling configured". */
function pos_credit_limit(PDO $pdo, int $userId): ?float
{
    $stmt = $pdo->prepare('SELECT credit_limit FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $limit = $stmt->fetchColumn();

    return $limit === false || $limit === null ? null : (float) $limit;
}

/**
 * Can this customer take another $amount on credit?
 * Returns [allowed, reason] so the till can explain the refusal.
 */
function pos_credit_allowed(PDO $pdo, int $userId, float $amount): array
{
    $owed = pos_outstanding_credit($pdo, $userId);
    $limit = pos_credit_limit($pdo, $userId);

    if ($limit !== null && $owed + $amount > $limit + 0.005) {
        return [false, 'This would exceed the credit limit of ' . money($limit) . ' (already owing ' . money($owed) . ').'];
    }

    return [true, ''];
}

/** Position of a sale's stock: whether it is sold from on-hand stock. */
function pos_is_sellable_stock(array $product): bool
{
    return ($product['stock_status'] ?? '') !== 'made_to_order';
}


