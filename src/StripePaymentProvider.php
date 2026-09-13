<?php

declare(strict_types=1);

namespace FourBag;

use RuntimeException;

final class StripePaymentProvider implements PaymentProviderInterface
{
    private const API_BASE = 'https://api.stripe.com/v1';
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __construct(
        private string $secretKey,
        private string $webhookSecret
    ) {
        $this->secretKey = trim($this->secretKey);
        $this->webhookSecret = trim($this->webhookSecret);
    }

    public static function fromEnvironment(): self
    {
        return new self(
            (string)(getenv('FOURBAG_STRIPE_SECRET_KEY') ?: ''),
            (string)(getenv('FOURBAG_STRIPE_WEBHOOK_SECRET') ?: '')
        );
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckoutSession(array $request): array
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Stripe checkout is not configured. Set FOURBAG_STRIPE_SECRET_KEY.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for Stripe checkout.');
        }

        $currency = strtolower((string)($request['currency'] ?? 'usd'));
        $body = [
            'mode' => 'payment',
            'success_url' => (string)$request['success_url'],
            'cancel_url' => (string)$request['cancel_url'],
            'client_reference_id' => (string)$request['order_id'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int)$request['amount_cents'],
                    'product_data' => [
                        'name' => (string)$request['description'],
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'fourbag_order_id' => (string)$request['order_id'],
                'fourbag_order_type' => (string)$request['order_type'],
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'fourbag_order_id' => (string)$request['order_id'],
                    'fourbag_order_type' => (string)$request['order_type'],
                ],
            ],
        ];

        $ch = curl_init(self::API_BASE . '/checkout/sessions');
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Stripe checkout request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($body),
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Idempotency-Key: ' . (string)$request['idempotency_key'],
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Stripe checkout request failed: ' . ($curlError ?: 'network error'));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Stripe returned an invalid checkout response.');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string)($decoded['error']['message'] ?? 'Stripe checkout request failed.');
            throw new RuntimeException($message);
        }

        $sessionId = trim((string)($decoded['id'] ?? ''));
        $checkoutUrl = trim((string)($decoded['url'] ?? ''));
        if ($sessionId === '' || $checkoutUrl === '') {
            throw new RuntimeException('Stripe did not return a hosted checkout session.');
        }

        return [
            'provider_session_id' => $sessionId,
            'checkout_url' => $checkoutUrl,
            'status' => (string)($decoded['status'] ?? 'open'),
        ];
    }

    public function verifyWebhook(string $rawPayload, string $signatureHeader): array
    {
        if ($this->webhookSecret === '') {
            throw new RuntimeException('Stripe webhook verification is not configured. Set FOURBAG_STRIPE_WEBHOOK_SECRET.');
        }
        if ($rawPayload === '' || trim($signatureHeader) === '') {
            throw new RuntimeException('Stripe webhook payload and signature are required.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int)$value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || !$signatures) {
            throw new RuntimeException('Stripe webhook signature header is malformed.');
        }
        if (abs(time() - $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            throw new RuntimeException('Stripe webhook signature timestamp is outside the allowed tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $this->webhookSecret);
        $valid = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new RuntimeException('Stripe webhook signature verification failed.');
        }

        $event = json_decode($rawPayload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            throw new RuntimeException('Stripe webhook event is invalid.');
        }

        return $event;
    }
}
