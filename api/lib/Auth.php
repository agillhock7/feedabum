<?php

declare(strict_types=1);

final class Auth
{
    /**
     * @return array{user_id:int, role:string, partner_id:int|null, is_demo:bool}
     */
    public static function requireAdminContext(): array
    {
        $userId = $_SESSION['admin_user_id'] ?? null;
        $role = $_SESSION['admin_role'] ?? null;

        if (!is_int($userId) || !is_string($role) || $role === '') {
            throw new HttpException(401, 'Authentication required.');
        }

        $partnerId = $_SESSION['admin_partner_id'] ?? null;
        if (!is_int($partnerId)) {
            $partnerId = null;
        }

        return [
            'user_id' => $userId,
            'role' => $role,
            'partner_id' => $partnerId,
            'is_demo' => self::isDemoSession(),
        ];
    }

    public static function requirePartnerId(): int
    {
        $context = self::requireAdminContext();
        if (!is_int($context['partner_id'])) {
            throw new HttpException(403, 'Partner scope is required for this account.');
        }

        return $context['partner_id'];
    }

    public static function canManageAllPartners(array $context): bool
    {
        return $context['role'] === 'admin_owner';
    }

    public static function currentPartner(PDO $pdo): ?array
    {
        $partnerId = $_SESSION['admin_partner_id'] ?? null;
        if (!is_int($partnerId)) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id, name, email FROM partners WHERE id = :id');
        $stmt->execute(['id' => $partnerId]);
        $partner = $stmt->fetch();

        return $partner ?: null;
    }

    public static function currentAdmin(PDO $pdo): ?array
    {
        $userId = $_SESSION['admin_user_id'] ?? null;
        if (!is_int($userId)) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT id, partner_id, email, display_name, role, status FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $admin = $stmt->fetch();

        return $admin ?: null;
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
