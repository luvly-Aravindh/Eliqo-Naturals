-- Pending-checkout tracker for Eliqo (MySQL 5.7+/8).
-- One row per customer email; the Shopify webhook flips it to 'completed',
-- the cron flips it to 'notified' after emailing.

CREATE TABLE IF NOT EXISTS checkout_pending (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(255) NOT NULL,
  phone        VARCHAR(20)  NOT NULL DEFAULT '',
  name         VARCHAR(255) NOT NULL DEFAULT '',
  address1     VARCHAR(500) NOT NULL DEFAULT '',
  address2     VARCHAR(500) NOT NULL DEFAULT '',
  city         VARCHAR(128) NOT NULL DEFAULT '',
  state        VARCHAR(128) NOT NULL DEFAULT '',
  pin          VARCHAR(16)  NOT NULL DEFAULT '',
  pack         VARCHAR(32)  NOT NULL DEFAULT '',
  jars         VARCHAR(8)   NOT NULL DEFAULT '',
  khapali      VARCHAR(4)   NOT NULL DEFAULT 'No',
  groundnut    VARCHAR(4)   NOT NULL DEFAULT 'No',
  jaggery      VARCHAR(4)   NOT NULL DEFAULT 'No',
  total        VARCHAR(32)  NOT NULL DEFAULT '',
  order_ref    VARCHAR(64)  NOT NULL DEFAULT '',
  page         VARCHAR(1000) NOT NULL DEFAULT '',
  source       VARCHAR(64)  NOT NULL DEFAULT '',
  status       ENUM('pending','notified','completed') NOT NULL DEFAULT 'pending',
  notified     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  notified_at  DATETIME     NULL,
  completed_at DATETIME     NULL,
  UNIQUE KEY uniq_email (email),
  KEY idx_phone (phone),
  KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;