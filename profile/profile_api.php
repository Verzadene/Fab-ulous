<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ProfileRepository.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'errors' => ['Authentication required.']]);
    exit;
}

$userId = (int)$_SESSION['user']['id'];
$repo = new ProfileRepository('db_connect');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $profileData = $repo->getProfileData($userId);
    if ($profileData) {
        echo json_encode(['success' => true, 'data' => $profileData]);
    } else {
        echo json_encode(['success' => false, 'errors' => ['Failed to load profile data.']]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $messages = [];

    // Handle profile info update
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $bio = trim($_POST['bio'] ?? '');

    if (empty($firstName) || empty($lastName) || empty($username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'First name, last name, username, and a valid email are required.';
    } else {
        $profileResult = $repo->updateProfile($userId, $firstName, $lastName, $username, $email, $bio);
        if ($profileResult['success']) {
            $messages[] = 'Profile information updated.';
        } else {
            $errors = array_merge($errors, $profileResult['errors']);
        }
    }

    // Handle password change
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if (!empty($newPassword)) {
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        } elseif (strlen($newPassword) < 8 || preg_match_all('/[^a-zA-Z0-9]/', $newPassword) < 1 || preg_match_all('/[0-9]/', $newPassword) < 1) {
            $errors[] = 'Password must be at least 8 characters with at least 1 special character and 1 number.';
        } else {
            $currentPassword = $_POST['current_password'] ?? null;
            $passwordResult = $repo->updatePassword($userId, $currentPassword, $newPassword);
            if ($passwordResult['success']) {
                $messages[] = 'Password updated successfully.';
            } else {
                $errors = array_merge($errors, $passwordResult['errors']);
            }
        }
    }

    // Handle profile picture upload
    $profilePic = $_FILES['profile_pic'] ?? null;
    if ($profilePic && $profilePic['error'] === UPLOAD_ERR_OK) {
        $picResult = $repo->updateProfilePicture($userId, $profilePic);
        if ($picResult['success']) {
            $messages[] = 'Profile picture updated.';
        } else {
            $errors = array_merge($errors, $picResult['errors']);
        }
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
    } else {
        $finalMessage = !empty($messages) ? implode(' ', $messages) : 'Profile saved. No changes were made.';
        echo json_encode(['success' => true, 'message' => $finalMessage]);
    }
    exit;
}

echo json_encode(['success' => false, 'errors' => ['Invalid request method.']]);