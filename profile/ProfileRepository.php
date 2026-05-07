<?php

class ProfileRepository
{
    private $dbConnectFactory;

    public function __construct(callable $dbConnectFactory)
    {
        $this->dbConnectFactory = $dbConnectFactory;
    }

    private function getConnection(string $domain): mysqli
    {
        return call_user_func($this->dbConnectFactory, $domain);
    }

    public function getProfileData(int $userId): ?array
    {
        $conn = $this->getConnection('accounts');
        $stmt = $conn->prepare("SELECT first_name, last_name, username, email, bio, created_at, google_id, password, profile_pic FROM accounts WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return null;
        }

        $avatarUrl = null;
        if ($user['profile_pic']) {
            $avatarPath = __DIR__ . '/../uploads/profile_pics/' . $user['profile_pic'];
            $v = file_exists($avatarPath) ? filemtime($avatarPath) : time();
            $avatarUrl = '../uploads/profile_pics/' . rawurlencode($user['profile_pic']) . '?v=' . $v;
        }

        return [
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'bio' => $user['bio'],
            'member_since' => date('F j, Y', strtotime($user['created_at'])),
            'has_google' => !empty($user['google_id']),
            'has_password' => !empty($user['password']),
            'avatar_url' => $avatarUrl,
        ];
    }

    public function updateProfile(int $userId, string $firstName, string $lastName, string $username, string $email, string $bio): array
    {
        $errors = [];
        $conn = $this->getConnection('accounts');

        // Check for uniqueness of username and email against other users
        $stmt = $conn->prepare("SELECT id, username, email FROM accounts WHERE (username = ? OR email = ?) AND id != ?");
        $stmt->bind_param('ssi', $username, $email, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (strtolower($row['username']) === strtolower($username)) {
                $errors[] = 'Username is already taken.';
            }
            if (strtolower($row['email']) === strtolower($email)) {
                $errors[] = 'Email address is already in use.';
            }
        }
        $stmt->close();

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $stmt = $conn->prepare("UPDATE accounts SET first_name = ?, last_name = ?, username = ?, email = ?, bio = ? WHERE id = ?");
        $stmt->bind_param('sssssi', $firstName, $lastName, $username, $email, $bio, $userId);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            // Update session
            $_SESSION['user']['name'] = trim($firstName . ' ' . $lastName);
            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['email'] = $email;
            return ['success' => true];
        }

        return ['success' => false, 'errors' => ['Failed to update profile information.']];
    }
    
    public function updatePassword(int $userId, ?string $currentPassword, string $newPassword): array
    {
        $conn = $this->getConnection('accounts');
        $stmt = $conn->prepare("SELECT password FROM accounts WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return ['success' => false, 'errors' => ['User not found.']];
        }

        // If user has a password, current password must be correct.
        if (!empty($user['password'])) {
            if (empty($currentPassword) || !password_verify($currentPassword, $user['password'])) {
                return ['success' => false, 'errors' => ['Current password is incorrect.']];
            }
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE accounts SET password = ? WHERE id = ?");
        $stmt->bind_param('si', $newHash, $userId);
        $success = $stmt->execute();
        $stmt->close();

        return $success ? ['success' => true] : ['success' => false, 'errors' => ['Failed to update password.']];
    }

    public function updateProfilePicture(int $userId, array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'errors' => ['File upload error: code ' . $file['error']]];
        }

        $uploadDir = __DIR__ . '/../uploads/profile_pics/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'errors' => ['Could not create upload directory.']];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'])) {
            return ['success' => false, 'errors' => ['Only JPEG or PNG images are allowed.']];
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            return ['success' => false, 'errors' => ['File size must be less than 2 MB.']];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = $userId . '_' . time() . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'errors' => ['Failed to save uploaded file.']];
        }

        $conn = $this->getConnection('accounts');

        $stmt = $conn->prepare("SELECT profile_pic FROM accounts WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $oldPic = $stmt->get_result()->fetch_assoc()['profile_pic'] ?? null;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE accounts SET profile_pic = ? WHERE id = ?");
        $stmt->bind_param('si', $filename, $userId);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            if ($oldPic && file_exists($uploadDir . $oldPic)) {
                @unlink($uploadDir . $oldPic);
            }
            $_SESSION['user']['profile_pic'] = $filename;
            unset($_SESSION['user']['profile_pic_synced']);
            return ['success' => true];
        }

        @unlink($destination);
        return ['success' => false, 'errors' => ['Failed to update profile picture in database.']];
    }
}