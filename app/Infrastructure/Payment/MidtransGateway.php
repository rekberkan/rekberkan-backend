<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Domain\Financial\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Http;

class MidtransGateway implements PaymentGatewayInterface
{
    private string $serverKey;
    private string $clientKey;
    private string $baseUrl;
    private bool $isProduction;

    public function __construct()
    {
        $this->serverKey = config('services.midtrans.server_key');
        $this->clientKey = config('services.midtrans.client_key');
        $this->isProduction = config('services.midtrans.is_production', false);
        $this->baseUrl = $this->isProduction
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    /**
     * Create Snap payment token.
     */
    public function createPayment(
        string $orderId,
        Money $amount,
        array $customerDetails,
        ?string $callbackUrl = null
    ): array {
        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) $amount->getMajorUnits(),
            ],
            'customer_details' => $customerDetails,
            'enabled_payments' => [
                'gopay', 'shopeepay', 'other_qris',
                'bca_va', 'bni_va', 'bri_va', 'permata_va',
                'credit_card',
            ],
        ];

        if ($callbackUrl) {
            $payload['callbacks'] = [
                'finish' => $callbackUrl,
            ];
        }

        $response = Http::withBasicAuth($this->serverKey, '')
            ->post("{$this->baseUrl}/v2/snap/transactions", $payload);

        if ($response->failed()) {
            throw new \Exception('Midtrans payment creation failed: ' . $response->body());
        }

        return [
            'token' => $response->json('token'),
            'redirect_url' => $response->json('redirect_url'),
            'order_id' => $orderId,
        ];
    }

    /**
     * Get transaction status.
     */
    public function getPaymentStatus(string $transactionId): array
    {
        $response = Http::withBasicAuth($this->serverKey, '')
            ->get("{$this->baseUrl}/v2/{$transactionId}/status");

        if ($response->failed()) {
            throw new \Exception('Failed to get payment status: ' . $response->body());
        }

        $data = $response->json();

        return [
            'transaction_id' => $data['transaction_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'status' => $this->mapStatus($data['transaction_status'] ?? 'unknown'),
            'payment_type' => $data['payment_type'] ?? null,
            'amount' => $data['gross_amount'] ?? null,
            'raw_response' => $data,
        ];
    }

    /**
     * Cancel/expire transaction.
     */
    public function cancelPayment(string $transactionId): array
    {
        $response = Http::withBasicAuth($this->serverKey, '')
            ->post("{$this->baseUrl}/v2/{$transactionId}/cancel");

        if ($response->failed()) {
            throw new \Exception('Failed to cancel payment: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Verify notification signature.
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        $orderId = $payload['order_id'] ?? '';
        $statusCode = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';

        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Create payout (using Iris API).
     */
    public function createPayout(
        string $orderId,
        Money $amount,
        array $recipientDetails
    ): array {
        // Iris API endpoint
        $irisUrl = $this->isProduction
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com';

        $payload = [
            'payouts' => [
                [
                    'beneficiary_name' => $recipientDetails['name'],
                    'beneficiary_account' => $recipientDetails['account_number'],
                    'beneficiary_bank' => $recipientDetails['bank_code'],
                    'amount' => (int) $amount->getMajorUnits(),
                    'notes' => $recipientDetails['notes'] ?? 'Withdrawal',
                ],
            ],
        ];

        $response = Http::withBasicAuth($this->serverKey, '')
            ->post("{$irisUrl}/api/v1/payouts", $payload);

        if ($response->failed()) {
            throw new \Exception('Payout creation failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Map Midtrans status to internal status.
     */
    private function mapStatus(string $midtransStatus): string
    {
        return match ($midtransStatus) {
            'capture', 'settlement' => 'completed',
            'pending' => 'pending',
            'deny', 'cancel', 'expire' => 'failed',
            default => 'unknown',
        };
    }
}
