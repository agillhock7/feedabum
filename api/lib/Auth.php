<?php

declare(strict_types=1);

final class Auth
{
    public static function requirePartnerId(): int
    {
        $partnerId = $_SESSION['partner_id'] ?? null;
        if (!is_int($partnerId)) {
            throw new HttpException(401, 'Authentication required.');
        }

        return $partnerId;
    }

    public static function currentPartner(PDO $pdo): ?array
    {
        $partnerId = $_SESSION['partner_id'] ?? null;
        if (!is_int($partnerId)) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id, name, email FROM partners WHERE id = :id');
        $stmt->execute(['id' => $partnerId]);
        $partner = $stmt->fetch();

        return $partner ?: null;
    }

    public static function isDemoSession(): bool
    {
        return ($_SESSION['is_demo'] ?? false) === true;
    }

    public static function requireWritableSession(): void
    {
        if (self::isDemoSession()) {
            throw new HttpException(403, 'Demo mode is read-only. Disable demo login or use a non-demo admin account for changes.');
        }
    }
}
