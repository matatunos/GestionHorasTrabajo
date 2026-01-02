<?php
require_once __DIR__ . '/db.php';
session_start();

function current_user(){
    if (empty($_SESSION['user_id'])) return null;
    $pdo = get_pdo();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT id, username, is_admin FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function require_login(){
    if (!current_user()){
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); http_response_code(401); echo json_encode(['ok'=>false,'login'=>true,'redirect'=>'login.php']); exit;
        }
        header('Location: login.php'); exit;
    }
}

function require_admin(){
    $u = current_user();
    if (!$u) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); http_response_code(401); echo json_encode(['ok'=>false,'login'=>true,'redirect'=>'login.php']); exit;
        }
        header('Location: login.php'); exit;
    }
    if (!$u['is_admin']){
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json'); http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
        }
        header('HTTP/1.1 403 Forbidden'); echo 'Forbidden'; exit;
    }
}

function do_login($username, $password){
    $pdo = get_pdo(); if (!$pdo) return false;
    $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if (!$u) return false;
    if (password_verify($password, $u['password'])){
        $_SESSION['user_id'] = $u['id'];
        return true;
    }
    return false;
}

function do_logout(){
    session_unset(); session_destroy();
}
