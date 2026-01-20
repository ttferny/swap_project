-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 20, 2026 at 12:33 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tp_amc`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `audit_id` bigint(20) NOT NULL,
  `actor_user_id` bigint(20) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `booking_id` bigint(20) NOT NULL,
  `equipment_id` bigint(20) NOT NULL,
  `requester_id` bigint(20) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled','flagged') NOT NULL DEFAULT 'pending',
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `approved_by` bigint(20) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` bigint(20) DEFAULT NULL,
  `flag_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `bookings`
--
DELIMITER $$
CREATE TRIGGER `trg_bookings_no_overlap_ins` BEFORE INSERT ON `bookings` FOR EACH ROW BEGIN
  IF EXISTS (
    SELECT 1
    FROM bookings b
    WHERE b.equipment_id = NEW.equipment_id
      AND b.status IN ('pending','approved')
      AND NEW.start_time < b.end_time
      AND NEW.end_time > b.start_time
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Booking overlaps with an existing booking (pending/approved).';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bookings_no_overlap_upd` BEFORE UPDATE ON `bookings` FOR EACH ROW BEGIN
  IF NEW.status IN ('pending','approved') THEN
    IF EXISTS (
      SELECT 1
      FROM bookings b
      WHERE b.equipment_id = NEW.equipment_id
        AND b.booking_id <> OLD.booking_id
        AND b.status IN ('pending','approved')
        AND NEW.start_time < b.end_time
        AND NEW.end_time > b.start_time
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Updated booking overlaps with an existing booking (pending/approved).';
    END IF;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `booking_waitlist`
--

CREATE TABLE `booking_waitlist` (
  `waitlist_id` bigint(20) NOT NULL,
  `equipment_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `desired_start` datetime NOT NULL,
  `desired_end` datetime NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certifications`
--

CREATE TABLE `certifications` (
  `cert_id` bigint(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `valid_days` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

CREATE TABLE `equipment` (
  `equipment_id` bigint(20) NOT NULL,
  `serial_no` varchar(80) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(60) NOT NULL,
  `location` varchar(80) DEFAULT NULL,
  `manufacturer` varchar(80) DEFAULT NULL,
  `model` varchar(80) DEFAULT NULL,
  `risk_level` enum('low','medium','high') NOT NULL DEFAULT 'low',
  `current_status` enum('operational','maintenance','faulty') NOT NULL DEFAULT 'operational',
  `status_updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status_updated_by` bigint(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equipment`
--

INSERT INTO `equipment` (`equipment_id`, `serial_no`, `name`, `category`, `location`, `manufacturer`, `model`, `risk_level`, `current_status`, `status_updated_at`, `status_updated_by`, `notes`, `created_at`, `updated_at`) VALUES
(21, 'TP-AMC-CNC-001', '3-Axis CNC Milling Machine', 'CNC Machining', 'Lobby', 'HAAS', 'VF-2', 'high', 'operational', '2026-01-19 14:08:38', 8, 'Used for precision machining and student training', '2026-01-19 14:08:38', '2026-01-19 14:08:38'),
(22, 'TP-AMC-CNC-002', 'CNC Turning Lathe', 'CNC Machining', 'Lobby', 'DMG MORI', 'CLX 350', 'high', 'operational', '2026-01-19 14:08:38', 8, 'High-speed turning operations', '2026-01-19 14:08:38', '2026-01-19 14:08:38'),
(23, 'TP-AMC-ROB-001', 'Industrial Robotic Arm', 'Robotics', 'Room 1', 'FANUC', 'M-20iD/25', 'high', 'operational', '2026-01-19 14:08:38', 8, 'Used for automation and pick-and-place demonstrations', '2026-01-19 14:08:38', '2026-01-19 14:08:38'),
(24, 'TP-AMC-ROB-002', 'Collaborative Robot (Cobot)', 'Robotics', 'Room 1', 'Universal Robots', 'UR5e', 'medium', 'operational', '2026-01-19 14:08:38', 8, 'Safe human-robot collaboration experiments', '2026-01-19 14:08:38', '2026-01-19 14:08:38'),
(25, 'TP-AMC-AM-001', 'Metal 3D Printer', 'Additive Manufacturing', 'Room 2', 'EOS', 'M 290', 'high', 'maintenance', '2026-01-19 14:08:38', 8, 'Metal powder handling requires supervision', '2026-01-19 14:08:38', '2026-01-19 14:19:53'),
(26, 'TP-AMC-AM-002', 'FDM 3D Printer', 'Additive Manufacturing', 'Room 2', 'Stratasys', 'F370', 'low', 'operational', '2026-01-19 14:08:38', 8, 'Prototyping and student projects', '2026-01-19 14:08:38', '2026-01-19 14:08:38'),
(27, 'TP-AMC-QA-001', 'Coordinate Measuring Machine (CMM)', 'Quality Assurance', 'Room 3', 'Mitutoyo', 'CRYSTA-Apex S', 'medium', 'operational', '2026-01-19 14:08:38', 8, 'Precision dimensional inspection', '2026-01-19 14:08:38', '2026-01-19 14:08:38'),
(28, 'TP-AMC-PLC-001', 'PLC Training System', 'Industrial Automation', 'Room 2', 'Siemens', 'S7-1500', 'low', 'operational', '2026-01-19 14:08:38', 8, 'Used for PLC programming and automation labs', '2026-01-19 14:08:38', '2026-01-19 14:08:38'),
(29, 'TP-AMC-VIS-001', 'Machine Vision Inspection System', 'Smart Manufacturing', 'Room 4', 'Keyence', 'XG-X Series', 'medium', 'operational', '2026-01-19 14:08:38', 8, 'Automated visual inspection and defect detection', '2026-01-19 14:08:38', '2026-01-19 14:08:38'),
(30, 'TP-AMC-AGV-001', 'Automated Guided Vehicle (AGV)', 'Smart Manufacturing', 'Room 4', 'Omron', 'LD-90', 'medium', 'faulty', '2026-01-19 14:08:38', 8, 'Autonomous material transport system', '2026-01-19 14:08:38', '2026-01-19 14:20:20');

-- --------------------------------------------------------

--
-- Table structure for table `equipment_required_certs`
--

CREATE TABLE `equipment_required_certs` (
  `equipment_id` bigint(20) NOT NULL,
  `cert_id` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_status_history`
--

CREATE TABLE `equipment_status_history` (
  `history_id` bigint(20) NOT NULL,
  `equipment_id` bigint(20) NOT NULL,
  `old_status` enum('operational','maintenance','faulty') NOT NULL,
  `new_status` enum('operational','maintenance','faulty') NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` bigint(20) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_training_materials`
--

CREATE TABLE `equipment_training_materials` (
  `equipment_id` bigint(20) NOT NULL,
  `material_id` bigint(20) NOT NULL,
  `linked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `linked_by` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `incidents`
--

CREATE TABLE `incidents` (
  `incident_id` bigint(20) NOT NULL,
  `reported_by` bigint(20) NOT NULL,
  `equipment_id` bigint(20) DEFAULT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `category` enum('near_miss','injury','hazard','damage','security','other') NOT NULL DEFAULT 'other',
  `location` varchar(80) DEFAULT NULL,
  `description` text NOT NULL,
  `status` enum('submitted','under_review','action_required','closed') NOT NULL DEFAULT 'submitted',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `incident_investigations`
--

CREATE TABLE `incident_investigations` (
  `investigation_id` bigint(20) NOT NULL,
  `incident_id` bigint(20) NOT NULL,
  `assigned_to` bigint(20) DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `actions_taken` text DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_records`
--

CREATE TABLE `maintenance_records` (
  `record_id` bigint(20) NOT NULL,
  `equipment_id` bigint(20) NOT NULL,
  `task_id` bigint(20) DEFAULT NULL,
  `downtime_start` datetime DEFAULT NULL,
  `downtime_end` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `logged_by` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_tasks`
--

CREATE TABLE `maintenance_tasks` (
  `task_id` bigint(20) NOT NULL,
  `equipment_id` bigint(20) NOT NULL,
  `title` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `task_type` enum('preventive','corrective') NOT NULL DEFAULT 'corrective',
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` enum('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
  `scheduled_for` datetime DEFAULT NULL,
  `assigned_to` bigint(20) DEFAULT NULL,
  `created_by` bigint(20) NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`, `description`) VALUES
(1, 'Admin', 'Full system admin'),
(2, 'Manager', 'Supervisor/manager approving bookings and handling incidents'),
(3, 'Technician', 'Handles maintenance tasks and equipment status updates'),
(4, 'User', 'Regular user (staff/student)');

-- --------------------------------------------------------

--
-- Table structure for table `training_materials`
--

CREATE TABLE `training_materials` (
  `material_id` bigint(20) NOT NULL,
  `title` varchar(160) NOT NULL,
  `material_type` enum('pdf','video','sop','manual','link','other') NOT NULL DEFAULT 'other',
  `file_url` varchar(500) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `version` varchar(30) DEFAULT NULL,
  `uploaded_by` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` bigint(20) NOT NULL,
  `role_id` int(11) NOT NULL,
  `tp_admin_no` varchar(30) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `status` enum('active','locked','suspended') NOT NULL DEFAULT 'active',
  `failed_login_count` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role_id`, `tp_admin_no`, `username`, `email`, `password_hash`, `full_name`, `status`, `failed_login_count`, `locked_until`, `last_login_at`, `created_at`, `updated_at`) VALUES
(8, 1, '1234567A', 'sysadmin', 'john_lee@tp.edu.sg', '$2y$10$abcdefghijklmnopqrstuv', 'System Administrator', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-19 14:00:40'),
(9, 2, '1234567B', 'amc_manager', 'jane_lee@tp.edu.sg', '$2y$10$abcdefghijklmnopqrstuv', 'AMC Manager', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-19 14:00:40'),
(10, 3, '1234567C', 'amc_technician', 'lily_ng@tp.edu.sg', '$2y$10$abcdefghijklmnopqrstuv', 'AMC Technician', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-19 14:00:40'),
(11, 4, '2503456C', 'jason_lim', '2503456C@student.tp.edu.sg', '$2y$10$abcdefghijklmnopqrstuv', 'Jason Lim Wei Jie', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-19 14:00:40'),
(12, 4, '2309876D', 'amelia_tan', '2309876D@student.tp.edu.sg', '$2y$10$abcdefghijklmnopqrstuv', 'Amelia Tan Xin Yi', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-19 14:00:40'),
(13, 4, '2406789E', 'ryan_goh', '2406789E@student.tp.edu.sg', '$2y$10$abcdefghijklmnopqrstuv', 'Ryan Goh Jun Hao', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-19 14:00:40'),
(14, 4, '2501122F', 'nur_aisyah', '2501122F@student.tp.edu.sg', '$2y$10$abcdefghijklmnopqrstuv', 'Nur Aisyah Binte Ahmad', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-19 14:00:40');

-- --------------------------------------------------------

--
-- Table structure for table `user_certifications`
--

CREATE TABLE `user_certifications` (
  `user_cert_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `cert_id` bigint(20) NOT NULL,
  `status` enum('in_progress','completed','expired','revoked') NOT NULL DEFAULT 'in_progress',
  `completed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `verified_by` bigint(20) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_audit_time` (`created_at`),
  ADD KEY `idx_audit_actor_time` (`actor_user_id`,`created_at`),
  ADD KEY `idx_audit_action_time` (`action`,`created_at`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD KEY `fk_bookings_approved_by` (`approved_by`),
  ADD KEY `fk_bookings_cancelled_by` (`cancelled_by`),
  ADD KEY `idx_bookings_equipment_time` (`equipment_id`,`start_time`,`end_time`),
  ADD KEY `idx_bookings_requester_time` (`requester_id`,`start_time`),
  ADD KEY `idx_bookings_status` (`status`,`requires_approval`);

--
-- Indexes for table `booking_waitlist`
--
ALTER TABLE `booking_waitlist`
  ADD PRIMARY KEY (`waitlist_id`),
  ADD UNIQUE KEY `uq_waitlist_unique` (`equipment_id`,`user_id`,`desired_start`,`desired_end`),
  ADD KEY `fk_waitlist_user` (`user_id`),
  ADD KEY `idx_waitlist_equipment_time` (`equipment_id`,`desired_start`);

--
-- Indexes for table `certifications`
--
ALTER TABLE `certifications`
  ADD PRIMARY KEY (`cert_id`),
  ADD UNIQUE KEY `uq_cert_name` (`name`);

--
-- Indexes for table `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`equipment_id`),
  ADD UNIQUE KEY `uq_equipment_serial_no` (`serial_no`),
  ADD KEY `idx_equipment_status` (`current_status`,`risk_level`),
  ADD KEY `fk_equipment_status_updated_by` (`status_updated_by`);

--
-- Indexes for table `equipment_required_certs`
--
ALTER TABLE `equipment_required_certs`
  ADD PRIMARY KEY (`equipment_id`,`cert_id`),
  ADD KEY `fk_reqcert_cert` (`cert_id`);

--
-- Indexes for table `equipment_status_history`
--
ALTER TABLE `equipment_status_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `fk_status_hist_changed_by` (`changed_by`),
  ADD KEY `idx_status_hist_equipment_time` (`equipment_id`,`changed_at`);

--
-- Indexes for table `equipment_training_materials`
--
ALTER TABLE `equipment_training_materials`
  ADD PRIMARY KEY (`equipment_id`,`material_id`),
  ADD KEY `fk_eq_train_material` (`material_id`),
  ADD KEY `fk_eq_train_linked_by` (`linked_by`);

--
-- Indexes for table `incidents`
--
ALTER TABLE `incidents`
  ADD PRIMARY KEY (`incident_id`),
  ADD KEY `fk_incidents_reported_by` (`reported_by`),
  ADD KEY `idx_incidents_equipment_time` (`equipment_id`,`created_at`),
  ADD KEY `idx_incidents_severity_status` (`severity`,`status`,`created_at`);

--
-- Indexes for table `incident_investigations`
--
ALTER TABLE `incident_investigations`
  ADD PRIMARY KEY (`investigation_id`),
  ADD UNIQUE KEY `uq_invest_one_per_incident` (`incident_id`),
  ADD KEY `fk_invest_assigned_to` (`assigned_to`);

--
-- Indexes for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `fk_maintrec_task` (`task_id`),
  ADD KEY `fk_maintrec_logged_by` (`logged_by`),
  ADD KEY `idx_maintrec_equipment_time` (`equipment_id`,`created_at`);

--
-- Indexes for table `maintenance_tasks`
--
ALTER TABLE `maintenance_tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `fk_maint_created_by` (`created_by`),
  ADD KEY `idx_maint_equipment_status` (`equipment_id`,`status`,`scheduled_for`),
  ADD KEY `idx_maint_assignee` (`assigned_to`,`status`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `uq_roles_name` (`role_name`);

--
-- Indexes for table `training_materials`
--
ALTER TABLE `training_materials`
  ADD PRIMARY KEY (`material_id`),
  ADD KEY `fk_training_uploaded_by` (`uploaded_by`),
  ADD KEY `idx_training_type` (`material_type`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_tp_admin_no` (`tp_admin_no`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `fk_users_role` (`role_id`);

--
-- Indexes for table `user_certifications`
--
ALTER TABLE `user_certifications`
  ADD PRIMARY KEY (`user_cert_id`),
  ADD UNIQUE KEY `uq_user_cert_once` (`user_id`,`cert_id`),
  ADD KEY `fk_usercert_cert` (`cert_id`),
  ADD KEY `fk_usercert_verified_by` (`verified_by`),
  ADD KEY `idx_usercert_status` (`status`,`expires_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `audit_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `booking_waitlist`
--
ALTER TABLE `booking_waitlist`
  MODIFY `waitlist_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certifications`
--
ALTER TABLE `certifications`
  MODIFY `cert_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `equipment`
--
ALTER TABLE `equipment`
  MODIFY `equipment_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `equipment_status_history`
--
ALTER TABLE `equipment_status_history`
  MODIFY `history_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `incidents`
--
ALTER TABLE `incidents`
  MODIFY `incident_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `incident_investigations`
--
ALTER TABLE `incident_investigations`
  MODIFY `investigation_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  MODIFY `record_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_tasks`
--
ALTER TABLE `maintenance_tasks`
  MODIFY `task_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `training_materials`
--
ALTER TABLE `training_materials`
  MODIFY `material_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `user_certifications`
--
ALTER TABLE `user_certifications`
  MODIFY `user_cert_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bookings_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bookings_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bookings_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `booking_waitlist`
--
ALTER TABLE `booking_waitlist`
  ADD CONSTRAINT `fk_waitlist_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_waitlist_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `equipment`
--
ALTER TABLE `equipment`
  ADD CONSTRAINT `fk_equipment_status_updated_by` FOREIGN KEY (`status_updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `equipment_required_certs`
--
ALTER TABLE `equipment_required_certs`
  ADD CONSTRAINT `fk_reqcert_cert` FOREIGN KEY (`cert_id`) REFERENCES `certifications` (`cert_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reqcert_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `equipment_status_history`
--
ALTER TABLE `equipment_status_history`
  ADD CONSTRAINT `fk_status_hist_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_status_hist_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `equipment_training_materials`
--
ALTER TABLE `equipment_training_materials`
  ADD CONSTRAINT `fk_eq_train_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_eq_train_linked_by` FOREIGN KEY (`linked_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_eq_train_material` FOREIGN KEY (`material_id`) REFERENCES `training_materials` (`material_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `incidents`
--
ALTER TABLE `incidents`
  ADD CONSTRAINT `fk_incidents_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_incidents_reported_by` FOREIGN KEY (`reported_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `incident_investigations`
--
ALTER TABLE `incident_investigations`
  ADD CONSTRAINT `fk_invest_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invest_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`incident_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  ADD CONSTRAINT `fk_maintrec_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_maintrec_logged_by` FOREIGN KEY (`logged_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_maintrec_task` FOREIGN KEY (`task_id`) REFERENCES `maintenance_tasks` (`task_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `maintenance_tasks`
--
ALTER TABLE `maintenance_tasks`
  ADD CONSTRAINT `fk_maint_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_maint_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_maint_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON UPDATE CASCADE;

--
-- Constraints for table `training_materials`
--
ALTER TABLE `training_materials`
  ADD CONSTRAINT `fk_training_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON UPDATE CASCADE;

--
-- Constraints for table `user_certifications`
--
ALTER TABLE `user_certifications`
  ADD CONSTRAINT `fk_usercert_cert` FOREIGN KEY (`cert_id`) REFERENCES `certifications` (`cert_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usercert_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usercert_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
