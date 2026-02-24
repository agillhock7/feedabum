<?php

declare(strict_types=1);

final class TokenService
{
    public function __construct(private readonly PDO $pdo, private readonly string $tokenSigningSecret)
    {
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token . $this->tokenSigningSecret);
    }

    public function createForRecipient(int $recipientId): array
    {
        $token = bin2hex(random_bytes(24));
        $tokenHash = $this->hashToken($token);
        $codeShort = $this->generateUniqueShortCode();

        $stmt = $this->pdo->prepare(
            'INSERT INTO recipient_tokens (recipient_id, token_hash, code_short, active, created_at) VALUES (:recipient_id, :token_hash, :code_short, 1, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'recipient_id' => $recipientId,
            'token_hash' => $tokenHash,
            'code_short' => $codeShort,
        ]);

        return [
            'token' => $token,
            'code_short' => $codeShort,
        ];
    }

    public function revokeActiveTokens(int $recipientId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE recipient_tokens SET active = 0, revoked_at = UTC_TIMESTAMP() WHERE recipient_id = :recipient_id AND active = 1'
        );
        $stmt->execute(['recipient_id' => $recipientId]);
    }

    private function generateUniqueShortCode(int $length = 7): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            $max = strlen($alphabet) - 1;
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }

            $stmt = $this->pdo->prepare('SELECT id FROM recipient_tokens WHERE code_short = :code_short LIMIT 1');
            $stmt->execute(['code_short' => $code]);
            $exists = (bool) $stmt->fetch();
        } while ($exists);

        return $code;
    }
}
