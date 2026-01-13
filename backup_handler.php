<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

if ($action === 'export') {
    try {
        $pdo = get_pdo();
        $filename = 'gestionhoras_backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // Get MySQL host, user, password, database from config
        $db_host = $_ENV['DB_HOST'] ?? 'localhost';
        $db_user = $_ENV['DB_USER'] ?? 'root';
        $db_pass = $_ENV['DB_PASS'] ?? '';
        $db_name = $_ENV['DB_NAME'] ?? 'gestionhoras';
        
        // Use mysqldump command
        $command = sprintf(
            "mysqldump -h %s -u %s %s %s",
            escapeshellarg($db_host),
            escapeshellarg($db_user),
            ($db_pass ? '-p' . escapeshellarg($db_pass) : ''),
            escapeshellarg($db_name)
        );
        
        $output = [];
        $return_var = 0;
        exec($command . ' 2>&1', $output, $return_var);
        
        if ($return_var !== 0) {
            throw new Exception('Error executing mysqldump: ' . implode("\n", $output));
        }
        
        $sql_content = implode("\n", $output);
        
        // Send as download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql_content));
        echo $sql_content;
        exit;
    } catch (Exception $e) {
        error_log('Backup error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al crear backup: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_FILES['backup_file'])) {
            throw new Exception('No file uploaded');
        }
        
        $file = $_FILES['backup_file'];
        $tmp_path = $file['tmp_name'];
        
        if (!is_uploaded_file($tmp_path)) {
            throw new Exception('Invalid file upload');
        }
        
        $sql_content = file_get_contents($tmp_path);
        if (!$sql_content) {
            throw new Exception('Cannot read uploaded file');
        }
        
        $pdo = get_pdo();
        
        // Get MySQL host, user, password, database from config
        $db_host = $_ENV['DB_HOST'] ?? 'localhost';
        $db_user = $_ENV['DB_USER'] ?? 'root';
        $db_pass = $_ENV['DB_PASS'] ?? '';
        $db_name = $_ENV['DB_NAME'] ?? 'gestionhoras';
        
        // Save SQL content to temporary file
        $temp_sql = tempnam(sys_get_temp_dir(), 'backup_') . '.sql';
        file_put_contents($temp_sql, $sql_content);
        
        // Use mysql command to import
        $command = sprintf(
            "mysql -h %s -u %s %s %s < %s",
            escapeshellarg($db_host),
            escapeshellarg($db_user),
            ($db_pass ? '-p' . escapeshellarg($db_pass) : ''),
            escapeshellarg($db_name),
            escapeshellarg($temp_sql)
        );
        
        $output = [];
        $return_var = 0;
        exec($command . ' 2>&1', $output, $return_var);
        
        // Clean up temporary file
        @unlink($temp_sql);
        
        if ($return_var !== 0) {
            throw new Exception('Error executing mysql: ' . implode("\n", $output));
        }
        
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Backup restaurado exitosamente']);
        exit;
    } catch (Exception $e) {
        error_log('Restore error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Error al restaurar backup: ' . $e->getMessage()]);
        exit;
    }
}

// If no valid action, redirect
header('Location: admin-settings.php');
exit;
