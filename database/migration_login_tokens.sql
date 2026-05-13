-- Migration: Add login_tokens table for magic login links
-- Run this once against your auto_repair_saas database.

CREATE TABLE IF NOT EXISTS `login_tokens` (
  `token_id`   int(11)      NOT NULL AUTO_INCREMENT,
  `tenant_id`  int(11)      NOT NULL,
  `user_id`    int(11)      NOT NULL,
  `token`      varchar(128) NOT NULL,
  `purpose`    enum('verified_login') NOT NULL DEFAULT 'verified_login',
  `expires_at` datetime     NOT NULL,
  `used_at`    datetime     DEFAULT NULL,
  `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `user_id`   (`user_id`),
  KEY `tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
