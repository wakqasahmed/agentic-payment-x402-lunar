<?php

namespace Lunar\CryptoPayments\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lunar\CryptoPayments\DataTransferObjects\SettlementResult;
use Lunar\CryptoPayments\Facilitators\FacilitatorNotSupportedException;

/**
 * Shared settlement core used by both entry points:
 * - CryptoPaymentType::authorize() for human wallet checkout
 * - X402PaymentMiddleware for agent-initiated requests
 *
 * Verifies a signed EIP-3009 transfer (x402 protocol v2) against a
 * facilitator's /verify + /settle endpoints. Tries facilitators in
 * `lunar-crypto.facilitator_order` (PayAI first by default) so a single
 * facilitator outage doesn't take checkout down.
 */
class SettleOnChainPayment
{
    /**
     * @param  array  $paymentPayload  x402 v2 PaymentPayload (includes the chosen `accepted` PaymentRequirements)
     * @param  array  $paymentRequirements  x402 v2 PaymentRequirements the payload must match
     */
    public function execute(array $paymentPayload, array $paymentRequirements): SettlementResult
    {
        $order = config('lunar-crypto.facilitator_order', []);

        $lastFailure = null;

        foreach ($order as $name) {
            $facilitator = config("lunar-crypto.facilitators.{$name}");

            if (! $facilitator) {
                continue;
            }

            try {
                $result = $this->attempt($name, $facilitator, $paymentPayload, $paymentRequirements);

                if ($result->success) {
                    return $result;
                }

                $lastFailure = $result;
            } catch (FacilitatorNotSupportedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning("Crypto payment facilitator [{$name}] failed, trying next.", ['exception' => $e->getMessage()]);
            }
        }

        return $lastFailure ?? new SettlementResult(success: false, message: 'No facilitator was able to settle the payment.');
    }

    protected function attempt(string $name, array $facilitator, array $paymentPayload, array $paymentRequirements): SettlementResult
    {
        if (empty($facilitator['url'])) {
            throw new FacilitatorNotSupportedException("Facilitator [{$name}] is missing a valid URL.");
        }

        $body = [
            'x402Version' => 2,
            'paymentPayload' => $paymentPayload,
            'paymentRequirements' => $paymentRequirements,
        ];

        $verify = $this->buildHttpClient($facilitator, 10)
            ->post('/verify', $body)
            ->throw()
            ->json();

        if (! ($verify['isValid'] ?? false)) {
            return new SettlementResult(success: false, message: $verify['invalidReason'] ?? 'Payment verification failed');
        }

        $settle = $this->buildHttpClient($facilitator, 15)
            ->post('/settle', $body)
            ->throw()
            ->json();

        if (! ($settle['success'] ?? false)) {
            return new SettlementResult(success: false, message: $settle['errorReason'] ?? 'Settlement failed');
        }

        // Don't trust the facilitator's own numbers blindly — if it reports
        // settling on a different network, or for a different amount, than
        // we asked for (facilitator bug, or a compromised/MITM'd endpoint),
        // that's not a payment we should treat as valid just because
        // `success` was true. `amount` is optional per the x402 spec (falls
        // back to what we requested); `network` is required — a facilitator
        // omitting a required field is itself suspicious, so that fails
        // closed rather than being skipped.
        if (! isset($settle['network']) || ! is_scalar($settle['network']) || (string) $settle['network'] !== $paymentRequirements['network']) {
            Log::error("Crypto payment facilitator [{$name}] settled on a different (or missing) network than requested — refusing to trust it.", [
                'requested' => $paymentRequirements['network'],
                'settled' => $settle['network'] ?? null,
                'transaction' => $settle['transaction'] ?? null,
            ]);

            return new SettlementResult(success: false, message: 'Facilitator settled on a different network than requested.');
        }

        $settledAmount = isset($settle['amount']) && is_scalar($settle['amount'])
            ? (string) $settle['amount']
            : $paymentRequirements['amount'];

        if ($settledAmount !== $paymentRequirements['amount']) {
            Log::error("Crypto payment facilitator [{$name}] settled a different amount than requested — refusing to trust it.", [
                'requested' => $paymentRequirements['amount'],
                'settled' => $settledAmount,
                'transaction' => $settle['transaction'] ?? null,
            ]);

            return new SettlementResult(success: false, message: 'Facilitator settled a different amount than requested.');
        }

        return new SettlementResult(
            success: true,
            txHash: $settle['transaction'] ?? null,
            settledAmount: (int) $settledAmount,
            message: null,
            payer: $settle['payer'] ?? null,
            facilitator: $name,
        );
    }

    /**
     * Build an HTTP client for a facilitator, applying timeout and authentication headers if configured.
     *
     * Supports CDP API key headers (CB-ACCESS-KEY / X-CDP-KEY-ID) and Bearer authorization tokens.
     */
    protected function buildHttpClient(array $facilitator, int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        $headers = [];

        if (! empty($facilitator['api_key_secret'])) {
            $headers['Authorization'] = 'Bearer ' . trim((string) $facilitator['api_key_secret']);
        } elseif (! empty($facilitator['bearer_token'])) {
            $headers['Authorization'] = 'Bearer ' . trim((string) $facilitator['bearer_token']);
        }

        if (! empty($facilitator['api_key_id'])) {
            $keyId = trim((string) $facilitator['api_key_id']);
            $headers['CB-ACCESS-KEY'] = $keyId;
            $headers['X-CDP-KEY-ID'] = $keyId;
        }

        if (! empty($facilitator['headers']) && is_array($facilitator['headers'])) {
            foreach ($facilitator['headers'] as $k => $v) {
                if (is_string($k) && is_scalar($v)) {
                    $headers[$k] = (string) $v;
                }
            }
        }

        $client = Http::baseUrl($facilitator['url'])->timeout($timeout);

        if (! empty($headers)) {
            $client = $client->withHeaders($headers);
        }

        return $client;
    }
}
