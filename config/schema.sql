-- ============================================================
-- Community Garden Management System — Full Database Schema
-- Compatible with MySQL 5.7+ / MariaDB (XAMPP)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP DATABASE IF EXISTS garden_db;
CREATE DATABASE garden_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE garden_db;

-- ============================================================
-- CORE: Users & Roles (Functions 27=RBAC, 30=Audit)
-- ============================================================

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,           -- 'admin','warden','plot_owner','member','guest'
    description TEXT
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT NOT NULL DEFAULT 5,             -- default: member
    phone VARCHAR(20),
    gate_code VARCHAR(20) UNIQUE,               -- Fn 22: security access
    membership_status ENUM('standard','premium','senior') DEFAULT 'standard',
    community_points INT DEFAULT 0,             -- Fn 4: waitlist priority
    karma_points INT DEFAULT 0,                 -- Fn 25: gift economy
    seed_bank_credits INT DEFAULT 0,            -- Fn 27: P2P advice credits
    residency_months INT DEFAULT 0,             -- Fn 4: waitlist priority
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- ============================================================
-- MODULE A: Land & Allotment (Functions 1–8)
-- ============================================================

-- Fn 1, 2, 3: Geospatial Plot Mapping
CREATE TABLE plots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plot_code VARCHAR(20) NOT NULL UNIQUE,      -- e.g. 'A-01'
    boundary_coords TEXT,                       -- JSON array of [lat,lng] points
    area_sqm DECIMAL(8,2),                      -- calculated from coords
    sunlight_level ENUM('full','partial','shade') DEFAULT 'full',
    soil_quality ENUM('premium','standard','poor') DEFAULT 'standard',
    status ENUM('available','occupied','maintenance','reserved') DEFAULT 'available',
    compliance_status ENUM('compliant','warning','violation') DEFAULT 'compliant',
    lat DECIMAL(10,8),                          -- center point for map marker
    lng DECIMAL(11,8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Fn 2, 6: Rental Billing & Leases
CREATE TABLE leases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plot_id INT NOT NULL,
    user_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    base_fee DECIMAL(10,2) NOT NULL,
    soil_multiplier DECIMAL(4,2) DEFAULT 1.00,
    membership_discount DECIMAL(4,2) DEFAULT 0.00,
    total_fee DECIMAL(10,2) NOT NULL,
    status ENUM('active','expired','terminated','grace_period') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plot_id) REFERENCES plots(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Fn 2: Billing Transactions
CREATE TABLE billing_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','card','bank_transfer') DEFAULT 'cash',
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('paid','pending','overdue','refunded') DEFAULT 'pending',
    notes TEXT,
    FOREIGN KEY (lease_id) REFERENCES leases(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Fn 3: Soil Health Lifecycle Tracker
CREATE TABLE soil_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plot_id INT NOT NULL,
    user_id INT NOT NULL,
    event_type ENUM('fertilizer','ph_test','crop_rotation','amendment','other') NOT NULL,
    fertilizer_type VARCHAR(100),
    ph_level DECIMAL(4,2),                      -- 0.00–14.00
    crop_name VARCHAR(100),
    notes TEXT,
    is_at_risk TINYINT(1) DEFAULT 0,            -- flagged by system
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plot_id) REFERENCES plots(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Fn 4: Plot Waitlist & Priority
CREATE TABLE waitlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    priority_score DECIMAL(8,2) DEFAULT 0.00,   -- calculated: residency + community_points
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('waiting','notified','accepted','declined') DEFAULT 'waiting',
    notified_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Fn 6: Pest & Disease Reports
CREATE TABLE pest_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plot_id INT NOT NULL,
    reported_by INT NOT NULL,
    pest_type VARCHAR(100) NOT NULL,
    severity ENUM('low','medium','high') DEFAULT 'medium',
    is_transmissible TINYINT(1) DEFAULT 0,      -- triggers neighbor alerts
    description TEXT,
    status ENUM('open','investigating','resolved') DEFAULT 'open',
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plot_id) REFERENCES plots(id),
    FOREIGN KEY (reported_by) REFERENCES users(id)
);

-- Fn 7: Land Use Compliance Audit (Warden inspections)
CREATE TABLE inspections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plot_id INT NOT NULL,
    warden_id INT NOT NULL,
    notes TEXT,
    photo_paths TEXT,                           -- JSON array of file paths
    result ENUM('pass','warning','fail') NOT NULL,
    violation_details TEXT,
    penalty_applied DECIMAL(10,2) DEFAULT 0.00,
    inspected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plot_id) REFERENCES plots(id),
    FOREIGN KEY (warden_id) REFERENCES users(id)
);

-- Fn 8: Compost Contribution Tracker
CREATE TABLE compost_contributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount_kg DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    contributed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- MODULE B: Seeds & Tools (Functions 9–16)
-- ============================================================

-- Fn 9: Seed Viability & Expiry
CREATE TABLE seeds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    variety VARCHAR(100),
    quantity_packets INT DEFAULT 0,
    stored_date DATE NOT NULL,
    expiry_months INT NOT NULL DEFAULT 24,      -- shelf life in months
    status ENUM('viable','nearing_expiry','expired','flagged_for_testing','recommended_planting') DEFAULT 'viable',
    parent_plant_notes TEXT,                    -- genetic lineage
    allergen_category VARCHAR(100),             -- Fn 26: allergen guard
    media_links TEXT,                           -- Fn 15: JSON array of links
    added_by INT,
    FOREIGN KEY (added_by) REFERENCES users(id)
);

-- Fn 10, 11: Tool State Machine & Usage Tracking
CREATE TABLE tools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('available','checked_out','in_repair','decommissioned','missing') DEFAULT 'available',
    total_usage_hours DECIMAL(8,2) DEFAULT 0.00,
    maintenance_threshold_hours DECIMAL(8,2) DEFAULT 50.00,
    needs_maintenance TINYINT(1) DEFAULT 0,
    last_maintained_at TIMESTAMP NULL,
    media_links TEXT,                           -- Fn 15: JSON array of links
    purchase_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Fn 10: Tool State Change Log
CREATE TABLE tool_state_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tool_id INT NOT NULL,
    changed_by INT NOT NULL,
    old_status VARCHAR(50),
    new_status VARCHAR(50),
    notes TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tool_id) REFERENCES tools(id),
    FOREIGN KEY (changed_by) REFERENCES users(id)
);

-- Fn 12: Shared Resource Reservations
CREATE TABLE tool_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tool_id INT NOT NULL,
    user_id INT NOT NULL,
    slot_date DATE NOT NULL,
    slot_start TIME NOT NULL,
    slot_end TIME NOT NULL,
    status ENUM('confirmed','cancelled','completed','overdue') DEFAULT 'confirmed',
    due_date DATETIME,
    returned_at DATETIME NULL,
    reserved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tool_id) REFERENCES tools(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Fn 13: Tool Damage Reports
CREATE TABLE damage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tool_id INT NOT NULL,
    reported_by INT NOT NULL,
    description TEXT NOT NULL,
    damage_type ENUM('natural_wear','negligence','unknown') DEFAULT 'unknown',
    repair_fee DECIMAL(10,2) DEFAULT 0.00,
    is_exempt TINYINT(1) DEFAULT 0,
    admin_reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    status ENUM('pending','reviewed','resolved') DEFAULT 'pending',
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tool_id) REFERENCES tools(id),
    FOREIGN KEY (reported_by) REFERENCES users(id),
    FOREIGN KEY (admin_reviewed_by) REFERENCES users(id)
);

-- Fn 14: Consumable Inventory
CREATE TABLE consumables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    unit VARCHAR(30) DEFAULT 'kg',
    stock_level DECIMAL(10,2) DEFAULT 0.00,
    reorder_threshold DECIMAL(10,2) DEFAULT 5.00,
    alert_sent TINYINT(1) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE consumable_usage_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consumable_id INT NOT NULL,
    used_by INT NOT NULL,
    amount_used DECIMAL(10,2) NOT NULL,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consumable_id) REFERENCES consumables(id),
    FOREIGN KEY (used_by) REFERENCES users(id)
);

-- Fn 16: Late Return Penalties
CREATE TABLE tool_penalties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    user_id INT NOT NULL,
    days_late INT NOT NULL,
    penalty_type ENUM('fine','community_service_hours') DEFAULT 'fine',
    fine_amount DECIMAL(10,2) DEFAULT 0.00,
    service_hours DECIMAL(5,2) DEFAULT 0.00,
    status ENUM('pending','paid','served','waived') DEFAULT 'pending',
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES tool_reservations(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- MODULE C: Volunteer & Operations (Functions 17–23)
-- ============================================================

-- Fn 17: Communal Task Weighting
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    difficulty_score INT DEFAULT 1,             -- 1=easy … 5=hard
    points_reward INT DEFAULT 10,               -- community points earned
    status ENUM('open','in_progress','partial','completed') DEFAULT 'open',
    assigned_to INT NULL,
    created_by INT NOT NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE task_completions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    completion_type ENUM('full','partial') DEFAULT 'full',
    points_awarded INT DEFAULT 0,
    verified_by INT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (verified_by) REFERENCES users(id)
);

-- Fn 18: Mandatory Service Hours
CREATE TABLE service_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    hours_logged DECIMAL(5,2) NOT NULL,
    activity_description TEXT,
    month_year VARCHAR(7) NOT NULL,             -- format: 'YYYY-MM'
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by INT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
);

CREATE TABLE service_hour_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    required_hours DECIMAL(5,2) DEFAULT 4.00,  -- monthly requirement
    effective_from DATE NOT NULL
);

-- Fn 19: Shift Substitution
CREATE TABLE shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    shift_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    assigned_to INT NOT NULL,
    status ENUM('scheduled','completed','cancelled','swapped') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES users(id)
);

CREATE TABLE shift_swap_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_id INT NOT NULL,
    requester_id INT NOT NULL,
    target_id INT NOT NULL,
    status ENUM('pending','accepted','rejected','expired') DEFAULT 'pending',
    expires_at TIMESTAMP,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    FOREIGN KEY (shift_id) REFERENCES shifts(id),
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (target_id) REFERENCES users(id)
);

-- Fn 20: Emergency Broadcaster
CREATE TABLE broadcasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    affected_plots TEXT,                        -- JSON array of plot IDs
    site_status ENUM('normal','warning','closed','emergency') DEFAULT 'warning',
    is_false_alarm TINYINT(1) DEFAULT 0,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id)
);

-- Fn 21: Communal Fund Voting
CREATE TABLE proposals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    created_by INT NOT NULL,
    status ENUM('open','closed','tie','decided') DEFAULT 'open',
    winner_id INT NULL,
    voting_ends_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    user_id INT NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (proposal_id, user_id),
    FOREIGN KEY (proposal_id) REFERENCES proposals(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Fn 22: Garden Security Access Log
CREATE TABLE access_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,                           -- NULL if code invalid
    gate_code_entered VARCHAR(20),
    is_valid TINYINT(1) DEFAULT 0,
    access_type ENUM('entry','exit') DEFAULT 'entry',
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Fn 23: Incident & Hazard Reporting
CREATE TABLE incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reported_by INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(200),
    severity ENUM('low','medium','high','critical') DEFAULT 'medium',
    status ENUM('open','in_process','resolved') DEFAULT 'open',
    resolved_by INT NULL,
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (reported_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);

-- ============================================================
-- MODULE D: Marketplace & Gift Economy (Functions 24–28)
-- ============================================================

-- Fn 24: Produce Flash Trade
CREATE TABLE flash_trades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    quantity VARCHAR(100),
    allergen_flag TINYINT(1) DEFAULT 0,         -- Fn 26
    allergen_category VARCHAR(100),
    expires_at TIMESTAMP NOT NULL,
    status ENUM('active','claimed','expired','cancelled') DEFAULT 'active',
    claimed_by INT NULL,
    claimed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id),
    FOREIGN KEY (claimed_by) REFERENCES users(id)
);

-- Fn 25: Gift Economy / Karma
CREATE TABLE donations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donor_id INT NOT NULL,
    produce_name VARCHAR(150) NOT NULL,
    quantity VARCHAR(100),
    karma_points_awarded INT DEFAULT 0,
    is_rejected TINYINT(1) DEFAULT 0,
    rejection_reason TEXT,
    donated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_id) REFERENCES users(id)
);

-- Fn 27: P2P Advice Exchange
CREATE TABLE advice_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asker_id INT NOT NULL,
    question TEXT NOT NULL,
    status ENUM('open','answered','closed') DEFAULT 'open',
    best_answer_id INT NULL,
    asked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asker_id) REFERENCES users(id)
);

CREATE TABLE advice_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    answerer_id INT NOT NULL,
    answer TEXT NOT NULL,
    credits_awarded INT DEFAULT 0,
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES advice_questions(id),
    FOREIGN KEY (answerer_id) REFERENCES users(id)
);

-- Fn 28: Produce Quality Rating
CREATE TABLE produce_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trade_id INT NOT NULL,
    rater_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    notes TEXT,
    rated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trade_id) REFERENCES flash_trades(id),
    FOREIGN KEY (rater_id) REFERENCES users(id)
);

-- ============================================================
-- Fn 30: System Audit Trail
-- ============================================================

CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action_type VARCHAR(100) NOT NULL,          -- e.g. 'plot_rented', 'tool_checked_out'
    module VARCHAR(50),                         -- 'land','resources','volunteer','marketplace'
    target_table VARCHAR(100),
    target_id INT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- Fn 29: RBAC — Permissions table
-- ============================================================

CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    module VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,               -- 'view','create','edit','delete','approve'
    UNIQUE KEY unique_perm (role_id, module, action),
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Roles
INSERT INTO roles (name, description) VALUES
('admin',      'Full system access'),
('warden',     'Inspection and compliance access'),
('plot_owner', 'Plot management and marketplace'),
('member',     'Community features, no plot'),
('guest',      'View-only public access');

-- Service hour requirements
INSERT INTO service_hour_requirements (required_hours, effective_from)
VALUES (4.00, '2025-01-01');

-- Demo admin user (password: admin123)
INSERT INTO users (full_name, email, password_hash, role_id, membership_status, gate_code)
VALUES ('Garden Admin', 'admin@garden.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
        1, 'premium', 'GATE001');

-- Demo warden (password: password)
INSERT INTO users (full_name, email, password_hash, role_id, gate_code)
VALUES ('Sam Warden', 'warden@garden.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        2, 'GATE002');

-- Demo plot owner (password: password)
INSERT INTO users (full_name, email, password_hash, role_id, membership_status, gate_code, community_points, residency_months)
VALUES ('Alice Owner', 'alice@garden.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        3, 'standard', 'GATE003', 45, 12);

-- Demo member (password: password)
INSERT INTO users (full_name, email, password_hash, role_id, gate_code)
VALUES ('Bob Member', 'bob@garden.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        4, 'GATE004');

-- Sample plots
INSERT INTO plots (plot_code, boundary_coords, area_sqm, sunlight_level, soil_quality, status, lat, lng) VALUES
('A-01', '[[30.0444,31.2357],[30.0446,31.2357],[30.0446,31.2360],[30.0444,31.2360]]', 24.00, 'full', 'premium', 'occupied', 30.0445, 31.2358),
('A-02', '[[30.0448,31.2357],[30.0450,31.2357],[30.0450,31.2360],[30.0448,31.2360]]', 18.00, 'partial', 'standard', 'available', 30.0449, 31.2358),
('B-01', '[[30.0452,31.2357],[30.0454,31.2357],[30.0454,31.2360],[30.0452,31.2360]]', 30.00, 'full', 'premium', 'available', 30.0453, 31.2358),
('B-02', '[[30.0456,31.2357],[30.0458,31.2357],[30.0458,31.2360],[30.0456,31.2360]]', 20.00, 'shade', 'poor', 'maintenance', 30.0457, 31.2358);

-- Sample lease for Alice on plot A-01
INSERT INTO leases (plot_id, user_id, start_date, end_date, base_fee, soil_multiplier, membership_discount, total_fee, status)
VALUES (1, 3, '2025-01-01', '2025-12-31', 200.00, 1.30, 0.00, 260.00, 'active');

-- Sample tools
INSERT INTO tools (name, description, status, total_usage_hours, maintenance_threshold_hours) VALUES
('Rotavator',    'Heavy-duty soil tiller',         'available',    42.00, 50.00),
('Wheelbarrow',  'Large capacity wheelbarrow',      'available',     5.00, 100.00),
('Lawnmower',    'Electric lawnmower',              'checked_out',  78.00, 50.00),
('Garden Fork',  'Heavy duty digging fork',         'in_repair',    15.00, 200.00),
('Hose Reel',    '30m hose with spray nozzle',      'available',     8.00, 500.00);

-- Sample seeds
INSERT INTO seeds (name, variety, quantity_packets, stored_date, expiry_months, status, allergen_category, added_by) VALUES
('Tomato',    'Cherry Roma',    12, '2024-06-01', 4,  'nearing_expiry', 'Nightshades', 3),
('Basil',     'Sweet Genovese', 8,  '2025-01-01', 24, 'viable',         NULL,          3),
('Sunflower', 'Giant Russian',  20, '2024-01-01', 18, 'expired',        NULL,          1),
('Carrot',    'Nantes',         15, '2025-03-01', 36, 'viable',         NULL,          3),
('Peanut',    'Valencia',       6,  '2025-02-01', 12, 'viable',         'Tree Nuts',   1);

-- Sample consumables
INSERT INTO consumables (name, unit, stock_level, reorder_threshold) VALUES
('Organic Fertilizer', 'kg',     12.00, 5.00),
('Mulch',              'bags',    3.00, 5.00),
('Plant Pots (small)', 'units', 150.00, 20.00),
('Garden Twine',       'rolls',   2.00, 5.00);

-- Sample tasks
INSERT INTO tasks (title, description, difficulty_score, points_reward, created_by) VALUES
('Mow the main path',         'Cut grass along path A and B',      2, 20,  1),
('Turn compost pile',         'Mix and aerate the large pile',      4, 40,  1),
('Clear plot B-02 weeds',     'Remove all weeds from plot B-02',    3, 30,  1),
('Clean shared tool storage', 'Organise and clean the tool shed',   2, 20,  1);

-- Sample allergen categories (for reference)
INSERT INTO permissions (role_id, module, action) VALUES
-- Admin: everything
(1,'land','view'),(1,'land','create'),(1,'land','edit'),(1,'land','delete'),(1,'land','approve'),
(1,'resources','view'),(1,'resources','create'),(1,'resources','edit'),(1,'resources','delete'),(1,'resources','approve'),
(1,'volunteer','view'),(1,'volunteer','create'),(1,'volunteer','edit'),(1,'volunteer','delete'),(1,'volunteer','approve'),
(1,'marketplace','view'),(1,'marketplace','create'),(1,'marketplace','edit'),(1,'marketplace','delete'),(1,'marketplace','approve'),
-- Warden: land view+approve, others view
(2,'land','view'),(2,'land','approve'),(2,'land','edit'),
(2,'resources','view'),(2,'volunteer','view'),(2,'marketplace','view'),
-- Plot owner: most things except admin actions
(3,'land','view'),(3,'land','create'),(3,'land','edit'),
(3,'resources','view'),(3,'resources','create'),(3,'resources','edit'),
(3,'volunteer','view'),(3,'volunteer','create'),
(3,'marketplace','view'),(3,'marketplace','create'),(3,'marketplace','edit'),
-- Member: view + limited create
(4,'land','view'),(4,'resources','view'),(4,'resources','create'),
(4,'volunteer','view'),(4,'volunteer','create'),
(4,'marketplace','view'),(4,'marketplace','create'),
-- Guest: view only
(5,'land','view'),(5,'marketplace','view');
