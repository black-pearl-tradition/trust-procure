<?php

/**
 * Apply config/migrations.sql to the configured database.
 *
 *     php config/run_migrations.php
 *
 * The migration file uses MySQL client statements (SET / PREPARE / EXECUTE) so
 * it cannot be sent as a single PDO query, and PDO does not allow multi-statement
 * queries here. This runner strips comments and splits the file into individual
 * statements — respecting quotes, so a semicolon inside a literal is kept — then
 * executes them one at a time. Every statement in the file is guarded by an
 * "if it does not exist" check, so running it repeatedly is safe.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/database.php';

$sqlFile = __DIR__ . '/migrations.sql';
$contents = file_get_contents($sqlFile);
if ($contents === false) {
    fwrite(STDERR, 'Could not read ' . $sqlFile . PHP_EOL);
    exit(1);
}

/**
 * Split a SQL script into statements, ignoring semicolons inside string
 * literals, quoted identifiers and comments.
 */
function migration_split_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $quote = null; // current quote character, or null
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $quote !== '' && $next !== '') {
                // Escaped character inside a string literal.
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                // '' or "" inside a literal re-opens it.
                if ($next === $quote) {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }

        if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
            $inLineComment = true;
            $i++;
            continue;
        }
        if ($char === '#' ) {
            $inLineComment = true;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $inBlockComment = true;
            $i++;
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

$statements = migration_split_statements($contents);
$applied = 0;
$failed = 0;

foreach ($statements as $index => $statement) {
    try {
        // query() + closeCursor() rather than exec(): statements such as
        // "SELECT 1" leave a result set open otherwise, which would block every
        // statement that follows it on this connection.
        $statementHandle = $pdo->query($statement);
        if ($statementHandle instanceof PDOStatement) {
            $statementHandle->closeCursor();
        }
        $applied++;
    } catch (PDOException $e) {
        $failed++;
        $preview = strtok(preg_replace('/\s+/', ' ', $statement), "\n") ?: $statement;
        fwrite(STDERR, 'Failed #' . ($index + 1) . ': ' . mb_substr($preview, 0, 160) . PHP_EOL);
        fwrite(STDERR, '    ' . $e->getMessage() . PHP_EOL);
    }
}

$available = count($statements);
echo "Migrations: $available statement(s), $applied applied, $failed failed." . PHP_EOL;

if ($failed > 0) {
    exit(1);
}

// Report the POS columns so the caller can confirm the till is ready to open.
$check = [];
foreach ([
    'products.sku',
    'products.barcode',
    'products.track_serial_numbers',
    'purchase_order_documents.delivery_id',
    'supplier_delivery_items.delivery_details',
    'orders.channel',
    'orders.order_number',
    'orders.invoice_number',
    'orders.discount_amount',
    'orders.credit_due_date',
    'orders.sold_by',
    'orders.walk_in_customer_name',
    'users.credit_limit',
    'users.credit_terms_days',
] as $column) {
    [$table, $name] = explode('.', $column);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $name]);
    $check[$column] = (int) $stmt->fetchColumn() > 0;
}

foreach (['customer_wallets', 'wallet_transactions', 'supplier_deliveries', 'supplier_delivery_items', 'supplier_delivery_serials'] as $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    $check[$table] = (int) $stmt->fetchColumn() > 0;
}

$stmt = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_type'");
$check['payments.payment_type=pos'] = str_contains((string) $stmt->fetchColumn(), "'pos'");

foreach ($check as $name => $present) {
    echo '  ' . ($present ? '[ok]  ' : '[MISSING] ') . $name . PHP_EOL;
}

exit(in_array(false, $check, true) ? 1 : 0);
