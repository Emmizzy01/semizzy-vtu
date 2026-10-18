<?php

namespace App\Services\Education\Providers;

use App\Models\EducationServiceProduct;
use App\Services\Education\EducationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

final class GsubzEducationProvider implements EducationProvider
{
    public function assertConfigured(): void
    {
        $base = rtrim((string) config('services.education.providers.gsubz.base_url'), '/');
        if ($base !== 'https://api.gsubz.com' || (string) config('services.education.providers.gsubz.api_key') === '') {
            throw new LogicException('GSUBZ education requires the official HTTPS endpoint and server-side API key.');
        }
        if (! config('services.education.providers.gsubz.enabled', false)) {
            throw new LogicException('GSUBZ education is disabled until Semizzy merchant access and product mappings are verified.');
        }
    }

    public function validateCandidate(EducationServiceProduct $product, array $candidate): array
    {
        if ($product->requires_candidate_validation) {
            return ['status' => 'unknown', 'message' => 'GSUBZ documents exam-pin purchase but no candidate-validation endpoint for these education products.'];
        }
        return ['status' => 'valid', 'message' => 'No provider candidate-validation step is required for this exam-pin product.'];
    }

    public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
    {
        $this->assertConfigured();
        $service = strtolower(trim((string) ($product->metadata['service_id'] ?? $product->provider_product_code)));
        $plans = ['waec' => 'waecdirect', 'neco' => 'neco', 'nabteb' => 'nabteb'];
        if (! isset($plans[$service])) {
            throw new LogicException('GSUBZ education supports only verified WAEC, NECO and NABTEB service IDs.');
        }
        $plan = (string) ($product->metadata['plan'] ?? $plans[$service]);
        $phone = $candidate['phone'] ?? null;
        if (! is_string($phone) || ! preg_match('/^[0-9+() -]{7,24}$/', $phone)) {
            throw new LogicException('A valid recipient phone number is required for GSUBZ exam-pin delivery.');

        }

        try {
            $response = $this->http()->asForm()->post('/api/pay/', [
                'api' => (string) config('services.education.providers.gsubz.api_key'),
                'serviceID' => $service,
                'plan' => $plan,
                'amount' => '',
                'phone' => $phone,
                'requestID' => $orderReference,
            ]);
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'message_code' => 'transport_outcome_unknown'];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return ['status' => 'unknown', 'message_code' => 'http_'.$response->status()];
        }
        return $this->normalize($response->json());
    }

    public function lookup(string $orderReference, EducationServiceProduct $product): array
    {
        $this->assertConfigured();
        try {
            $response = $this->http()->asForm()->post('/api/verify/', [
                'api' => (string) config('services.education.providers.gsubz.api_key'),
                'requestID' => $orderReference,
            ]);
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'message_code' => 'lookup_transport_unknown'];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return ['status' => 'unknown', 'message_code' => 'lookup_http_'.$response->status()];
        }
        return $this->normalize($response->json(), true);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.education.providers.gsubz.base_url'), '/'))
            ->withToken((string) config('services.education.providers.gsubz.api_key'))
            ->acceptJson()->connectTimeout(5)->timeout(20);
    }

    /** @param array<string,mixed> $body */
    private function normalize(array $body, bool $lookup = false): array
    {
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        $code = isset($body['code']) && is_scalar($body['code']) ? (string) $body['code'] : null;
        $reference = $body['transactionID'] ?? $body['transactionId'] ?? $body['transaction_id'] ?? null;

        if (in_array($status, ['successful', 'success', 'completed'], true)) {
            return [
                'status' => 'success',
                'provider_reference' => is_scalar($reference) ? (string) $reference : null,
                'message_code' => $code,
                'delivery_payload' => array_filter([
                    'api_response' => isset($body['api_response']) && is_string($body['api_response']) ? $body['api_response'] : null,
                    'pins' => isset($body['pins']) && is_array($body['pins']) ? $body['pins'] : null,
                    'pin' => isset($body['pin']) && is_scalar($body['pin']) ? (string) $body['pin'] : null,
                    'serial' => isset($body['serial']) && is_scalar($body['serial']) ? (string) $body['serial'] : null,
                ], fn ($value) => $value !== null),
            ];
        }
        if ($lookup && in_array($status, ['failed', 'fail', 'refunded'], true)) {
            return ['status' => 'failed', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => $code ?? 'provider_confirmed_failed'];
        }
        if (in_array($status, ['pending', 'processing'], true)) {
            return ['status' => 'pending', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => $code];
        }

        // HTTP 200 is not proof of success or definitive failure. Unknown and
        // failure-shaped responses stay unresolved unless the provider contract is conclusive.
        return ['status' => 'unknown', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => $code ?? 'unconfirmed_status'];
    }
}
