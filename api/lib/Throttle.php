<?php

declare(strict_types=1);

final class Throttle
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hit(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $stmt = $this->pdo->prepare('SELECT id, count, reset_at FROM throttle WHERE `key` = :throttle_key LIMIT 1');
        $stmt->execute(['throttle_key' => $key]);
        $row = $stmt->fetch();

        if (!$row) {
            $resetAt = $now->modify('+' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');
            $insert = $this->pdo->prepare(
                'INSERT INTO throttle (`key`, count, reset_at, created_at, updated_at) VALUES (:throttle_key, 1, :reset_at, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert->execute([
                'throttle_key' => $key,
                'reset_at' => $resetAt,
            ]);

            return ['allowed' => true, 'retry_after' => 0];
        }

        $resetAt = new DateTimeImmutable((string) $row['reset_at'], new DateTimeZone('UTC'));
        $count = (int) $row['count'];

        if ($now >= $resetAt) {
            $newResetAt = $now->modify('+' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');
            $update = $this->pdo->prepare('UPDATE throttle SET count = 1, reset_at = :reset_at, updated_at = UTC_TIMESTAMP() WHERE id = :id');
            $update->execute([
                'reset_at' => $newResetAt,
                'id' => $row['id'],
            ]);

            return ['allowed' => true, 'retry_after' => 0];
        }

        $count++;
        $update = $this->pdo->prepare('UPDATE throttle SET count = :count, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute([
            'count' => $count,
            'id' => $row['id'],
        ]);

        if ($count > $maxAttempts) {
            $retryAfter = max(1, $resetAt->getTimestamp() - $now->getTimestamp());
            return ['allowed' => false, 'retry_after' => $retryAfter];
        }

        return ['allowed' => true, 'retry_after' => 0];
    }

    public function clear(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM throttle WHERE `key` = :throttle_key');
        $stmt->execute(['throttle_key' => $key]);
    }
}
