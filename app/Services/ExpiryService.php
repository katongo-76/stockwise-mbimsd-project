<?php

class ExpiryService
{
    public const COLORS = [
        'fresh'   => '#50E3A4',
        'warning' => '#F5A623',
        'expired' => '#FF6B6B',
        'none'    => '#2a2e45',
    ];

    public const LABELS = [
        'fresh'   => 'Fresh',
        'warning' => 'Expiring Soon',
        'expired' => 'Expired',
        'none'    => 'No Expiry Date',
    ];

    /**
     * @param string|null $expiryDate 'Y-m-d' or null
     * @param int $alertDays the product's configurable pre-expiry alert window
     */
    public static function status(?string $expiryDate, int $alertDays = 14): string
    {
        if (!$expiryDate) {
            return 'none';
        }
        $days = self::daysUntil($expiryDate);
        if ($days < 0) {
            return 'expired';
        }
        if ($days <= $alertDays) {
            return 'warning';
        }
        return 'fresh';
    }

    public static function daysUntil(?string $expiryDate): ?int
    {
        if (!$expiryDate) {
            return null;
        }
        $today = new DateTime('today');
        $expiry = new DateTime($expiryDate);
        return (int)$today->diff($expiry)->format('%r%a');
    }

    /** @param array $batches each with 'expiry_date' and 'expiry_alert_days' */
    public static function stats(array $batches): array
    {
        $stats = ['fresh' => 0, 'warning' => 0, 'expired' => 0, 'none' => 0];
        foreach ($batches as $b) {
            $status = self::status($b['expiry_date'] ?? null, (int)($b['expiry_alert_days'] ?? 14));
            $stats[$status]++;
        }
        return $stats;
    }
}
