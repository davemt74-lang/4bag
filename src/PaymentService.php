<?php

declare(strict_types=1);

namespace FourBag;

use PDO;
use RuntimeException;
use Throwable;

final class PaymentService
{
    public function __construct(
        private PDO $db,
        private PaymentProviderInterface $provider,
        private RegistrationService $registrationService,
        private string $baseUrl
    ) {
        $this->baseUrl = rtrim(trim($this->baseUrl), '/');
    }

    public static function baseUrlFromEnvironment(): string
    {
        return (string)(getenv('FOURBAG_BASE_URL') ?: '');
    }

    public function createCheckout(int $orderId, ?string $checkoutToken = null, bool $authorized = false): array
    {
        if ($orderId < 1) {
            throw new RuntimeException('Valid order_id is required.');
        }
        if ($this->baseUrl === '') {
            throw new RuntimeException('Checkout is not configured. Set FOURBAG_BASE_URL.');
        }

        $order = $this->orderById($orderId);
        if ($order['status'] !== 'pending') {
            throw new RuntimeException('Only pending orders can begin checkout.');
        }

        if (!$authorized) {
            $token = strtolower(trim((string)$checkoutToken));
            $storedHash = trim((string)($order['checkout_token_hash'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/', $token) || $storedHash === '' || !hash_equals($storedHash, hash('sha256', $token))) {
                throw new RuntimeException('Valid checkout access token required.');
            }
        }

        // Reuse an existing hosted session instead of creating multiple payable sessions
        // for the same FourBag order. Expired sessions are marked cancelled by the webhook
        // and therefore fall through to a new session on the next checkout request.
        $existing = $this->db->prepare("SELECT id,provider_session_id,checkout_url FROM payment_attempts WHERE order_id=:order_id AND provider=:provider AND status='pending' AND checkout_url IS NOT NULL ORDER BY id DESC LIMIT 1");
        $existing->execute([
            'order_id' => $orderId,
            'provider' => $this->provider->name(),
        ]);
        $existingAttempt = $existing->fetch();
        if ($existingAttempt) {
            return [
                'attempt_id' => (int)$existingAttempt['id'],
                'order_id' => $orderId,
                'provider' => $this->provider->name(),
                'provider_session_id' => (string)$existingAttempt['provider_session_id'],
                'checkout_url' => (string)$existingAttempt['checkout_url'],
                'amount_cents' => (int)$order['subtotal_cents'],
                'currency' => strtoupper((string)$order['currency']),
                'reused' => true,
            ];
        }

        $idempotencyKey = bin2hex(random_bytes(32));
        $attempt = $this->db->prepare("INSERT INTO payment_attempts(order_id,provider,idempotency_key,amount_cents,currency,status,created_at,updated_at) VALUES(:order_id,:provider,:idempotency_key,:amount_cents,:currency,'created',NOW(),NOW())");
        $attempt->execute([
            'order_id' => $orderId,
            'provider' => $this->provider->name(),
            'idempotency_key' => $idempotencyKey,
            'amount_cents' => (int)$order['subtotal_cents'],
            'currency' => strtoupper((string)$order['currency']),
        ]);
        $attemptId = (int)$this->db->lastInsertId();

        try {
            $checkout = $this->provider->createCheckoutSession([
                'order_id' => $orderId,
                'order_type' => (string)$order['order_type'],
                'amount_cents' => (int)$order['subtotal_cents'],
                'currency' => strtoupper((string)$order['currency']),
                'description' => $this->orderDescription((string)$order['order_type']),
                'success_url' => $this->baseUrl . '/checkout-complete.php?order_id=' . $orderId . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->baseUrl . '/?checkout=cancelled#register',
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->db->prepare("UPDATE payment_attempts SET provider_session_id=:provider_session_id,checkout_url=:checkout_url,status='pending',updated_at=NOW() WHERE id=:id")->execute([
                'provider_session_id' => (string)$checkout['provider_session_id'],
                'checkout_url' => (string)$checkout['checkout_url'],
                'id' => $attemptId,
            ]);

            return [
                'attempt_id' => $attemptId,
                'order_id' => $orderId,
                'provider' => $this->provider->name(),
                'provider_session_id' => (string)$checkout['provider_session_id'],
                'checkout_url' => (string)$checkout['checkout_url'],
                'amount_cents' => (int)$order['subtotal_cents'],
                'currency' => strtoupper((string)$order['currency']),
                'reused' => false,
            ];
        } catch (Throwable $e) {
            $this->db->prepare("UPDATE payment_attempts SET status='failed',failure_message=:message,updated_at=NOW() WHERE id=:id")->execute([
                'message' => substr($e->getMessage(), 0, 500),
                'id' => $attemptId,
            ]);
            throw $e;
        }
    }

    public function processWebhook(string $rawPayload, string $signatureHeader): array
    {
        $event = $this->provider->verifyWebhook($rawPayload, $signatureHeader);
        $eventId = trim((string)($event['id'] ?? ''));
        $eventType = trim((string)($event['type'] ?? ''));
        if ($eventId === '' || $eventType === '') {
            throw new RuntimeException('Payment webhook event is missing an id or type.');
        }

        if ($this->eventExists($eventId)) {
            return ['status' => 'duplicate', 'event_id' => $eventId];
        }

        $object = $event['data']['object'] ?? null;
        if (!is_array($object)) {
            throw new RuntimeException('Payment webhook event object is missing.');
        }
        $providerSessionId = trim((string)($object['id'] ?? ''));

        if ($eventType === 'checkout.session.expired') {
            if ($providerSessionId !== '') {
                $this->db->prepare("UPDATE payment_attempts SET status='cancelled',updated_at=NOW() WHERE provider=:provider AND provider_session_id=:provider_session_id AND status IN('created','pending')")->execute([
                    'provider' => $this->provider->name(),
                    'provider_session_id' => $providerSessionId,
                ]);
            }
            $this->recordEvent($eventId, $eventType, $providerSessionId ?: null, null, $rawPayload, 'processed');
            return ['status' => 'expired', 'event_id' => $eventId];
        }

        $payingEvent = in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true);
        if (!$payingEvent || (string)($object['payment_status'] ?? '') !== 'paid') {
            $this->recordEvent($eventId, $eventType, $providerSessionId ?: null, null, $rawPayload, 'ignored');
            return ['status' => 'ignored', 'event_id' => $eventId];
        }

        if ($providerSessionId === '') {
            throw new RuntimeException('Paid checkout event is missing the provider session id.');
        }
        $orderId = (int)($object['metadata']['fourbag_order_id'] ?? 0);
        if ($orderId < 1) {
            throw new RuntimeException('Paid checkout event is missing FourBag order metadata.');
        }

        $attemptStmt = $this->db->prepare('SELECT * FROM payment_attempts WHERE provider=:provider AND provider_session_id=:provider_session_id LIMIT 1');
        $attemptStmt->execute([
            'provider' => $this->provider->name(),
            'provider_session_id' => $providerSessionId,
        ]);
        $attempt = $attemptStmt->fetch();
        if (!$attempt || (int)$attempt['order_id'] !== $orderId) {
            throw new RuntimeException('Paid checkout session does not match a FourBag payment attempt.');
        }

        $order = $this->orderById($orderId);
        $amountTotal = (int)($object['amount_total'] ?? -1);
        $currency = strtoupper((string)($object['currency'] ?? ''));
        if ($amountTotal !== (int)$order['subtotal_cents'] || $currency !== strtoupper((string)$order['currency'])) {
            throw new RuntimeException('Paid checkout amount or currency does not match the FourBag order.');
        }

        $this->db->beginTransaction();
        try {
            $lockedOrderStmt = $this->db->prepare('SELECT id,status,order_type FROM orders WHERE id=:id FOR UPDATE');
            $lockedOrderStmt->execute(['id' => $orderId]);
            $lockedOrder = $lockedOrderStmt->fetch();
            if (!$lockedOrder) {
                throw new RuntimeException('Order not found while applying payment.');
            }
            if (in_array($lockedOrder['status'], ['cancelled', 'refunded'], true)) {
                throw new RuntimeException('Cancelled or refunded orders cannot be marked paid.');
            }

            $this->db->prepare("UPDATE orders SET status='paid',checkout_token_hash=NULL,updated_at=NOW() WHERE id=:id AND status='pending'")->execute(['id' => $orderId]);
            $this->db->prepare("UPDATE payment_attempts SET status='paid',paid_at=COALESCE(paid_at,NOW()),updated_at=NOW() WHERE id=:id")->execute(['id' => (int)$attempt['id']]);
            $this->db->prepare("UPDATE payment_attempts SET status='cancelled',updated_at=NOW() WHERE order_id=:order_id AND id<>:paid_attempt_id AND status IN('created','pending')")->execute([
                'order_id' => $orderId,
                'paid_attempt_id' => (int)$attempt['id'],
            ]);
            $this->db->prepare("UPDATE venue_invoices SET status='paid',paid_at=COALESCE(paid_at,NOW()),updated_at=NOW() WHERE order_id=:order_id AND status='open'")->execute(['order_id' => $orderId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ((string)$order['order_type'] === 'fourbag_set') {
            $this->registrationService->completeBoardOrder($orderId);
        }

        $this->recordEvent($eventId, $eventType, $providerSessionId, $orderId, $rawPayload, 'processed');

        return [
            'status' => 'paid',
            'event_id' => $eventId,
            'order_id' => $orderId,
            'order_type' => (string)$order['order_type'],
        ];
    }

    public function orderById(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT id,player_id,season_id,order_type,subtotal_cents,currency,status,checkout_token_hash,created_at,updated_at FROM orders WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        return $order;
    }

    private function eventExists(string $eventId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM payment_events WHERE provider=:provider AND provider_event_id=:event_id LIMIT 1');
        $stmt->execute(['provider' => $this->provider->name(), 'event_id' => $eventId]);
        return (bool)$stmt->fetchColumn();
    }

    private function recordEvent(
        string $eventId,
        string $eventType,
        ?string $providerSessionId,
        ?int $orderId,
        string $rawPayload,
        string $status
    ): void {
        try {
            $stmt = $this->db->prepare('INSERT INTO payment_events(provider,provider_event_id,event_type,provider_session_id,order_id,payload_hash,status,created_at) VALUES(:provider,:provider_event_id,:event_type,:provider_session_id,:order_id,:payload_hash,:status,NOW())');
            $stmt->execute([
                'provider' => $this->provider->name(),
                'provider_event_id' => $eventId,
                'event_type' => $eventType,
                'provider_session_id' => $providerSessionId,
                'order_id' => $orderId,
                'payload_hash' => hash('sha256', $rawPayload),
                'status' => $status,
            ]);
        } catch (Throwable $e) {
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    private function orderDescription(string $orderType): string
    {
        return match ($orderType) {
            'fourbag_set' => 'FourBag Set — Board + Four Bags',
            'bag_set' => 'FourBag Replacement 4-Bag Set',
            'custom_board' => 'FourBag Custom Board',
            'league_host_fee' => 'FourBag League Host Fee',
            default => 'FourBag Order',
        };
    }
}
