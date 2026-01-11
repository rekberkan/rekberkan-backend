<?php

declare(strict_types=1);

namespace App\Domain\Payment\Contracts;

use App\Domain\Financial\ValueObjects\Money;

interface PaymentGatewayInterface
{
    /**
     * Create payment transaction.
     */
    public function createPayment(
        string $orderId,
        Money $amount,
        array $customerDetails,
        ?string $callbackUrl = null
    ): array;

    /**
     * Get payment status.
     */
    public function getPaymentStatus(string $transactionId): array;

    /**
     * Cancel payment.
     */
    public function cancelPayment(string $transactionId): array;

    /**
     * Verify webhook signature.
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool;

    /**
     * Create payout/disbursement.
     */
    public function createPayout(
        string $orderId,
        Money $amount,
        array $recipientDetails
    ): array;
}
