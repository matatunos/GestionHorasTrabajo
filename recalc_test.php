<?php
try {
  $lp = __DIR__ . '/recalc_test.log';
  $data = [
    'ts' => strftime('%Y-%m-%d %H:%M:%S'),
    'remote' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNK',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'get' => $_GET,
    'post' => $_POST,
    'headers' => getallheaders()
  ];
  error_log(json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) { }
header('Content-Type: application/json');
echo json_encode(['ok'=>true,'msg'=>'recalc_test reached']);
