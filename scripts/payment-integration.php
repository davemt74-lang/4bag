<?php

declare(strict_types=1);

use FourBag\AuthService;
use FourBag\BillingService;
use FourBag\Database;
use FourBag\LeagueService;
use FourBag\PaymentProviderInterface;
use FourBag\PaymentService;
use FourBag\RegistrationService;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LeagueService.php';
require_once __DIR__ . '/../src/RegistrationService.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/BillingService.php';
require_once __DIR__ . '/../src/PaymentProviderInterface.php';
require_once __DIR__ . '/../src/PaymentService.php';

function paymentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PAYMENT FAIL: {$message}\n");
        exit(1);
    }
}

final class FakePaymentProvider implements PaymentProviderInterface
{
    private int $counter = 0;
    public array $requests = [];

    public function name(): string
    {
        return 'fake';
    }

    public function createCheckoutSession(array $request): array
    {
        $this->requests[] = $request;
        $this->counter++;
        $sessionId = 'fake_cs_' . (int)$request['order_id'] . '_' . $this->counter;
        return [
            'provider_session_id' => $sessionId,
            'checkout_url' => 'https://payments.example.test/' . $sessionId,
            'status' => 'open',
        ];
    }

    public function verifyWebhook(string $rawPayload, string $signatureHeader): array
    {
        if ($signatureHeader !== 'test-signature') {
            throw new RuntimeException('Invalid fake webhook signature.');
        }
        $event = json_decode($rawPayload, true);
        if (!is_array($event)) {
            throw new RuntimeException('Invalid fake webhook payload.');
        }
        return $event;
    }
}

function paidCheckoutEvent(string $eventId, string $sessionId, int $orderId, int $amountCents, string $currency = 'usd'): string
{
    return json_encode([
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => $sessionId,
                'payment_status' => 'paid',
                'amount_total' => $amountCents,
                'currency' => $currency,
                'metadata' => [
                    'fourbag_order_id' => (string)$orderId,
                ],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES) ?: '';
}

function expiredCheckoutEvent(string $eventId, string $sessionId): string
{
    return json_encode([
        'id' => $eventId,
        'type' => 'checkout.session.expired',
        'data' => ['object' => ['id' => $sessionId]],
    ], JSON_UNESCAPED_SLASHES) ?: '';
}

$db = Database::connect();
$league = new LeagueService($db);
$registration = new RegistrationService($db);
$auth = new AuthService($db);
$billing = new BillingService($db);
$provider = new FakePaymentProvider();
$payments = new PaymentService($db, $provider, $registration, 'https://fourbag.example.test');

$suffix = bin2hex(random_bytes(4));
$admin = $auth->createUser('Payment Admin', "payments-admin-{$suffix}@example.test", 'PaymentAdmin!123', 'admin');
$venueId = $league->createVenue(['name' => "Payment Test Venue {$suffix}", 'city' => 'Phoenix', 'state' => 'AZ']);
$seasonId = $league->createSeason([
    'venue_id' => $venueId,
    'name' => "Board Checkout {$suffix}",
    'registration_fee_cents' => 5000,
]);

$boardRegistration = $registration->register([
    'season_id' => $seasonId,
    'name' => 'Checkout Player',
    'email' => "checkout-player-{$suffix}@example.test",
    'join_type' => 'solo',
    'board_purchase' => true,
]);
paymentAssert($boardRegistration['payment_status'] === 'awaiting_board_payment', 'Board registration must await payment.');
paymentAssert(is_string($boardRegistration['checkout_token']) && strlen($boardRegistration['checkout_token']) === 64, 'Board order must return a one-time checkout access token.');
$orderId = (int)$boardRegistration['board_order_id'];

$badTokenRejected = false;
try {
    $payments->createCheckout($orderId, str_repeat('0', 64), false);
} catch (RuntimeException $e) {
    $badTokenRejected = str_contains($e->getMessage(), 'checkout access token');
}
paymentAssert($badTokenRejected, 'Incorrect checkout token must be rejected.');

$checkout = $payments->createCheckout($orderId, (string)$boardRegistration['checkout_token'], false);
paymentAssert($checkout['provider'] === 'fake', 'Checkout should use the configured provider.');
paymentAssert(str_starts_with($checkout['checkout_url'], 'https://payments.example.test/'), 'Checkout should return a hosted payment URL.');
paymentAssert($checkout['reused'] === false, 'First checkout must create a new hosted session.');
paymentAssert((int)$db->query("SELECT COUNT(*) FROM payment_attempts WHERE order_id={$orderId} AND status='pending'")->fetchColumn() === 1, 'Checkout must persist a pending payment attempt.');
$boardRequest = $provider->requests[array_key_last($provider->requests)];
paymentAssert(str_ends_with((string)$boardRequest['cancel_url'], '/?checkout=cancelled#register'), 'Board checkout cancellation must return to public registration.');
paymentAssert(!str_contains((string)$boardRequest['success_url'], 'return=billing'), 'Board checkout success must not route to venue billing.');

$reusedCheckout = $payments->createCheckout($orderId, (string)$boardRegistration['checkout_token'], false);
paymentAssert($reusedCheckout['reused'] === true, 'Repeated checkout must reuse the active hosted session.');
paymentAssert($reusedCheckout['attempt_id'] === $checkout['attempt_id'], 'Repeated checkout must reuse the same payment attempt.');
paymentAssert($reusedCheckout['provider_session_id'] === $checkout['provider_session_id'], 'Repeated checkout must not create a second payable session.');
paymentAssert((int)$db->query("SELECT COUNT(*) FROM payment_attempts WHERE order_id={$orderId}")->fetchColumn() === 1, 'Repeated checkout must not create another payment-attempt row.');

$expired = $payments->processWebhook(
    expiredCheckoutEvent('evt_expired_' . $suffix, (string)$checkout['provider_session_id']),
    'test-signature'
);
paymentAssert($expired['status'] === 'expired', 'Checkout expiration must be processed.');
paymentAssert((string)$db->query("SELECT status FROM payment_attempts WHERE id=" . (int)$checkout['attempt_id'])->fetchColumn() === 'cancelled', 'Expired checkout must cancel its payment attempt.');

$checkout = $payments->createCheckout($orderId, (string)$boardRegistration['checkout_token'], false);
paymentAssert($checkout['reused'] === false, 'Checkout after expiration must create a fresh hosted session.');
paymentAssert($checkout['provider_session_id'] !== $reusedCheckout['provider_session_id'], 'Replacement checkout must receive a new provider session.');
paymentAssert((int)$db->query("SELECT COUNT(*) FROM payment_attempts WHERE order_id={$orderId} AND status='pending'")->fetchColumn() === 1, 'Only one payment attempt may remain pending after expiration recovery.');

$eventPayload = paidCheckoutEvent('evt_board_' . $suffix, (string)$checkout['provider_session_id'], $orderId, 19900);
$paid = $payments->processWebhook($eventPayload, 'test-signature');
paymentAssert($paid['status'] === 'paid', 'Verified paid webhook must settle the board order.');
paymentAssert((string)$db->query("SELECT status FROM orders WHERE id={$orderId}")->fetchColumn() === 'paid', 'Board order should be paid after verified webhook.');
paymentAssert((string)$db->query("SELECT payment_status FROM registrations WHERE season_id={$seasonId} LIMIT 1")->fetchColumn() === 'included_with_board', 'Paid board checkout must activate included league registration.');
paymentAssert((int)$db->query("SELECT board_purchase FROM registrations WHERE season_id={$seasonId} LIMIT 1")->fetchColumn() === 1, 'Paid board checkout must mark board purchase complete.');
paymentAssert($db->query("SELECT checkout_token_hash FROM orders WHERE id={$orderId}")->fetchColumn() === null, 'Paid order must clear its checkout token hash.');
paymentAssert((int)$db->query("SELECT COUNT(*) FROM payment_attempts WHERE order_id={$orderId} AND status='pending'")->fetchColumn() === 0, 'Settled order must not retain pending payment attempts.');

$duplicate = $payments->processWebhook($eventPayload, 'test-signature');
paymentAssert($duplicate['status'] === 'duplicate', 'Repeated provider event must be idempotent.');
paymentAssert((int)$db->query("SELECT COUNT(*) FROM payment_events WHERE provider_event_id='evt_board_{$suffix}'")->fetchColumn() === 1, 'Duplicate webhook must not create duplicate event rows.');

$hostSeasonId = $league->createSeason([
    'venue_id' => $venueId,
    'name' => "Host Fee {$suffix}",
]);
$invoice = $billing->createHostFeeInvoice(
    $hostSeasonId,
    75000,
    date('Y-m-d', strtotime('+21 days')),
    'Official FourBag league hosting fee',
    (int)$admin['id']
);
paymentAssert($invoice['status'] === 'open', 'Host fee invoice should begin open.');
paymentAssert((int)$invoice['amount_cents'] === 75000, 'Host fee invoice should preserve configured amount.');

$duplicateInvoiceRejected = false;
try {
    $billing->createHostFeeInvoice($hostSeasonId, 80000, null, null, (int)$admin['id']);
} catch (RuntimeException $e) {
    $duplicateInvoiceRejected = str_contains($e->getMessage(), 'already has');
}
paymentAssert($duplicateInvoiceRejected, 'A season must not receive duplicate active host-fee invoices.');

$invoiceCheckout = $payments->createCheckout((int)$invoice['order_id'], null, true);
$hostRequest = $provider->requests[array_key_last($provider->requests)];
paymentAssert(str_ends_with((string)$hostRequest['cancel_url'], '/billing.php?checkout=cancelled'), 'Host-fee cancellation must return to venue billing.');
paymentAssert(str_contains((string)$hostRequest['success_url'], '&return=billing'), 'Host-fee success must preserve the venue-billing return context.');
$invoicePayload = paidCheckoutEvent('evt_host_' . $suffix, (string)$invoiceCheckout['provider_session_id'], (int)$invoice['order_id'], 75000);
$hostPaid = $payments->processWebhook($invoicePayload, 'test-signature');
paymentAssert($hostPaid['status'] === 'paid', 'Verified host-fee payment must settle its order.');
$paidInvoice = $billing->invoiceById((int)$invoice['id']);
paymentAssert($paidInvoice['status'] === 'paid', 'Verified host-fee payment must mark venue invoice paid.');
paymentAssert($paidInvoice['paid_at'] !== null, 'Paid host-fee invoice must capture paid_at.');

$invalidSignatureRejected = false;
try {
    $payments->processWebhook('{"id":"evt_invalid","type":"checkout.session.completed","data":{"object":{}}}', 'bad-signature');
} catch (RuntimeException $e) {
    $invalidSignatureRejected = str_contains($e->getMessage(), 'signature');
}
paymentAssert($invalidSignatureRejected, 'Webhook signature failures must be rejected before settlement.');

echo "FourBag payments and host-billing checks passed.\n";
