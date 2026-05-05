<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PostRepository.php';

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/login.php');
    exit;
}

$conn = db_connect();

$userID   = (int)$_SESSION['user']['id'];
$caption  = trim($_POST['caption'] ?? '');
$imageURL = null;

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = '../uploads/posts/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext     = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];

    if (in_array($ext, $allowed) && $_FILES['image']['size'] <= 5 * 1024 * 1024) {
        $filename = uniqid('post_', true) . '.' . $ext;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename)) {
            $imageURL = '../uploads/posts/' . $filename;
        }
    }
}

if (empty($caption) && !$imageURL) {
    header('Location: post.php');
    exit;
}

$repo = new PostRepository($conn);
$repo->createPost($userID, $caption, $imageURL);
$conn->close();

header('Location: post.php');
exit;
?>
