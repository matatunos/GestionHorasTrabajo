<?php
// Test if PHP is being processed correctly
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html>
<head><title>PHP Processing Test</title></head>
<body>
<h1>PHP Processing Test</h1>
<p>If you see this text, PHP is being processed.</p>
<p>Server time: <?php echo date('Y-m-d H:i:s'); ?></p>
<p>PHP Version: <?php echo phpversion(); ?></p>

<script>
console.log('PHP processed value: "<?php echo 'test123'; ?>"');
console.log('Unprocessed PHP would show: "<<?php echo "test"; ?>>"');
</script>
</body>
</html>
