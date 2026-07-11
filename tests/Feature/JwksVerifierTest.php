<?php

declare(strict_types=1);

use App\Support\Auth\InvalidTokenException;
use App\Support\Auth\JwksSupabaseTokenVerifier;
use App\Support\Auth\SystemClock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key\InMemory;

const JWKS_URL = 'https://proj.supabase.co/auth/v1/.well-known/jwks.json';
const ISS = 'https://proj.supabase.co/auth/v1';
const AUD = 'authenticated';

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** @return array{0: string, 1: array<string, mixed>, 2: string} [privatePem, jwk, publicPem] */
function makeEcKey(string $kid): array
{
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($res, $privatePem);
    $d = openssl_pkey_get_details($res);

    $jwk = [
        'kty' => 'EC', 'crv' => 'P-256', 'kid' => $kid, 'alg' => 'ES256',
        'x' => b64url($d['ec']['x']), 'y' => b64url($d['ec']['y']),
    ];

    return [$privatePem, $jwk, $d['key']];
}

/** @param array<string, mixed> $overrides */
function mintEs256(string $privatePem, string $publicPem, string $kid, array $overrides = []): string
{
    $config = Configuration::forAsymmetricSigner(new EcdsaSha256, InMemory::plainText($privatePem), InMemory::plainText($publicPem));
    $now = new DateTimeImmutable;
    $b = $config->builder()
        ->withHeader('kid', $kid)
        ->issuedBy($overrides['iss'] ?? ISS)
        ->permittedFor($overrides['aud'] ?? AUD)
        ->relatedTo($overrides['sub'] ?? 'user-1')
        ->issuedAt($now)
        ->expiresAt($overrides['exp'] ?? $now->modify('+1 hour'));
    if (isset($overrides['email'])) {
        $b = $b->withClaim('email', $overrides['email']);
    }

    return $b->getToken($config->signer(), $config->signingKey())->toString();
}

function jwksVerifier(string $secret = ''): JwksSupabaseTokenVerifier
{
    return new JwksSupabaseTokenVerifier(JWKS_URL, $secret, ISS, AUD, 30, new SystemClock);
}

beforeEach(function () {
    Cache::flush();
});

it('verifies a valid ES256 token via JWKS', function () {
    [$priv, $jwk, $pub] = makeEcKey('kid-1');
    Http::fake([JWKS_URL => Http::response(['keys' => [$jwk]])]);
    $jwt = mintEs256($priv, $pub, 'kid-1', ['sub' => 'user-42', 'email' => 'a@b.c']);

    $user = jwksVerifier()->verify($jwt);

    expect($user->id)->toBe('user-42');
    expect($user->email)->toBe('a@b.c');
    expect($user->role)->toBe('authenticated');
});

it('rejects an expired ES256 token', function () {
    [$priv, $jwk, $pub] = makeEcKey('kid-1');
    Http::fake([JWKS_URL => Http::response(['keys' => [$jwk]])]);
    $jwt = mintEs256($priv, $pub, 'kid-1', ['exp' => new DateTimeImmutable('-1 hour')]);

    expect(fn () => jwksVerifier()->verify($jwt))->toThrow(InvalidTokenException::class);
});

it('rejects a token whose kid is absent from the JWKS', function () {
    [$priv, $jwk, $pub] = makeEcKey('kid-1');
    Http::fake([JWKS_URL => Http::response(['keys' => [$jwk]])]);
    $jwt = mintEs256($priv, $pub, 'kid-OTHER');

    expect(fn () => jwksVerifier()->verify($jwt))->toThrow(InvalidTokenException::class);
});

it('rejects an ES256 token signed by a different key (same kid)', function () {
    [, $jwk1] = makeEcKey('kid-1');
    [$priv2, , $pub2] = makeEcKey('kid-1');
    Http::fake([JWKS_URL => Http::response(['keys' => [$jwk1]])]);
    $jwt = mintEs256($priv2, $pub2, 'kid-1');

    expect(fn () => jwksVerifier()->verify($jwt))->toThrow(InvalidTokenException::class);
});

it('rejects a wrong issuer / audience', function () {
    [$priv, $jwk, $pub] = makeEcKey('kid-1');
    Http::fake([JWKS_URL => Http::response(['keys' => [$jwk]])]);
    $jwt = mintEs256($priv, $pub, 'kid-1', ['iss' => 'https://evil/auth/v1']);

    expect(fn () => jwksVerifier()->verify($jwt))->toThrow(InvalidTokenException::class);
});

it('falls back to HS256 with the shared secret for legacy tokens', function () {
    $secret = 'legacy-shared-secret-that-is-at-least-32-bytes-long-0123456789';
    $config = Configuration::forSymmetricSigner(new HmacSha256, InMemory::plainText($secret));
    $now = new DateTimeImmutable;
    $jwt = $config->builder()
        ->issuedBy(ISS)->permittedFor(AUD)->relatedTo('user-hs')
        ->issuedAt($now)->expiresAt($now->modify('+1 hour'))
        ->getToken($config->signer(), $config->signingKey())->toString();

    Http::fake([JWKS_URL => Http::response(['keys' => []])]); // JWKS not needed for HS256
    $user = jwksVerifier($secret)->verify($jwt);

    expect($user->id)->toBe('user-hs');
});
