<?php

declare(strict_types=1);

final class StripeClient
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    public function __construct(private readonly string $secretKey)
    {
    }

    public function createPaymentIntent(int $amountCents, string $currency, int $recipientId, ?string $donorEmail): array
    {
        $params = [
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'automatic_payment_methods[enabled]' => 'true',
            'metadata[recipient_id]' => (string) $recipientId,
        ];

        if ($donorEmail) {
            $params['receipt_email'] = $donorEmail;
        }

        return $this->request('POST', '/payment_intents', $params);
    }

    public function createSubscriptionCheckoutSession(
        int $recipientId,
        int $amountCents,
        string $interval,
        string $successUrl,
        string $cancelUrl,
        ?string $donorEmail
    ): array {
        $params = [
            'mode' => 'subscription',
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => $amountCents,
            'line_items[0][price_data][recurring][interval]' => $interval,
            'line_items[0][price_data][product_data][name]' => 'Feed a Bum Recurring Support',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata[recipient_id]' => (string) $recipientId,
            'metadata[amount_cents]' => (string) $amountCents,
            'metadata[interval]' => $interval,
            'subscription_data[metadata][recipient_id]' => (string) $recipientId,
            'subscription_data[metadata][amount_cents]' => (string) $amountCents,
            'subscription_data[metadata][interval]' => $interval,
        ];

        if ($donorEmail) {
            $params['customer_email'] = $donorEmail;
        }

        return $this->request('POST', '/checkout/sessions', $params);
    }

    public static function verifyWebhookSignature(string $payload, string $signatureHeader, string $webhookSecret): bool
    {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key && $value) {
                $parts[$key] = $value;
            }
        }

        if (!isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return hash_equals($expected, $parts['v1']);
    }

    private function request(string $method, string $path, array $params = []): array
    {
        $url = self::BASE_URL . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException(500, 'Unable to initialize Stripe request.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
        ];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new HttpException(502, 'Stripe connection failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(502, 'Stripe returned an invalid response.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $decoded['error']['message'] ?? 'Stripe request failed.';
            throw new HttpException(502, $message);
        }

        return $decoded;
    }
}
