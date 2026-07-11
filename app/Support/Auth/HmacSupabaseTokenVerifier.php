<?php

declare(strict_types=1);

namespace App\Support\Auth;

use DateInterval;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Weryfikator HS256 dla dzisiejszych (legacy) tokenów Supabase GoTrue,
 * podpisanych symetrycznym sekretem projektu (SUPABASE_JWT_SECRET).
 *
 * Waliduje: podpis, exp/nbf/iat (z leeway), iss (${SUPABASE_URL}/auth/v1)
 * oraz aud (`authenticated`). Każdy błąd → InvalidTokenException → HTTP 401.
 */
final class HmacSupabaseTokenVerifier implements SupabaseTokenVerifier
{
    private ?Configuration $config = null;

    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $leewaySeconds,
        private readonly ClockInterface $clock,
    ) {
        // Świadomie NIE budujemy Configuration tutaj: przy pustym sekrecie
        // (np. lokalnie bez Supabase) instancja ma powstać bez wyjątku, a
        // dopiero verify() ma odrzucić — inaczej routes z auth dają 500 zamiast 401.
    }

    private function config(): Configuration
    {
        return $this->config ??= Configuration::forSymmetricSigner(
            new Sha256,
            InMemory::plainText($this->secret),
        );
    }

    public function verify(string $jwt): SupabaseUser
    {
        if ($this->secret === '') {
            throw new InvalidTokenException('SUPABASE_JWT_SECRET nie jest ustawiony.');
        }

        $jwt = trim($jwt);
        if ($jwt === '') {
            throw new InvalidTokenException('Pusty token.');
        }

        try {
            $token = $this->config()->parser()->parse($jwt);
        } catch (Throwable $e) {
            throw new InvalidTokenException('Nie udało się sparsować tokenu: '.$e->getMessage(), 0, $e);
        }

        if (! $token instanceof Plain) {
            throw new InvalidTokenException('Nieobsługiwany typ tokenu.');
        }

        $constraints = [
            new SignedWith($this->config()->signer(), $this->config()->signingKey()),
            new LooseValidAt($this->clock, new DateInterval('PT'.$this->leewaySeconds.'S')),
        ];
        if ($this->issuer !== '') {
            $constraints[] = new IssuedBy($this->issuer);
        }
        if ($this->audience !== '') {
            $constraints[] = new PermittedFor($this->audience);
        }

        try {
            $this->config()->validator()->assert($token, ...$constraints);
        } catch (Throwable $e) {
            throw new InvalidTokenException('Token nie przeszedł walidacji: '.$e->getMessage(), 0, $e);
        }

        $claims = $token->claims();
        $sub = $claims->get('sub');
        if (! is_string($sub) || $sub === '') {
            throw new InvalidTokenException('Brak claim `sub` (user_id).');
        }

        $email = $claims->get('email');
        $role = $claims->get('role');

        return new SupabaseUser(
            id: $sub,
            email: is_string($email) ? $email : null,
            role: is_string($role) ? $role : 'authenticated',
            claims: $claims->all(),
        );
    }
}
