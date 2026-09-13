<?php

declare(strict_types=1);

use FourBag\Database;
use FourBag\PaymentService;
use FourBag\RegistrationService;
use FourBag\StripePaymentProvider;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/RegistrationService.php';
require_once __DIR__ . '/../src/PaymentProviderInterface.php';
require_once __DIR__ . '/../src/StripePaymentProvider.php';
require_once __DIR__ . '/../src/PaymentService.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    $rawPayload = file_get_contents('php://input') ?: '';
    $signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

    $db = Database::connect();
    $registrationService = new RegistrationService($db);
    $provider = StripePaymentProvider::fromEnvironment();
    $payments = new PaymentService(
        $db,
        $provider,
        $registrationService,
        PaymentService::baseUrlFromEnvironment()
    );

    $result = $payments->processWebhook($rawPayload, $signature);
    http_response_code(200);
    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
