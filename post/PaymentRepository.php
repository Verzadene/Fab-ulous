<?php
/**
 * PaymentRepository — payment lifecycle for the PayMongo flow. Backs
 * post/paymongo_checkout.php (creates a pending row, then attaches the
 * checkout session id) and post/paymongo_webhook.php (marks the row paid
 * and fires the commission_paid notification).
 *
 * Cross-database rule:
 *   Cross-domain reads (commission row + payer account) use fully-qualified
 *   `db_name`.`table_name` references, mirroring CommissionRepository.
 *   Writes target the commission_payments domain only.
 *
 * Storage strategy (no schema change):
 *   commission_payments.paymongo_payment_id is NOT NULL UNIQUE.
 *     1. createPendingPaymentRecord stores 'pending_' . uniqid() as a placeholder.
 *     2. updatePaymentWithCheckoutDetails overwrites it with the PayMongo
 *        checkout session id (cs_…).
 *     3. processWebhookPayment overwrites it with the real PayMongo payment id
 *        (pay_…), sets status='paid' and paid_at, then fires the notification.
 *   The schema has no column for the PayMongo reference number or checkout url,
 *   so updatePaymentWithCheckoutDetails accepts but does not persist them.
 */
class PaymentRepository {
    private $dbConnectFactory;

    public function __construct(callable $dbConnectFactory)
    {
        $this->dbConnectFactory = $dbConnectFactory;
    }

    private function getConnection(string $domain): mysqli
    {
        return call_user_func($this->dbConnectFactory, $domain);
    }

    public function checkPaymentsTableExists(): bool
    {
        $conn = $this->getConnection('commission_payments');
        $result = $conn->query("SHOW TABLES LIKE 'commission_payments'");
        return $result && $result->num_rows > 0;
    }

    /**
     * Returns the commission row plus payer info needed to seed a PayMongo
     * checkout. Cross-DB read: commissions joined to accounts via fully
     * qualified names.
     */
    public function getCommissionForPayment(int $commissionId, int $userId): ?array
    {
        $conn = $this->getConnection('commissions');
        $dbAccounts = DB_CONFIG['accounts']['name'];

        $cols = [];
        $colResult = $conn->query('SHOW COLUMNS FROM commissions');
        while ($colResult && $col = $colResult->fetch_assoc()) {
            $cols[$col['Field']] = true;
        }
        $titleExpr = isset($cols['commission_name'])
            ? "COALESCE(NULLIF(c.commission_name, ''), c.description)"
            : (isset($cols['title']) ? "COALESCE(NULLIF(c.title, ''), c.description)" : 'c.description');

        $sql = "SELECT c.commissionID,
                       c.amount,
                       {$titleExpr} AS title,
                       a.email,
                       CONCAT(a.first_name, ' ', a.last_name) AS payer_name
                FROM commissions c
                JOIN `{$dbAccounts}`.accounts a ON c.userID = a.id
                WHERE c.commissionID = ? AND c.userID = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('ii', $commissionId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function isCommissionPaid(int $commissionId): bool
    {
        $conn = $this->getConnection('commission_payments');
        $stmt = $conn->prepare(
            "SELECT 1 FROM commission_payments WHERE commissionID = ? AND status = 'paid' LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i', $commissionId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    public function createPendingPaymentRecord(
        int $commissionId,
        int $userId,
        string $payerName,
        string $payerEmail,
        float $amount
    ): ?int {
        $conn = $this->getConnection('commission_payments');
        $placeholder = 'pending_' . uniqid('', true);
        $status = 'pending';

        $stmt = $conn->prepare(
            "INSERT INTO commission_payments (commissionID, paymongo_payment_id, status, amount)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) return null;
        $stmt->bind_param('issd', $commissionId, $placeholder, $status, $amount);
        $ok = $stmt->execute();
        $newId = $ok ? (int)$conn->insert_id : null;
        $stmt->close();
        return $newId;
    }

    public function failPaymentRecord(int $paymentId): bool
    {
        $conn = $this->getConnection('commission_payments');
        $stmt = $conn->prepare(
            "UPDATE commission_payments SET status = 'failed' WHERE paymentID = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i', $paymentId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function updatePaymentWithCheckoutDetails(
        int $paymentId,
        string $checkoutId,
        ?string $reference,
        string $checkoutUrl
    ): bool {
        $conn = $this->getConnection('commission_payments');
        $stmt = $conn->prepare(
            "UPDATE commission_payments SET paymongo_payment_id = ? WHERE paymentID = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param('si', $checkoutId, $paymentId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Idempotent: returns 0 without re-firing the notification when the row
     * is already paid. Resolves the row by metadata.payment_id (our internal
     * paymentID, set in checkout.php) and falls back to looking up the stored
     * checkout session id.
     */
    public function processWebhookPayment(?string $eventId, string $eventType, array $resource): int
    {
        $conn = $this->getConnection('commission_payments');
        $resourceId = (string)($resource['id'] ?? '');
        $attributes = $resource['attributes'] ?? [];
        $metadata = $attributes['metadata'] ?? [];

        $paymentId = isset($metadata['payment_id']) ? (int)$metadata['payment_id'] : 0;

        if ($paymentId <= 0 && $resourceId !== '') {
            $stmt = $conn->prepare(
                "SELECT paymentID FROM commission_payments WHERE paymongo_payment_id = ? LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $resourceId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $paymentId = $row ? (int)$row['paymentID'] : 0;
                $stmt->close();
            }
        }

        if ($paymentId <= 0) return 0;

        $actualPaymentId = $resourceId;
        if ($eventType === 'checkout_session.payment.paid') {
            $payments = $attributes['payments'] ?? [];
            if (!empty($payments) && isset($payments[0]['id'])) {
                $actualPaymentId = (string)$payments[0]['id'];
            }
        }
        if ($actualPaymentId === '') {
            $actualPaymentId = 'paid_' . uniqid('', true);
        }

        $stmt = $conn->prepare(
            "SELECT commissionID, status FROM commission_payments WHERE paymentID = ? LIMIT 1"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return 0;

        $commissionId = (int)$row['commissionID'];
        if (($row['status'] ?? '') === 'paid') return 0;

        $stmt = $conn->prepare(
            "UPDATE commission_payments
             SET status = 'paid', paymongo_payment_id = ?, paid_at = NOW()
             WHERE paymentID = ? AND status <> 'paid'"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('si', $actualPaymentId, $paymentId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0 && $commissionId > 0) {
            $ownerId = $this->getCommissionOwnerId($commissionId);
            if ($ownerId > 0) {
                create_notification($ownerId, $ownerId, 'commission_paid', null, $commissionId);
            }
        }

        return $affected;
    }

    private function getCommissionOwnerId(int $commissionId): int
    {
        $conn = $this->getConnection('commissions');
        $stmt = $conn->prepare("SELECT userID FROM commissions WHERE commissionID = ? LIMIT 1");
        if (!$stmt) return 0;
        $stmt->bind_param('i', $commissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['userID'] : 0;
    }
}
