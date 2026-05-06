<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PostRepository.php';

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/login.php');
    exit;
}

$conn = db_connect();

$userID  = (int)$_SESSION['user']['id'];
$caption = trim($_POST['caption'] ?? '');
$image   = $_FILES['image'] ?? null;

$repo = new PostRepository($conn);
$repo->processCreatePost($userID, $caption, $image);
$conn->close();

header('Location: post.php');
exit;
?>
