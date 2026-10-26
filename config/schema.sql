-- Trust Procurement System
-- Database schema

CREATE DATABASE IF NOT EXISTS tr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tr;

-- ---------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) DEFAULT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    credit_limit DECIMAL(14,2) DEFAULT NULL,
    credit_terms_days TINYINT UNSIGNED DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    role ENUM('super_admin','admin','staff','supplier','user') NOT NULL DEFAULT 'user',
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Categories
-- ---------------------------------------------------------------
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Products
-- ---------------------------------------------------------------
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    sku VARCHAR(60) DEFAULT NULL,
    barcode VARCHAR(60) DEFAULT NULL,
    track_serial_numbers TINYINT(1) NOT NULL DEFAULT 0,
    brand VARCHAR(80) DEFAULT NULL,
    description TEXT,
    specifications TEXT,
    price DECIMAL(14,2) NOT NULL DEFAULT 0,
    image VARCHAR(255) DEFAULT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    reserved_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    is_high_end TINYINT(1) NOT NULL DEFAULT 0,
    deposit_percentage TINYINT UNSIGNED NOT NULL DEFAULT 70,
    stock_status ENUM('in_stock','out_of_stock','made_to_order') NOT NULL DEFAULT 'in_stock',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    is_new_arrival TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_products_sku (sku),
    UNIQUE KEY uniq_products_barcode (barcode),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Suppliers
-- ---------------------------------------------------------------
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(120) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    performance_status ENUM('preferred','watchlist','needs_review') NOT NULL DEFAULT 'watchlist',
    payment_terms ENUM('cod','net_15','net_30','net_60','split_advance','prepaid') NOT NULL DEFAULT 'net_30',
    advance_percentage TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bank_name VARCHAR(100) DEFAULT NULL,
    bank_account_name VARCHAR(150) DEFAULT NULL,
    bank_account_number VARCHAR(50) DEFAULT NULL,
    bank_branch VARCHAR(100) DEFAULT NULL,
    mobile_money_provider VARCHAR(50) DEFAULT NULL,
    mobile_money_number VARCHAR(30) DEFAULT NULL,
    tax_identification_number VARCHAR(50) DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Which suppliers carry which products, and on what terms.
-- supplier_price is nullable when staff link a product without cost access.
-- Suppliers submit quoted costs through supplier_product_submissions; admin
-- approval links the quote here. Only admins can review supplier costs.
-- ---------------------------------------------------------------
CREATE TABLE supplier_products (
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

CREATE TABLE supplier_product_submissions (
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

-- ---------------------------------------------------------------
-- Product gallery images (in addition to the single cover image
-- stored directly on products.image)
-- ---------------------------------------------------------------
CREATE TABLE product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Procurement requests (customer asks to procure a product)
-- ---------------------------------------------------------------
CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    notes VARCHAR(500) DEFAULT NULL,
    delivery_address VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','rejected','converted','cancelled') NOT NULL DEFAULT 'pending',
    resolution_note VARCHAR(255) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Orders (created once a request is approved)
-- ---------------------------------------------------------------
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) DEFAULT NULL,
    invoice_number VARCHAR(20) DEFAULT NULL,
    channel ENUM('online','pos') NOT NULL DEFAULT 'online',
    sold_by INT DEFAULT NULL,
    walk_in_customer_name VARCHAR(120) DEFAULT NULL,
    -- Who was served at the till, snapshotted onto the sale. A receipt is a
    -- record of what was true at the time, so these are copies rather than a
    -- lookup into users. Set for walk-in and account customers alike.
    pos_contact_name VARCHAR(120) DEFAULT NULL,
    pos_contact_phone VARCHAR(30) DEFAULT NULL,
    pos_contact_email VARCHAR(150) DEFAULT NULL,
    request_id INT DEFAULT NULL,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    status ENUM('request_submitted','request_approved','request_rejected','pending_deposit','deposit_paid','processing','procuring','shipped','delivered','completed','cancelled') NOT NULL DEFAULT 'request_submitted',
    payment_status ENUM('unpaid','pending','partially_paid','paid') NOT NULL DEFAULT 'unpaid',
    delivery_address VARCHAR(255) DEFAULT NULL,
    customer_notes VARCHAR(500) DEFAULT NULL,
    credit_due_date DATE DEFAULT NULL,
    cancellation_reason VARCHAR(255) DEFAULT NULL,
    rejection_reason VARCHAR(255) DEFAULT NULL,
    supplier_id INT DEFAULT NULL,
    supplier_cost DECIMAL(14,2) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_sold_by FOREIGN KEY (sold_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_orders_order_number (order_number)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Items belonging to a customer order. The legacy orders columns remain
-- populated for compatibility with existing reports and records.
-- ---------------------------------------------------------------
CREATE TABLE order_items (
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
-- Purchase orders — a real order placed with a supplier, decoupled
-- from any single customer order so several customer orders (or pure
-- restocking) can be bundled into one PO. Admin/super_admin only:
-- like supplier_products.supplier_price, this is cost data.
-- ---------------------------------------------------------------
CREATE TABLE purchase_orders (
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
    supplier_proposed_delivery_date DATE DEFAULT NULL,
    cancellation_reason VARCHAR(255) DEFAULT NULL,
    payment_status ENUM('unpaid','partially_paid','paid') NOT NULL DEFAULT 'unpaid',
    payment_due_date DATE DEFAULT NULL,
    amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_po_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Line items. order_id is nullable: a line can source stock for a
-- specific customer order, or be a plain restock with no customer tied
-- to it yet — both are valid on the same PO.
CREATE TABLE purchase_order_items (
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

CREATE TABLE supplier_deliveries (
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

CREATE TABLE supplier_delivery_items (
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

CREATE TABLE supplier_delivery_serials (
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

-- Documents a supplier shares against a purchase order (quotes, invoices,
-- delivery notes). Files themselves live outside the web root in tps_private/
-- and are streamed through po-document.php, which checks who is asking.
CREATE TABLE purchase_order_documents (
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


-- Inventory ledger. Every stock change is recorded with its source.
CREATE TABLE inventory_movements (
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

-- ---------------------------------------------------------------
-- Payments (deposit / balance, manually recorded and verified)
-- ---------------------------------------------------------------
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_type ENUM('deposit','balance','pos') NOT NULL,
    method VARCHAR(50) DEFAULT NULL,
    reference_note VARCHAR(150) DEFAULT NULL,
    proof_image VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(255) DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Customer wallets: prepaid balance spent at the point of sale
-- ---------------------------------------------------------------
CREATE TABLE customer_wallets (
    user_id INT NOT NULL PRIMARY KEY,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Wallet ledger: every credit and debit, with the running balance
-- ---------------------------------------------------------------
CREATE TABLE wallet_transactions (
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

-- ---------------------------------------------------------------
-- Audit logs
-- ---------------------------------------------------------------
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Customer notifications
-- ---------------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE order_events (
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

-- ---------------------------------------------------------------
-- Product reviews (public users rate and review products)
-- ---------------------------------------------------------------
CREATE TABLE product_reviews (
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

-- ---------------------------------------------------------------
-- Promotional banners (public homepage)
-- ---------------------------------------------------------------
CREATE TABLE promotional_banners (
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

-- ---------------------------------------------------------------
-- Password reset tokens
-- ---------------------------------------------------------------
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Contact messages (public contact form)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    department ENUM('general','orders','payments','products','support') NOT NULL DEFAULT 'general',
    subject VARCHAR(150) DEFAULT NULL,
    message TEXT NOT NULL,
    admin_reply TEXT DEFAULT NULL,
    replied_at DATETIME DEFAULT NULL,
    replied_by INT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contact_messages_replied_by FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------

INSERT INTO categories (name, slug, description) VALUES
('Smartphones', 'smartphones', 'Mobile phones and accessories'),
('Laptops', 'laptops', 'Laptops and notebooks for work and study'),
('Televisions', 'televisions', 'Smart TVs and home entertainment'),
('Audio', 'audio', 'Headphones, speakers and sound systems'),
('Accessories', 'accessories', 'Chargers, cases and other accessories');

INSERT INTO products (category_id, name, slug, brand, description, specifications, price, is_high_end, deposit_percentage, is_featured, is_new_arrival, status) VALUES
(1, 'Galaxy S24 Ultra', 'galaxy-s24-ultra', 'Samsung', 'Flagship smartphone with a 200MP camera and S Pen.', 'RAM: 12GB, Storage: 256GB, Display: 6.8" QHD+, Battery: 5000mAh', 4800000, 1, 70, 1, 1, 'active'),
(1, 'iPhone 15', 'iphone-15', 'Apple', 'Latest generation iPhone with A16 Bionic chip.', 'Storage: 128GB, Display: 6.1" Super Retina, Camera: Dual 48MP', 4200000, 1, 70, 1, 0, 'active'),
(2, 'MacBook Air M2', 'macbook-air-m2', 'Apple', 'Lightweight laptop built for everyday productivity.', 'Chip: Apple M2, RAM: 8GB, Storage: 256GB SSD, Display: 13.6" Liquid Retina', 5200000, 1, 70, 1, 1, 'active'),
(2, 'ThinkPad E14', 'thinkpad-e14', 'Lenovo', 'Reliable business laptop for office and study use.', 'Processor: Intel Core i5, RAM: 8GB, Storage: 512GB SSD, Display: 14" FHD', 3100000, 0, 70, 0, 0, 'active'),
(3, 'Bravia 55" 4K', 'bravia-55-4k', 'Sony', 'Smart 4K television with vivid colour and clarity.', 'Screen size: 55 inch, Resolution: 4K UHD, Platform: Android TV, HDR: HDR10', 3600000, 0, 70, 0, 1, 'active'),
(4, 'WH-1000XM5', 'wh-1000xm5', 'Sony', 'Industry-leading noise cancelling headphones.', 'Connectivity: Bluetooth 5.2, Battery life: 30 hours, Feature: Active Noise Cancelling', 1350000, 0, 70, 1, 0, 'active'),
(5, '65W GaN Charger', '65w-gan-charger', 'Anker', 'Compact fast charger for laptops and phones.', 'Output: 65W USB-C PD, Ports: Dual port, Technology: GaN', 180000, 0, 70, 0, 0, 'active');

INSERT INTO promotional_banners (title, message, cta_text, cta_link, status, sort_order) VALUES
('End of Year Electronics Sale', 'Get up to 10% off deposits on selected laptops and smartphones this month.', 'Shop the sale', '/products.php', 'active', 1),
('New: Sony Audio Collection', 'Explore our newest audio gear, now available for procurement.', 'View audio products', '/products.php?category=4', 'active', 2);

INSERT INTO suppliers (name, contact_person, phone, email, address, notes, status) VALUES
('Kampala Tech Distributors', 'Grace Nakato', '+256701234567', 'sales@kampalatechdist.example', 'Plot 14, Kampala Road, Kampala', 'Primary supplier for Samsung and Apple devices. Reliable stock, 3-5 day lead time.', 'active'),
('East Africa Electronics Ltd', 'Peter Okello', '+256772345678', 'orders@eaelectronics.example', 'Nakawa Industrial Area, Kampala', 'Covers laptops and TVs. Slightly higher pricing but strong warranty support.', 'active'),
('Global Gadgets Wholesale', 'Amina Ssali', '+256783456789', 'wholesale@globalgadgets.example', 'Ben Kiwanuka Street, Kampala', 'Used for accessories and backup sourcing when primary suppliers are out of stock.', 'active');

INSERT INTO supplier_products (supplier_id, product_id, supplier_price, warranty_covered, lead_time_days, notes) VALUES
(1, 1, 4100000, 1, 3, 'Genuine Samsung stock, official warranty card included.'),
(1, 2, 3600000, 1, 4, 'Apple authorised reseller.'),
(2, 3, 4500000, 1, 5, NULL),
(2, 4, 2600000, 1, 3, NULL),
(2, 5, 3000000, 1, 5, 'Bulky item — confirm delivery vehicle before ordering.'),
(3, 6, 1050000, 0, 2, 'Grey-market import — no manufacturer warranty, sold as-is.'),
(3, 7, 130000, 0, 1, NULL);
