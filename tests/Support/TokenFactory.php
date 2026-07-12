<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

final class TokenFactory
{
    public static function forUser(string $userId, array $overrides = []): string
    {
        $jwt = config('supabase.jwt');
        $config = Configuration::forSymmetricSigner(
            new Sha256,
            InMemory::plainText((string) $jwt['secret']),
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $token = $config->builder()
            ->issuedBy($overrides['iss'] ?? (string) $jwt['issuer'])
            ->permittedFor($overrides['aud'] ?? (string) $jwt['audience'])
            ->relatedTo($userId)
            ->issuedAt($overrides['iat'] ?? $now)
            ->expiresAt($overrides['exp'] ?? $now->modify('+1 hour'))
            ->withClaim('email', $overrides['email'] ?? 'player@grimshade.pl')
            ->withClaim('role', 'authenticated')
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }
}
