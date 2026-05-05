<?php

class ProfileRepository {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function hasProfilePicColumn(): bool {
        $colCheck = $this->conn->query("SHOW COLUMNS FROM accounts LIKE 'profile_pic'");
        return $colCheck && $colCheck->num_rows > 0;
    }

    public function getUserProfile(int $userId): ?array {
        $picSelect = $this->hasProfilePicColumn() ? ', profile_pic' : '';
        $stmt = $this->conn->prepare(
            "SELECT first_name, last_name, username, email, password, google_id, created_at{$picSelect}
             FROM accounts WHERE id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($user && !isset($user['profile_pic'])) {
            $user['profile_pic'] = null;
        }
        return $user ?: null;
    }

    public function isUsernameOrEmailTaken(string $username, string $email, int $excludeId): bool {
        $stmt = $this->conn->prepare("SELECT id FROM accounts WHERE (username = ? OR email = ?) AND id != ?");
        $stmt->bind_param("ssi", $username, $email, $excludeId);
        $stmt->execute();
        $stmt->store_result();
        $taken = $stmt->num_rows > 0;
        $stmt->close();
        return $taken;
    }

    public function updateProfile(int $id, string $first, string $last, string $user, string $email, ?string $hash, ?string $pic, bool $updatePic, bool $updatePass): bool {
        $sql = "UPDATE accounts SET first_name=?, last_name=?, username=?, email=?";
        $types = "ssss";
        $params = [$first, $last, $user, $email];

        if ($updatePass) { $sql .= ", password=?"; $types .= "s"; $params[] = $hash; }
        if ($updatePic)  { $sql .= ", profile_pic=?"; $types .= "s"; $params[] = $pic; }
        $sql .= " WHERE id=?"; $types .= "i"; $params[] = $id;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
?>