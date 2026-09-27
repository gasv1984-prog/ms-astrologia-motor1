CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(32) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(180) NOT NULL,
  phone VARCHAR(24) NOT NULL,
  birth_date DATE NOT NULL,
  birth_time TIME NOT NULL,
  birthplace VARCHAR(180) NOT NULL,
  latitude DECIMAL(10,6) NOT NULL,
  longitude DECIMAL(10,6) NOT NULL,
  timezone VARCHAR(80) NOT NULL,
  country_code CHAR(2) NULL,
  admin1_code VARCHAR(20) NULL,
  city_id BIGINT NULL,
  notes TEXT NULL,
  payment_method ENUM('nequi','daviplata','llave') NOT NULL,
  payment_status ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
  status ENUM('pending','calculated','completed','cancelled') NOT NULL DEFAULT 'pending',
  request_password_hash VARCHAR(255) NOT NULL,
  chart_svg LONGTEXT NULL,
  chart_data LONGTEXT NULL,
  chart_engine VARCHAR(30) NULL,
  chart_generated_at DATETIME NULL,
  chart_context LONGTEXT NULL,
  ai_interpretation LONGTEXT NULL,
  ai_provider VARCHAR(20) NULL,
  ai_model VARCHAR(120) NULL,
  notified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_requests_status (status),
  INDEX idx_requests_payment (payment_status),
  INDEX idx_requests_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS astrology_configs (
  id TINYINT UNSIGNED PRIMARY KEY,
  mode ENUM('github_pages','self_hosted','rapidapi') NOT NULL DEFAULT 'github_pages',
  base_url VARCHAR(255) NOT NULL,
  encrypted_api_key TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_configs (
  provider ENUM('openai','gemini') PRIMARY KEY,
  encrypted_api_key TEXT NOT NULL,
  model VARCHAR(120) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aurita_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aurita_request (request_id, id),
  CONSTRAINT fk_aurita_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS horoscopes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sign VARCHAR(20) NOT NULL,
  period_type ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
  period_label VARCHAR(120) NOT NULL,
  title VARCHAR(180) NOT NULL,
  content LONGTEXT NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  ai_provider VARCHAR(20) NULL,
  ai_model VARCHAR(120) NULL,
  published_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_horoscopes_public (status, sign, published_at),
  INDEX idx_horoscopes_period (period_type, period_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS countries (
  code CHAR(2) PRIMARY KEY,
  name VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin1 (
  country_code CHAR(2) NOT NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(160) NOT NULL,
  geoname_id BIGINT NULL,
  PRIMARY KEY (country_code, code),
  INDEX idx_admin1_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS places (
  id BIGINT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  ascii_name VARCHAR(180) NOT NULL,
  latitude DECIMAL(10,6) NOT NULL,
  longitude DECIMAL(10,6) NOT NULL,
  country_code CHAR(2) NOT NULL,
  admin1_code VARCHAR(20) NOT NULL,
  population BIGINT NOT NULL DEFAULT 0,
  timezone VARCHAR(80) NOT NULL,
  INDEX idx_places_parent (country_code, admin1_code, population, name),
  INDEX idx_places_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
