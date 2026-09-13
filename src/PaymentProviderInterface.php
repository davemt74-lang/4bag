<?php

declare(strict_types=1);

namespace FourBag;

interface PaymentProviderInterface
{
    public function name(): string;

    /**
     * @param array{order_id:int,order_type:string,amount_cents:int,currency:string,description:string,success_url:string,cancel_url:string,idempotency_key:string} $request
     * @return array{provider_session_id:string,checkout_url:string,status:string}
     */
    public function createCheckoutSession(array $request): array;

    /**
     * Verify the provider webhook and return the decoded event payload.
     */
    public function verifyWebhook(string $rawPayload, string $signatureHeader): array;
}
