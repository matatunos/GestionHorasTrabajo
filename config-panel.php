<?php
// Redirect to settings with proper PHP processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST_COPY = $_POST;
    $_GET_COPY = $_GET;
    include __DIR__ . '/settings.php';
    exit;
}
header('Location: settings.php');
exit;
?>
