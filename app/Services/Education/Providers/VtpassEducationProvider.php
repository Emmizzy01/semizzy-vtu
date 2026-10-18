<?php

namespace App\Services\Education\Providers;

use App\Models\EducationServiceProduct;
use App\Services\Education\EducationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

final class VtpassEducationProvider implements EducationProvider
{
    public function assertConfigured(): void
    {
        $base = rtrim((string) config('services.education.providers.vtpass.base_url'), '/');
        $key = (string) config('services.education.providers.vtpass.api_key');
        $secret = (string) config('services.education.providers.vtpass.secret_key');
        if (! in_array($base, ['https://vtpass.com/api', 'https://sandbox.vtpass.com/api'], true) || $key === '' || $secret === '') {
            throw new LogicException('VTpass education requires its official HTTPS endpoint and server-side API credentials.');
        }
        if (! config('services.education.providers.vtpass.enabled', false)) {
            throw new LogicException('VTpass education is disabled until merchant access, product mappings and request-ID recovery are verified.');
        }
        if (config('app.env') === 'production' && $base === 'https://sandbox.vtpass.com/api') {
            throw new LogicException('VTpass sandbox cannot be used for production purchases.');
        }
    }

    public function validateCandidate(EducationServiceProduct $product, array $candidate): array
    {
        if ($product->requires_candidate_validation) {
            return ['status' => 'unknown', 'message' => 'Candidate verification is not enabled for this VTpass product; no purchase should proceed.'];
        }
        return ['status' => 'valid', 'message' => 'This mapped exam-pin product does not require separate candidate verification.'];
    }

    public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
    {
        $this->assertConfigured();
        $metadata = is_array($product->metadata) ? $product->metadata : [];
        $serviceId = $metadata['provider_service_id'] ?? null;
        $variation = $metadata['provider_variation_code'] ?? $product->provider_product_code;
        if (! is_string($serviceId) || ! preg_match('/^[a-z0-9-]{2,80}$/i', $serviceId)
            || ! is_string($variation) || trim($variation) === '') {
            throw new LogicException('VTpass education requires an explicitly verified service ID and variation code.');
        }

        $payload = [
            'request_id' => $this->requestId($orderReference),
            'serviceID' => $serviceId,
            'variation_code' => $variation,
            'phone' => is_string($candidate['phone'] ?? null) ? $candidate['phone'] : '',
        ];
        foreach (['candidate_number', 'profile_id', 'email', 'name'] as $field) {
            if (isset($candidate[$field]) && is_scalar($candidate[$field])) {
                $payload[$field] = (string) $candidate[$field];
            }
        }

        try {
            $response = $this->http()->post('/pay', $payload);
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
            $response = $this->http()->post('/requery', ['request_id' => $this->requestId($orderReference)]);
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'message_code' => 'lookup_transport_unknown'];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return ['status' => 'unknown', 'message_code' => 'lookup_http_'.$response->status()];
        }
        return $this->normalize($response->json());
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.education.providers.vtpass.base_url'), '/'))
            ->withHeaders([
                'api-key' => (string) config('services.education.providers.vtpass.api_key'),
                'secret-key' => (string) config('services.education.providers.vtpass.secret_key'),
            ])->acceptJson()->asJson()->connectTimeout(5)->timeout(20);
    }

    private function requestId(string $orderReference): string
    {
        // Deterministic per order so submit and requery always use the same ID.
        // Activation stays disabled until VTpass account tests confirm this ID format.
        return 'EDU'.strtoupper(substr(hash('sha256', $orderReference), 0, 29));
    }

    /** @param array<string,mixed> $body */
    private function normalize(array $body): array
    {
        $code = isset($body['code']) && is_scalar($body['code']) ? (string) $body['code'] : null;
        $transactions = $body['content']['transactions'] ?? null;
        $raw = is_array($transactions) ? strtolower((string) ($transactions['status'] ?? '')) : '';
        $reference = is_array($transactions) ? ($transactions['transactionId'] ?? null) : null;

        if ($code === '000' && $raw === 'delivered') {
            return [
                'status' => 'success',
                'provider_reference' => is_scalar($reference) ? (string) $reference : null,
                'message_code' => $code,
                'delivery_payload' => array_filter([
                    'cards' => isset($body['cards']) && is_array($body['cards']) ? $body['cards'] : null,
                    'tokens' => isset($body['tokens']) && is_array($body['tokens']) ? $body['tokens'] : null,
                    'purchased_code' => isset($body['purchased_code']) && is_string($body['purchased_code']) ? $body['purchased_code'] : null,
                ], fn ($value) => $value !== null),
            ];
        }
        if ($code === '000' && in_array($raw, ['initiated', 'pending'], true)) {
            return ['status' => 'pending', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => $code];
        }
        if (in_array($code, ['016', '091'], true)) {
            return ['status' => 'failed', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => $code];
        }
        return ['status' => 'unknown', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => $code ?? 'unrecognized_provider_status'];
    }
}
