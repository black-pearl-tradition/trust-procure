<?php

require_once __DIR__ . '/../includes/functions.php';

if (!function_exists('get_supplier_for_user')) {
    throw new RuntimeException('Missing supplier lookup helper: get_supplier_for_user');
}

final class SupplierEmailBindingStub
{
    public string $lastQuery = '';

    public function prepare(string $query): self
    {
        $this->lastQuery = $query;
        return $this;
    }

    public function execute(array $params = []): bool
    {
        return true;
    }

    public function fetch(): mixed
    {
        if (str_contains($this->lastQuery, 'u.role = "supplier"')
            && str_contains($this->lastQuery, 'u.status = "active"')
            && str_contains($this->lastQuery, 'u.deleted_at IS NULL')) {
            return ['id' => 42, 'name' => 'Acme Components', 'email' => 'supplier@example.com'];
        }

        return false;
    }
}

$originalPdo = $GLOBALS['pdo'] ?? null;
$GLOBALS['pdo'] = new SupplierEmailBindingStub();
$resolved = get_supplier_for_user(9);
$GLOBALS['pdo'] = $originalPdo;

if (($resolved['id'] ?? null) !== 42) {
    throw new RuntimeException('Supplier lookup must reject non-supplier accounts and only match active supplier users.');
}

// Staff need to see whether a supplier record resolves to a login account;
// without it a mis-linked supplier is invisible (admin/suppliers/view.php).
$viewFile = __DIR__ . '/../admin/suppliers/view.php';
if (!file_exists($viewFile)) {
    throw new RuntimeException('Missing admin supplier detail page.');
}
if (!str_contains((string) file_get_contents($viewFile), 'get_supplier_user_id(')) {
    throw new RuntimeException('Admin supplier detail should surface the linked portal account.');
}

echo "Supplier lookup helper is present.\n";
