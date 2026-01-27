<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

if ($action === 'export') {
    try {
        $pdo = get_pdo();
        $filename = 'gestionhoras_backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // Get MySQL host, user, password, database from config or env
        $conf = function_exists('get_config') ? get_config() : [];
        $db_conf = $conf['db'] ?? [];
        $db_host = getenv('DB_HOST') ?: ($db_conf['host'] ?? 'localhost');
        $db_user = getenv('DB_USER') ?: ($db_conf['user'] ?? 'app_user');
        $db_pass = getenv('DB_PASS') ?: ($db_conf['pass'] ?? '');
        $db_name = getenv('DB_NAME') ?: ($db_conf['name'] ?? 'gestion_horas');

        // Create a temporary defaults file to avoid passing password on the command line
        $defaultsContent = "[client]\nuser={$db_user}\npassword={$db_pass}\nhost={$db_host}\n";
        $tmpDefaults = tempnam(sys_get_temp_dir(), 'mycnf_');
        if ($tmpDefaults === false) {
            throw new Exception('Cannot create temporary defaults file');
        }
        file_put_contents($tmpDefaults, $defaultsContent);
        @chmod($tmpDefaults, 0600);

        // Use mysqldump streaming via proc_open to avoid loading into memory
        $command = sprintf(
            "mysqldump --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables %s",
            escapeshellarg($tmpDefaults),
            escapeshellarg($db_name)
        );

        $descriptors = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($tmpDefaults);
            throw new Exception('Failed to start mysqldump process');
        }

        // Stream output to client
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        if (is_resource($pipes[1])) {
            $outStream = fopen('php://output', 'w');
            stream_copy_to_stream($pipes[1], $outStream);
            fclose($outStream);
            fclose($pipes[1]);
        }

        $stderr = '';
        if (is_resource($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $return_var = proc_close($process);
        @unlink($tmpDefaults);

        if ($return_var !== 0) {
            throw new Exception('mysqldump failed: ' . $stderr);
        }
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
        
        // Get MySQL host, user, password, database from config or env
        $conf = function_exists('get_config') ? get_config() : [];
        $db_conf = $conf['db'] ?? [];
        $db_host = getenv('DB_HOST') ?: ($db_conf['host'] ?? 'localhost');
        $db_user = getenv('DB_USER') ?: ($db_conf['user'] ?? 'app_user');
        $db_pass = getenv('DB_PASS') ?: ($db_conf['pass'] ?? '');
        $db_name = getenv('DB_NAME') ?: ($db_conf['name'] ?? 'gestion_horas');

        // Save SQL content to temporary file
        $temp_sql = tempnam(sys_get_temp_dir(), 'backup_');
        if ($temp_sql === false) {
            throw new Exception('Cannot create temporary file for import');
        }
        // Ensure .sql extension for clarity
        $temp_sql_with_ext = $temp_sql . '.sql';
        rename($temp_sql, $temp_sql_with_ext);
        file_put_contents($temp_sql_with_ext, $sql_content);

        // Create temporary defaults file to avoid password on CLI
        $defaultsContent = "[client]\nuser={$db_user}\npassword={$db_pass}\nhost={$db_host}\n";
        $tmpDefaults = tempnam(sys_get_temp_dir(), 'mycnf_');
        if ($tmpDefaults === false) {
            @unlink($temp_sql_with_ext);
            throw new Exception('Cannot create temporary defaults file');
        }
        file_put_contents($tmpDefaults, $defaultsContent);
        @chmod($tmpDefaults, 0600);

        $command = sprintf(
            "mysql --defaults-extra-file=%s %s",
            escapeshellarg($tmpDefaults),
            escapeshellarg($db_name)
        );

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($temp_sql_with_ext);
            @unlink($tmpDefaults);
            throw new Exception('Failed to start mysql process');
        }

        // Feed the SQL file into the process stdin
        if (is_resource($pipes[0])) {
            $fp = fopen($temp_sql_with_ext, 'r');
            if ($fp === false) {
                fclose($pipes[0]);
                proc_close($process);
                @unlink($temp_sql_with_ext);
                @unlink($tmpDefaults);
                throw new Exception('Cannot open temporary SQL file for reading');
            }
            stream_copy_to_stream($fp, $pipes[0]);
            fclose($fp);
            fclose($pipes[0]);
        }

        $stdout = '';
        if (is_resource($pipes[1])) {
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }

        $stderr = '';
        if (is_resource($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $return_var = proc_close($process);

        // Clean up temporary files
        @unlink($temp_sql_with_ext);
        @unlink($tmpDefaults);

        if ($return_var !== 0) {
            throw new Exception('mysql import failed: ' . $stderr . '\n' . $stdout);
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
