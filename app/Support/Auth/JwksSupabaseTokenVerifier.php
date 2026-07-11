<?php

declare(strict_types=1);

namespace App\Support\Auth;

use DateInterval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Weryfikator ASYMETRYCZNY (ES256 przez JWKS Supabase) + fallback HS256.
 *
 * Po włączeniu asymetrycznych kluczy JWT Supabase (GoTrue) podpisuje tokeny
 * algorytmem ES256 kluczem, którego publiczna część jest w
 * `${SUPABASE_URL}/auth/v1/.well-known/jwks.json`. Legacy tokeny HS256 (jeszcze
 * aktywne sesje) weryfikujemy współdzielonym sekretem — dzięki temu przełączenie
 * na asymetrię nie wylogowuje graczy z aktywnymi tokenami.
 *
 * Waliduje: podpis, exp/nbf/iat (z leeway), iss (${SUPABASE_URL}/auth/v1) oraz
 * aud (`authenticated`). Każdy błąd → InvalidTokenException → HTTP 401.
 */
final class JwksSupabaseTokenVerifier implements SupabaseTokenVerifier
{
    private const CACHE_KEY = 'supabase.jwks.keys';

    public function __construct(
        private readonly string $jwksUrl,
        private readonly string $secret, // fallback HS256 (legacy)
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $leewaySeconds,
        private readonly ClockInterface $clock,
    ) {}

    public function verify(string $jwt): SupabaseUser
    {
        $jwt = trim($jwt);
        if ($jwt === '') {
            throw new InvalidTokenException('Pusty token.');
        }

        try {
            $token = (new Parser(new JoseEncoder))->parse($jwt);
        } catch (Throwable $e) {
            throw new InvalidTokenException('Nie udało się sparsować tokenu: '.$e->getMessage(), 0, $e);
        }
        if (! $token instanceof Plain) {
            throw new InvalidTokenException('Nieobsługiwany typ tokenu.');
        }

        [$signer, $key] = $this->signerAndKey((string) $token->headers()->get('alg'), $token);

        $constraints = [
            new SignedWith($signer, $key),
            new LooseValidAt($this->clock, new DateInterval('PT'.$this->leewaySeconds.'S')),
        ];
        if ($this->issuer !== '') {
            $constraints[] = new IssuedBy($this->issuer);
        }
        if ($this->audience !== '') {
            $constraints[] = new PermittedFor($this->audience);
        }

        try {
            (new Validator)->assert($token, ...$constraints);
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

    /**
     * @return array{0: Signer, 1: Key}
     */
    private function signerAndKey(string $alg, Plain $token): array
    {
        if ($alg === 'HS256') {
            if ($this->secret === '') {
                throw new InvalidTokenException('Token HS256, a SUPABASE_JWT_SECRET nie jest ustawiony.');
            }

            return [new HmacSha256, InMemory::plainText($this->secret)];
        }

        if ($alg === 'ES256') {
            $kid = $token->headers()->get('kid');
            if (! is_string($kid) || $kid === '') {
                throw new InvalidTokenException('Token ES256 bez `kid`.');
            }

            return [new EcdsaSha256, InMemory::plainText($this->ecPublicKeyPem($kid))];
        }

        throw new InvalidTokenException("Nieobsługiwany alg tokenu: {$alg}.");
    }

    private function ecPublicKeyPem(string $kid): string
    {
        $jwk = $this->findJwk($kid);
        if ($jwk === null) {
            // Klucz mógł się zrotować — odśwież JWKS raz i spróbuj ponownie.
            Cache::forget(self::CACHE_KEY);
            $jwk = $this->findJwk($kid);
        }
        if ($jwk === null) {
            throw new InvalidTokenException("Brak klucza JWKS dla kid={$kid}.");
        }
        if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') {
            throw new InvalidTokenException('Nieobsługiwany typ klucza JWKS (oczekiwano EC P-256).');
        }

        $x = self::b64urlDecode((string) ($jwk['x'] ?? ''));
        $y = self::b64urlDecode((string) ($jwk['y'] ?? ''));
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new InvalidTokenException('Nieprawidłowe współrzędne klucza EC.');
        }

        // SubjectPublicKeyInfo dla P-256 = stały prefiks DER (OID ecPublicKey +
        // OID prime256v1 + BIT STRING) + punkt nieskompresowany: 0x04 || x || y.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$x.$y;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            .'-----END PUBLIC KEY-----'."\n";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findJwk(string $kid): ?array
    {
        foreach ($this->jwks() as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
                return $jwk;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jwks(): array
    {
        if ($this->jwksUrl === '') {
            throw new InvalidTokenException('SUPABASE_JWKS_URL nie jest ustawiony.');
        }

        /** @var array<int, array<string, mixed>> $keys */
        $keys = Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            try {
                $res = Http::timeout(10)->acceptJson()->get($this->jwksUrl);
            } catch (Throwable $e) {
                throw new InvalidTokenException('Nie udało się pobrać JWKS: '.$e->getMessage(), 0, $e);
            }
            if (! $res->ok()) {
                throw new InvalidTokenException('JWKS zwrócił HTTP '.$res->status());
            }
            $k = $res->json('keys');

            return is_array($k) ? $k : [];
        });

        return $keys;
    }

    private static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($s, true);
    }
}
