<?php
// Backup and shrink app_settings.site_config to only keep site_name
require_once __DIR__ . '/../db.php';
try {
    $pdo = get_pdo();
} catch (Throwable $e) {
    echo "Cannot get DB connection: " . $e->getMessage() . "\n";
    exit(1);
}
$pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
    name VARCHAR(191) PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$stmt = $pdo->prepare('SELECT value FROM app_settings WHERE name = ?');
$stmt->execute(['site_config']);
$row = $stmt->fetchColumn();
if (!$row) { echo "No site_config row found\n"; exit(0); }
$data = json_decode($row, true);
if ($data === null) { echo "site_config JSON parse error\n"; exit(1); }
$bakFile = __DIR__ . '/site_config_backup_' . time() . '.json';
file_put_contents($bakFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$new = ['site_name' => $data['site_name'] ?? ($data['siteName'] ?? 'GestionHoras')];
$stmt = $pdo->prepare('REPLACE INTO app_settings (name,value) VALUES (?,?)');
$stmt->execute(['site_config', json_encode($new, JSON_UNESCAPED_UNICODE)]);
echo "site_config cleaned, backup written to: $bakFile\n";
exit(0);
