-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 29, 2026 at 04:28 PM
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

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`audit_id`, `actor_user_id`, `action`, `entity_type`, `entity_id`, `ip_address`, `user_agent`, `details`, `created_at`) VALUES
(222, NULL, 'booking_flagged', 'bookings', 22, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"requester_id\":13}', '2026-01-29 16:30:45'),
(223, NULL, 'booking_flagged', 'bookings', 26, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"requester_id\":13}', '2026-01-29 16:30:45'),
(224, NULL, 'booking_flagged', 'bookings', 27, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"requester_id\":13}', '2026-01-29 16:31:59'),
(225, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 16:53:42'),
(226, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"User\"}', '2026-01-29 16:54:44'),
(227, 13, 'incident_reported', 'incidents', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"high\",\"category\":\"injury\",\"equipment_id\":23}', '2026-01-29 17:08:34'),
(228, 13, 'incident_reported', 'incidents', 2, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"low\",\"category\":\"damage\",\"equipment_id\":null}', '2026-01-29 17:10:24'),
(229, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 17:15:06'),
(230, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 17:15:20'),
(231, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 17:15:25'),
(232, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 17:16:12'),
(233, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 17:30:00'),
(234, 15, 'login', 'authentication', 15, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567D\",\"role\":\"Staff\"}', '2026-01-29 17:30:10'),
(235, 15, 'logout', 'authentication', 15, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567D\"}', '2026-01-29 17:30:21'),
(236, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 17:33:55'),
(237, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 17:33:59'),
(238, 8, 'login', 'authentication', 8, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567A\",\"role\":\"Admin\"}', '2026-01-29 17:34:11'),
(239, 8, 'logout', 'authentication', 8, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567A\"}', '2026-01-29 17:34:16'),
(240, NULL, 'login_lockout', 'authentication', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"admin_number\":\"2506789C\",\"attempts\":3,\"lock_seconds\":300}', '2026-01-29 17:34:31'),
(241, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 17:56:06'),
(242, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 17:56:10'),
(243, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"Student\"}', '2026-01-29 17:56:30'),
(244, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 17:56:42'),
(245, 15, 'login', 'authentication', 15, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567D\",\"role\":\"Staff\"}', '2026-01-29 17:56:48'),
(246, 15, 'logout', 'authentication', 15, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567D\"}', '2026-01-29 17:56:54'),
(247, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 17:56:59'),
(248, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 18:29:39'),
(249, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 18:30:07'),
(250, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 20:50:05'),
(251, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"Student\"}', '2026-01-29 20:50:11'),
(252, 13, 'user_cert_action', 'certification', 4, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:27'),
(253, 13, 'user_cert_action', 'certification', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:29'),
(254, 13, 'user_cert_action', 'certification', 12, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:30'),
(255, 13, 'user_cert_action', 'certification', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:32'),
(256, 13, 'user_cert_action', 'certification', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:33'),
(257, 13, 'user_cert_action', 'certification', 18, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:36'),
(258, 13, 'user_cert_action', 'certification', 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:37'),
(259, 13, 'user_cert_action', 'certification', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:39'),
(260, 13, 'user_cert_action', 'certification', 11, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:41'),
(261, 13, 'user_cert_action', 'certification', 8, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"action\":\"request\"}', '2026-01-29 20:50:44'),
(262, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 20:50:46'),
(263, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 20:50:54'),
(264, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 20:50:57'),
(265, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"Student\"}', '2026-01-29 20:54:13'),
(266, 13, 'booking_created', 'bookings', 28, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"equipment_id\":21,\"purpose\":\"Booking submitted via portal.\",\"origin\":\"portal_booking_form\"}', '2026-01-29 20:54:27'),
(267, 13, 'booking_created', 'bookings', 29, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"equipment_id\":22,\"purpose\":\"Booking submitted via portal.\",\"origin\":\"portal_booking_form\"}', '2026-01-29 20:54:40'),
(268, 13, 'booking_created', 'bookings', 30, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"equipment_id\":21,\"purpose\":\"Booking submitted via portal.\",\"origin\":\"portal_booking_form\"}', '2026-01-29 20:55:00'),
(269, 13, 'booking_created', 'bookings', 31, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"equipment_id\":25,\"purpose\":\"Booking submitted via portal.\",\"origin\":\"portal_booking_form\"}', '2026-01-29 20:55:12'),
(270, 13, 'booking_created', 'bookings', 32, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"equipment_id\":26,\"purpose\":\"Booking submitted via portal.\",\"origin\":\"portal_booking_form\"}', '2026-01-29 20:55:26'),
(271, 13, 'booking_created', 'bookings', 33, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"equipment_id\":28,\"purpose\":\"Booking submitted via portal.\",\"origin\":\"portal_booking_form\"}', '2026-01-29 20:55:40'),
(272, 13, 'booking_created', 'bookings', 34, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"equipment_id\":28,\"purpose\":\"Booking submitted via portal.\",\"origin\":\"portal_booking_form\"}', '2026-01-29 20:55:55'),
(273, 13, 'booking_created', 'bookings', 35, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"equipment_id\":29,\"purpose\":\"Booking submitted via portal.\",\"origin\":\"portal_booking_form\"}', '2026-01-29 20:56:10'),
(274, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 20:56:17'),
(275, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 20:56:25'),
(276, 9, 'booking_approved', 'booking', 28, NULL, NULL, '{\"equipment_id\":21,\"requester_id\":13,\"start_time\":\"2026-01-14 08:54:00\",\"end_time\":\"2026-01-14 09:54:00\"}', '2026-01-29 20:56:29'),
(277, 9, 'booking_approved', 'booking', 29, NULL, NULL, '{\"equipment_id\":22,\"requester_id\":13,\"start_time\":\"2026-01-14 20:54:00\",\"end_time\":\"2026-01-14 21:54:00\"}', '2026-01-29 20:56:30'),
(278, 9, 'booking_approved', 'booking', 30, NULL, NULL, '{\"equipment_id\":21,\"requester_id\":13,\"start_time\":\"2026-01-15 20:56:00\",\"end_time\":\"2026-01-15 22:56:00\"}', '2026-01-29 20:56:30'),
(279, 9, 'booking_approved', 'booking', 35, NULL, NULL, '{\"equipment_id\":29,\"requester_id\":13,\"start_time\":\"2026-01-20 11:56:00\",\"end_time\":\"2026-01-20 13:56:00\"}', '2026-01-29 20:56:31'),
(280, 9, 'booking_approved', 'booking', 33, NULL, NULL, '{\"equipment_id\":28,\"requester_id\":13,\"start_time\":\"2026-01-24 08:56:00\",\"end_time\":\"2026-01-24 09:26:00\"}', '2026-01-29 20:56:32'),
(281, 9, 'booking_approved', 'booking', 34, NULL, NULL, '{\"equipment_id\":28,\"requester_id\":13,\"start_time\":\"2026-01-24 15:55:00\",\"end_time\":\"2026-01-24 16:55:00\"}', '2026-01-29 20:56:32'),
(282, 9, 'booking_approved', 'booking', 31, NULL, NULL, '{\"equipment_id\":25,\"requester_id\":13,\"start_time\":\"2026-01-27 11:57:00\",\"end_time\":\"2026-01-27 12:57:00\"}', '2026-01-29 20:56:33'),
(283, 9, 'booking_approved', 'booking', 32, NULL, NULL, '{\"equipment_id\":26,\"requester_id\":13,\"start_time\":\"2026-01-28 08:00:00\",\"end_time\":\"2026-01-28 11:00:00\"}', '2026-01-29 20:56:33'),
(284, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 20:57:11'),
(285, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"Student\"}', '2026-01-29 20:57:15'),
(286, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 20:57:23'),
(287, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 20:57:31'),
(288, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 21:19:05'),
(289, 11, 'login', 'authentication', 11, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2503456C\",\"role\":\"Student\"}', '2026-01-29 21:19:11'),
(290, 11, 'incident_reported', 'incidents', 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"security\",\"equipment_id\":27}', '2026-01-29 21:19:59'),
(291, 11, 'incident_reported', 'incidents', 4, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"near_miss\",\"equipment_id\":29}', '2026-01-29 21:20:32'),
(292, 11, 'incident_reported', 'incidents', 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"near_miss\",\"equipment_id\":29}', '2026-01-29 21:20:47'),
(293, 11, 'incident_reported', 'incidents', 6, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"near_miss\",\"equipment_id\":29}', '2026-01-29 21:21:04'),
(294, 11, 'incident_reported', 'incidents', 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":27}', '2026-01-29 21:21:31'),
(295, 11, 'incident_reported', 'incidents', 8, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":27}', '2026-01-29 21:21:47'),
(296, 11, 'incident_reported', 'incidents', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"high\",\"category\":\"injury\",\"equipment_id\":27}', '2026-01-29 21:21:56'),
(297, 11, 'incident_reported', 'incidents', 10, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":27}', '2026-01-29 21:22:04'),
(298, 11, 'logout', 'authentication', 11, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2503456C\"}', '2026-01-29 21:22:07'),
(299, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 21:22:12'),
(300, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 21:24:56'),
(301, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"Student\"}', '2026-01-29 21:25:01'),
(302, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 21:25:34'),
(303, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 21:25:44'),
(304, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 21:26:53'),
(305, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"Student\"}', '2026-01-29 21:26:58'),
(306, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 21:49:43'),
(307, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 21:49:48'),
(308, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 21:52:08'),
(309, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"Student\"}', '2026-01-29 21:52:13'),
(310, 13, 'incident_reported', 'incidents', 11, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"low\",\"category\":\"near_miss\",\"equipment_id\":21}', '2026-01-29 21:52:25'),
(311, 13, 'incident_reported', 'incidents', 12, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"injury\",\"equipment_id\":21}', '2026-01-29 21:52:33'),
(312, 13, 'incident_reported', 'incidents', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":24}', '2026-01-29 21:52:42'),
(313, 13, 'incident_reported', 'incidents', 14, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":24}', '2026-01-29 21:52:49'),
(314, 13, 'incident_reported', 'incidents', 15, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":24}', '2026-01-29 21:52:56'),
(315, 13, 'incident_reported', 'incidents', 16, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"injury\",\"equipment_id\":24}', '2026-01-29 21:53:08'),
(316, 13, 'incident_reported', 'incidents', 17, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"high\",\"category\":\"damage\",\"equipment_id\":26}', '2026-01-29 21:53:20'),
(317, 13, 'incident_reported', 'incidents', 18, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"hazard\",\"equipment_id\":23}', '2026-01-29 21:53:31'),
(318, 13, 'incident_reported', 'incidents', 19, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"hazard\",\"equipment_id\":23}', '2026-01-29 21:53:40'),
(319, 13, 'incident_reported', 'incidents', 20, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"security\",\"equipment_id\":23}', '2026-01-29 21:53:53'),
(320, 13, 'incident_reported', 'incidents', 21, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"other\",\"equipment_id\":null}', '2026-01-29 21:54:15'),
(321, 13, 'incident_reported', 'incidents', 22, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"low\",\"category\":\"other\",\"equipment_id\":21}', '2026-01-29 21:59:14'),
(322, 13, 'incident_reported', 'incidents', 23, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"high\",\"category\":\"near_miss\",\"equipment_id\":21}', '2026-01-29 21:59:27'),
(323, 13, 'incident_reported', 'incidents', 24, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":22}', '2026-01-29 21:59:42'),
(324, 13, 'incident_reported', 'incidents', 25, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":22}', '2026-01-29 21:59:48'),
(325, 13, 'incident_reported', 'incidents', 26, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"critical\",\"category\":\"injury\",\"equipment_id\":22}', '2026-01-29 21:59:55'),
(326, 13, 'incident_reported', 'incidents', 27, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"damage\",\"equipment_id\":29}', '2026-01-29 22:02:23'),
(327, 13, 'incident_reported', 'incidents', 28, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"damage\",\"equipment_id\":27}', '2026-01-29 22:02:31'),
(328, 13, 'incident_reported', 'incidents', 29, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"medium\",\"category\":\"damage\",\"equipment_id\":27}', '2026-01-29 22:02:36'),
(329, 13, 'incident_reported', 'incidents', 30, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"low\",\"category\":\"security\",\"equipment_id\":29}', '2026-01-29 22:02:43'),
(330, 13, 'incident_reported', 'incidents', 31, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"low\",\"category\":\"hazard\",\"equipment_id\":null}', '2026-01-29 22:02:52'),
(331, 13, 'incident_reported', 'incidents', 32, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"severity\":\"low\",\"category\":\"other\",\"equipment_id\":null}', '2026-01-29 22:03:10'),
(332, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 22:04:33'),
(333, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 22:04:37'),
(334, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 23:22:08'),
(335, 9, 'login', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567B\",\"role\":\"Manager\"}', '2026-01-29 23:22:12'),
(336, 9, 'logout', 'authentication', 9, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567B\"}', '2026-01-29 23:22:23'),
(337, 8, 'login', 'authentication', 8, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567A\",\"role\":\"Admin\"}', '2026-01-29 23:22:28'),
(338, 8, 'logout', 'authentication', 8, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567A\"}', '2026-01-29 23:22:33'),
(339, 10, 'login', 'authentication', 10, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"1234567C\",\"role\":\"Technician\"}', '2026-01-29 23:22:42'),
(340, 10, 'logout', 'authentication', 10, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"1234567C\"}', '2026-01-29 23:22:49'),
(341, 13, 'login', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"login\",\"admin_number\":\"2406789E\",\"role\":\"Student\"}', '2026-01-29 23:22:53'),
(342, 13, 'logout', 'authentication', 13, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '{\"event\":\"logout\",\"admin_number\":\"2406789E\"}', '2026-01-29 23:23:10');

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
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`booking_id`, `equipment_id`, `requester_id`, `start_time`, `end_time`, `purpose`, `status`, `requires_approval`, `approved_by`, `approved_at`, `rejection_reason`, `cancelled_at`, `cancelled_by`, `flag_reason`, `created_at`, `updated_at`) VALUES
(21, 21, 13, '2026-01-01 08:35:00', '2026-01-01 10:05:00', 'meow', 'rejected', 1, 13, '2026-01-21 20:36:06', '', NULL, NULL, NULL, '2026-01-21 20:36:01', '2026-01-21 20:36:06'),
(22, 21, 13, '2026-01-01 08:35:00', '2026-01-01 10:05:00', 'meow', 'flagged', 1, NULL, NULL, NULL, '2026-01-21 20:36:15', 13, NULL, '2026-01-21 20:36:09', '2026-01-29 16:12:03'),
(23, 21, 11, '2026-01-30 06:59:00', '2026-01-30 08:29:00', 'for project work', 'approved', 1, 9, '2026-01-27 19:01:46', NULL, NULL, NULL, NULL, '2026-01-27 19:00:07', '2026-01-27 19:01:46'),
(24, 24, 11, '2026-01-29 12:03:00', '2026-01-29 13:03:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-27 19:01:44', NULL, NULL, NULL, NULL, '2026-01-27 19:00:23', '2026-01-27 19:01:44'),
(25, 30, 13, '2026-01-28 07:06:00', '2026-01-28 07:36:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-27 19:01:43', NULL, NULL, NULL, NULL, '2026-01-27 19:01:25', '2026-01-27 19:01:43'),
(26, 21, 13, '2026-02-12 16:20:00', '2026-02-12 17:20:00', 'Booking submitted via portal.', 'flagged', 1, 9, '2026-01-29 16:22:07', 'no!', NULL, NULL, NULL, '2026-01-29 16:21:30', '2026-01-29 16:30:40'),
(27, 25, 13, '2026-01-31 16:24:00', '2026-01-31 17:24:00', 'plsss', 'flagged', 1, 9, '2026-01-29 16:25:47', 'you do not have the required safety certification', NULL, NULL, NULL, '2026-01-29 16:25:03', '2026-01-29 16:31:40'),
(28, 21, 13, '2026-01-14 08:54:00', '2026-01-14 09:54:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-29 20:56:29', NULL, NULL, NULL, NULL, '2026-01-29 20:54:27', '2026-01-29 20:56:29'),
(29, 22, 13, '2026-01-14 20:54:00', '2026-01-14 21:54:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-29 20:56:30', NULL, NULL, NULL, NULL, '2026-01-29 20:54:40', '2026-01-29 20:56:30'),
(30, 21, 13, '2026-01-15 20:56:00', '2026-01-15 22:56:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-29 20:56:30', NULL, NULL, NULL, NULL, '2026-01-29 20:55:00', '2026-01-29 20:56:30'),
(31, 25, 13, '2026-01-27 11:57:00', '2026-01-27 12:57:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-29 20:56:33', NULL, NULL, NULL, NULL, '2026-01-29 20:55:12', '2026-01-29 20:56:33'),
(32, 26, 13, '2026-01-28 08:00:00', '2026-01-28 11:00:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-29 20:56:33', NULL, NULL, NULL, NULL, '2026-01-29 20:55:26', '2026-01-29 20:56:33'),
(33, 28, 13, '2026-01-24 08:56:00', '2026-01-24 09:26:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-29 20:56:32', NULL, NULL, NULL, NULL, '2026-01-29 20:55:40', '2026-01-29 20:56:32'),
(34, 28, 13, '2026-01-24 15:55:00', '2026-01-24 16:55:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-29 20:56:32', NULL, NULL, NULL, NULL, '2026-01-29 20:55:55', '2026-01-29 20:56:32'),
(35, 29, 13, '2026-01-20 11:56:00', '2026-01-20 13:56:00', 'Booking submitted via portal.', 'approved', 1, 9, '2026-01-29 20:56:31', NULL, NULL, NULL, NULL, '2026-01-29 20:56:10', '2026-01-29 20:56:31');

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

--
-- Dumping data for table `certifications`
--

INSERT INTO `certifications` (`cert_id`, `name`, `description`, `valid_days`, `created_at`) VALUES
(1, 'AMC Safety Induction (Medium/High Risk)', 'Mandatory lab safety induction for operating medium and high risk equipment in TP AMC.', 365, '2026-01-27 19:16:54'),
(2, 'Machine Risk Assessment & SOP Briefing', 'Completion of machine-specific risk assessment, SOP walkthrough, and hazard controls acknowledgement.', 365, '2026-01-27 19:16:54'),
(3, 'Lockout/Tagout (LOTO) Awareness', 'Training on isolation of energy sources before setup, cleaning, maintenance, or jam clearing.', 730, '2026-01-27 19:16:54'),
(4, 'CNC Machining Operator Certification', 'Authorization to operate CNC mills/routers/turning centers including workholding, tooling, offsets, and emergency response.', 365, '2026-01-27 19:16:54'),
(5, 'CNC Lathe Safety Certification', 'Safe operation of CNC lathe: chuck safety, swarf control, tool changes, and safe measurement procedures.', 365, '2026-01-27 19:16:54'),
(6, 'Laser Cutter Operator Certification', 'Safe use of laser cutting system: optics safety, fume extraction, fire prevention, and material restrictions.', 365, '2026-01-27 19:16:54'),
(7, 'Hot Work Permit Training', 'Training for processes involving heat/sparks (welding, grinding, cutting); permit and fire watch requirements.', 365, '2026-01-27 19:16:54'),
(8, 'Welding Safety & PPE Certification', 'Arc/spot/MIG/TIG welding safety: PPE, UV exposure, ventilation, and safe setup.', 365, '2026-01-27 19:16:54'),
(9, 'Compressed Gas Cylinder Handling', 'Safe storage, transport, regulator use, leak checks, and emergency response for gas cylinders.', 730, '2026-01-27 19:16:54'),
(10, 'Chemical Handling & SDS Awareness', 'Understanding of SDS, labeling, spill response, and safe handling of solvents/coolants/resins.', 730, '2026-01-27 19:16:54'),
(11, 'Respiratory Protection (Mask Fit & Use)', 'Proper selection and use of respirators for fumes/dust; includes fit and user checks.', 365, '2026-01-27 19:16:54'),
(12, 'Electrical Safety (Basic / Workshop)', 'Electrical hazard awareness, safe isolation practices, and reporting of unsafe conditions.', 730, '2026-01-27 19:16:54'),
(13, 'Manual Handling & Ergonomics', 'Safe lifting, handling of heavy workpieces/materials, and ergonomics in workshop tasks.', 730, '2026-01-27 19:16:54'),
(14, 'Fire Safety & Extinguisher Familiarization', 'Fire triangle, extinguisher types, and response for common workshop fire scenarios.', 730, '2026-01-27 19:16:54'),
(15, 'First Aid Awareness (Workshop)', 'Basic first aid response for cuts, burns, eye exposure; how to escalate and report incidents.', 730, '2026-01-27 19:16:54'),
(16, 'High-Speed Rotating Equipment Safety', 'Safety for rotating machinery: entanglement hazards, guarding, correct attire, and safe operation.', 365, '2026-01-27 19:16:54'),
(17, 'Press/Shear/Brake Safety Certification', 'Authorization for press brake/shear machines; pinch-point awareness, guards, and safe loading/unloading.', 365, '2026-01-27 19:16:54'),
(18, 'Forklift/Pallet Jack Awareness (Facility Only)', 'Basic safe movement in shared workshop areas; right-of-way and load stability awareness.', 365, '2026-01-27 19:16:54');

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
(21, 'TP-AMC-CNC-001', '3-Axis CNC Milling Machine', 'CNC Machining', 'Lobby', 'HAAS', 'VF-2', 'high', 'maintenance', '2026-01-27 13:05:14', 10, 'Used for precision machining and student training', '2026-01-19 14:08:38', '2026-01-27 13:05:14'),
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

--
-- Dumping data for table `equipment_required_certs`
--

INSERT INTO `equipment_required_certs` (`equipment_id`, `cert_id`) VALUES
(21, 1),
(21, 2),
(21, 3),
(21, 4),
(21, 14),
(21, 16),
(22, 1),
(22, 2),
(22, 3),
(22, 5),
(22, 14),
(22, 16),
(23, 1),
(23, 2),
(23, 3),
(23, 12),
(23, 14),
(24, 1),
(24, 2),
(24, 12),
(25, 1),
(25, 2),
(25, 10),
(25, 11),
(25, 12),
(25, 14),
(27, 1),
(27, 2),
(27, 13),
(29, 1),
(29, 2),
(29, 12),
(30, 1),
(30, 2),
(30, 12),
(30, 14);

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

--
-- Dumping data for table `equipment_training_materials`
--

INSERT INTO `equipment_training_materials` (`equipment_id`, `material_id`, `linked_at`, `linked_by`) VALUES
(21, 1, '2026-01-27 19:29:22', 8),
(21, 4, '2026-01-27 22:41:00', 8),
(22, 2, '2026-01-27 19:29:22', 8),
(23, 3, '2026-01-27 19:29:22', 8),
(23, 5, '2026-01-27 22:41:00', 8),
(25, 6, '2026-01-27 22:41:00', 8);

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

--
-- Dumping data for table `incidents`
--

INSERT INTO `incidents` (`incident_id`, `reported_by`, `equipment_id`, `severity`, `category`, `location`, `description`, `status`, `created_at`, `updated_at`) VALUES
(22, 13, 21, 'low', 'other', '3-Axis CNC Milling Machine', 'a', 'submitted', '2026-01-29 21:59:14', '2026-01-29 21:59:14'),
(23, 13, 21, 'high', 'near_miss', '3-Axis CNC Milling Machine', 's', 'submitted', '2026-01-29 21:59:27', '2026-01-29 21:59:27'),
(24, 13, 22, 'critical', 'injury', 'CNC Turning Lathe', 'a', 'submitted', '2026-01-29 21:59:42', '2026-01-29 21:59:42'),
(25, 13, 22, 'critical', 'injury', 'CNC Turning Lathe', 'a', 'submitted', '2026-01-29 21:59:48', '2026-01-29 21:59:48'),
(26, 13, 22, 'critical', 'injury', 'CNC Turning Lathe', 'a', 'submitted', '2026-01-29 21:59:55', '2026-01-29 21:59:55'),
(27, 13, 29, 'medium', 'damage', 'Machine Vision Inspection System', 'a', 'submitted', '2026-01-29 22:02:23', '2026-01-29 22:02:23'),
(28, 13, 27, 'medium', 'damage', 'Coordinate Measuring Machine (CMM)', 'a', 'submitted', '2026-01-29 22:02:31', '2026-01-29 22:02:31'),
(29, 13, 27, 'medium', 'damage', 'Coordinate Measuring Machine (CMM)', 'a', 'submitted', '2026-01-29 22:02:36', '2026-01-29 22:02:36'),
(30, 13, 29, 'low', 'security', 'Machine Vision Inspection System', 'a', 'submitted', '2026-01-29 22:02:43', '2026-01-29 22:02:43'),
(31, 13, NULL, 'low', 'hazard', 'counter', 'aa', 'submitted', '2026-01-29 22:02:52', '2026-01-29 22:02:52'),
(32, 13, NULL, 'low', 'other', 'door', 'a', 'submitted', '2026-01-29 22:03:10', '2026-01-29 22:03:10');

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

--
-- Dumping data for table `maintenance_records`
--

INSERT INTO `maintenance_records` (`record_id`, `equipment_id`, `task_id`, `downtime_start`, `downtime_end`, `notes`, `logged_by`, `created_at`) VALUES
(8, 21, 1, '2026-01-28 14:22:57', '2026-01-27 18:55:52', 'Equipment is currently in maintenance. Diagnose root cause, perform service or repairs, then verify with a test run before returning to operational.', 10, '2026-01-27 18:55:52'),
(9, 23, 3, '2026-02-17 14:22:57', '2026-01-27 18:56:08', 'Inspect joints, cabling and emergency stop, verify safety zones, check end-effector mounting and run diagnostics.', 10, '2026-01-27 18:56:08'),
(10, 28, 8, '2026-02-26 14:22:57', '2026-01-27 18:56:13', 'Inspect wiring and IO modules, test sample PLC program, verify communications and power stability.', 10, '2026-01-27 18:56:13'),
(11, 21, 1, '2026-01-28 14:22:57', '2026-01-27 18:57:26', 'Equipment is currently in maintenance. Diagnose root cause, perform service or repairs, then verify with a test run before returning to operational.', 10, '2026-01-27 18:57:26');

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

--
-- Dumping data for table `maintenance_tasks`
--

INSERT INTO `maintenance_tasks` (`task_id`, `equipment_id`, `title`, `description`, `task_type`, `priority`, `status`, `scheduled_for`, `assigned_to`, `created_by`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 21, 'Repair/Service: 3-Axis CNC Milling Machine', 'Equipment is currently in maintenance. Diagnose root cause, perform service or repairs, then verify with a test run before returning to operational.', 'corrective', 'high', 'done', '2026-01-28 14:22:57', 10, 10, '2026-01-27 18:57:26', '2026-01-27 14:22:57', '2026-01-27 18:57:26'),
(2, 22, 'Preventive Check: CNC Turning Lathe', 'Routine inspection: clean machine, check lubrication, tool alignment, belts and hoses, then run a calibration cut.', 'preventive', 'medium', 'in_progress', '2026-02-10 14:22:57', 10, 10, '2026-01-27 18:28:14', '2026-01-27 14:22:57', '2026-01-27 19:12:23'),
(3, 23, 'Preventive Check: Industrial Robotic Arm', 'Inspect joints, cabling and emergency stop, verify safety zones, check end-effector mounting and run diagnostics.', 'preventive', 'medium', 'done', '2026-02-17 14:22:57', 10, 10, '2026-01-27 18:56:08', '2026-01-27 14:22:57', '2026-01-27 18:56:08'),
(4, 24, 'Preventive Check: Collaborative Robot (Cobot)', 'Verify torque sensors, safety limits, firmware version and payload settings. Perform functional test.', 'preventive', 'medium', 'in_progress', '2026-02-17 14:22:57', 10, 10, NULL, '2026-01-27 14:22:57', '2026-01-27 19:12:27'),
(5, 25, 'Service: Metal 3D Printer', 'Equipment is currently in maintenance. Inspect build plate, filters, material handling and calibration before returning to service.', 'corrective', 'high', 'open', '2026-01-29 14:22:57', 10, 10, '2026-01-27 18:27:30', '2026-01-27 14:22:57', '2026-01-27 18:54:43'),
(6, 26, 'Preventive Check: FDM 3D Printer', 'Clean nozzle and extruder, check bed leveling, inspect belts and rails, and print a calibration model.', 'preventive', 'low', 'open', '2026-02-26 14:22:57', 10, 10, '2026-01-27 18:28:18', '2026-01-27 14:22:57', '2026-01-27 18:54:50'),
(7, 27, 'Preventive Calibration: CMM', 'Inspect probe, clean granite surface, verify environmental conditions, and run full calibration routine.', 'preventive', 'high', 'cancelled', '2026-02-03 14:22:57', 10, 10, NULL, '2026-01-27 14:22:57', '2026-01-27 18:56:05'),
(8, 28, 'Preventive Check: PLC Training System', 'Inspect wiring and IO modules, test sample PLC program, verify communications and power stability.', 'preventive', 'low', 'done', '2026-02-26 14:22:57', 10, 10, '2026-01-27 18:56:13', '2026-01-27 14:22:57', '2026-01-27 18:56:13'),
(9, 29, 'Preventive Check: Machine Vision Inspection System', 'Clean camera lens and lighting, verify focus and exposure, test defect detection and software health.', 'preventive', 'medium', 'open', '2026-02-17 14:22:57', 10, 10, NULL, '2026-01-27 14:22:57', '2026-01-27 14:42:08'),
(10, 30, 'Fix Fault: Automated Guided Vehicle (AGV)', 'AGV is currently faulty. Inspect battery, sensors, navigation system, motors and safety systems, then perform supervised test run.', 'corrective', 'high', 'open', '2026-01-28 14:22:57', 10, 10, NULL, '2026-01-27 14:22:57', '2026-01-27 18:54:56');

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
(4, 'Student', 'Regular user (student)'),
(5, 'Staff', 'Regular user (Staff)');

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

--
-- Dumping data for table `training_materials`
--

INSERT INTO `training_materials` (`material_id`, `title`, `material_type`, `file_url`, `file_path`, `version`, `uploaded_by`, `created_at`, `updated_at`) VALUES
(1, '3-Axis CNC Milling Machine – Training Material', 'pdf', NULL, 'C:/xampp/htdocs/swap_project/uploads/3-Axis_CNC_Milling_Machine.pdf', 'v1.0', 8, '2026-01-27 19:27:55', '2026-01-27 23:19:37'),
(2, 'CNC Turning Lathe – Training Material', 'pdf', NULL, 'C:/xampp/htdocs/swap_project/uploads/CNC_Turning_Lathe.pdf', 'v1.0', 8, '2026-01-27 19:27:55', '2026-01-27 23:20:02'),
(3, 'Industrial Robotic Arm – Training Material', 'pdf', NULL, 'C:/xampp/htdocs/swap_project/uploads/Industrial_Robotic_Arm.pdf', 'v1.0', 8, '2026-01-27 19:27:55', '2026-01-27 23:20:14'),
(4, '3-Axis CNC Milling Machine – Video Tutorial', 'video', 'https://www.youtube.com/watch?v=CqePrbeAQoM&t=145s', NULL, 'v1.0', 8, '2026-01-27 22:38:12', '2026-01-27 22:42:13'),
(5, 'Industrial Robotic Arm – Video Tutorial', 'video', 'https://www.youtube.com/watch?v=SisrRUX_Zfk', NULL, 'v1.0', 8, '2026-01-27 22:38:12', '2026-01-27 22:42:13'),
(6, 'Metal 3D Printing – Video Tutorial', 'video', 'https://www.youtube.com/watch?v=19XZ4jwrXe0', NULL, 'v1.0', 8, '2026-01-27 22:38:12', '2026-01-27 22:42:13');

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
(8, 1, '1234567A', 'sysadmin', 'john_lee@tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-20 19:46:53'),
(9, 2, '1234567B', 'amc_manager', 'jane_lee@tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'AMC Manager', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-20 19:46:53'),
(10, 3, '1234567C', 'amc_technician', 'lily_ng@tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'AMC Technician', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-20 19:46:53'),
(11, 4, '2503456C', 'jason_lim', '2503456C@student.tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jason Lim Wei Jie', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-20 19:46:53'),
(12, 4, '2309876D', 'amelia_tan', '2309876D@student.tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Amelia Tan Xin Yi', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-20 19:46:53'),
(13, 4, '2406789E', 'ryan_goh', '2406789E@student.tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ryan Goh Jun Hao', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-20 19:46:53'),
(14, 4, '2501122F', 'nur_aisyah', '2501122F@student.tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nur Aisyah Binte Ahmad', 'active', 0, NULL, NULL, '2026-01-19 14:00:40', '2026-01-20 19:46:53'),
(15, 5, '1234567D', 'daniel_tan_staff', 'daniel_tan@tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Daniel Tan', 'active', 0, NULL, NULL, '2026-01-29 17:28:08', '2026-01-29 17:28:08'),
(16, 5, '1234567E', 'cheryl_lim_staff', 'cheryl_lim@tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Cheryl Lim', 'active', 0, NULL, NULL, '2026-01-29 17:28:08', '2026-01-29 17:29:15'),
(17, 5, '1234567F', 'muhammad_irfan_staff', 'muhammad_irfan@tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Muhammad Irfan', 'active', 0, NULL, NULL, '2026-01-29 17:28:08', '2026-01-29 17:29:29'),
(18, 5, '1234567G', 'nur_aisyah_staff', 'siti_nur_aisyah@tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Siti Nur Aisyah', 'active', 0, NULL, NULL, '2026-01-29 17:28:08', '2026-01-29 17:29:44'),
(19, 5, '1234567H', 'benjamin_ong_staff', 'benjamin_ong@tp.edu.sg', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Benjamin Ong', 'active', 0, NULL, NULL, '2026-01-29 17:28:08', '2026-01-29 17:29:53');

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
-- Dumping data for table `user_certifications`
--

INSERT INTO `user_certifications` (`user_cert_id`, `user_id`, `cert_id`, `status`, `completed_at`, `expires_at`, `verified_by`, `verified_at`, `notes`) VALUES
(5, 13, 5, 'completed', NULL, NULL, NULL, NULL, NULL),
(6, 13, 15, 'completed', NULL, NULL, NULL, NULL, NULL),
(7, 13, 16, 'completed', NULL, NULL, NULL, NULL, NULL),
(8, 13, 17, 'completed', NULL, NULL, NULL, NULL, NULL),
(9, 13, 10, 'completed', NULL, NULL, NULL, NULL, NULL),
(10, 13, 1, 'completed', NULL, NULL, NULL, NULL, NULL),
(11, 13, 14, 'completed', NULL, NULL, NULL, NULL, NULL),
(12, 13, 2, 'completed', NULL, NULL, NULL, NULL, NULL),
(13, 13, 4, 'completed', NULL, NULL, NULL, NULL, NULL),
(14, 13, 9, 'completed', NULL, NULL, NULL, NULL, NULL),
(15, 13, 12, 'completed', NULL, NULL, NULL, NULL, NULL),
(16, 13, 6, 'completed', NULL, NULL, NULL, NULL, NULL),
(17, 13, 7, 'completed', NULL, NULL, NULL, NULL, NULL),
(18, 13, 18, 'completed', NULL, NULL, NULL, NULL, NULL),
(19, 13, 3, 'completed', NULL, NULL, NULL, NULL, NULL),
(20, 13, 13, 'completed', NULL, NULL, NULL, NULL, NULL),
(21, 13, 11, 'completed', NULL, NULL, NULL, NULL, NULL),
(22, 13, 8, 'completed', NULL, NULL, NULL, NULL, NULL);

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
  MODIFY `audit_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=343;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `booking_waitlist`
--
ALTER TABLE `booking_waitlist`
  MODIFY `waitlist_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `certifications`
--
ALTER TABLE `certifications`
  MODIFY `cert_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

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
  MODIFY `incident_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `incident_investigations`
--
ALTER TABLE `incident_investigations`
  MODIFY `investigation_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  MODIFY `record_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `maintenance_tasks`
--
ALTER TABLE `maintenance_tasks`
  MODIFY `task_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `training_materials`
--
ALTER TABLE `training_materials`
  MODIFY `material_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `user_certifications`
--
ALTER TABLE `user_certifications`
  MODIFY `user_cert_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

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
