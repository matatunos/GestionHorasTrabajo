<?php
require_once __DIR__ . '/db.php';
$pdo = get_pdo();
$alter1 = "ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS expected_daily_hours_winter DOUBLE DEFAULT NULL";
$alter2 = "ALTER TABLE year_configs ADD COLUMN IF NOT EXISTS expected_daily_hours_summer DOUBLE DEFAULT NULL";
try { $pdo->exec($alter1); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN expected_daily_hours_winter DOUBLE DEFAULT NULL"); } catch (Throwable $e2) {} }
try { $pdo->exec($alter2); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE year_configs ADD COLUMN expected_daily_hours_summer DOUBLE DEFAULT NULL"); } catch (Throwable $e2) {} }
echo "Columnas expected_daily_hours_winter y expected_daily_hours_summer añadidas (si no existían).\n";
