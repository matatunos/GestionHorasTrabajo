<?php
require_once __DIR__ . '/auth.php';
// A test endpoint to verify require_admin() redirects when session expired
// Ensure no user is logged in
unset($_SESSION['user_id']);
require_admin();
echo "Should not reach here if redirected";
