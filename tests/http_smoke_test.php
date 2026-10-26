<?php

/**
 * Live HTTP smoke test for the supplier portal (plus its admin touch-points).
 *
 * Drives http://localhost/tps like a browser: logs in with a cookie jar,
 * extracts CSRF tokens, submits the restored and new supplier flows, and
 * asserts on HTTP status codes, redirect targets and database state. All
 * fixtures (users, supplier, product link, order, documents, files) are created
 * with a random tag and removed again at the end, so the database is left
 * untouched apart from the fixture rows' short lifetime.
 *
 * Prints PASS/SKIP lines and exits non-zero on the first hard failure. Skips
 * itself when the web server or the database is unavailable.
 */

const SMOKE_BASE_URL = 'http://localhost/tps';
const SMOKE_PASSWORD = 'SmokeTest123';
const SMOKE_PO_PREFIX = 'PO-SMK-';
const SMOKE_CUSTOMER_PASSWORD = 'SmokeCustomer123';
const SMOKE_CUSTOMER_RESET_PASSWORD = 'SmokeReset123';

$smokeFailures = 0;
$smokeChecks = 0;
$smokeSkips = 0;

function smoke_check(bool $condition, string $message): void
{
    global $smokeFailures, $smokeChecks;

    $smokeChecks++;
    if ($condition) {
        echo "PASS  $message\n";
    } else {
        $smokeFailures++;
        echo "FAIL  $message\n";
        throw new RuntimeException('Smoke test stopped at first failure: ' . $message);
    }
}

function smoke_skip(string $reason): void
{
    global $smokeSkips;

    $smokeSkips++;
    echo 'SKIP  ' . $reason . "\n";
}

function smoke_random_tag(): string
{
    return substr(bin2hex(random_bytes(4)), 0, 8);
}

function smoke_live_pdo(): ?PDO
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

function smoke_server_up(): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, SMOKE_BASE_URL . '/index.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    return $ok !== false && $code === 200;
}

/**
 * Minimal cookie-jar HTTP client that tracks the latest CSRF token.
 */
final class SmokeClient
{
    private string $jar = '';
    private string $token = '';
    private int $lastStatus = 0;
    private string $lastBody = '';
    private string $lastLocation = '';

    public function __construct()
    {
        $jar = tempnam(sys_get_temp_dir(), 'smoke-jar-');
        $this->jar = is_string($jar) ? $jar : '';
    }

    public function __destruct()
    {
        if ($this->jar !== '' && is_file($this->jar)) {
            @unlink($this->jar);
        }
    }

    /** @param array<string,string> $fields */
    public function get(string $path): string
    {
        // Redirects are intentionally not followed: guards are asserted on the
        // raw status and Location header, then followed explicitly.
        return $this->request('GET', $path, [], [], false);
    }

    /**
     * @param array<string,string> $fields
     * @param array<string,array{path:string,name:string,type:string}> $files
     */
    public function post(string $path, array $fields, array $files = []): string
    {
        return $this->request('POST', $path, $fields, $files, false);
    }

    public function follow(): string
    {
        $target = $this->lastLocation !== '' ? $this->lastLocation : '/index.php';
        if (str_starts_with($target, SMOKE_BASE_URL)) {
            $target = substr($target, strlen(SMOKE_BASE_URL));
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

    /**
     * @param array<string,string> $fields
     * @param array<string,array{path:string,name:string,type:string}> $files
     */
    private function request(string $method, string $path, array $fields, array $files, bool $followRedirects = true): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, SMOKE_BASE_URL . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->jar);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($files) {
                $post = $fields;
                foreach ($files as $field => $spec) {
                    $post[$field] = new CURLFile($spec['path'], $spec['type'], $spec['name']);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
            }
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

// --- Availability guards ------------------------------------------------
smoke_check(function_exists('curl_init'), 'cURL is available for HTTP checks');

$pdo = smoke_live_pdo();
smoke_check($pdo instanceof PDO, 'database is reachable for fixtures');
smoke_check(smoke_server_up(), 'web server answers at ' . SMOKE_BASE_URL);

// --- Tagged fixtures ------------------------------------------------------

// __CHUNK2B__

$tag = smoke_random_tag();
$supplierEmail = 'smokesupplier-' . $tag . '@example.test';
$adminEmail = 'smokeadmin-' . $tag . '@example.test';
$createdCustomerEmail = 'smokecustomer-' . $tag . '@example.test';
$poNumber = SMOKE_PO_PREFIX . strtoupper($tag);
$createdCustomerId = 0;

$baseline = [];
foreach (['users', 'suppliers', 'purchase_orders', 'purchase_order_items', 'purchase_order_documents', 'notifications'] as $baselineTable) {
    $baseline[$baselineTable] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $baselineTable)->fetchColumn();
}

$pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
    ->execute(['Smoke Supplier', $supplierEmail, password_hash(SMOKE_PASSWORD, PASSWORD_DEFAULT), 'supplier', 'active']);
$supplierUserId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
    ->execute(['Smoke Admin', $adminEmail, password_hash(SMOKE_PASSWORD, PASSWORD_DEFAULT), 'admin', 'active']);
$adminUserId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Smoke Supplier ' . $tag, 'Smoke Contact', '0700000000', $supplierEmail, 'Smoke Street 1', 'Smoke fixture', 'active']);
$fixtureSupplierId = (int) $pdo->lastInsertId();

$productId = (int) $pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
smoke_check($productId > 0, 'a product exists for the fixture order');

$pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, status, expected_delivery_date, subtotal, additional_costs, grand_total, notes, created_by, supplier_response) VALUES (?, ?, 'sent', ?, ?, 0, ?, ?, ?, 'pending')")
    ->execute([$poNumber, $fixtureSupplierId, date('Y-m-d', strtotime('+14 days')), 300000, 300000, 'Smoke fixture order', $adminUserId]);
$fixturePoId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity_ordered, unit_cost, line_total) VALUES (?, ?, ?, ?, ?)')
    ->execute([$fixturePoId, $productId, 2, 150000, 300000]);

$fixtureSupplier = get_supplier_for_user($supplierUserId);
smoke_check($fixtureSupplier !== null && (int) $fixtureSupplier['id'] === $fixtureSupplierId, 'supplier record resolves for the smoke user');

// --- Security boundaries (no session yet) --------------------------------
$anon = new SmokeClient();
$anon->get('/supplier/dashboard.php');
smoke_check($anon->status() !== 200 && str_contains($anon->location(), 'login.php'), 'supplier dashboard is closed to anonymous visitors');

$anon->post('/supplier/purchase_orders/acknowledge.php', ['id' => (string) $fixturePoId, 'csrf_token' => 'bogus']);
smoke_check($anon->status() !== 200, 'acknowledge action is closed to anonymous visitors');

// --- Supplier login --------------------------------------------------------
$client = new SmokeClient();
$client->get('/login.php');
smoke_check($client->token() !== '', 'login page exposes a CSRF token');

$client->post('/login.php', ['email' => $supplierEmail, 'password' => SMOKE_PASSWORD, 'csrf_token' => $client->token()]);
smoke_check($client->status() === 302 && str_contains($client->location(), 'supplier/dashboard.php'), 'supplier login redirects to the supplier dashboard');

$dashboard = $client->follow();
smoke_check($client->status() === 200 && str_contains($dashboard, 'Awaiting Your Response'), 'supplier dashboard renders');

// --- Wrong-role boundary ----------------------------------------------------
$client->get('/admin/purchase_orders/view.php?id=' . $fixturePoId);
smoke_check($client->status() !== 200 && str_contains($client->location(), 'user/dashboard.php'), 'supplier account is kept out of the admin area');

// --- Forged CSRF token is rejected while logged in ---------------------------
$client->get('/supplier/purchase_orders/view.php?id=' . $fixturePoId);
$client->post('/supplier/purchase_orders/acknowledge.php', ['id' => (string) $fixturePoId, 'csrf_token' => 'bogus-token']);
smoke_check($client->status() === 302 && str_contains($client->location(), 'purchase_orders'), 'forged acknowledge is rejected');

$responseBefore = $pdo->prepare('SELECT supplier_response FROM purchase_orders WHERE id = ?');
$responseBefore->execute([$fixturePoId]);
smoke_check($responseBefore->fetchColumn() === 'pending', 'forged acknowledge changed nothing');

// --- Restored pages ------------------------------------------------------------
$profile = $client->get('/supplier/profile/index.php');
smoke_check($client->status() === 200 && str_contains($profile, 'My Profile') && !str_contains($profile, 'not available'), 'supplier profile page is reachable');

$products = $client->get('/supplier/products/index.php');
smoke_check($client->status() === 200 && str_contains($products, 'Products I Supply'), 'supplier products page is reachable');

// --- Profile update persists ------------------------------------------------------
$client->get('/supplier/profile/index.php');
$client->post('/supplier/profile/index.php', [
    'name' => 'Smoke Supplier ' . $tag,
    'contact_person' => 'Smoke Contact Updated',
    'phone' => '0700000001',
    'address' => 'Smoke Street 1',
    'notes' => 'Smoke fixture',
    'csrf_token' => $client->token(),
]);
smoke_check($client->status() === 302, 'profile update redirects after saving');

$contactCheck = $pdo->prepare('SELECT contact_person FROM suppliers WHERE id = ?');
$contactCheck->execute([$fixtureSupplierId]);
smoke_check($contactCheck->fetchColumn() === 'Smoke Contact Updated', 'profile update persists');

// --- Acknowledge with a proposed delivery date ---------------------------------------
$proposed = date('Y-m-d', strtotime('+21 days'));
$orderView = $client->get('/supplier/purchase_orders/view.php?id=' . $fixturePoId);
smoke_check($client->status() === 200 && str_contains($orderView, 'Your response'), 'fixture order renders for the supplier');

$client->post('/supplier/purchase_orders/acknowledge.php', [
    'id' => (string) $fixturePoId,
    'supplier_response_notes' => 'Smoke acknowledgement',
    'supplier_proposed_delivery_date' => $proposed,
    'csrf_token' => $client->token(),
]);
smoke_check($client->status() === 302 && str_contains($client->location(), 'view.php'), 'acknowledge redirects to the order');

$ackCheck = $pdo->prepare('SELECT supplier_response, supplier_response_notes, supplier_proposed_delivery_date FROM purchase_orders WHERE id = ?');
$ackCheck->execute([$fixturePoId]);
$ackRow = $ackCheck->fetch();
smoke_check($ackRow !== false && $ackRow['supplier_response'] === 'acknowledged', 'acknowledge persists');
smoke_check($ackRow['supplier_proposed_delivery_date'] === $proposed, 'proposed delivery date persists');

// --- Withdraw resets to pending --------------------------------------------------------
$client->get('/supplier/purchase_orders/view.php?id=' . $fixturePoId);
$client->post('/supplier/purchase_orders/withdraw.php', ['id' => (string) $fixturePoId, 'csrf_token' => $client->token()]);
smoke_check($client->status() === 302, 'withdraw redirects');

$withdrawCheck = $pdo->prepare('SELECT supplier_response, supplier_proposed_delivery_date FROM purchase_orders WHERE id = ?');
$withdrawCheck->execute([$fixturePoId]);
$withdrawRow = $withdrawCheck->fetch();
smoke_check($withdrawRow !== false && $withdrawRow['supplier_response'] === 'pending', 'withdraw resets the response');
smoke_check($withdrawRow['supplier_proposed_delivery_date'] === null, 'withdraw clears the proposed date');

// --- Document upload and private download -------------------------------------------------
$fixtureUpload = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smoke-doc-' . $tag . '.pdf';
file_put_contents($fixtureUpload, "%PDF-1.4\n%smoke fixture\n");

$client->get('/supplier/purchase_orders/view.php?id=' . $fixturePoId);
$client->post(
    '/supplier/purchase_orders/upload_document.php',
    ['id' => (string) $fixturePoId, 'document_type' => 'quote', 'csrf_token' => $client->token()],
    ['document' => ['path' => $fixtureUpload, 'name' => 'smoke-quote.pdf', 'type' => 'application/pdf']]
);
smoke_check($client->status() === 302 && str_contains($client->location(), 'view.php'), 'document upload redirects to the order');

$docCheck = $pdo->prepare('SELECT id, original_name, stored_name, mime_type, file_size FROM purchase_order_documents WHERE purchase_order_id = ?');
$docCheck->execute([$fixturePoId]);
$docRow = $docCheck->fetch();
smoke_check($docRow !== false && $docRow['original_name'] === 'smoke-quote.pdf', 'document record is created');
$fixtureDocId = (int) $docRow['id'];

$storedPath = po_document_directory() . '/' . $docRow['stored_name'];
smoke_check(is_file($storedPath), 'document lands in the private directory');

$downloadBody = $client->get('/po-document.php?id=' . $fixtureDocId);
smoke_check($client->status() === 200 && str_contains($downloadBody, 'smoke fixture'), 'document downloads through the private endpoint');

$foreign = new SmokeClient();
$foreign->get('/po-document.php?id=' . $fixtureDocId);
smoke_check($foreign->status() === 404, 'document download is closed to anonymous visitors');

// --- Admin accepts the proposed date ----------------------------------------------------------
$client->get('/supplier/purchase_orders/view.php?id=' . $fixturePoId);
$client->post('/supplier/purchase_orders/acknowledge.php', [
    'id' => (string) $fixturePoId,
    'supplier_response_notes' => 'Smoke re-acknowledgement',
    'supplier_proposed_delivery_date' => $proposed,
    'csrf_token' => $client->token(),
]);
smoke_check($client->status() === 302, 're-acknowledge redirects');

$admin = new SmokeClient();
$admin->get('/login.php');
$admin->post('/login.php', ['email' => $adminEmail, 'password' => SMOKE_PASSWORD, 'csrf_token' => $admin->token()]);
smoke_check($admin->status() === 302 && str_contains($admin->location(), 'admin/dashboard.php'), 'admin login redirects to the admin dashboard');

$admin->get('/admin/users/add.php');
smoke_check($admin->status() === 200 && $admin->token() !== '', 'admin can open the add-user form');
$admin->post('/admin/users/add.php', [
    'full_name' => 'Smoke Created Customer',
    'email' => $createdCustomerEmail,
    'phone' => '',
    'role' => 'user',
    'password' => SMOKE_CUSTOMER_PASSWORD,
    'csrf_token' => $admin->token(),
]);
smoke_check(
    $admin->status() === 302 && str_contains($admin->location(), '/admin/users/index.php'),
    'admin can create a customer account'
);

$createdCustomerStmt = $pdo->prepare('SELECT id, role, status, password_hash, session_version FROM users WHERE email = ?');
$createdCustomerStmt->execute([$createdCustomerEmail]);
$createdCustomer = $createdCustomerStmt->fetch();
$createdCustomerId = (int) ($createdCustomer['id'] ?? 0);
smoke_check(
    $createdCustomerId > 0
        && $createdCustomer['role'] === 'user'
        && $createdCustomer['status'] === 'active'
        && password_verify(SMOKE_CUSTOMER_PASSWORD, $createdCustomer['password_hash']),
    'admin-created customer is active with a valid password hash'
);

$createdCustomerClient = new SmokeClient();
$createdCustomerClient->get('/login.php');
$createdCustomerClient->post('/login.php', [
    'email' => $createdCustomerEmail,
    'password' => SMOKE_CUSTOMER_PASSWORD,
    'csrf_token' => $createdCustomerClient->token(),
]);
smoke_check(
    $createdCustomerClient->status() === 302
        && str_contains($createdCustomerClient->location(), '/user/dashboard.php'),
    'admin-created customer can log in'
);

$customerEdit = $admin->get('/admin/users/edit.php?id=' . $createdCustomerId);
smoke_check(
    $admin->status() === 200 && str_contains($customerEdit, 'name="new_password"'),
    'admin can access the customer password reset field'
);
$admin->post('/admin/users/edit.php?id=' . $createdCustomerId, [
    'id' => (string) $createdCustomerId,
    'full_name' => 'Smoke Created Customer',
    'phone' => '',
    'role' => 'user',
    'status' => 'active',
    'new_password' => SMOKE_CUSTOMER_RESET_PASSWORD,
    'csrf_token' => $admin->token(),
]);
smoke_check(
    $admin->status() === 302 && str_contains($admin->location(), '/admin/users/index.php'),
    'admin can reset the customer password'
);

$createdCustomerStmt->execute([$createdCustomerEmail]);
$resetCustomer = $createdCustomerStmt->fetch();
smoke_check(
    $resetCustomer
        && (int) $resetCustomer['session_version'] > (int) $createdCustomer['session_version']
        && password_verify(SMOKE_CUSTOMER_RESET_PASSWORD, $resetCustomer['password_hash'])
        && !password_verify(SMOKE_CUSTOMER_PASSWORD, $resetCustomer['password_hash']),
    'password reset updates the hash and increments the session version'
);
unset($createdCustomer['password_hash'], $resetCustomer['password_hash']);

$createdCustomerClient->get('/user/dashboard.php');
smoke_check(
    $createdCustomerClient->status() === 302
        && str_contains($createdCustomerClient->location(), 'login.php'),
    'password reset invalidates the customer’s existing session'
);
$createdCustomerClient->get('/login.php');
$createdCustomerClient->post('/login.php', [
    'email' => $createdCustomerEmail,
    'password' => SMOKE_CUSTOMER_RESET_PASSWORD,
    'csrf_token' => $createdCustomerClient->token(),
]);
smoke_check(
    $createdCustomerClient->status() === 302
        && str_contains($createdCustomerClient->location(), '/user/dashboard.php'),
    'customer can log in with the reset password'
);

$adminView = $admin->get('/admin/purchase_orders/view.php?id=' . $fixturePoId);
smoke_check($admin->status() === 200 && str_contains($adminView, 'Documents from the supplier'), 'admin order view lists supplier documents');
smoke_check(str_contains($adminView, 'Supplier proposed'), 'admin order view shows the proposed date');

$admin->post('/admin/purchase_orders/accept_delivery_date.php', ['id' => (string) $fixturePoId, 'csrf_token' => $admin->token()]);
smoke_check($admin->status() === 302 && str_contains($admin->location(), 'view.php'), 'accepting the date redirects');

$dateCheck = $pdo->prepare('SELECT expected_delivery_date FROM purchase_orders WHERE id = ?');
$dateCheck->execute([$fixturePoId]);
smoke_check($dateCheck->fetchColumn() === $proposed, 'expected delivery date is updated from the proposal');

// --- Notifications reach both sides -----------------------------------------------------------------
$supplierNotifs = $client->get('/supplier/notifications/index.php');
smoke_check($client->status() === 200 && str_contains($supplierNotifs, 'Delivery date confirmed'), 'supplier sees the confirmation notification');

$notifCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Delivery date confirmed'");
$notifCount->execute([$supplierUserId]);
smoke_check((int) $notifCount->fetchColumn() >= 1, 'supplier notification is recorded');

$adminNotifs = $admin->get('/admin/notifications/index.php');
smoke_check($admin->status() === 200, 'admin notifications page renders');

// --- Cleanup: every fixture row and file goes again --------------------------------------------
if (isset($fixtureUpload) && is_file($fixtureUpload)) {
    @unlink($fixtureUpload);
}

if (isset($storedPath) && is_file($storedPath)) {
    @unlink($storedPath);
}

$pdo->prepare('DELETE FROM notifications WHERE user_id IN (?, ?)')->execute([$supplierUserId, $adminUserId]);
// Supplier actions also notify the real admin accounts (by design); those rows
// name the random fixture PO number, which no real data can collide with.
$pdo->prepare('DELETE FROM notifications WHERE message LIKE ?')->execute(['%' . $poNumber . '%']);
$pdo->prepare('DELETE FROM audit_logs WHERE user_id IN (?, ?, ?)')->execute([$supplierUserId, $adminUserId, $createdCustomerId ?: null]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$createdCustomerId]);
$pdo->prepare('DELETE FROM purchase_order_documents WHERE purchase_order_id = ?')->execute([$fixturePoId]);
$pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$fixturePoId]);
$pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$fixturePoId]);
$pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$supplierUserId, $adminUserId]);
$pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$fixtureSupplierId]);

foreach (
    [
        ['users', 'email', $supplierEmail],
        ['users', 'email', $adminEmail],
        ['suppliers', 'email', $supplierEmail],
        ['purchase_orders', 'po_number', $poNumber],
    ] as [$table, $column, $value]
) {
    $left = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?');
    $left->execute([$value]);
    smoke_check((int) $left->fetchColumn() === 0, 'fixture rows are removed from ' . $table);
}

foreach ($baseline as $baselineTable => $baselineCount) {
    $current = (int) $pdo->query('SELECT COUNT(*) FROM ' . $baselineTable)->fetchColumn();
    smoke_check($current === $baselineCount, $baselineTable . ' row count is unchanged');
}

echo 'HTTP smoke checks passed: ' . $smokeChecks . ' checks, ' . $smokeSkips . ' skips.' . PHP_EOL;
