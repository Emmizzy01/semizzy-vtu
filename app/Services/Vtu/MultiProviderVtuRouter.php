<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProviderAttempt;
use App\Models\VtuProduct;
use App\Services\IntegrationSettings;
use LogicException;

class MultiProviderVtuRouter implements VtuProvider
{
    /** @var array<string, VtuProvider> */
    private array $providers;
    private ?string $selectedProvider = null;

    public function __construct(
        private readonly VtuProviderClient $configuredProvider,
        private readonly VtpassProviderClient $vtpassProvider,
        private readonly IntegrationSettings $settings,
        SogoProviderClient $sogo,
        GsubzProviderClient $gsubz,
        DatawayProviderClient $dataway,
    ) {
        $this->providers = ['sogo' => $sogo, 'gsubz' => $gsubz, 'dataway' => $dataway];
    }

    public function assertConfigured(): void
    {
        if (! $this->enabled()) $this->legacyProvider()->assertConfigured();
    }

    public function assertProductConfigured(VtuProduct $product): void
    {
        if (! $this->enabled()) {
            $this->legacyProvider()->assertProductConfigured($product);
            return;
        }
        $this->selectProvider($product);
    }

    public function providerNameForProduct(VtuProduct $product): string
    {
        if (! $this->enabled()) {
            return $this->driver() === 'vtpass' ? 'vtpass' : (string) config('services.vtu.name', 'configured_vtu');
        }
        $this->selectProvider($product);
        if ($this->selectedProvider === null) throw new LogicException('No eligible VTU provider was selected before debit.');
        return $this->selectedProvider;
    }

    public function submit(VtuOrder $order): array
    {
        return $this->providerForRecordedOrder($order)->submit($order);
    }

    public function lookup(VtuOrder $order): array
    {
        return $this->providerForRecordedOrder($order)->lookup($order);
    }

    /** Reconcile the provider recorded on a specific durable attempt, not the order's current provider. */
    public function lookupAttempt(VtuOrder $order, VtuProviderAttempt $attempt): array
    {
        if ((int) $attempt->vtu_order_id !== (int) $order->getKey()) {
            throw new LogicException('Provider attempt does not belong to this VTU order.');
        }

        return $this->providerForAttempt($attempt->provider)->lookup($order);
    }

    /** Submit only against the provider recorded on a persisted attempt. */
    public function submitAttempt(VtuOrder $order, VtuProviderAttempt $attempt): array
    {
        if ((int) $attempt->vtu_order_id !== (int) $order->getKey()
            || (string) $attempt->provider !== (string) $order->provider) {
            throw new LogicException('Provider attempt does not match the order dispatch target.');
        }

        return $this->providerForAttempt($attempt->provider)->submit($order);
    }

    /**
     * Pick a different configured, explicitly mapped provider for a durable
     * second attempt. Dataway remains excluded until its lookup contract is verified.
     */
    public function nextProviderForAttempt(VtuOrder $order, VtuProviderAttempt $attempt): string
    {
        if (! $this->enabled()) {
            throw new LogicException('Multi-provider failover is disabled.');
        }
        if ((int) $attempt->vtu_order_id !== (int) $order->getKey()) {
            throw new LogicException('Provider attempt does not belong to this VTU order.');
        }

        $product = $order->product;
        if (! $product instanceof VtuProduct) {
            throw new LogicException('VTU order product metadata is unavailable.');
        }

        $priority = config('services.vtu.multi_provider.priority', ['sogo', 'gsubz', 'dataway']);
        if (! is_array($priority)) $priority = ['sogo', 'gsubz', 'dataway'];

        foreach ($priority as $name) {
            if (! is_string($name) || $name === $attempt->provider || ! isset($this->providers[$name])) continue;
            if ($name === 'dataway') continue;

            try {
                $this->providers[$name]->assertConfigured();
                $this->providers[$name]->assertProductConfigured($product);
                return $name;
            } catch (LogicException) {
                // A candidate that is not fully configured/mapped is never dispatched.
            }
        }

        throw new LogicException('No alternate provider has a verified mapping and reconciliation adapter.');
    }

    public function verifyElectricityMeter(VtuProduct $product, string $meterNumber, string $meterType): array
    {
        $provider = $this->legacyProvider();
        if (! $provider instanceof VtpassProviderClient) {
            throw new LogicException('Electricity verification requires the approved VTpass verification adapter.');
        }
        return $provider->verifyElectricityMeter($product, $meterNumber, $meterType);
    }

    public function verifyTvSmartcard(VtuProduct $product, string $smartcardNumber): array
    {
        $provider = $this->legacyProvider();
        if (! $provider instanceof VtpassProviderClient) {
            throw new LogicException('TV verification requires the approved VTpass verification adapter.');
        }
        return $provider->verifyTvSmartcard($product, $smartcardNumber);
    }

    private function providerForRecordedOrder(VtuOrder $order): VtuProvider
    {
        if (isset($this->providers[$order->provider])) return $this->providers[$order->provider];
        return $this->legacyProvider();
    }

    private function providerForAttempt(string $provider): VtuProvider
    {
        if (isset($this->providers[$provider])) return $this->providers[$provider];
        if ($provider === 'vtpass') return $this->vtpassProvider;
        if ($provider === (string) config('services.vtu.name', 'configured_vtu')) return $this->configuredProvider;

        throw new LogicException('The provider recorded for this attempt is not available for reconciliation.');
    }

    private function selectProvider(VtuProduct $product): void
    {
        $this->selectedProvider = null;
        $priority = config('services.vtu.multi_provider.priority', ['sogo', 'gsubz', 'dataway']);
        if (! is_array($priority)) $priority = ['sogo', 'gsubz', 'dataway'];

        foreach ($priority as $name) {
            if (! is_string($name) || ! isset($this->providers[$name])) continue;
            // Dataway is excluded until its account-specific requery contract is confirmed.
            if ($name === 'dataway') continue;

            try {
                $this->providers[$name]->assertConfigured();
                $this->providers[$name]->assertProductConfigured($product);
                $this->selectedProvider = $name;
                return;
            } catch (LogicException) {
                // Safe pre-dispatch fallback only; no order or wallet debit exists yet.
            }
        }
        throw new LogicException('No configured, mapped, reconciliation-capable VTU provider is available for this product.');
    }

    private function enabled(): bool
    {
        return config('services.vtu.multi_provider.enabled', false) === true;
    }

    private function driver(): string
    {
        return (string) $this->settings->get('vtu.driver', config('services.vtu.driver', 'vtpass'));
    }

    private function legacyProvider(): VtuProvider
    {
        return $this->driver() === 'vtpass' ? $this->vtpassProvider : $this->configuredProvider;
    }
}
