<?php
require __DIR__ . '/auth.php';
echo "PDO ok: " . (get_pdo() ? "yes\n" : "no\n");
$res = do_login('admin','admin');
echo "do_login result: "; var_export($res); echo "\n";
$u = current_user();
echo "current_user: "; var_export($u); echo "\n";
