<?php

declare(strict_types=1);

final class StripeWebhookHandler
{
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
    }

    public function handle(string $payload, string $signatureHeader): array
    {
        if (!StripeClient::verifyWebhookSignature($payload, $signatureHeader, $this->config['STRIPE_WEBHOOK_SECRET'])) {
            throw new HttpException(400, 'Invalid Stripe webhook signature.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['type'])) {
            throw new HttpException(400, 'Invalid Stripe webhook payload.');
        }

        $type = (string) $event['type'];
        $object = $event['data']['object'] ?? [];

        switch ($type) {
            case 'payment_intent.succeeded':
                $this->markDonationSucceeded((string) ($object['id'] ?? ''));
                break;

            case 'payment_intent.payment_failed':
            case 'payment_intent.canceled':
                $this->updateDonationStatus((string) ($object['id'] ?? ''), 'failed');
                break;

            case 'checkout.session.completed':
                $this->markSubscriptionCreated($object);
                break;

            case 'invoice.paid':
                $this->creditSubscriptionInvoice($object);
                break;

            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $this->updateSubscriptionStatus($object);
                break;
        }

        return ['received' => true];
    }

    private function markDonationSucceeded(string $paymentIntentId): void
    {
        if ($paymentIntentId === '') {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, recipient_id, amount_cents, status FROM donations WHERE stripe_payment_intent_id = :pi_id LIMIT 1'
        );
        $stmt->execute(['pi_id' => $paymentIntentId]);
        $donation = $stmt->fetch();

        if (!$donation) {
            return;
        }

        if ($donation['status'] !== 'succeeded') {
            $update = $this->pdo->prepare('UPDATE donations SET status = :status WHERE id = :id');
            $update->execute([
                'status' => 'succeeded',
                'id' => $donation['id'],
            ]);
        }

        $this->insertLedgerIfMissing(
            (int) $donation['recipient_id'],
            (int) $donation['amount_cents'],
            'donation',
            'donation',
            (string) $donation['id']
        );
    }

    private function updateDonationStatus(string $paymentIntentId, string $status): void
    {
        if ($paymentIntentId === '') {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE donations SET status = :status WHERE stripe_payment_intent_id = :pi_id');
        $stmt->execute([
            'status' => $status,
            'pi_id' => $paymentIntentId,
        ]);
    }

    private function markSubscriptionCreated(array $session): void
    {
        if (($session['mode'] ?? '') !== 'subscription') {
            return;
        }

        $subscriptionId = (string) ($session['subscription'] ?? '');
        if ($subscriptionId === '') {
            return;
        }

        $metadata = $session['metadata'] ?? [];
        $recipientId = (int) ($metadata['recipient_id'] ?? 0);
        $amountCents = (int) ($metadata['amount_cents'] ?? 0);
        $interval = (string) ($metadata['interval'] ?? 'month');
        $email = $session['customer_details']['email'] ?? $session['customer_email'] ?? null;
        $donorId = $this->createOrGetDonor($email);

        $existing = $this->pdo->prepare('SELECT id FROM subscriptions WHERE stripe_subscription_id = :sub_id LIMIT 1');
        $existing->execute(['sub_id' => $subscriptionId]);
        $row = $existing->fetch();

        if ($row) {
            $update = $this->pdo->prepare(
                'UPDATE subscriptions SET status = :status, donor_id = :donor_id WHERE id = :id'
            );
            $update->execute([
                'status' => 'active',
                'donor_id' => $donorId,
                'id' => $row['id'],
            ]);
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO subscriptions (donor_id, recipient_id, `interval`, amount_cents, stripe_subscription_id, status, created_at)
             VALUES (:donor_id, :recipient_id, :interval, :amount_cents, :stripe_subscription_id, :status, UTC_TIMESTAMP())'
        );
        $insert->execute([
            'donor_id' => $donorId,
            'recipient_id' => $recipientId,
            'interval' => $interval,
            'amount_cents' => $amountCents,
            'stripe_subscription_id' => $subscriptionId,
            'status' => 'active',
        ]);
    }

    private function creditSubscriptionInvoice(array $invoice): void
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        $subscriptionId = (string) ($invoice['subscription'] ?? '');
        $amountPaid = (int) ($invoice['amount_paid'] ?? 0);

        if ($invoiceId === '' || $subscriptionId === '' || $amountPaid <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, recipient_id FROM subscriptions WHERE stripe_subscription_id = :sub_id LIMIT 1'
        );
        $stmt->execute(['sub_id' => $subscriptionId]);
        $subscription = $stmt->fetch();

        if (!$subscription) {
            return;
        }

        $this->insertLedgerIfMissing(
            (int) $subscription['recipient_id'],
            $amountPaid,
            'subscription',
            'invoice',
            $invoiceId
        );
    }

    private function updateSubscriptionStatus(array $subscription): void
    {
        $subscriptionId = (string) ($subscription['id'] ?? '');
        if ($subscriptionId === '') {
            return;
        }

        $status = (string) ($subscription['status'] ?? 'inactive');
        $stmt = $this->pdo->prepare('UPDATE subscriptions SET status = :status WHERE stripe_subscription_id = :sub_id');
        $stmt->execute([
            'status' => $status,
            'sub_id' => $subscriptionId,
        ]);
    }

    private function insertLedgerIfMissing(int $recipientId, int $amountCents, string $category, string $refType, string $refId): void
    {
        $check = $this->pdo->prepare(
            'SELECT id FROM wallet_ledger WHERE ref_type = :ref_type AND ref_id = :ref_id LIMIT 1'
        );
        $check->execute([
            'ref_type' => $refType,
            'ref_id' => $refId,
        ]);

        if ($check->fetch()) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO wallet_ledger (recipient_id, type, amount_cents, category, ref_type, ref_id, created_at)
             VALUES (:recipient_id, :type, :amount_cents, :category, :ref_type, :ref_id, UTC_TIMESTAMP())'
        );
        $insert->execute([
            'recipient_id' => $recipientId,
            'type' => 'credit',
            'amount_cents' => $amountCents,
            'category' => $category,
            'ref_type' => $refType,
            'ref_id' => $refId,
        ]);
    }

    private function createOrGetDonor(?string $email): ?int
    {
        if (!$email) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM donors WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        if ($row) {
            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare('INSERT INTO donors (email, created_at) VALUES (:email, UTC_TIMESTAMP())');
        $insert->execute(['email' => $email]);

        return (int) $this->pdo->lastInsertId();
    }
}
