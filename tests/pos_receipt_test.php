<?php

/**
 * Live end-to-end test for the point-of-sale terminal and its receipt.
 *
 * Drives http://localhost/tps like a browser: logs in as a staff account with a
 * cookie jar, rings up a real sale against a tagged product fixture, and asserts
 * that checkout lands on a printable receipt carrying the sale's own figures.
 * Also checks the two ways the receipt can be refused (unknown order, an order
 * that was not rung up at the till) and that the page stays closed to anonymous
 * visitors.
 *
 * Every fixture is created with a random tag and deleted again at the end, so
 * the database is left as it was found. Prints PASS/SKIP lines and exits
 * non-zero on the first hard failure; skips itself when the web server or the
 * database is unavailable.
 */

const POS_TEST_BASE_URL = 'http://localhost/tps';
const POS_TEST_PASSWORD = 'TillTest123';
const POS_TEST_NAME = 'POS Test Item';

$posTestFailures = 0;
$posTestSkips = 0;

function pos_test_check(bool $condition, string $message): void
{
    global $posTestFailures;

    if ($condition) {
        echo "PASS  $message\n";

        return;
    }

    $posTestFailures++;
    echo "FAIL  $message\n";
    throw new RuntimeException('POS test stopped at first failure: ' . $message);
}

function pos_test_skip(string $reason): void
{
    global $posTestSkips;

    $posTestSkips++;
    echo 'SKIP  ' . $reason . "\n";
}

function pos_test_tag(): string
{
    return substr(bin2hex(random_bytes(4)), 0, 8);
}

function pos_test_pdo(): ?PDO
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: 'tr';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    try {
        return new PDO(
            'mysql:host=' . $host . ';port=3306;dbname=' . $name . ';charset=utf8mb4',
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        return null;
    }
}

function pos_test_server_up(): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, POS_TEST_BASE_URL . '/index.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    return $ok !== false && $code === 200;
}

/** Minimal cookie-jar HTTP client that tracks the latest CSRF token. */
final class PosTestClient
{
    private string $jar = '';
    private string $token = '';
    private int $lastStatus = 0;
    private string $lastBody = '';
    private string $lastLocation = '';

    public function __construct()
    {
        $jar = tempnam(sys_get_temp_dir(), 'pos-jar-');
        $this->jar = is_string($jar) ? $jar : '';
    }

    public function __destruct()
    {
        if ($this->jar !== '' && is_file($this->jar)) {
            @unlink($this->jar);
        }
    }

    public function get(string $path): string
    {
        // Redirects are not followed: guards are asserted on the raw status and
        // Location header, then followed explicitly.
        return $this->request('GET', $path, []);
    }

    /** @param array<string,string> $fields */
    public function post(string $path, array $fields): string
    {
        return $this->request('POST', $path, $fields);
    }

    public function follow(): string
    {
        $target = $this->lastLocation !== '' ? $this->lastLocation : '/index.php';
        if (str_starts_with($target, POS_TEST_BASE_URL)) {
            $target = substr($target, strlen(POS_TEST_BASE_URL));
            if ($target === '' || $target === false) {
                $target = '/index.php';
            }
        }

        return $this->get($target);
    }

    public function status(): int
    {
        return $this->lastStatus;
    }

    public function body(): string
    {
        return $this->lastBody;
    }

    public function location(): string
    {
        return $this->lastLocation;
    }

    public function token(): string
    {
        return $this->token;
    }

    /** @param array<string,string> $fields */
    private function request(string $method, string $path, array $fields): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, POS_TEST_BASE_URL . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->jar);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $response = (string) curl_exec($ch);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $this->lastBody = substr($response, $headerSize);
        $this->lastLocation = '';

        if (preg_match_all('/^Location:\s*(\S+)/mi', $headers, $matches) && !empty($matches[1])) {
            $this->lastLocation = trim((string) end($matches[1]));
        }

        if (preg_match('/name="csrf_token" value="([^"]+)"/', $this->lastBody, $match)) {
            $this->token = $match[1];
        }

        return $this->lastBody;
    }
}

require_once __DIR__ . '/../includes/functions.php';

// --- Availability guards --------------------------------------------------
if (!function_exists('curl_init')) {
    pos_test_skip('cURL is not available.');

    exit(0);
}

$pdo = pos_test_pdo();
if (!$pdo instanceof PDO) {
    pos_test_skip('database is not reachable.');

    exit(0);
}

if (!pos_test_server_up()) {
    pos_test_skip('web server does not answer at ' . POS_TEST_BASE_URL);

    exit(0);
}

// --- Tagged fixtures ------------------------------------------------------

$tag = pos_test_tag();
$staffEmail = 'posstaff-' . $tag . '@example.test';
$adminEmail = 'posadmin-' . $tag . '@example.test';
$customerEmail = 'poscustomer-' . $tag . '@example.test';
$productName = POS_TEST_NAME . ' ' . strtoupper($tag);
$productSlug = 'pos-test-' . $tag;
$productPrice = 250000.00;
$productStock = 7;

$staffId = 0;
$adminId = 0;
$customerId = 0;
$productId = 0;
$orderId = 0;
$orderIds = [];

$pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, "staff", "active")')
    ->execute(['POS Test Staff', $staffEmail, password_hash(POS_TEST_PASSWORD, PASSWORD_DEFAULT)]);
$staffId = (int) $pdo->lastInsertId();

// An administrator is needed because crediting a wallet is deliberately above a
// staff member's pay grade; the test needs both roles to prove the boundary.
$pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, "admin", "active")')
    ->execute(['POS Test Admin', $adminEmail, password_hash(POS_TEST_PASSWORD, PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, "user", "active")')
    ->execute(['POS Test Customer', $customerEmail, '070' . $tag, password_hash(POS_TEST_PASSWORD, PASSWORD_DEFAULT)]);
$customerId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO products (name, slug, sku, barcode, brand, price, stock_quantity, stock_status, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, "in_stock", "active")'
)->execute([
    $productName,
    $productSlug,
    'POSSKU-' . strtoupper($tag),
    'POSBAR-' . strtoupper($tag),
    'TestBrand',
    $productPrice,
    $productStock,
]);
$productId = (int) $pdo->lastInsertId();

$cleanup = static function () use ($pdo, &$orderId, &$orderIds, $productId, $customerId, $staffId, $adminId, $tag): void {
    // order_items, payments, order_events and inventory_movements all
    // cascade from the order.
    foreach ($orderIds as $id) {
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
    }
    if ($orderId > 0) {
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
    }
    if ($productId > 0) {
        $pdo->prepare('DELETE FROM inventory_movements WHERE product_id = ?')->execute([$productId]);
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
    }
    // Products created through the real product form further down. The tag can
    // sit a word or two into the name ("POSCoded Blank <tag>"), so match on the
    // prefix and the tag rather than assuming they are adjacent.
    $codedPattern = 'POSCoded%' . strtoupper((string) $tag) . '%';
    $pdo->prepare('DELETE FROM inventory_movements WHERE product_id IN (SELECT id FROM products WHERE name LIKE ?)')
        ->execute([$codedPattern]);
    $pdo->prepare('DELETE FROM products WHERE name LIKE ?')->execute([$codedPattern]);
    if ($customerId > 0) {
        $pdo->prepare('DELETE FROM wallet_transactions WHERE user_id = ?')->execute([$customerId]);
        $pdo->prepare('DELETE FROM customer_wallets WHERE user_id = ?')->execute([$customerId]);
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$customerId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$customerId]);
    }
    if ($staffId > 0) {
        $pdo->prepare('DELETE FROM wallet_transactions WHERE user_id = ?')->execute([$staffId]);
        $pdo->prepare('DELETE FROM customer_wallets WHERE user_id = ?')->execute([$staffId]);
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$staffId]);
        $pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$staffId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$staffId]);
    }
    if ($adminId > 0) {
        $pdo->prepare('DELETE FROM wallet_transactions WHERE user_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM customer_wallets WHERE user_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
    }
};

try {
    // --- Security boundary (no session yet) --------------------------------
    $anon = new PosTestClient();
    $anon->get('/admin/pos/receipt.php?order=1');
    pos_test_check(
        $anon->status() !== 200 && str_contains($anon->location(), 'login.php'),
        'the till receipt is closed to anonymous visitors'
    );

    // --- Staff login --------------------------------------------------------
    $client = new PosTestClient();
    $client->get('/login.php');
    pos_test_check($client->token() !== '', 'login page exposes a CSRF token');

    $client->post('/login.php', ['email' => $staffEmail, 'password' => POS_TEST_PASSWORD, 'csrf_token' => $client->token()]);
    pos_test_check(
        $client->status() === 302 && str_contains($client->location(), 'admin/dashboard.php'),
        'staff login redirects to the admin dashboard'
    );

    // --- The till itself ----------------------------------------------------
    $till = $client->get('/admin/pos/index.php');
    pos_test_check($client->status() === 200 && str_contains($till, 'Point of Sale'), 'the till renders for staff');
    pos_test_check($client->token() !== '', 'the till exposes a CSRF token for its forms');

    // assets/js/pos.js enhances the till by swapping the cart panel in place,
    // so the page has to keep providing the hooks it looks for.
    pos_test_check(str_contains($till, '/assets/js/pos.js'), 'the till loads assets/js/pos.js');
    pos_test_check(
        (bool) preg_match('~id="pos-cart-panel">\s*<div class="panel">~', $till),
        'the till exposes the cart panel the script swaps'
    );
    pos_test_check(
        (bool) preg_match('~id="posScanForm".*?name="code"~s', $till),
        'the till exposes the quick-add box the script refocuses after a scan'
    );

    // A barcode scanner types into whatever holds focus and presses Enter, so
    // the box that adds to the sale has to be the one that starts focused.
    pos_test_check(
        (bool) preg_match('~name="code"[^>]*autofocus~s', $till),
        'the scan box takes the focus on page load'
    );
    pos_test_check(
        substr_count($till, 'autofocus') === 1,
        'only the scan box is autofocused, so searching does not steal it'
    );
    pos_test_check(
        strpos($till, 'id="posScanForm"') !== false
            && strpos($till, 'id="posSearch"') !== false
            && strpos($till, 'id="posScanForm"') < strpos($till, 'id="posSearch"'),
        'the scan box comes before the search box, so tabbing reaches the search'
    );
    pos_test_check(
        (bool) preg_match('~id="posCheckoutForm".*?data-confirm~s', $till),
        'the checkout button the script re-binds a confirmation to is present'
    );
    pos_test_check(
        str_contains($till, '.pos-status'),
        'the till styles the status line the script writes into'
    );

    // Every selector pos.js reaches for must exist in the page it enhances.
    // These are read out of the script rather than listed by hand, so a new DOM
    // dependency added to the script fails here instead of silently breaking in
    // a browser — this project has no browser in its test setup.
    $posScriptPath = __DIR__ . '/../assets/js/pos.js';
    pos_test_check(is_file($posScriptPath), 'assets/js/pos.js exists on disk');
    $posScript = (string) file_get_contents($posScriptPath);

    preg_match_all('~getElementById\("([^"]+)"\)|querySelector\(\s*[\'"]#([A-Za-z0-9_-]+)~', $posScript, $posIdMatches);
    $posScriptIds = array_values(array_unique(array_filter(array_merge(
        $posIdMatches[1],
        $posIdMatches[2]
    ))));
    preg_match_all('~\[(?:name|type)="([^"]+)"\]~', $posScript, $posAttrMatches);
    $posScriptAttrs = array_values(array_unique($posAttrMatches[1]));
    preg_match_all('~querySelector\("\.([A-Za-z0-9_-]+)"\)~', $posScript, $posClassMatches);
    $posScriptClasses = array_values(array_unique($posClassMatches[1]));

    // Guard against the extraction quietly matching nothing and passing blind.
    pos_test_check(count($posScriptIds) >= 3, 'pos.js element selectors were extracted for checking');
    pos_test_check(count($posScriptAttrs) >= 2, 'pos.js attribute selectors were extracted for checking');

    foreach ($posScriptIds as $posId) {
        pos_test_check(
            str_contains($till, 'id="' . $posId . '"'),
            "pos.js reaches for #{$posId}, which the till provides"
        );
    }
    foreach ($posScriptAttrs as $posAttr) {
        // The extracted text is the attribute's *value*, which the page carries
        // as name="code" / type="submit", not as code="...".
        pos_test_check(
            str_contains($till, '="' . $posAttr . '"'),
            "pos.js selects [{$posAttr}], which the till provides"
        );
    }
    foreach ($posScriptClasses as $posClass) {
        pos_test_check(
            str_contains($till, 'class="' . $posClass . '"'),
            "pos.js selects .{$posClass}, which the till provides"
        );
    }

    // The till has to be reachable from the sidebar, not just by typing its URL.
    // This session is a plain staff account, so this also proves the link is not
    // hidden behind the admin-only sections.
    pos_test_check(
        (bool) preg_match('~<a href="[^"]*admin/pos/index\.php"[^>]*>\s*<i class="bi bi-upc-scan~', $till),
        'a staff account is offered the Point of Sale link in the sidebar'
    );
    pos_test_check(
        str_contains($till, 'Point of Sale</span>'),
        'the sidebar link is labelled Point of Sale'
    );
    pos_test_check(
        (bool) preg_match('~<a href="[^"]*admin/pos/index\.php" class="active"~', $till),
        'the Point of Sale link is highlighted while the till is open'
    );

    // The sidebar highlights by substring match, so "pos/" must not light the
    // till up from elsewhere — Customer Orders and Purchase Orders are the
    // pages most likely to collide.
    $ordersPage = $client->get('/admin/orders/index.php');
    pos_test_check($client->status() === 200, 'the customer orders page renders');
    pos_test_check(
        (bool) preg_match('~<a href="[^"]*admin/pos/index\.php" class=""~', $ordersPage),
        'the Point of Sale link is not highlighted on an unrelated admin page'
    );
    pos_test_check(
        (bool) preg_match('~<a href="[^"]*admin/orders/index\.php(?:\?[^"]*)?" class="active"~', $ordersPage),
        'Customer Orders still highlights itself correctly'
    );

    // Barcode scanning resolves straight to the product, so the fixture is sold
    // the way a scanner would sell it.
    $cart = $client->post('/admin/pos/cart.php', [
        'action' => 'add',
        'code' => 'POSBAR-' . strtoupper($tag),
        'quantity' => '2',
        'csrf_token' => $client->token(),
    ]);
    $cartJson = json_decode($cart, true);
    pos_test_check(
        is_array($cartJson) && ($cartJson['success'] ?? false) === true && (int) ($cartJson['count'] ?? 0) === 2,
        'a scanned barcode adds the product to the cart'
    );

    // assets/js/pos.js swaps result.cart_html into the panel and reads these
    // fields for its status line, so the payload shape is part of the contract.
    pos_test_check(
        is_array($cartJson)
            && array_key_exists('message', $cartJson)
            && array_key_exists('total', $cartJson)
            && is_string($cartJson['cart_html'] ?? null)
            && str_contains($cartHtml = (string) ($cartJson['cart_html'] ?? ''), 'id="posCheckoutForm"'),
        'the cart endpoint returns the fields and re-rendered panel the script needs'
    );
    pos_test_check(
        str_contains((string) ($cartJson['cart_html'] ?? ''), $productName),
        'the returned panel already reflects the new line'
    );

    // Every cart action the panel can post. The quantity stepper in particular
    // reads the product through a different query than the one that added it,
    // so each action is exercised rather than assumed.
    $cartAction = static function (array $fields) use ($client): array {
        $response = $client->post('/admin/pos/cart.php', $fields + ['csrf_token' => $client->token()]);
        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : ['success' => false, 'count' => -1, 'message' => $response];
    };

    $stepped = $cartAction(['action' => 'update', 'product_id' => (string) $productId, 'quantity' => '3']);
    pos_test_check(
        ($stepped['success'] ?? false) === true && (int) ($stepped['count'] ?? 0) === 3,
        'the quantity stepper sets how many are being sold'
    );
    pos_test_check(
        (float) ($stepped['total'] ?? 0) === $productPrice * 3,
        'the stepper re-prices the sale'
    );

    $trimmed = $cartAction(['action' => 'update', 'product_id' => (string) $productId, 'quantity' => '0']);
    pos_test_check(
        ($trimmed['success'] ?? false) === true && (int) ($trimmed['count'] ?? -1) === 0,
        'stepping down to zero removes the line'
    );

    $reAdded = $cartAction(['action' => 'add', 'product_id' => (string) $productId, 'quantity' => '2']);
    pos_test_check(
        ($reAdded['success'] ?? false) === true && (int) ($reAdded['count'] ?? 0) === 2,
        'the line can be added back by product id'
    );

    $removed = $cartAction(['action' => 'remove', 'product_id' => (string) $productId]);
    pos_test_check(
        ($removed['success'] ?? false) === true && (int) ($removed['count'] ?? -1) === 0,
        'removing a line clears it from the sale'
    );

    $discounted = $cartAction(['action' => 'set_discount', 'discount' => '50000']);
    pos_test_check(($discounted['success'] ?? false) === true, 'a discount can be applied at the till');

    // Put the cart back the way the sale below expects to find it: two units,
    // no discount.
    $cartAction(['action' => 'set_discount', 'discount' => '0']);
    $restocked = $cartAction(['action' => 'add', 'product_id' => (string) $productId, 'quantity' => '2']);
    pos_test_check(
        ($restocked['success'] ?? false) === true && (int) ($restocked['count'] ?? 0) === 2,
        'the cart is restocked for the sale that follows'
    );
    pos_test_check(
        (float) ($restocked['total'] ?? 0) === $productPrice * 2,
        'the restored cart is worth the full two units with no discount'
    );

    $client->post('/admin/pos/cart.php', [
        'action' => 'set_customer',
        'customer_id' => (string) $customerId,
        'csrf_token' => $client->token(),
    ]);
    $client->post('/admin/pos/cart.php', [
        'action' => 'set_tendered',
        'tendered' => '600000',
        'csrf_token' => $client->token(),
    ]);

    // --- Checkout lands on the receipt --------------------------------------
    $client->post('/admin/pos/checkout.php', ['method' => 'cash', 'csrf_token' => $client->token()]);
    pos_test_check(
        $client->status() === 302 && str_contains($client->location(), '/admin/pos/receipt.php'),
        'completing a sale redirects to the till receipt'
    );

    preg_match('/order=(\d+)/', $client->location(), $match);
    $orderId = (int) ($match[1] ?? 0);
    pos_test_check($orderId > 0, 'the redirect carries the new order id');
    $orderIds[] = $orderId;

    $receipt = $client->follow();
    pos_test_check($client->status() === 200, 'the receipt page loads');
    pos_test_check(str_contains($receipt, 'Point of Sale receipt'), 'the receipt is titled as a till sale');
    pos_test_check(str_contains($receipt, e($productName)), 'the receipt names the product sold');
    pos_test_check(str_contains($receipt, 'UGX 500,000'), 'the receipt shows the total charged');
    pos_test_check(str_contains($receipt, 'Cash tendered UGX 600,000'), 'the receipt shows the cash tendered');
    pos_test_check(str_contains($receipt, 'change UGX 100,000'), 'the receipt shows the change given');
    pos_test_check(
        str_contains($receipt, 'POS Test Customer'), 'the receipt names the customer'
    );
    pos_test_check(str_contains($receipt, 'Print / Save PDF'), 'the receipt offers a print action');

    // --- Customer details captured at the till ------------------------------
    pos_test_check(
        str_contains($till, 'name="contact_name"')
            && str_contains($till, 'name="contact_phone"')
            && str_contains($till, 'name="contact_email"'),
        'the till offers a place to capture customer details'
    );

    // Choosing an account pre-fills the details from it rather than making the
    // operator retype what the system already knows.
    $contactStmt = $pdo->prepare('SELECT pos_contact_name, pos_contact_phone, pos_contact_email FROM orders WHERE id = ?');
    $contactStmt->execute([$orderId]);
    $captured = $contactStmt->fetch();
    pos_test_check(
        is_array($captured) && $captured['pos_contact_name'] === 'POS Test Customer',
        "choosing an account pre-fills the name captured on the sale"
    );
    pos_test_check(
        is_array($captured) && $captured['pos_contact_email'] === $customerEmail,
        'the account email is snapshotted onto the sale'
    );
    pos_test_check(
        str_contains($receipt, $customerEmail),
        'the receipt prints the captured contact details'
    );

    // The walk-in sale itself is rung up at the very end of this test: it draws
    // stock down, which would disturb the main sale's own stock assertions.

    // The till prints on arrival only when asked to, so a reprint stays quiet.
    $reprint = $client->get('/admin/pos/receipt.php?order=' . $orderId);
    pos_test_check(
        $client->status() === 200 && !str_contains($reprint, "addEventListener('load'"),
        'a reprint does not force the print dialog open'
    );
    $auto = $client->get('/admin/pos/receipt.php?order=' . $orderId . '&print=1');
    pos_test_check(
        $client->status() === 200 && str_contains($auto, "addEventListener('load'"),
        'print=1 opens the print dialog automatically'
    );

    // --- What the sale actually wrote ---------------------------------------
    $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();
    pos_test_check(is_array($order) && $order['channel'] === 'pos', 'the sale is recorded as a till order');
    pos_test_check(is_array($order) && (int) $order['sold_by'] === $staffId, 'the sale records the operator who rang it');
    pos_test_check(is_array($order) && (int) $order['user_id'] === $customerId, 'the sale is attached to the chosen customer');
    pos_test_check(
        is_array($order) && str_starts_with((string) $order['order_number'], 'POS-'),
        'the sale is given a POS receipt number'
    );

    $lineStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = ?');
    $lineStmt->execute([$orderId]);
    pos_test_check((int) $lineStmt->fetchColumn() === 1, 'the sale is stored as one order line');

    $stockStmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ?');
    $stockStmt->execute([$productId]);
    pos_test_check(
        (int) $stockStmt->fetchColumn() === $productStock - 2,
        'the sale took two units off the shelf'
    );

    $payStmt = $pdo->prepare("SELECT method FROM payments WHERE order_id = ? AND status = 'verified' LIMIT 1");
    $payStmt->execute([$orderId]);
    $payRow = $payStmt->fetch();
    pos_test_check(is_array($payRow) && $payRow['method'] === 'cash', 'the cash taken is recorded as verified');

    // --- The two ways the receipt refuses ------------------------------------
    $client->get('/admin/pos/receipt.php?order=99999999');
    pos_test_check(
        $client->status() === 302 && str_contains($client->location(), '/admin/pos/index.php'),
        'an unknown sale sends the operator back to the till'
    );

    $pdo->prepare(
        "INSERT INTO orders (user_id, product_id, quantity, unit_price, total_amount, status, payment_status)
         VALUES (?, ?, 1, ?, ?, 'processing', 'unpaid')"
    )->execute([$customerId, $productId, $productPrice, $productPrice]);
    $onlineId = (int) $pdo->lastInsertId();

    $client->get('/admin/pos/receipt.php?order=' . $onlineId);
    pos_test_check(
        $client->status() === 302 && str_contains($client->location(), '/admin/orders/view.php'),
        'an online order is sent to its own admin view instead of a till receipt'
    );
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$onlineId]);

    // --- The Sales button --------------------------------------------------
    pos_test_check(
        str_contains($till, '/admin/pos/sales.php') && str_contains($till, '/admin/pos/wallets.php'),
        'the till header links to the sales register and to wallets'
    );

    $salesPage = $client->get('/admin/pos/sales.php');
    pos_test_check($client->status() === 200, 'the Sales button opens the sales register');
    pos_test_check(
        str_contains($salesPage, (string) $order['order_number']),
        'the sale just rung up appears in the register by its receipt number'
    );
    pos_test_check(str_contains($salesPage, 'POS Test Customer'), 'the register shows who the sale was to');
    pos_test_check(
        str_contains($salesPage, '/admin/pos/receipt.php?order=' . $orderId),
        'each register row links to that sale\'s receipt'
    );

    $cashSales = $client->get('/admin/pos/sales.php?method=cash');
    pos_test_check(
        $client->status() === 200 && str_contains($cashSales, (string) $order['order_number']),
        'filtering the register by Cash keeps the cash sale'
    );
    $walletSales = $client->get('/admin/pos/sales.php?method=wallet');
    pos_test_check(
        $client->status() === 200 && !str_contains($walletSales, (string) $order['order_number']),
        'filtering the register by Wallet leaves the cash sale out'
    );

    // --- The Wallets button, and who may add credit -------------------------
    $walletsPage = $client->get('/admin/pos/wallets.php');
    pos_test_check($client->status() === 200, 'the Wallets button opens the wallets page');

    $focus = $client->get('/admin/pos/wallets.php?user_id=' . $customerId);
    pos_test_check(
        $client->status() === 200 && str_contains($focus, 'POS Test Customer'),
        'a customer wallet can be opened from the wallets page'
    );
    pos_test_check(str_contains($focus, 'Top up this wallet'), 'the customer view names the top-up step');
    pos_test_check(
        !str_contains($focus, 'name="amount"'),
        'a staff member is not offered the top-up form'
    );
    pos_test_check(
        str_contains($focus, 'only an administrator can do it'),
        'a staff member is told who is allowed to top up'
    );

    // The till points staff at the same page, but never offers them the action.
    // The sale above cleared the cart, so pick the customer again to get the
    // customer block (and its wallet link) back on screen.
    $client->get('/admin/pos/index.php');
    $client->post('/admin/pos/cart.php', [
        'action' => 'set_customer',
        'customer_id' => (string) $customerId,
        'csrf_token' => $client->token(),
    ]);
    $staffTill = $client->get('/admin/pos/index.php');
    pos_test_check(
        str_contains($staffTill, 'Wallet history'),
        "a staff member's customer panel links to the wallet"
    );
    pos_test_check(
        !str_contains($staffTill, 'top up'),
        "a staff member's till never offers a top-up"
    );

    // A staff POST must be refused even with a genuine token, because the form
    // they never saw could still be posted by hand.
    $client->get('/admin/pos/wallets.php?user_id=' . $customerId);
    $client->post('/admin/pos/wallets.php?user_id=' . $customerId, [
        'user_id' => (string) $customerId,
        'amount' => '75000',
        'note' => 'Staff should not be able to do this',
        'csrf_token' => $client->token(),
    ]);
    pos_test_check(
        $client->status() === 302 && str_contains($client->location(), 'user_id=' . $customerId),
        'a staff top-up is redirected back to the wallet'
    );

    $balanceStmt = $pdo->prepare('SELECT COALESCE(balance, 0) FROM customer_wallets WHERE user_id = ?');
    $balanceStmt->execute([$customerId]);
    pos_test_check(
        (float) $balanceStmt->fetchColumn() === 0.0,
        "a staff member's top-up moves no money"
    );

    $refusal = $client->follow();
    pos_test_check(
        str_contains($refusal, 'Only an administrator can top up a wallet'),
        'the staff member is told why the top-up was refused'
    );

    // --- An administrator tops up -------------------------------------------
    $adminClient = new PosTestClient();
    $adminClient->get('/login.php');
    $adminClient->post('/login.php', [
        'email' => $adminEmail,
        'password' => POS_TEST_PASSWORD,
        'csrf_token' => $adminClient->token(),
    ]);
    pos_test_check(
        $adminClient->status() === 302 && str_contains($adminClient->location(), 'admin/dashboard.php'),
        'administrator login redirects to the admin dashboard'
    );

    $adminFocus = $adminClient->get('/admin/pos/wallets.php?user_id=' . $customerId);
    pos_test_check(
        str_contains($adminFocus, 'name="amount"'),
        'an administrator is offered the top-up form'
    );

    $adminClient->post('/admin/pos/wallets.php?user_id=' . $customerId, [
        'user_id' => (string) $customerId,
        'amount' => '75000',
        'note' => 'Smoke top up',
        'csrf_token' => $adminClient->token(),
    ]);
    pos_test_check(
        $adminClient->status() === 302 && str_contains($adminClient->location(), 'user_id=' . $customerId),
        'a top-up redirects back to the customer'
    );

    $balanceStmt->execute([$customerId]);
    pos_test_check(
        (float) $balanceStmt->fetchColumn() === 75000.0,
        'the top-up credited the wallet by exactly the amount entered'
    );

    $ledgerStmt = $pdo->prepare(
        "SELECT amount, balance_after, note, created_by FROM wallet_transactions
         WHERE user_id = ? AND type = 'topup' ORDER BY id DESC LIMIT 1"
    );
    $ledgerStmt->execute([$customerId]);
    $ledger = $ledgerStmt->fetch();
    pos_test_check(
        is_array($ledger) && (float) $ledger['amount'] === 75000.0 && (float) $ledger['balance_after'] === 75000.0,
        'the top-up left a ledger entry carrying the running balance'
    );
    pos_test_check(
        is_array($ledger) && (int) $ledger['created_by'] === $adminId,
        'the ledger names the administrator who took the money'
    );

    $auditStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'wallet_topup'");
    $auditStmt->execute([$adminId]);
    pos_test_check((int) $auditStmt->fetchColumn() >= 1, 'the top-up was written to the audit trail');

    $staffAuditStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'wallet_topup'");
    $staffAuditStmt->execute([$staffId]);
    pos_test_check(
        (int) $staffAuditStmt->fetchColumn() === 0,
        'the refused staff top-up left nothing in the audit trail'
    );

    $noticeStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Wallet topped up'");
    $noticeStmt->execute([$customerId]);
    pos_test_check((int) $noticeStmt->fetchColumn() === 1, 'the customer was told their wallet was topped up');

    // A forged submission must not move money.
    $adminClient->get('/admin/pos/wallets.php?user_id=' . $customerId);
    $adminClient->post('/admin/pos/wallets.php?user_id=' . $customerId, [
        'user_id' => (string) $customerId,
        'amount' => '999999',
        'csrf_token' => 'not-the-real-token',
    ]);
    $balanceStmt->execute([$customerId]);
    pos_test_check(
        (float) $balanceStmt->fetchColumn() === 75000.0,
        'a top-up with a forged CSRF token does not move any money'
    );

    // A wallet cannot be opened for someone who is not a customer. This is
    // posted as the administrator, so it exercises the customer check rather
    // than the role gate that would have refused a staff account first.
    $adminClient->get('/admin/pos/wallets.php');
    $adminClient->post('/admin/pos/wallets.php?user_id=' . $staffId, [
        'user_id' => (string) $staffId,
        'amount' => '5000',
        'csrf_token' => $adminClient->token(),
    ]);
    pos_test_check(
        $adminClient->status() === 302 && str_contains($adminClient->location(), 'wallets.php'),
        'a wallet cannot be opened against a staff account'
    );
    $staffWalletStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_wallets WHERE user_id = ?');
    $staffWalletStmt->execute([$staffId]);
    pos_test_check((int) $staffWalletStmt->fetchColumn() === 0, 'no wallet was created for the staff account');
    // --- Product codes: what actually makes a product scannable -------------
    $addForm = $client->get('/admin/products/add.php');
    pos_test_check(
        str_contains($addForm, 'name="sku"') && str_contains($addForm, 'name="barcode"'),
        'the add-product form offers an SKU and a barcode'
    );

    $editForm = $client->get('/admin/products/edit.php?id=' . $productId);
    pos_test_check(
        str_contains($editForm, 'name="sku"') && str_contains($editForm, 'name="barcode"'),
        'the edit-product form offers an SKU and a barcode'
    );

    $codedName = 'POSCoded ' . strtoupper($tag);
    $codedSku = 'FORMSKU-' . strtoupper($tag);
    $codedBarcode = 'FORMBAR-' . strtoupper($tag);

    $client->get('/admin/products/add.php');
    $client->post('/admin/products/add.php', [
        'name' => $codedName,
        'brand' => 'TestBrand',
        'price' => '12000',
        'stock_quantity' => '4',
        'sku' => $codedSku,
        'barcode' => $codedBarcode,
        'csrf_token' => $client->token(),
    ]);
    pos_test_check(
        $client->status() === 302,
        'a product entered with an SKU and a barcode saves'
    );

    $codedStmt = $pdo->prepare('SELECT id, sku, barcode FROM products WHERE name = ?');
    $codedStmt->execute([$codedName]);
    $coded = $codedStmt->fetch();
    pos_test_check(
        is_array($coded) && $coded['sku'] === $codedSku && $coded['barcode'] === $codedBarcode,
        'both codes are stored exactly as entered'
    );
    pos_test_check(
        is_array($coded) && pos_find_product($pdo, $codedBarcode)['id'] === (int) $coded['id'],
        'the barcode is immediately resolvable by the till'
    );
    pos_test_check(
        is_array($coded) && pos_find_product($pdo, $codedSku)['id'] === (int) $coded['id'],
        'the SKU is immediately resolvable by the till'
    );

    // Blank codes must be stored as NULL, not '': both columns are UNIQUE, and
    // MySQL allows many NULLs but only one empty string.
    $client->get('/admin/products/add.php');
    $client->post('/admin/products/add.php', [
        'name' => 'POSCoded Blank ' . strtoupper($tag),
        'brand' => 'TestBrand',
        'price' => '5000',
        'stock_quantity' => '1',
        'sku' => '',
        'barcode' => '',
        'csrf_token' => $client->token(),
    ]);
    pos_test_check(
        $client->status() === 302,
        'a second product with no codes at all still saves'
    );

    $blankStmt = $pdo->prepare("SELECT sku, barcode FROM products WHERE name = ?");
    $blankStmt->execute(['POSCoded Blank ' . strtoupper($tag)]);
    $blank = $blankStmt->fetch();
    pos_test_check(
        is_array($blank) && $blank['sku'] === null && $blank['barcode'] === null,
        'blank codes are stored as NULL so the UNIQUE index is not tripped'
    );

    // A duplicate must be refused with a readable message, not a database error.
    $client->get('/admin/products/add.php');
    $client->post('/admin/products/add.php', [
        'name' => 'POSCoded Clash ' . strtoupper($tag),
        'brand' => 'TestBrand',
        'price' => '5000',
        'stock_quantity' => '1',
        'sku' => '',
        'barcode' => $codedBarcode,
        'csrf_token' => $client->token(),
    ]);
    $clashPage = $client->body();
    pos_test_check(
        $client->status() === 200,
        'a duplicate barcode is refused instead of saving'
    );
    pos_test_check(
        str_contains($clashPage, 'already used by'),
        'the duplicate barcode is explained in plain language'
    );

    $clashCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE name = 'POSCoded Clash " . strtoupper($tag) . "'")->fetchColumn();
    pos_test_check($clashCount === 0, 'the rejected product was not created');

    // The till looks a code up across both columns at once, so a code sitting in
    // the "wrong" column is just as ambiguous and must also be refused.
    $client->get('/admin/products/add.php');
    $client->post('/admin/products/add.php', [
        'name' => 'POSCoded CrossCol ' . strtoupper($tag),
        'brand' => 'TestBrand',
        'price' => '5000',
        'stock_quantity' => '1',
        'sku' => $codedBarcode,
        'barcode' => '',
        'csrf_token' => $client->token(),
    ]);
    pos_test_check(
        $client->status() === 200 && str_contains($client->body(), 'already used by'),
        'a code already used as a barcode cannot be reused as an SKU'
    );

    // Saving a product without touching its own code must be allowed.
    $client->get('/admin/products/edit.php?id=' . (int) $coded['id']);
    $client->post('/admin/products/edit.php?id=' . (int) $coded['id'], [
        'id' => (string) $coded['id'],
        'name' => $codedName,
        'brand' => 'TestBrand',
        'price' => '12000',
        'sku' => $codedSku,
        'barcode' => $codedBarcode,
        'csrf_token' => $client->token(),
    ]);
    pos_test_check(
        $client->status() === 302,
        'a product can be saved without changing its own codes'
    );
    // --- A walk-in sale, captured by hand ------------------------------------
    // A walk-in has no account, so the details must be typed. This is the case the
    // feature exists for, and it used to be unreachable from the till.
    $client->get('/admin/pos/index.php');
    $walkInName = 'Walkin Tester ' . strtoupper($tag);
    $walkInPhone = '07' . strtoupper(substr($tag, 0, 8));
    $walkInEmail = 'walkin-' . $tag . '@example.test';

    $badEmail = $client->post('/admin/pos/cart.php', [
        'action' => 'set_contact',
        'contact_name' => $walkInName,
        'contact_email' => 'not-an-email',
        'csrf_token' => $client->token(),
    ]);
    pos_test_check(
        str_contains($badEmail, 'does not look right'),
        'a malformed email is refused rather than stored'
    );

    $client->get('/admin/pos/index.php');
    // Detach the account first, the way the till's "Change" button does. Setting
    // contact details alone would not make this a walk-in sale.
    $client->post('/admin/pos/cart.php', [
        'action' => 'set_customer',
        'customer_id' => '0',
        'csrf_token' => $client->token(),
    ]);
    $client->get('/admin/pos/index.php');
    $client->post('/admin/pos/cart.php', [
        'action' => 'set_contact',
        'contact_name' => $walkInName,
        'contact_phone' => $walkInPhone,
        'contact_email' => $walkInEmail,
        'csrf_token' => $client->token(),
    ]);
    $contactPanel = $client->body();
    pos_test_check(
        str_contains($contactPanel, htmlspecialchars($walkInName, ENT_QUOTES))
            && str_contains($contactPanel, $walkInPhone),
        'the captured details appear in the cart panel straight away'
    );

    // Ring the walk-in sale up so the details have to survive onto the receipt.
    $client->post('/admin/pos/cart.php', [
        'action' => 'add',
        'code' => 'POSBAR-' . strtoupper($tag),
        'quantity' => '1',
        'csrf_token' => $client->token(),
    ]);
    $client->post('/admin/pos/cart.php', ['action' => 'set_method', 'method' => 'cash', 'csrf_token' => $client->token()]);
    $client->post('/admin/pos/checkout.php', ['method' => 'cash', 'csrf_token' => $client->token()]);
    pos_test_check(
        $client->status() === 302 && str_contains($client->location(), 'receipt.php'),
        'a walk-in sale with captured details completes'
    );

    preg_match('/order=(\d+)/', $client->location(), $walkInMatch);
    $walkInOrderId = (int) ($walkInMatch[1] ?? 0);
    pos_test_check($walkInOrderId > 0, 'the walk-in sale was recorded');
    $orderIds[] = $walkInOrderId;

    // pos_is_walk_in_sale() reads the joined customer_email, so the email has to
    // come along rather than reading the bare orders row.
    $walkInStmt = $pdo->prepare(
        'SELECT o.*, u.email AS customer_email, u.full_name AS customer_name
         FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?'
    );
    $walkInStmt->execute([$walkInOrderId]);
    $walkInOrder = $walkInStmt->fetch();
    pos_test_check(
        is_array($walkInOrder) && $walkInOrder['pos_contact_name'] === $walkInName
            && $walkInOrder['pos_contact_phone'] === $walkInPhone
            && $walkInOrder['pos_contact_email'] === $walkInEmail,
        'the walk-in name, phone and email are all stored on the sale'
    );
    pos_test_check(
        is_array($walkInOrder) && pos_is_walk_in_sale($walkInOrder),
        'the walk-in sale is still recognised as having no account'
    );

    $walkInReceipt = $client->follow();
    pos_test_check(str_contains($walkInReceipt, $walkInName), 'the receipt prints the walk-in name');
    pos_test_check(str_contains($walkInReceipt, $walkInPhone), 'the receipt prints the walk-in phone number');
    pos_test_check(str_contains($walkInReceipt, $walkInEmail), 'the receipt prints the walk-in email');
    pos_test_check(
        str_contains($walkInReceipt, 'Walk-in'),
        'the receipt makes clear the buyer had no account'
    );

    // A walk-in is booked against one shared placeholder account, so its email
    // must never be printed as though it were the buyer's.
    pos_test_check(
        !str_contains($walkInReceipt, POS_WALKIN_EMAIL),
        'the placeholder account email never appears on a customer receipt'
    );

    // An older walk-in sale with nothing captured must show no contact details
    // at all rather than the placeholder's.
    $bare = [
        'customer_email' => POS_WALKIN_EMAIL,
        'customer_phone' => '',
        'pos_contact_name' => null,
        'pos_contact_phone' => null,
        'pos_contact_email' => null,
        'walk_in_customer_name' => null,
        'customer_name' => POS_WALKIN_NAME,
    ];
    pos_test_check(
        pos_sale_contact_email($bare) === '' && pos_sale_contact_phone($bare) === '',
        'an uncaptured walk-in sale reports no contact details'
    );
    pos_test_check(
        pos_sale_customer_name($bare) === POS_WALKIN_NAME,
        'an uncaptured walk-in sale still shows a customer name'
    );
} finally {
    $cleanup();
}

echo "\nPOS receipt tests passed.\n";
