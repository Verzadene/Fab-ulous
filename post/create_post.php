<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PostRepository.php';

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/login.php');
    exit;
}

$userID     = (int)$_SESSION['user']['id'];
$username   = (string)($_SESSION['user']['username'] ?? '');
$caption    = trim($_POST['caption'] ?? '');
$image      = $_FILES['image'] ?? null;
$visibility = ($_POST['visibility'] ?? 'friends') === 'public' ? 'public' : 'friends';

$repo = new PostRepository('db_connect');
$repo->processCreatePost($userID, $caption, $image, $visibility, $username);

header('Location: post.php');
exit;
?>
