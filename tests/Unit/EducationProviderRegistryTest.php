<?php

namespace Tests\Unit;

use App\Models\EducationServiceProduct;
use App\Services\Education\EducationProvider;
use App\Services\Education\EducationProviderRegistry;
use LogicException;
use Tests\TestCase;

class EducationProviderRegistryTest extends TestCase
{
    public function test_unregistered_provider_fails_closed_instead_of_falling_back_to_another_provider(): void
    {
        $product = new EducationServiceProduct([
            'provider' => 'gsubz',
            'provider_product_code' => 'waec',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not verified or registered');

        app(EducationProviderRegistry::class)->forProduct($product);
    }

    public function test_registry_requires_an_explicit_adapter_implementing_education_contract(): void
    {
        app()->instance('education.providers.test-education-provider', new class {
            public function assertConfigured(): void {}
        });

        $product = new EducationServiceProduct(['provider' => 'test-education-provider']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement the required transaction contract');

        app(EducationProviderRegistry::class)->forProduct($product);
    }
}
