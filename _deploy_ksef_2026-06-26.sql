-- =====================================================================
-- KSeF — schemat bazy (odpowiednik 5 migracji 2026_06_26_*).
-- Import w phpMyAdmin na bazie produkcyjnej (admin_pim). URUCHOM RAZ.
-- Idempotentne: CREATE TABLE IF NOT EXISTS + INSERT IGNORE.
-- Po imporcie NIE trzeba odpalać `php artisan migrate` (migracje są poniżej rejestrowane).
-- =====================================================================

SET NAMES utf8mb4;

-- 1) Poświadczenia integracji KSeF per firma (token wpisujesz w panelu) ---------
CREATE TABLE IF NOT EXISTS `ksef_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company` VARCHAR(255) NOT NULL,
  `label` VARCHAR(255) NOT NULL DEFAULT '',
  `nip` VARCHAR(255) NULL DEFAULT NULL,
  `environment` VARCHAR(16) NOT NULL DEFAULT 'test',
  `auth_token` TEXT NULL DEFAULT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `last_sync_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ksef_settings_company_unique` (`company`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Faktury KSeF (wypełniane przez „Zaciągnij wszystko") -----------------------
CREATE TABLE IF NOT EXISTS `ksef_invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company` VARCHAR(255) NOT NULL,
  `issue_date` DATE NOT NULL,
  `number` VARCHAR(255) NOT NULL,
  `contractor` VARCHAR(255) NULL DEFAULT NULL,
  `items_text` TEXT NULL DEFAULT NULL,
  `category` VARCHAR(255) NULL DEFAULT NULL,
  `due_date` DATE NULL DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(8) NOT NULL DEFAULT 'PLN',
  `status` VARCHAR(16) NOT NULL DEFAULT 'unpaid',
  `ksef_ref` VARCHAR(255) NULL DEFAULT NULL,
  `pdf_path` VARCHAR(255) NULL DEFAULT NULL,
  `xml` LONGTEXT NULL DEFAULT NULL,
  `source` VARCHAR(16) NOT NULL DEFAULT 'demo',
  `imported_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ksef_invoices_company_index` (`company`),
  UNIQUE KEY `ksef_invoices_company_ksef_ref_unique` (`company`,`ksef_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Kategorie FV per firma (edytowalne w zakładce Ustawienia) ------------------
CREATE TABLE IF NOT EXISTS `ksef_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `position` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ksef_categories_company_index` (`company`),
  UNIQUE KEY `ksef_categories_company_name_unique` (`company`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- domyślne kategorie (jak seed w migracji 140000)
INSERT IGNORE INTO `ksef_categories` (`company`,`name`,`position`,`created_at`,`updated_at`) VALUES
('pareto','Sprzedaż',0,NOW(),NOW()),
('pareto','Usługi',1,NOW(),NOW()),
('pareto','Towary',2,NOW(),NOW()),
('pareto','Transport',3,NOW(),NOW()),
('pareto','Inne',4,NOW(),NOW()),
('bsp','Sprzedaż',0,NOW(),NOW()),
('bsp','Usługi',1,NOW(),NOW()),
('bsp','Towary',2,NOW(),NOW()),
('bsp','Transport',3,NOW(),NOW()),
('bsp','Inne',4,NOW(),NOW());

-- 4) Rejestracja migracji, żeby `php artisan migrate` ich nie powtórzył ---------
DELETE FROM `migrations` WHERE `migration` IN (
  '2026_06_26_120000_create_ksef_settings_table',
  '2026_06_26_130000_create_ksef_invoices_table',
  '2026_06_26_140000_create_ksef_categories_table',
  '2026_06_26_150000_ksef_invoices_dedup_by_ksef_ref',
  '2026_06_26_160000_add_xml_to_ksef_invoices'
);
SET @b = (SELECT IFNULL(MAX(`batch`),0)+1 FROM `migrations`);
INSERT INTO `migrations` (`migration`,`batch`) VALUES
('2026_06_26_120000_create_ksef_settings_table', @b),
('2026_06_26_130000_create_ksef_invoices_table', @b),
('2026_06_26_140000_create_ksef_categories_table', @b),
('2026_06_26_150000_ksef_invoices_dedup_by_ksef_ref', @b),
('2026_06_26_160000_add_xml_to_ksef_invoices', @b);
