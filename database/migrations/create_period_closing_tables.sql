-- Accounting Period Close Database Migration
CREATE TABLE IF NOT EXISTS `accounting_periods` (
  `id` varchar(64) NOT NULL,
  `fiscal_year_id` varchar(64) NOT NULL,
  `period_name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('open','soft_closed','closed','locked','archived') NOT NULL DEFAULT 'open',
  `closed_by` varchar(64) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `locked_by` varchar(64) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `reopened_by` varchar(64) DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fy_id` (`fiscal_year_id`),
  KEY `idx_dates` (`start_date`,`end_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `period_audit_logs` (
  `id` varchar(64) NOT NULL,
  `period_id` varchar(64) NOT NULL,
  `user_id` varchar(64) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `before_status` varchar(50) DEFAULT NULL,
  `after_status` varchar(50) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `period_report_snapshots` (
  `id` varchar(64) NOT NULL,
  `period_id` varchar(64) NOT NULL,
  `report_type` varchar(100) NOT NULL,
  `report_name` varchar(255) NOT NULL,
  `snapshot_data` longtext NOT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period_report` (`period_id`,`report_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
