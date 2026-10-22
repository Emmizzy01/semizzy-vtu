<?php

namespace App\Services\Addons;

use App\Http\Controllers\EducationServiceController;
use App\Http\Controllers\StreamingSubscriptionController;
use App\Http\Controllers\Vtu\VtuOrderController;
use App\Http\Controllers\VtuWebsiteRequestController;

/**
 * Explicit catalogue of reviewed, in-application service implementations.
 *
 * This is intentionally not a dynamic loader. A database manifest alone is
 * never proof that executable service code has been reviewed and registered.
 */
class AddonImplementationRegistry
{
    /** @var array<string, array{handler: class-string, operations: list<string>}> */
    private const IMPLEMENTATIONS = [
        'service.vtu' => [
            'handler' => VtuOrderController::class,
            'operations' => ['wallet.vtu.products', 'wallet.vtu.orders.create', 'wallet.vtu.orders.bulk'],
        ],
        // Independent service manifests share the reviewed VTU handler while
        // retaining Core-owned wallet, pricing, and provider safeguards.
        'service.airtime' => [
            'handler' => VtuOrderController::class,
            'operations' => ['service.airtime.purchase', 'wallet.vtu.products', 'wallet.vtu.orders.create', 'wallet.vtu.orders.bulk'],
        ],
        'service.data' => [
            'handler' => VtuOrderController::class,
            'operations' => ['service.data.purchase', 'wallet.vtu.products', 'wallet.vtu.orders.create', 'wallet.vtu.orders.bulk'],
        ],
        // Legacy catalogue key remains registered during the compatibility window.
        'service.airtime_data' => [
            'handler' => VtuOrderController::class,
            'operations' => ['wallet.vtu.products', 'wallet.vtu.orders.create', 'wallet.vtu.orders.bulk'],
        ],
        'service.electricity' => [
            'handler' => VtuOrderController::class,
            'operations' => ['service.electricity.purchase', 'wallet.vtu.products', 'wallet.vtu.orders.create', 'wallet.vtu.orders.bulk', 'wallet.vtu.billers.verify'],
        ],
        'service.tv' => [
            'handler' => VtuOrderController::class,
            'operations' => ['service.tv.purchase', 'wallet.vtu.products', 'wallet.vtu.orders.create', 'wallet.vtu.orders.bulk', 'wallet.vtu.billers.verify'],
        ],
        'service.bills' => [
            'handler' => VtuOrderController::class,
            'operations' => ['wallet.vtu.products', 'wallet.vtu.orders.create', 'wallet.vtu.orders.bulk', 'wallet.vtu.billers.verify'],
        ],
        'service.education' => [
            'handler' => EducationServiceController::class,
            'operations' => ['education-services.index', 'education-services.delivery', 'education-services.admin.catalogue', 'education-services.admin.catalogue.create', 'education-services.admin.catalogue.update'],
        ],
        'service.streaming' => [
            'handler' => StreamingSubscriptionController::class,
            'operations' => ['streaming-subscriptions.index', 'streaming-subscriptions.admin.catalogue', 'streaming-subscriptions.admin.catalogue.create', 'streaming-subscriptions.admin.catalogue.update'],
        ],
        'service.vtu-website-requests' => [
            'handler' => VtuWebsiteRequestController::class,
            'operations' => ['vtu-website-requests.index', 'vtu-website-requests.store'],
        ],
    ];

    /** @return array{handler: class-string, operations: list<string>}|null */
    public function find(string $addonKey): ?array
    {
        $implementation = self::IMPLEMENTATIONS[$addonKey] ?? null;

        if ($implementation === null || ! class_exists($implementation['handler'])) {
            return null;
        }

        return $implementation;
    }

    public function isRegistered(string $addonKey): bool
    {
        return $this->find($addonKey) !== null;
    }

    /** @return array<string, array{handler: class-string, operations: list<string>}> */
    public function all(): array
    {
        return array_filter(
            self::IMPLEMENTATIONS,
            fn (array $implementation): bool => class_exists($implementation['handler']),
        );
    }
}
