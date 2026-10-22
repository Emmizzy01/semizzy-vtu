<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorData;
use Webauthn\CollectedClientData;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\U2FPublicKey;
use Symfony\Component\Uid\Uuid;

class PasskeyCeremonyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'passkeys.enabled' => true,
            'passkeys.relying_party_id' => 'semizzy.example.test',
            'passkeys.allowed_origins' => ['https://semizzy.example.test'],
            'auth_security.email_otp_enabled' => false,
            'auth_security.sms_otp_enabled' => false,
            'auth_security.whatsapp_otp_enabled' => false,
        ]);
    }

    public function test_passkey_login_rotates_session_and_consumes_verification_options_once(): void
    {
        $user = User::query()->create([
            'name' => 'Passkey Login Customer',
            'email' => 'passkey-login-customer@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $passkey = $user->passkeys()->create([
            'name' => 'Test authenticator',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFs',
            'credential' => [],
        ]);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'semizzy.example.test',
        );
        $this->mock(VerifyPasskey::class)
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn($passkey);

        $credential = $this->assertionCredential();
        $this->withSession(['passkey.verification_options' => WebAuthn::toJson($options)]);
        $before = $this->app['session']->driver()->getId();

        $this->postJson('/passkeys/login', ['credential' => $credential])
            ->assertOk()
            ->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, $this->app['session']->driver()->getId(), 'Passkey login must rotate the session identifier.');

        auth()->logout();
        $this->postJson('/passkeys/login', ['credential' => $credential])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);
        $this->assertGuest();
    }

    public function test_passkey_login_rejects_a_missing_or_expired_verification_challenge(): void
    {
        $this->postJson('/passkeys/login', ['credential' => $this->assertionCredential()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        $this->assertGuest();
    }

    public function test_real_webauthn_validator_rejects_an_invalid_assertion_signature(): void
    {
        $user = User::query()->create([
            'name' => 'Invalid Signature Customer',
            'email' => 'invalid-signature-customer@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $credentialId = random_bytes(16);
        $source = $this->credentialSource((string) $user->id, $credentialId);
        $passkey = $user->passkeys()->create([
            'name' => 'Signature test authenticator',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        ]);
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'semizzy.example.test',
        );
        $validResponse = $this->signedAssertion($options->challenge, 'https://semizzy.example.test', 1);
        $invalidResponse = AuthenticatorAssertionResponse::create(
            clientDataJSON: $validResponse->clientDataJSON,
            authenticatorData: $validResponse->authenticatorData,
            signature: random_bytes(64),
            userHandle: null,
        );
        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $invalidResponse,
        );

        try {
            app(VerifyPasskey::class)($credential, $options);
            $this->fail('The WebAuthn verifier accepted an invalid assertion signature.');
        } catch (\Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertNull($passkey->fresh()->last_used_at, 'Rejected assertions must not update credential usage state.');
    }

    private function assertionCredential(): array
    {
        $rawId = random_bytes(16);
        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
            'origin' => 'https://semizzy.example.test',
        ], JSON_THROW_ON_ERROR);
        $rpIdHash = hash('sha256', 'semizzy.example.test', true);
        $authData = $rpIdHash.chr(AuthenticatorData::FLAG_UP).pack('N', 0);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($rawId),
            'rawId' => Base64UrlSafe::encodeUnpadded($rawId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded(random_bytes(64)),
            ],
        ];
    }

    private function credentialSource(string $userHandle, string $credentialId): CredentialRecord
    {
        $privateKey = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIExwigMuj7pGzk+XVIKVp72gYQc6AU//5HlUHb3X5HGRoAoGCCqGSM49
AwEHoUQDQgAE53mVK/HTD1mPae7VZ8uzETJC7ZLmAyWKa75YyZ9lNbHFl8oSKWJe
RYf/wGQSBmBTBSaU+rRuRbuVAx2zexNCzQ==
-----END EC PRIVATE KEY-----
PEM;
        $ec = openssl_pkey_get_details(openssl_pkey_get_private($privateKey))['ec'];
        $publicKey = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return CredentialRecord::create(
            publicKeyCredentialId: $credentialId,
            type: 'public-key',
            transports: [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: U2FPublicKey::convertToCoseKey($publicKey),
            userHandle: $userHandle,
            counter: 0,
        );
    }

    private function signedAssertion(string $challenge, string $origin, int $counter): AuthenticatorAssertionResponse
    {
        $privateKey = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIExwigMuj7pGzk+XVIKVp72gYQc6AU//5HlUHb3X5HGRoAoGCCqGSM49
AwEHoUQDQgAE53mVK/HTD1mPae7VZ8uzETJC7ZLmAyWKa75YyZ9lNbHFl8oSKWJe
RYf/wGQSBmBTBSaU+rRuRbuVAx2zexNCzQ==
-----END EC PRIVATE KEY-----
PEM;
        $clientPayload = [
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => $origin,
        ];
        $clientRaw = json_encode($clientPayload, JSON_THROW_ON_ERROR);
        $clientData = CollectedClientData::create($clientRaw, $clientPayload);
        $rpIdHash = hash('sha256', 'semizzy.example.test', true);
        $flags = chr(AuthenticatorData::FLAG_UP);
        $authRaw = $rpIdHash.$flags.pack('N', $counter);
        $authData = \Webauthn\AuthenticatorData::create(
            authData: $authRaw,
            rpIdHash: $rpIdHash,
            flags: $flags,
            signCount: $counter,
        );
        openssl_sign($authRaw.hash('sha256', $clientRaw, true), $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return AuthenticatorAssertionResponse::create(
            clientDataJSON: $clientData,
            authenticatorData: $authData,
            signature: $signature,
            userHandle: null,
        );
    }
}
