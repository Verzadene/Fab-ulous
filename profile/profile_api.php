<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ProfileRepository.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'errors' => ['Not logged in']]);
    exit;
}

$conn = db_connect();
$repo = new ProfileRepository($conn);
$userID = (int) $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = $repo->getUserProfile($userID);
    if (!$user) {
        session_destroy();
        echo json_encode(['success' => false, 'errors' => ['User not found']]);
        exit;
    }

    $avatarUrl = null;
    if (!empty($user['profile_pic'])) {
        $avatarPath = __DIR__ . '/../uploads/profile_pics/' . $user['profile_pic'];
        $avatarVersion = file_exists($avatarPath) ? (string) filemtime($avatarPath) : (string) time();
        $avatarUrl = '../uploads/profile_pics/' . rawurlencode($user['profile_pic']) . '?v=' . rawurlencode($avatarVersion);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'has_password' => !empty($user['password']),
            'has_google' => !empty($user['google_id']),
            'member_since' => date('M d, Y', strtotime($user['created_at'])),
            'avatar_url' => $avatarUrl
        ]
    ]);
    $conn->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $repo->getUserProfile($userID);
    $errors = [];

    $firstName   = trim($_POST['first_name'] ?? '');
    $lastName    = trim($_POST['last_name'] ?? '');
    $newUsername = trim($_POST['username'] ?? '');
    $newEmail    = trim($_POST['email'] ?? '');
    $newPass     = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';
    $currentPass = $_POST['current_password'] ?? '';

    $picUpdated = false;
    $newPicFilename = null;
    $hasPicColumn = $repo->hasProfilePicColumn();

    if (!$hasPicColumn && !empty($_FILES['profile_pic']['name'])) {
        $errors[] = 'Profile pictures are not enabled yet. Run database/migration_v4.sql first.';
    } elseif ($hasPicColumn && !empty($_FILES['profile_pic']['name'])) {
        $file = $_FILES['profile_pic'];
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);

        if ($uploadError !== UPLOAD_ERR_OK) {
            $errors[] = 'The selected profile picture could not be uploaded. Please try again.';
        } elseif (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'The selected profile picture upload is invalid.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!isset($allowedMimes[$mime])) {
                $errors[] = 'Profile picture must be a JPEG or PNG image.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Profile picture must be smaller than 2 MB.';
            } else {
                $ext = $allowedMimes[$mime];
                $uploadDir = __DIR__ . '/../uploads/profile_pics/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    $errors[] = 'Could not prepare the profile picture upload folder.';
                } else {
                    $newPicFilename = $userID . '.' . $ext;
                    $destPath = $uploadDir . $newPicFilename;
                    $oldPic = $user['profile_pic'] ?? '';
                    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                        $errors[] = 'Failed to save profile picture. Please try again.';
                        $newPicFilename = null;
                    } else {
                        if ($oldPic && $oldPic !== $newPicFilename && file_exists($uploadDir . $oldPic)) {
                            @unlink($uploadDir . $oldPic);
                        }
                        $picUpdated = true;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        if ($firstName === '' || $lastName === '' || $newUsername === '' || $newEmail === '') {
            $errors[] = 'All profile fields are required.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif (strlen($newUsername) < 3 || strlen($newUsername) > 50) {
            $errors[] = 'Username must be 3–50 characters.';
        } elseif ($repo->isUsernameOrEmailTaken($newUsername, $newEmail, $userID)) {
            $errors[] = 'That username or email is already used by another account.';
        }
    }

    $updatePass = false;
    $hashedPass = null;
    if (empty($errors) && $newPass !== '') {
        if (strlen($newPass) < 16) {
            $errors[] = 'Password must be at least 16 characters.';
        } elseif (preg_match_all('/[^a-zA-Z0-9]/', $newPass) < 2 || preg_match_all('/[0-9]/', $newPass) < 2) {
            $errors[] = 'Password must contain at least 2 special characters and 2 numbers.';
        } elseif ($newPass !== $confirmPass) {
            $errors[] = 'New password and confirmation do not match.';
        } else {
            if (!empty($user['password']) && !password_verify($currentPass, $user['password'])) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $updatePass = true;
                $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
            }
        }
    }

    if (empty($errors)) {
        $ok = $repo->updateProfile($userID, $firstName, $lastName, $newUsername, $newEmail, $hashedPass, $newPicFilename, $picUpdated, $updatePass);
        if ($ok) {
            $_SESSION['user']['name'] = $firstName . ' ' . $lastName;
            $_SESSION['user']['username'] = $newUsername;
            $_SESSION['user']['email'] = $newEmail;
            if ($picUpdated) {
                $_SESSION['user']['profile_pic'] = $newPicFilename;
            }
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'errors' => ['Update failed. Please try again.']]);
        }
    } else {
        echo json_encode(['success' => false, 'errors' => $errors]);
    }
    $conn->close();
}
?>