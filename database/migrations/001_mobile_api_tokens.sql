-- Mobile API bearer tokens (opaque). Run once on local MySQL and on AlwaysData after schema import.
-- mysql -u ... -p auto_repair_saas < database/migrations/001_mobile_api_tokens.sql

CREATE TABLE IF NOT EXISTS `mobile_api_tokens` (
  `token_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `access_token_hash` char(64) NOT NULL,
  `refresh_token_hash` char(64) NOT NULL,
  `access_expires_at` datetime NOT NULL,
  `refresh_expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_access_token_hash` (`access_token_hash`),
  UNIQUE KEY `uq_refresh_token_hash` (`refresh_token_hash`),
  KEY `idx_user_tenant` (`user_id`,`tenant_id`),
  CONSTRAINT `mobile_api_tokens_ibfk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `mobile_api_tokens_ibfk_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
