<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PostRepository.php';

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/login.php');
    exit;
}

$userID  = (int)$_SESSION['user']['id'];
$caption = trim($_POST['caption'] ?? '');
$image   = $_FILES['image'] ?? null;

$repo = new PostRepository('db_connect');
$repo->processCreatePost($userID, $caption, $image);

header('Location: post.php');
exit;
?>
