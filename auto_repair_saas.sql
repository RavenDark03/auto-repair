-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 30, 2026 at 09:03 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `auto_repair_saas`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `appointment_date` date DEFAULT NULL,
  `status` enum('pending','approved','cancelled') NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `appointments`
--
DELIMITER $$
CREATE TRIGGER `trg_create_job_after_approval` AFTER UPDATE ON `appointments` FOR EACH ROW BEGIN
    IF NEW.status = 'approved' AND OLD.status <> 'approved' THEN
        INSERT INTO jobs (tenant_id, appointment_id)
        VALUES (NEW.tenant_id, NEW.appointment_id);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `billing_requests`
--

CREATE TABLE `billing_requests` (
  `billing_request_id` int(11) NOT NULL,
  `registration_id` int(11) NOT NULL,
  `plan_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `addon_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'PHP',
  `billing_status` enum('draft','sent','paid','cancelled') NOT NULL DEFAULT 'draft',
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_reference_check_status` varchar(30) DEFAULT 'unchecked',
  `payment_reference_checked_at` datetime DEFAULT NULL,
  `payment_reference_check_notes` text DEFAULT NULL,
  `payment_reference_checked_by` int(11) DEFAULT NULL,
  `paymongo_checkout_session_id` varchar(100) DEFAULT NULL,
  `paymongo_checkout_url` text DEFAULT NULL,
  `paymongo_payment_intent_id` varchar(100) DEFAULT NULL,
  `paymongo_payment_id` varchar(100) DEFAULT NULL,
  `paymongo_status` varchar(50) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `email_log_id` int(11) NOT NULL,
  `registration_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(150) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `email_type` enum('registration_received','billing_sent','approval_notice','rejection_notice','onboarding') NOT NULL,
  `send_status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `features`
--

CREATE TABLE `features` (
  `feature_id` int(11) NOT NULL,
  `feature_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `features`
--

INSERT INTO `features` (`feature_id`, `feature_name`, `description`) VALUES
(1, 'appointments', 'Appointment management'),
(2, 'jobs', 'Job tracking'),
(3, 'inventory', 'Inventory system'),
(4, 'invoicing', 'Invoice generation'),
(5, 'payments', 'Payment processing'),
(6, 'reports', 'Reports and analytics'),
(7, 'mechanic_module', 'Mechanic interface'),
(8, 'customer_module', 'Customer interface'),
(9, 'staff_management', 'Tenant staff and user administration'),
(10, 'analytics', 'Advanced analytics and date-range insights'),
(11, 'multi_branch', 'Multi-branch support for repair businesses');

-- --------------------------------------------------------

--
-- Table structure for table `feature_pricing`
--

CREATE TABLE `feature_pricing` (
  `feature_pricing_id` int(11) NOT NULL,
  `feature_id` int(11) NOT NULL,
  `monthly_addon_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `yearly_addon_price` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `feature_pricing`
--

INSERT INTO `feature_pricing` (`feature_pricing_id`, `feature_id`, `monthly_addon_price`, `yearly_addon_price`, `is_active`) VALUES
(1, 3, 299.00, 2990.00, 1),
(2, 5, 399.00, 3990.00, 1),
(3, 6, 249.00, 2490.00, 1),
(4, 7, 349.00, 3490.00, 1);

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `part_name` varchar(100) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reorder_level` int(11) NOT NULL DEFAULT 5,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_purchases`
--

CREATE TABLE `inventory_purchases` (
  `purchase_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payable_status` enum('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_purchase_items`
--

CREATE TABLE `inventory_purchase_items` (
  `purchase_item_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `status` enum('draft','unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'draft',
  `due_date` date DEFAULT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `invoice_item_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `item_type` enum('service','part','manual') NOT NULL DEFAULT 'manual',
  `source_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `job_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `mechanic_id` int(11) DEFAULT NULL,
  `status` enum('ongoing','completed') NOT NULL DEFAULT 'ongoing'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `jobs`
--
DELIMITER $$
CREATE TRIGGER `trg_log_job_status` AFTER UPDATE ON `jobs` FOR EACH ROW BEGIN
    INSERT INTO job_status_logs (tenant_id, job_id, status)
    VALUES (NEW.tenant_id, NEW.job_id, NEW.status);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `job_parts_used`
--

CREATE TABLE `job_parts_used` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `quantity_used` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `job_parts_used`
--
DELIMITER $$
CREATE TRIGGER `trg_deduct_inventory` AFTER INSERT ON `job_parts_used` FOR EACH ROW BEGIN
    UPDATE inventory
    SET quantity = quantity - NEW.quantity_used
    WHERE inventory_id = NEW.inventory_id
      AND tenant_id = NEW.tenant_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `job_services`
--

CREATE TABLE `job_services` (
  `job_service_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `service_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_status_logs`
--

CREATE TABLE `job_status_logs` (
  `log_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plan_features`
--

CREATE TABLE `plan_features` (
  `plan_feature_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `feature_id` int(11) NOT NULL,
  `is_included` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `plan_features`
--

INSERT INTO `plan_features` (`plan_feature_id`, `plan_id`, `feature_id`, `is_included`) VALUES
(1, 1, 1, 1),
(2, 1, 8, 1),
(3, 1, 2, 1),
(4, 2, 1, 1),
(5, 2, 8, 1),
(6, 2, 3, 1),
(7, 2, 4, 1),
(8, 2, 2, 1),
(9, 2, 5, 1),
(11, 3, 1, 1),
(12, 3, 8, 1),
(13, 3, 3, 1),
(14, 3, 4, 1),
(15, 3, 2, 1),
(16, 3, 7, 1),
(17, 3, 5, 1),
(18, 3, 6, 1);

-- --------------------------------------------------------

--
-- Table structure for table `registration_requested_features`
--

CREATE TABLE `registration_requested_features` (
  `registration_requested_feature_id` int(11) NOT NULL,
  `registration_id` int(11) NOT NULL,
  `feature_id` int(11) NOT NULL,
  `is_requested` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `subscription_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `plan` varchar(50) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','expired') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE `subscription_plans` (
  `plan_id` int(11) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `monthly_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `yearly_price` decimal(10,2) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscription_plans`
--

INSERT INTO `subscription_plans` (`plan_id`, `plan_name`, `monthly_price`, `yearly_price`, `description`, `is_active`, `created_at`) VALUES
(1, 'Starter', 1999.00, 19990.00, 'Core workflow for small repair shops', 1, '2026-04-20 17:35:05'),
(2, 'Growth', 3499.00, 34990.00, 'Operations, billing, and inventory for growing shops', 1, '2026-04-20 17:35:05'),
(3, 'Pro', 5499.00, 54990.00, 'Full platform access for established repair businesses', 1, '2026-04-20 17:35:05'),
(4, 'Read-Only', 499.00, 4990.00, 'Access historical records and reports without editing operational data', 1, '2026-04-20 17:35:05');

-- --------------------------------------------------------

--
-- Table structure for table `super_admins`
--

CREATE TABLE `super_admins` (
  `super_admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `super_admins`
--

INSERT INTO `super_admins` (`super_admin_id`, `username`, `password_hash`, `created_at`) VALUES
(1, 'superadmin', '$2y$10$9FkSqvjTCjW/uaX1xpMTN.O6KXicjpoLKJUNX0o1nE3Bht1H.D0Xa', '2026-04-20 04:54:43');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `supplier_payment_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `tenant_id` int(11) NOT NULL,
  `business_name` varchar(150) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `access_mode` enum('full_access','read_only') NOT NULL DEFAULT 'full_access',
  `read_only_source_plan` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`tenant_id`, `business_name`, `status`, `access_mode`, `read_only_source_plan`, `created_at`) VALUES
(1, 'Policarpio Auto Shop', 'active', 'full_access', NULL, '2026-04-20 04:54:43');

-- --------------------------------------------------------

--
-- Table structure for table `tenant_features`
--

CREATE TABLE `tenant_features` (
  `tenant_feature_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `feature_id` int(11) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenant_registrations`
--

CREATE TABLE `tenant_registrations` (
  `registration_id` int(11) NOT NULL,
  `business_name` varchar(150) NOT NULL,
  `owner_full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `preferred_username` varchar(50) DEFAULT NULL,
  `selected_plan_id` int(11) NOT NULL,
  `billing_cycle` enum('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `registration_status` enum('pending','approved','rejected','billing_sent','paid','converted') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `reviewed_by_super_admin_id` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `converted_tenant_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `role` enum('admin','cashier','mechanic','customer') NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `tenant_id`, `full_name`, `username`, `password_hash`, `must_change_password`, `role`, `status`, `created_at`) VALUES
(1, 1, 'System Admin', 'admin', '$2y$10$AeBVVLoZPmRoy8JLgD7brujy6AzBZ1KcenX5lV7F9S.o82Hsbwcqm', 0, 'admin', 'active', '2026-04-20 04:54:43');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `make` varchar(100) DEFAULT NULL,
  `year_model` varchar(20) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `plate` varchar(50) DEFAULT NULL,
  `mileage` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `billing_requests`
--
ALTER TABLE `billing_requests`
  ADD PRIMARY KEY (`billing_request_id`),
  ADD KEY `idx_billing_requests_registration` (`registration_id`),
  ADD KEY `idx_billing_requests_paymongo_session` (`paymongo_checkout_session_id`),
  ADD KEY `idx_billing_requests_paymongo_payment` (`paymongo_payment_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD KEY `idx_customers_tenant_name` (`tenant_id`,`name`),
  ADD KEY `idx_customers_tenant_status` (`tenant_id`,`status`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`email_log_id`),
  ADD KEY `registration_id` (`registration_id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `features`
--
ALTER TABLE `features`
  ADD PRIMARY KEY (`feature_id`),
  ADD UNIQUE KEY `feature_name` (`feature_name`);

--
-- Indexes for table `feature_pricing`
--
ALTER TABLE `feature_pricing`
  ADD PRIMARY KEY (`feature_pricing_id`),
  ADD KEY `feature_id` (`feature_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`),
  ADD KEY `idx_inventory_tenant_status` (`tenant_id`,`status`);

--
-- Indexes for table `inventory_purchases`
--
ALTER TABLE `inventory_purchases`
  ADD PRIMARY KEY (`purchase_id`),
  ADD KEY `idx_inventory_purchases_tenant_status` (`tenant_id`,`payable_status`),
  ADD KEY `idx_inventory_purchases_supplier` (`supplier_id`);

--
-- Indexes for table `inventory_purchase_items`
--
ALTER TABLE `inventory_purchase_items`
  ADD PRIMARY KEY (`purchase_item_id`),
  ADD KEY `fk_inventory_purchase_items_tenant` (`tenant_id`),
  ADD KEY `fk_inventory_purchase_items_inventory` (`inventory_id`),
  ADD KEY `idx_inventory_purchase_items_purchase` (`purchase_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `idx_invoices_tenant_invoice_no` (`tenant_id`,`invoice_no`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `idx_invoices_tenant` (`tenant_id`),
  ADD KEY `idx_invoices_tenant_status` (`tenant_id`,`status`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`invoice_item_id`),
  ADD KEY `fk_invoice_items_invoice` (`invoice_id`),
  ADD KEY `idx_invoice_items_tenant_invoice` (`tenant_id`,`invoice_id`),
  ADD KEY `idx_invoice_items_type_source` (`item_type`,`source_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`job_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `mechanic_id` (`mechanic_id`),
  ADD KEY `idx_jobs_tenant` (`tenant_id`);

--
-- Indexes for table `job_parts_used`
--
ALTER TABLE `job_parts_used`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `inventory_id` (`inventory_id`);

--
-- Indexes for table `job_services`
--
ALTER TABLE `job_services`
  ADD PRIMARY KEY (`job_service_id`),
  ADD KEY `fk_job_services_job` (`job_id`),
  ADD KEY `idx_job_services_tenant_job` (`tenant_id`,`job_id`);

--
-- Indexes for table `job_status_logs`
--
ALTER TABLE `job_status_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `job_id` (`job_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_payments_tenant` (`tenant_id`),
  ADD KEY `idx_payments_invoice_date` (`invoice_id`,`payment_date`);

--
-- Indexes for table `plan_features`
--
ALTER TABLE `plan_features`
  ADD PRIMARY KEY (`plan_feature_id`),
  ADD UNIQUE KEY `uq_plan_feature` (`plan_id`,`feature_id`),
  ADD KEY `feature_id` (`feature_id`);

--
-- Indexes for table `registration_requested_features`
--
ALTER TABLE `registration_requested_features`
  ADD PRIMARY KEY (`registration_requested_feature_id`),
  ADD UNIQUE KEY `uq_registration_feature` (`registration_id`,`feature_id`),
  ADD KEY `feature_id` (`feature_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`subscription_id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  ADD PRIMARY KEY (`plan_id`),
  ADD UNIQUE KEY `plan_name` (`plan_name`);

--
-- Indexes for table `super_admins`
--
ALTER TABLE `super_admins`
  ADD PRIMARY KEY (`super_admin_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD KEY `idx_suppliers_tenant_status` (`tenant_id`,`status`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`supplier_payment_id`),
  ADD KEY `fk_supplier_payments_tenant` (`tenant_id`),
  ADD KEY `idx_supplier_payments_purchase` (`purchase_id`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`tenant_id`);

--
-- Indexes for table `tenant_features`
--
ALTER TABLE `tenant_features`
  ADD PRIMARY KEY (`tenant_feature_id`),
  ADD UNIQUE KEY `uq_tenant_feature` (`tenant_id`,`feature_id`),
  ADD KEY `feature_id` (`feature_id`);

--
-- Indexes for table `tenant_registrations`
--
ALTER TABLE `tenant_registrations`
  ADD PRIMARY KEY (`registration_id`),
  ADD KEY `selected_plan_id` (`selected_plan_id`),
  ADD KEY `reviewed_by_super_admin_id` (`reviewed_by_super_admin_id`),
  ADD KEY `converted_tenant_id` (`converted_tenant_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_users_tenant_username` (`tenant_id`,`username`),
  ADD KEY `idx_users_tenant` (`tenant_id`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_vehicles_tenant_customer` (`tenant_id`,`customer_id`),
  ADD KEY `idx_vehicles_tenant_plate` (`tenant_id`,`plate`),
  ADD KEY `idx_vehicles_tenant_status` (`tenant_id`,`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `billing_requests`
--
ALTER TABLE `billing_requests`
  MODIFY `billing_request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `email_log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `features`
--
ALTER TABLE `features`
  MODIFY `feature_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `feature_pricing`
--
ALTER TABLE `feature_pricing`
  MODIFY `feature_pricing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_purchases`
--
ALTER TABLE `inventory_purchases`
  MODIFY `purchase_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_purchase_items`
--
ALTER TABLE `inventory_purchase_items`
  MODIFY `purchase_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `invoice_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `job_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_parts_used`
--
ALTER TABLE `job_parts_used`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_services`
--
ALTER TABLE `job_services`
  MODIFY `job_service_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_status_logs`
--
ALTER TABLE `job_status_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plan_features`
--
ALTER TABLE `plan_features`
  MODIFY `plan_feature_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `registration_requested_features`
--
ALTER TABLE `registration_requested_features`
  MODIFY `registration_requested_feature_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `subscription_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  MODIFY `plan_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `super_admins`
--
ALTER TABLE `super_admins`
  MODIFY `super_admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `supplier_payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `tenant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tenant_features`
--
ALTER TABLE `tenant_features`
  MODIFY `tenant_feature_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenant_registrations`
--
ALTER TABLE `tenant_registrations`
  MODIFY `registration_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE;

--
-- Constraints for table `billing_requests`
--
ALTER TABLE `billing_requests`
  ADD CONSTRAINT `billing_requests_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `tenant_registrations` (`registration_id`) ON DELETE CASCADE;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD CONSTRAINT `email_logs_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `tenant_registrations` (`registration_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `email_logs_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE SET NULL;

--
-- Constraints for table `feature_pricing`
--
ALTER TABLE `feature_pricing`
  ADD CONSTRAINT `feature_pricing_ibfk_1` FOREIGN KEY (`feature_id`) REFERENCES `features` (`feature_id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_purchases`
--
ALTER TABLE `inventory_purchases`
  ADD CONSTRAINT `fk_inventory_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_purchases_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_purchase_items`
--
ALTER TABLE `inventory_purchase_items`
  ADD CONSTRAINT `fk_inventory_purchase_items_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `inventory_purchases` (`purchase_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_purchase_items_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoice_items_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `jobs`
--
ALTER TABLE `jobs`
  ADD CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `jobs_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `jobs_ibfk_3` FOREIGN KEY (`mechanic_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `job_parts_used`
--
ALTER TABLE `job_parts_used`
  ADD CONSTRAINT `job_parts_used_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `job_parts_used_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `job_parts_used_ibfk_3` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`inventory_id`) ON DELETE CASCADE;

--
-- Constraints for table `job_services`
--
ALTER TABLE `job_services`
  ADD CONSTRAINT `fk_job_services_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_job_services_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `job_status_logs`
--
ALTER TABLE `job_status_logs`
  ADD CONSTRAINT `job_status_logs_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `job_status_logs_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE;

--
-- Constraints for table `plan_features`
--
ALTER TABLE `plan_features`
  ADD CONSTRAINT `plan_features_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`plan_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `plan_features_ibfk_2` FOREIGN KEY (`feature_id`) REFERENCES `features` (`feature_id`) ON DELETE CASCADE;

--
-- Constraints for table `registration_requested_features`
--
ALTER TABLE `registration_requested_features`
  ADD CONSTRAINT `registration_requested_features_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `tenant_registrations` (`registration_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `registration_requested_features_ibfk_2` FOREIGN KEY (`feature_id`) REFERENCES `features` (`feature_id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `fk_suppliers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD CONSTRAINT `fk_supplier_payments_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `inventory_purchases` (`purchase_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_supplier_payments_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `tenant_features`
--
ALTER TABLE `tenant_features`
  ADD CONSTRAINT `tenant_features_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tenant_features_ibfk_2` FOREIGN KEY (`feature_id`) REFERENCES `features` (`feature_id`) ON DELETE CASCADE;

--
-- Constraints for table `tenant_registrations`
--
ALTER TABLE `tenant_registrations`
  ADD CONSTRAINT `tenant_registrations_ibfk_1` FOREIGN KEY (`selected_plan_id`) REFERENCES `subscription_plans` (`plan_id`),
  ADD CONSTRAINT `tenant_registrations_ibfk_2` FOREIGN KEY (`reviewed_by_super_admin_id`) REFERENCES `super_admins` (`super_admin_id`),
  ADD CONSTRAINT `tenant_registrations_ibfk_3` FOREIGN KEY (`converted_tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vehicles_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
