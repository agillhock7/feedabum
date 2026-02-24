<?php

declare(strict_types=1);

final class Mailer
{
    public static function sendText(string $toEmail, string $subject, string $body, string $fromEmail, string $fromName): bool
    {
        $encodedFromName = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($fromName, 'UTF-8')
            : $fromName;

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        return mail($toEmail, $subject, $body, implode("\r\n", $headers));
    }
}
