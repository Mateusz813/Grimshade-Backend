<?php

declare(strict_types=1);

use App\Support\Auth\HmacSupabaseTokenVerifier;
use App\Support\Auth\InvalidTokenException;
use App\Support\Auth\SupabaseUser;
use App\Support\Auth\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

const TEST_SECRET = 'test-super-secret-jwt-key-for-testing-only-min-256-bits-long!!';
const TEST_ISS = 'https://test-project.supabase.co/auth/v1';
const TEST_AUD = 'authenticated';

/**
 * Buduje token HS256 pod testy. Domyślnie ważny; parametry pozwalają
 * wyprodukować warianty złe (inny sekret/iss/aud/exp/sub).
 */
function makeToken(array $overrides = []): string
{
    $secret = $overrides['secret'] ?? TEST_SECRET;
    $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText($secret));

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $builder = $config->builder()
        ->issuedBy($overrides['iss'] ?? TEST_ISS)
        ->permittedFor($overrides['aud'] ?? TEST_AUD)
        ->issuedAt($overrides['iat'] ?? $now)
        ->expiresAt($overrides['exp'] ?? $now->modify('+1 hour'))
        ->withClaim('email', $overrides['email'] ?? 'player@grimshade.pl')
        ->withClaim('role', $overrides['role'] ?? 'authenticated');

    // array_key_exists, nie ??, bo `sub => null` ma ZNACZYĆ „usuń claim".
    $sub = array_key_exists('sub', $overrides)
        ? $overrides['sub']
        : '11111111-1111-1111-1111-111111111111';
    if ($sub !== null) {
        $builder = $builder->relatedTo($sub);
    }

    return $builder->getToken($config->signer(), $config->signingKey())->toString();
}

function verifier(): HmacSupabaseTokenVerifier
{
    return new HmacSupabaseTokenVerifier(
        secret: TEST_SECRET,
        issuer: TEST_ISS,
        audience: TEST_AUD,
        leewaySeconds: 30,
        clock: new SystemClock,
    );
}

it('accepts a valid token and returns the SupabaseUser', function () {
    $user = verifier()->verify(makeToken(['sub' => 'abc-123', 'email' => 'a@b.c']));

    expect($user)->toBeInstanceOf(SupabaseUser::class)
        ->and($user->id)->toBe('abc-123')
        ->and($user->email)->toBe('a@b.c')
        ->and($user->role)->toBe('authenticated');
});

it('rejects an expired token', function () {
    $past = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    verifier()->verify(makeToken([
        'iat' => $past->modify('-2 hours'),
        'exp' => $past->modify('-1 hour'),
    ]));
})->throws(InvalidTokenException::class);

it('rejects a token signed with a different secret', function () {
    verifier()->verify(makeToken(['secret' => 'a-totally-different-secret-key-also-long-enough-to-pass!!']));
})->throws(InvalidTokenException::class);

it('rejects a token with the wrong audience', function () {
    verifier()->verify(makeToken(['aud' => 'anon']));
})->throws(InvalidTokenException::class);

it('rejects a token with the wrong issuer', function () {
    verifier()->verify(makeToken(['iss' => 'https://evil.example.com/auth/v1']));
})->throws(InvalidTokenException::class);

it('rejects a token without a sub claim', function () {
    verifier()->verify(makeToken(['sub' => null]));
})->throws(InvalidTokenException::class);

it('rejects a malformed token string', function () {
    verifier()->verify('not-a-jwt');
})->throws(InvalidTokenException::class);

it('rejects an empty bearer value', function () {
    verifier()->verify('   ');
})->throws(InvalidTokenException::class);
