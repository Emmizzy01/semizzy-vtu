<?php

namespace App\Services\Addons;

/**
 * Streaming addon admission snapshot. Catalogue management is not purchase
 * authorization; customer purchase stays unavailable until a reviewed purchase
 * operation and provider fulfilment/recovery contract are registered.
 */
final class StreamingPurchaseAdmissionPolicy
{
    public function snapshot(): array
    {
        $assessment = app(AddonOperationAdmissionPolicy::class)->assess(
            'service.streaming',
            'service.streaming_subscriptions',
            'streaming-subscriptions.purchase',
            false,
        );
        $operations = $assessment['operations'];

        return [
            'available' => $assessment['available'],
            'feature' => $assessment['feature'],
            'addon_lifecycle' => [
                ...$assessment['addon_lifecycle'],
                'catalogue_operation_registered' => in_array('streaming-subscriptions.index', $operations, true),
                'purchase_operation_registered' => $assessment['addon_lifecycle']['operation_registered'],
            ],
            'gate_reason' => 'Purchases are locked until a verified provider contract, transaction lookup, accepted-order recovery workflow and purchase coordinator are available.',
        ];
    }
}
