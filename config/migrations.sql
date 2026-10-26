-- Add department, phone, and reply columns to contact_messages
-- Track physical stock received through supplier purchase orders.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'stock_quantity');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN stock_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER image', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'low_stock_threshold');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5 AFTER stock_quantity', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'reserved_quantity');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN reserved_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER stock_quantity', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'profile_image');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL AFTER phone', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'rejection_reason');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE payments ADD COLUMN rejection_reason VARCHAR(255) DEFAULT NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE contact_messages
    ADD COLUMN IF NOT EXISTS phone VARCHAR(30) DEFAULT NULL AFTER email,
    ADD COLUMN IF NOT EXISTS department ENUM('general','orders','payments','products','support') NOT NULL DEFAULT 'general' AFTER phone,
    ADD COLUMN IF NOT EXISTS admin_reply TEXT DEFAULT NULL AFTER message,
    ADD COLUMN IF NOT EXISTS replied_at DATETIME DEFAULT NULL AFTER admin_reply,
    ADD COLUMN IF NOT EXISTS replied_by INT DEFAULT NULL AFTER replied_at;

-- Add foreign key for replied_by if it doesn't exist
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND CONSTRAINT_NAME = 'fk_contact_messages_replied_by');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE contact_messages ADD CONSTRAINT fk_contact_messages_replied_by FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Safely add customer_notes to orders if it doesn't exist
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'customer_notes');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN customer_notes VARCHAR(500) DEFAULT NULL AFTER delivery_address', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE orders
    MODIFY COLUMN status ENUM('request_submitted','request_approved','request_rejected','pending_deposit','deposit_paid','processing','procuring','shipped','delivered','completed','cancelled') NOT NULL DEFAULT 'request_submitted';

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    event_type VARCHAR(60) NOT NULL,
    title VARCHAR(150) NOT NULL,
    details VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Upgrade: comparison / purchase-order / roles / banners / reviews
-- ---------------------------------------------------------------

ALTER TABLE users
    MODIFY COLUMN role ENUM('super_admin','admin','staff','supplier','user') NOT NULL DEFAULT 'user';

-- If you have an existing admin account you want treated as the protected
-- Super Administrator, promote it manually, e.g.:
--   UPDATE users SET role = 'super_admin' WHERE email = 'your-admin@example.com';

CREATE TABLE IF NOT EXISTS product_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT uq_review_per_user_product UNIQUE (product_id, user_id),
    CONSTRAINT chk_rating_range CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS promotional_banners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    cta_text VARCHAR(80) DEFAULT NULL,
    cta_link VARCHAR(255) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(120) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    performance_status ENUM('preferred','watchlist','needs_review') NOT NULL DEFAULT 'watchlist',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- supplier_price is nullable when staff link a product without cost access.
-- Supplier quotations are staged in supplier_product_submissions and become
-- linked supplier costs only after admin approval.
CREATE TABLE IF NOT EXISTS supplier_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    product_id INT NOT NULL,
    supplier_price DECIMAL(14,2) DEFAULT NULL,
    warranty_covered TINYINT(1) NOT NULL DEFAULT 1,
    lead_time_days INT UNSIGNED DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_supplier_product (supplier_id, product_id),
    CONSTRAINT fk_supplier_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    CONSTRAINT fk_supplier_products_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(20) NOT NULL UNIQUE,
    supplier_id INT NOT NULL,
    status ENUM('draft','submitted','approved','rejected','sent','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
    expected_delivery_date DATE DEFAULT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    additional_costs DECIMAL(14,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes VARCHAR(500) DEFAULT NULL,
    created_by INT NOT NULL,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    approval_notes VARCHAR(500) DEFAULT NULL,
    supplier_response ENUM('pending','acknowledged','declined') NOT NULL DEFAULT 'pending',
    supplier_response_at DATETIME DEFAULT NULL,
    supplier_response_notes VARCHAR(500) DEFAULT NULL,
    cancellation_reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_po_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    product_id INT NOT NULL,
    order_id INT DEFAULT NULL,
    source_supplier_product_id INT DEFAULT NULL,
    quantity_ordered INT UNSIGNED NOT NULL,
    quantity_received INT UNSIGNED NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,2) NOT NULL,
    line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_poi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_poi_supplier_product FOREIGN KEY (source_supplier_product_id) REFERENCES supplier_products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    supplier_id INT NOT NULL,
    delivery_code VARCHAR(10) NOT NULL,
    status ENUM('submitted','verified') NOT NULL DEFAULT 'submitted',
    expected_delivery_date DATE DEFAULT NULL,
    tracking_reference VARCHAR(100) DEFAULT NULL,
    supplier_notes VARCHAR(500) DEFAULT NULL,
    submitted_by INT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_by INT DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_supplier_deliveries_code (delivery_code),
    INDEX idx_supplier_deliveries_po_status (purchase_order_id, status),
    CONSTRAINT fk_sd_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_sd_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_sd_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id),
    CONSTRAINT fk_sd_verified_by FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_delivery_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    purchase_order_item_id INT NOT NULL,
    declared_quantity INT UNSIGNED NOT NULL,
    delivery_details VARCHAR(500) DEFAULT NULL,
    received_quantity INT UNSIGNED DEFAULT NULL,
    accepted_quantity INT UNSIGNED DEFAULT NULL,
    rejected_quantity INT UNSIGNED DEFAULT NULL,
    discrepancy_type ENUM('none','short_delivery','damaged','wrong_item','other') DEFAULT NULL,
    inspection_note VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY uniq_delivery_po_item (delivery_id, purchase_order_item_id),
    CONSTRAINT fk_sdi_delivery FOREIGN KEY (delivery_id) REFERENCES supplier_deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_sdi_po_item FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_delivery_items' AND COLUMN_NAME = 'delivery_details');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE supplier_delivery_items ADD COLUMN delivery_details VARCHAR(500) DEFAULT NULL AFTER declared_quantity', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS supplier_delivery_serials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_item_id INT NOT NULL,
    product_id INT NOT NULL,
    serial_number VARCHAR(100) NOT NULL,
    inspection_status ENUM('declared','accepted','rejected','not_received') NOT NULL DEFAULT 'declared',
    inspection_note VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_serial_number (product_id, serial_number),
    INDEX idx_delivery_serial_item (delivery_item_id),
    CONSTRAINT fk_sds_delivery_item FOREIGN KEY (delivery_item_id) REFERENCES supplier_delivery_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_sds_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    movement_type ENUM('received','shipped','adjustment','return') NOT NULL,
    quantity_change INT NOT NULL,
    purchase_order_id INT DEFAULT NULL,
    order_id INT DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_movements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_movements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_movements_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_movements_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_inventory_movements_product_created (product_id, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Supplier responses, proposed delivery dates and supplier documents.
-- ---------------------------------------------------------------------------
-- A database created from an earlier schema already has purchase_orders, so the
-- CREATE TABLE IF NOT EXISTS above is skipped and every column added since then
-- is still missing. Add them individually and guarded, before anything else
-- refers to them. No AFTER clause is used here: a positional clause would
-- depend on a sibling column that an older database may also be missing.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'approved_by');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_orders ADD COLUMN approved_by INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'approved_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_orders ADD COLUMN approved_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'approval_notes');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_orders ADD COLUMN approval_notes VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'supplier_response');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_orders ADD COLUMN supplier_response ENUM(''pending'',''acknowledged'',''declined'') NOT NULL DEFAULT ''pending''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'supplier_response_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_orders ADD COLUMN supplier_response_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'supplier_response_notes');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_orders ADD COLUMN supplier_response_notes VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'cancellation_reason');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_orders ADD COLUMN cancellation_reason VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'supplier_proposed_delivery_date');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_orders ADD COLUMN supplier_proposed_delivery_date DATE DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Files are stored outside the web root and streamed by po-document.php.
CREATE TABLE IF NOT EXISTS purchase_order_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    delivery_id INT DEFAULT NULL,
    uploaded_by INT NOT NULL,
    document_type ENUM('quote','invoice','delivery_note','other') NOT NULL DEFAULT 'other',
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pod_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_pod_delivery FOREIGN KEY (delivery_id) REFERENCES supplier_deliveries(id) ON DELETE SET NULL,
    CONSTRAINT fk_pod_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_pod_po_created (purchase_order_id, created_at),
    INDEX idx_pod_delivery (delivery_id)
) ENGINE=InnoDB;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order_documents' AND COLUMN_NAME = 'delivery_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_order_documents ADD COLUMN delivery_id INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order_documents' AND INDEX_NAME = 'idx_pod_delivery');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE purchase_order_documents ADD INDEX idx_pod_delivery (delivery_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order_documents' AND CONSTRAINT_NAME = 'fk_pod_delivery');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE purchase_order_documents ADD CONSTRAINT fk_pod_delivery FOREIGN KEY (delivery_id) REFERENCES supplier_deliveries(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Supplier Payment Structure & Payables (Option 2)
-- ---------------------------------------------------------------------------

-- Supplier payment terms, advance %, and banking/mobile money details
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'performance_status');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN performance_status ENUM('preferred','watchlist','needs_review') NOT NULL DEFAULT 'watchlist'", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'payment_terms');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN payment_terms ENUM('cod','net_15','net_30','net_60','split_advance','prepaid') NOT NULL DEFAULT 'net_30'", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'advance_percentage');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN advance_percentage TINYINT UNSIGNED NOT NULL DEFAULT 0", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'bank_name');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN bank_name VARCHAR(100) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'bank_account_name');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN bank_account_name VARCHAR(150) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'bank_account_number');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN bank_account_number VARCHAR(50) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'bank_branch');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN bank_branch VARCHAR(100) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'mobile_money_provider');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN mobile_money_provider VARCHAR(50) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'mobile_money_number');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN mobile_money_number VARCHAR(30) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'tax_identification_number');
SET @sql = IF(@col_exists = 0, "ALTER TABLE suppliers ADD COLUMN tax_identification_number VARCHAR(50) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;




-- PO payment status & tracking fields
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'payment_status');
SET @sql = IF(@col_exists = 0, "ALTER TABLE purchase_orders ADD COLUMN payment_status ENUM('unpaid','partially_paid','paid') NOT NULL DEFAULT 'unpaid'", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'payment_due_date');
SET @sql = IF(@col_exists = 0, "ALTER TABLE purchase_orders ADD COLUMN payment_due_date DATE DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'amount_paid');
SET @sql = IF(@col_exists = 0, "ALTER TABLE purchase_orders ADD COLUMN amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Supplier Payments Ledger
CREATE TABLE IF NOT EXISTS supplier_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_method ENUM('bank_transfer','mobile_money','cheque','cash','other') NOT NULL DEFAULT 'bank_transfer',
    payment_type ENUM('advance','milestone','balance','full') NOT NULL DEFAULT 'full',
    reference_number VARCHAR(100) DEFAULT NULL,
    payment_date DATE NOT NULL,
    proof_document_id INT DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    recorded_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sp_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_sp_proof FOREIGN KEY (proof_document_id) REFERENCES purchase_order_documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_sp_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id),
    INDEX idx_sp_po_date (purchase_order_id, payment_date)
) ENGINE=InnoDB;

-- Supplier Invoices table (Formal vendor billing invoices / proformas)
CREATE TABLE IF NOT EXISTS supplier_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    invoice_number VARCHAR(100) NOT NULL,
    invoice_type ENUM('proforma','tax_invoice','credit_note') NOT NULL DEFAULT 'tax_invoice',
    invoice_date DATE NOT NULL,
    due_date DATE DEFAULT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    status ENUM('pending','approved','rejected','settled') NOT NULL DEFAULT 'pending',
    document_id INT DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    uploaded_by INT NOT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_si_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_si_doc FOREIGN KEY (document_id) REFERENCES purchase_order_documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_si_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT fk_si_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_si_po_status (purchase_order_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_product_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    submitted_by INT DEFAULT NULL,
    category_id INT DEFAULT NULL,
    product_id INT DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    brand VARCHAR(80) DEFAULT NULL,
    description TEXT,
    specifications TEXT,
    image VARCHAR(255) DEFAULT NULL,
    supplier_price DECIMAL(14,2) NOT NULL,
    warranty_covered TINYINT(1) NOT NULL DEFAULT 1,
    lead_time_days INT UNSIGNED DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(500) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sps_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sps_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sps_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_sps_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_sps_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sps_status_created (status, created_at),
    INDEX idx_sps_supplier_status (supplier_id, status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Point of Sale (POS)
-- ---------------------------------------------------------------------------

-- Scannable product identity: an internal SKU code and an optional barcode.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'sku');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN sku VARCHAR(60) DEFAULT NULL AFTER slug', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'barcode');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN barcode VARCHAR(60) DEFAULT NULL AFTER sku', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'track_serial_numbers');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN track_serial_numbers TINYINT(1) NOT NULL DEFAULT 0 AFTER barcode', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Both are optional (NULL is allowed many times) but must never repeat when set.
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'uniq_products_sku');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE products ADD UNIQUE KEY uniq_products_sku (sku)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'uniq_products_barcode');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE products ADD UNIQUE KEY uniq_products_barcode (barcode)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Till sales reuse the normal order pipeline, tagged with a channel so they can
-- be reported separately from web orders.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'order_number');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN order_number VARCHAR(20) DEFAULT NULL AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'channel');
SET @sql = IF(@col_exists = 0, "ALTER TABLE orders ADD COLUMN channel ENUM('online','pos') NOT NULL DEFAULT 'online' AFTER order_number", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'sold_by');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN sold_by INT DEFAULT NULL AFTER channel', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'walk_in_customer_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN walk_in_customer_name VARCHAR(120) DEFAULT NULL AFTER sold_by', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_sold_by');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE orders ADD CONSTRAINT fk_orders_sold_by FOREIGN KEY (sold_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'uniq_orders_order_number');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE orders ADD UNIQUE KEY uniq_orders_order_number (order_number)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Money taken over the counter is neither a deposit nor a balance payment.
SET @enum_needs_pos = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_type' AND COLUMN_TYPE NOT LIKE '%pos%');
SET @sql = IF(@enum_needs_pos > 0, "ALTER TABLE payments MODIFY COLUMN payment_type ENUM('deposit','balance','pos') NOT NULL", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;



-- ---------------------------------------------------------------------------
-- POS: wallets, credit sales, invoices, discounts
-- ---------------------------------------------------------------------------

-- Prepaid balance a customer keeps on account and can spend at the till.
CREATE TABLE IF NOT EXISTS customer_wallets (
    user_id INT NOT NULL PRIMARY KEY,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Every cent that enters or leaves a wallet, with the running balance.
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('topup','sale','refund','adjustment') NOT NULL DEFAULT 'topup',
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    order_id INT DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_wt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wt_user_created (user_id, created_at)
) ENGINE=InnoDB;

-- Credit control for customers who pay later.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'credit_limit');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN credit_limit DECIMAL(14,2) DEFAULT NULL AFTER profile_image', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'credit_terms_days');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN credit_terms_days TINYINT UNSIGNED DEFAULT NULL AFTER credit_limit', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Discount taken at the till, the sale's invoice number, and when credit falls due.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'discount_amount');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER balance_amount', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'invoice_number');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN invoice_number VARCHAR(20) DEFAULT NULL AFTER order_number', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'credit_due_date');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN credit_due_date DATE DEFAULT NULL AFTER customer_notes', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'uniq_orders_invoice_number');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE orders ADD UNIQUE KEY uniq_orders_invoice_number (invoice_number)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Who was served at the till, snapshotted onto the sale.
--
-- These are deliberately snapshots rather than a lookup into users: a receipt is
-- a record of what was true at the time, so a customer who later changes their
-- phone number must not rewrite the receipts already issued. They cover walk-in
-- and account customers alike — a registered customer's details are pre-filled
-- from their account but remain editable for this sale only.
-- ---------------------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pos_contact_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN pos_contact_name VARCHAR(120) DEFAULT NULL AFTER walk_in_customer_name', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pos_contact_phone');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN pos_contact_phone VARCHAR(30) DEFAULT NULL AFTER pos_contact_name', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pos_contact_email');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN pos_contact_email VARCHAR(150) DEFAULT NULL AFTER pos_contact_phone', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Procurement approval workflow
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    min_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    max_amount DECIMAL(14,2) DEFAULT NULL,
    required_role ENUM('staff','admin','super_admin') NOT NULL DEFAULT 'admin',
    department VARCHAR(80) DEFAULT NULL,
    category_id INT DEFAULT NULL,
    sequence TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_approval_rules_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_approval_rules_active_amount (is_active, min_amount, max_amount),
    INDEX idx_approval_rules_category (category_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_request_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    approver_id INT NOT NULL,
    decision ENUM('approved','rejected','pending') NOT NULL DEFAULT 'pending',
    comments TEXT DEFAULT NULL,
    stage VARCHAR(60) NOT NULL DEFAULT 'review',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pra_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_pra_approver FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_pra_request_stage (request_id, stage, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS approval_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_type VARCHAR(40) NOT NULL DEFAULT 'request',
    target_id INT NOT NULL,
    actor_id INT DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    details VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_target_action (target_type, target_id, action, created_at)
) ENGINE=InnoDB;
