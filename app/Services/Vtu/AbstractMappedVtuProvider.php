<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProduct;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;

abstract class AbstractMappedVtuProvider implements VtuProvider
{
    abstract protected function providerKey(): string;

    /** @return list<string> */
    abstract protected function requiredMappingFields(VtuProduct $product): array;

    public function assertConfigured(): void
    {
        $key = $this->providerKey();
        $baseUrl = $this->baseUrl();
        $apiKey = $this->apiKey();

        if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || $apiKey === '') {
            throw new LogicException("The {$key} VTU provider is not securely configured.");
        }
    }

    public function assertProductConfigured(VtuProduct $product): void
    {
        if (! in_array($product->service_type, ['airtime', 'data'], true)) {
            throw new LogicException('This provider adapter currently supports only explicitly mapped airtime and data products.');
        }

        $mapping = $this->mapping($product);
        foreach ($this->requiredMappingFields($product) as $field) {
            if (! isset($mapping[$field]) || (! is_string($mapping[$field]) && ! is_numeric($mapping[$field]))
                || trim((string) $mapping[$field]) === '') {
                throw new LogicException("The {$this->providerKey()} product mapping is missing {$field}.");
            }
        }
    }

    /** @return array<string, mixed> */
    protected function mapping(VtuProduct $product): array
    {
        $mappings = is_array($product->metadata) ? ($product->metadata['provider_mappings'] ?? []) : [];
        $mapping = is_array($mappings) ? ($mappings[$this->providerKey()] ?? []) : [];

        if (! is_array($mapping)) {
            throw new LogicException("The {$this->providerKey()} product mapping is invalid.");
        }

        return $mapping;
    }

    protected function http(): PendingRequest
    {
        $key = $this->providerKey();

        return Http::baseUrl(rtrim($this->baseUrl(), '/'))
            ->withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(60);
    }

    protected function baseUrl(): string
    {
        $key = $this->providerKey();
        return (string) app(IntegrationSettings::class)->get(
            "vtu.{$key}.base_url",
            config("services.vtu.{$key}.base_url", ''),
        );
    }

    protected function apiKey(): string
    {
        $key = $this->providerKey();
        return (string) app(IntegrationSettings::class)->get(
            "vtu.{$key}.api_key",
            config("services.vtu.{$key}.api_key", ''),
        );
    }

    protected function amountNaira(VtuOrder $order): string
    {
        return number_format($order->amount_minor / 100, 2, '.', '');
    }

    protected function status(mixed $value, array $success, array $failure, array $pending): string
    {
        if (! is_string($value)) {
            return 'unknown';
        }

        $value = strtolower(trim($value));

        return in_array($value, $success, true) ? 'success'
            : (in_array($value, $failure, true) ? 'failed'
            : (in_array($value, $pending, true) ? 'pending' : 'unknown'));
    }

    /** @return array<string, mixed> */
    protected function normalized(string $status, array $body): array
    {
        $reference = $body['provider_reference'] ?? $body['reference'] ?? data_get($body, 'data.reference');

        return [
            'status' => $status,
            'provider_reference' => is_string($reference) ? substr($reference, 0, 150) : null,
            'message_code' => is_string($body['code'] ?? null) ? substr($body['code'], 0, 80) : null,
        ];
    }

    protected function orderProduct(VtuOrder $order): VtuProduct
    {
        $product = $order->product;

        if (! $product instanceof VtuProduct) {
            throw new LogicException('VTU order product metadata is unavailable.');
        }

        $this->assertProductConfigured($product);

        return $product;
    }
}
