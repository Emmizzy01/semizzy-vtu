<?php

namespace Tests\Feature;

use App\Models\BankDirectoryEntry;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Services\Wallets\BankPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class BankDirectoryImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        IntegrationSetting::query()->updateOrCreate(
            ['key' => 'paystack.secret_key'],
            ['value' => 'sk_test_semizzy_bank_directory_test', 'updated_by_user_id' => null],
        );
        IntegrationSetting::query()->updateOrCreate(
            ['key' => 'paystack.mode'],
            ['value' => 'test', 'updated_by_user_id' => null],
        );
        IntegrationSetting::query()->updateOrCreate(
            ['key' => 'paystack.transfers_enabled'],
            ['value' => '1', 'updated_by_user_id' => null],
        );
    }

    public function test_bank_directory_is_imported_and_normalized_from_provider(): void
    {
        Http::fake([
            'api.paystack.co/bank*' => Http::response([
                'status' => true,
                'data' => [
                    ['name' => '  Test Bank  ', 'code' => '001'],
                    ['name' => 'Test Bank', 'code' => '001'],
                    ['name' => '', 'code' => '002'],
                    ['name' => 'Bad Code Bank', 'code' => 'not valid'],
                    ['name' => 'Second Bank', 'code' => '003'],
                ],
            ], 200),
        ]);

        $banks = app(BankPayoutService::class)->banks();

        $this->assertSame([
            ['name' => 'Second Bank', 'code' => '003'],
            ['name' => 'Test Bank', 'code' => '001'],
        ], $banks);
        $this->assertDatabaseCount('bank_directory', 2);
        $this->assertDatabaseHas('bank_directory', [
            'provider' => 'paystack',
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'enabled' => true,
        ]);
    }

    public function test_cached_bank_directory_is_available_when_provider_is_unreachable(): void
    {
        BankDirectoryEntry::query()->create([
            'provider' => 'paystack',
            'bank_code' => '001',
            'bank_name' => 'Cached Test Bank',
            'enabled' => true,
        ]);

        Http::fake([
            'api.paystack.co/bank*' => Http::response(['status' => false, 'message' => 'unavailable'], 503),
        ]);

        $this->assertSame([
            ['name' => 'Cached Test Bank', 'code' => '001'],
        ], app(BankPayoutService::class)->banks());
    }

    public function test_account_name_verification_must_match_the_requested_account_number(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/bank/resolve')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'account_name' => 'Some Account',
                        'account_number' => '9999999999',
                    ],
                ], 200);
            }

            return Http::response([
                'status' => true,
                'data' => [['name' => 'Test Bank', 'code' => '001']],
            ], 200);
        });

        $user = User::query()->create([
            'name' => 'Directory Test User',
            'email' => 'directory-test@example.test',
            'password' => 'not-a-real-password',
        ]);

        try {
            app(BankPayoutService::class)->addBeneficiary($user, '001', '0123456789');
            $this->fail('A mismatched provider account number must not be accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('did not verify this account', $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_beneficiaries', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/transferrecipient'));
    }
}
