<?php

namespace App\Services\Education\Providers;

use App\Models\EducationServiceProduct;
use App\Services\Education\EducationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

final class SogoEducationProvider implements EducationProvider
{
    public function assertConfigured(): void
    {
        $base = rtrim((string) config('services.education.providers.sogo.base_url'), '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $key = (string) config('services.education.providers.sogo.api_key');

        if (! in_array($base, ['https://api.sogo.africa/v1', 'https://sandbox.sogo.africa/v1'], true)
            || ! in_array($host, ['api.sogo.africa', 'sandbox.sogo.africa'], true)
            || $key === '') {
            throw new LogicException('Sogo education requires an official HTTPS endpoint and server-side secret key.');
        }

        if (! config('services.education.providers.sogo.enabled', false)) {
            throw new LogicException('Sogo education is disabled until Semizzy merchant access and product mappings are verified.');
        }

        if ($host === 'api.sogo.africa' && ! str_starts_with($key, 'sogo_sk_live_')) {
            throw new LogicException('Sogo live endpoint requires a live secret key.');
        }
        if ($host === 'sandbox.sogo.africa' && ! str_starts_with($key, 'sogo_sk_test_')) {
            throw new LogicException('Sogo sandbox endpoint requires a sandbox secret key.');
        }
    }

    public function validateCandidate(EducationServiceProduct $product, array $candidate): array
    {
        if (! $product->requires_candidate_validation) {
            return ['status' => 'valid', 'message' => 'This product does not require candidate verification.'];
        }

        $exam = strtoupper((string) $product->exam_body);
        if ($exam !== 'JAMB' || ! is_string($candidate['profile_id'] ?? null) || trim($candidate['profile_id']) === '') {
            return ['status' => 'unknown', 'message' => 'Sogo candidate verification is only implemented for a mapped JAMB profile ID.'];
        }

        $this->assertConfigured();
        try {
            $response = $this->http()->post('/bills/education/verify-jamb', [
                'profile_id' => trim($candidate['profile_id']),
            ]);
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'message' => 'Candidate verification transport outcome is unknown.'];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return ['status' => 'unknown', 'message' => 'Candidate verification could not be confirmed.'];
        }

        $body = $response->json();
        $data = is_array($body['verification'] ?? null) ? $body['verification'] : (is_array($body['data'] ?? null) ? $body['data'] : $body);
        if (($data['verified'] ?? false) === true || ($data['valid'] ?? false) === true
            || ($exam === 'JAMB' && $data !== [] && (isset($data['candidate_name']) || isset($data['customer_name']) || isset($data['name'])))) {
            return ['status' => 'valid', 'reference' => isset($data['reference']) ? (string) $data['reference'] : null];
        }
        if (($data['verified'] ?? null) === false || ($data['valid'] ?? null) === false) {
            return ['status' => 'invalid', 'message' => 'The provider rejected the candidate details.'];
        }

        return ['status' => 'unknown', 'message' => 'The provider response did not positively confirm candidate validity.'];
    }

    public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
    {
        $this->assertConfigured();
        $variation = $product->provider_product_code;
        if (! is_string($variation) || trim($variation) === '') {
            throw new LogicException('Sogo education product is missing its verified provider product code.');
        }

        $exam = strtolower(trim((string) $product->exam_body));
        if (! in_array($exam, ['jamb', 'waec'], true)) {
            throw new LogicException('Sogo education supports only mapped JAMB and WAEC products.');
        }
        $payload = [
            'provider' => $exam,
            'variation_code' => $variation,
            'amount' => round(((int) ($product->provider_cost_minor ?? $product->price_minor)) / 100, 2),
        ];
        if ($exam === 'waec') {
            $serviceType = $product->metadata['service_type'] ?? null;
            if (! in_array($serviceType, ['result_checker', 'registration'], true)) {
                throw new LogicException('Sogo WAEC product must declare service_type as result_checker or registration.');
            }
            $payload['service_type'] = $serviceType;
        }
        foreach (['profile_id', 'candidate_number', 'phone', 'email'] as $field) {
            if (isset($candidate[$field]) && is_scalar($candidate[$field])) {
                $payload[$field] = (string) $candidate[$field];
            }
        }

        try {
            $response = $this->http()->withHeaders(['Idempotency-Key' => $orderReference])
                ->post('/bills/education', $payload);
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'message_code' => 'transport_outcome_unknown'];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return ['status' => 'unknown', 'message_code' => 'http_'.$response->status()];
        }

        $body = $response->json();
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        return $this->normalize($data);
    }

    public function lookup(string $orderReference, EducationServiceProduct $product): array
    {
        $this->assertConfigured();
        $host = strtolower((string) parse_url((string) config('services.education.providers.sogo.base_url'), PHP_URL_HOST));

        try {
            $response = $this->http()->get('/transactions/'.rawurlencode($orderReference), ['type' => 'bill_payment']);
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'message_code' => 'lookup_transport_unknown'];
        }

        // Sogo explicitly does not persist sandbox transactions. A sandbox 404 is inconclusive.
        if ($response->status() === 404) {
            return ['status' => 'unknown', 'message_code' => $host === 'sandbox.sogo.africa' ? 'sandbox_lookup_not_persisted' : 'transaction_not_found'];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return ['status' => 'unknown', 'message_code' => 'lookup_http_'.$response->status()];
        }

        $body = $response->json();
        return $this->normalize(is_array($body['data'] ?? null) ? $body['data'] : $body);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.education.providers.sogo.base_url'), '/'))
            ->withToken((string) config('services.education.providers.sogo.api_key'))
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    /** @param array<string,mixed> $data */
    private function normalize(array $data): array
    {
        if (is_array($data['transaction'] ?? null)) {
            $data = $data['transaction'];
        }
        $status = strtolower((string) ($data['status'] ?? ''));
        $reference = $data['reference'] ?? $data['id'] ?? null;
        $message = $data['code'] ?? $data['message_code'] ?? null;
        if ($status === 'completed') return ['status' => 'success', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => is_scalar($message) ? (string) $message : null];
        if ($status === 'failed' || $status === 'refunded') return ['status' => 'failed', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => is_scalar($message) ? (string) $message : 'provider_final_failure'];
        if (in_array($status, ['processing', 'pending'], true)) return ['status' => 'pending', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => is_scalar($message) ? (string) $message : null];
        return ['status' => 'unknown', 'provider_reference' => is_scalar($reference) ? (string) $reference : null, 'message_code' => is_scalar($message) ? (string) $message : 'unrecognized_provider_status'];
    }
}
