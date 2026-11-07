<?php

require_once __DIR__ . '/../../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class NotificationService
{
    /** Low-stock alert to the branch's own store personnel, right after a transaction. */
    public static function dispatchLowStockAlert(PDO $pdo, array $product, string $branchId): void
    {
        $recipients = self::branchRecipients($pdo, $branchId);
        if (!$recipients) {
            return;
        }
        $subject = "Low stock: {$product['name']}";
        $message = "{$product['name']} ({$product['category']}) is at {$product['current_quantity']} "
            . "{$product['unit_of_measure']}, at or below its reorder threshold of {$product['low_stock_threshold']}.";

        foreach ($recipients as $to) {
            self::send($pdo, $to, $subject, $message, 'low_stock', $branchId);
        }
    }

    /** Pre-expiry alert for a single batch, to the branch's store personnel. */
    public static function dispatchExpiryAlert(PDO $pdo, array $product, array $batch, string $branchId): void
    {
        $recipients = self::branchRecipients($pdo, $branchId);
        if (!$recipients) {
            return;
        }
        $subject = "Expiring soon: {$product['name']}";
        $message = "A batch of {$product['name']} (barcode {$batch['barcode']}, "
            . "{$batch['quantity_remaining']} {$product['unit_of_measure']} remaining) expires on {$batch['expiry_date']}.";

        foreach ($recipients as $to) {
            self::send($pdo, $to, $subject, $message, 'expiry', $branchId);
        }
    }

    /** One consolidated digest per branch for the daily sweep (avoids flooding inboxes item by item). */
    public static function dispatchBranchDigest(PDO $pdo, string $branchId, string $text): void
    {
        foreach (self::branchRecipients($pdo, $branchId) as $to) {
            self::send($pdo, $to, 'Daily inventory alerts for your branch', $text, 'branch_digest', $branchId);
        }
    }

    /** Consolidated summary to every manager of a company, covering all their branches. */
    public static function dispatchManagerSummary(PDO $pdo, int $companyId, string $summaryText): void
    {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE company_id = ? AND role = 'manager'");
        $stmt->execute([$companyId]);
        $managers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($managers as $to) {
            self::send($pdo, $to, 'Daily inventory summary — all branches', $summaryText, 'manager_summary', null, $companyId);
        }
    }

    private static function branchRecipients(PDO $pdo, string $branchId): array
    {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE branch_id = ? AND role = 'employee'");
        $stmt->execute([$branchId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function send(PDO $pdo, string $to, string $subject, string $message, string $type, ?string $branchId, ?int $companyIdOverride = null): void
    {
        $status = 'sent';
        $companyId = $companyIdOverride;

        if ($companyId === null && $branchId !== null) {
            $stmt = $pdo->prepare('SELECT company_id FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            $companyId = (int)($stmt->fetchColumn() ?: 0);
        }

        try {
            if (env('SMTP_HOST')) {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = env('SMTP_HOST');
                $mail->SMTPAuth = true;
                $mail->Username = env('SMTP_USERNAME');
                $mail->Password = env('SMTP_PASSWORD');
                $mail->SMTPSecure = env('SMTP_ENCRYPTION', 'tls');
                $mail->Port = (int)env('SMTP_PORT', '587');
                $mail->setFrom(env('SMTP_FROM', 'alerts@stockwise.local'), 'Stockwise Alerts');
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body = $message;
                $mail->send();
            } else {
                // No SMTP_HOST configured (e.g. running offline): nothing leaves the system,
                // but the alert is still logged so the trail stays visible and testable.
                $status = 'not_sent';
            }
        } catch (PHPMailerException $e) {
            $status = 'failed';
        }

        $pdo->prepare(
            'INSERT INTO notifications (company_id, branch_id, type, recipient, subject, message, status) VALUES (?,?,?,?,?,?,?)'
        )->execute([$companyId ?: 0, $branchId, $type, $to, $subject, $message, $status]);
    }
}
