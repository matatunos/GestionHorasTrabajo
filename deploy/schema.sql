-- Schema for GestionHorasTrabajo
CREATE DATABASE IF NOT EXISTS gestion_horas CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE gestion_horas;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  date DATE NOT NULL,
  start TIME NULL,
  coffee_out TIME NULL,
  coffee_in TIME NULL,
  lunch_out TIME NULL,
  lunch_in TIME NULL,
  end TIME NULL,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY user_date (user_id,date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS app_config (
  k VARCHAR(100) PRIMARY KEY,
  v TEXT
);
