# Grimshade — Backend (Laravel)

Autorytatywny serwer gry Grimshade. Przejmuje logikę i walidację z klienta (React),
staje się **jedynym zapisującym** do bazy Supabase i zamyka powierzchnię oszustw
(forge gold/itemów/poziomu, fałszywe wygrane areny, kupno bez płacenia na markecie).

- **Supabase** zostaje jako: Auth (GoTrue) + PostgreSQL + Realtime.
- **Laravel** = autorytet: przelicza każdy wynik, waliduje, zapisuje.
- Pełny plan migracji (fazy, kontrakt API, parytet logiki, lockdown) — patrz plik planu
  w `~/.claude/plans/` repo frontu.

Stack: **PHP 8.3 · Laravel 11 · Pest 3**. Auth: `lcobucci/jwt` (weryfikacja JWT Supabase).

---

## Status: Faza 0 (scaffold + auth + pierwszy endpoint)

Zrobione:
- Szkielet Laravel 11, warstwy `app/Domain` (logika gry), `app/Services`, `app/Repositories`.
- Weryfikacja JWT Supabase (HS256) — `VerifySupabaseJwt` + `EnsureOwnsCharacter`.
- `GET /api/v1/characters` — E2E (200 własne postaci, 401 zły token, izolacja userów).
- Testy: Pest (unit weryfikatora + feature endpointu), Pint (styl), CI (GitHub Actions).

Kolejne fazy (patrz plan): pipeline treści + golden-vectory → port logiki `src/systems`
→ znormalizowane tabele → intent-endpointy (`/combat/resolve`, `/market/.../buy`, `/arena/match`)
→ Realtime → lockdown Supabase → cutover.

---

## Setup lokalny

> **Dev loop front + backend z live-edit** (Docker :8088 + `npm run dev` :5170, zmiany w
> kodzie backendu widoczne od razu) — patrz [LOCAL_DEV.md](LOCAL_DEV.md).

Domyślnie działa **offline na sqlite** (bez creds Supabase) — testy i dev bez bazy zewnętrznej.

```bash
composer install
cp .env.example .env         # lub użyj istniejącego .env (sqlite)
php artisan key:generate
php artisan test             # albo: ./vendor/bin/pest
```

### Praca na realnej bazie Supabase

1. W Supabase utwórz rolę aplikacyjną (jednorazowo, jako `postgres`):
   ```sql
   CREATE ROLE grimshade_app LOGIN PASSWORD '<silne-haslo>';
   ALTER ROLE grimshade_app BYPASSRLS;
   GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO grimshade_app;
   -- (lockdown klienta: REVOKE ... FROM anon, authenticated — dopiero na cutover)
   ```
2. W `.env` ustaw `DB_CONNECTION=pgsql` + `DB_*` (host = **session pooler** `...pooler.supabase.com:5432`,
   user = `grimshade_app.<project-ref>`, `DB_SSLMODE=require`).
3. Auth: `SUPABASE_URL` + `SUPABASE_JWT_SECRET` (Dashboard → Settings → API → JWT Secret).

> **Pooling:** zacznij od session-mode (5432 przez pooler). Jeśli pod obciążeniem PHP-FPM
> pojawi się błąd „prepared statement already exists", rozważ transaction-mode (6543) — do
> zweryfikowania z realnym ruchem.

> **Uwaga:** migracja `characters` jest **idempotentna** (`Schema::hasTable`) — na realnej
> bazie Supabase (gdzie tabela istnieje) to no-op. Nie tworzy ani nie modyfikuje istniejących
> tabel produkcyjnych.

---

## Zmienne środowiskowe (kluczowe)

| Zmienna | Opis |
|---|---|
| `DB_CONNECTION` | `sqlite` (lokalnie) lub `pgsql` (Supabase) |
| `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` | połączenie do Postgresa Supabase (rola `grimshade_app`) |
| `DB_SSLMODE` | `require` dla Supabase |
| `SUPABASE_URL` | `https://<ref>.supabase.co` — buduje oczekiwane `iss` |
| `SUPABASE_JWT_DRIVER` | `hmac` (dziś) lub `jwks` (migracja na klucze asymetryczne) |
| `SUPABASE_JWT_SECRET` | sekret HS256 projektu (tryb `hmac`) |

---

## Komendy

```bash
php artisan test              # Pest (unit + feature) na sqlite in-memory
./vendor/bin/pest --coverage  # z pokryciem (wymaga pcov lub xdebug)
./vendor/bin/pint             # auto-format (Laravel Pint)
./vendor/bin/pint --test      # sprawdzenie stylu (jak w CI)
php artisan route:list        # lista tras
```

Coverage: cel **≥90% na `app/Domain/**`** (logika gry), egzekwowany jako ratchet w CI,
gdy dojdzie logika domenowa. Kontrolery/glue nie są ścigane do 100%.

---

## Architektura (skrót)

```
app/
  Http/Controllers/Api/    # cienkie kontrolery
  Http/Middleware/         # VerifySupabaseJwt, EnsureOwnsCharacter
  Http/Resources/          # kształt odpowiedzi = ICharacter z frontu
  Domain/                  # PRZENIESIONA LOGIKA GRY (czyste PHP, bez Eloquent/RNG)
  Services/                # orkiestracja + granica transakcji
  Repositories/            # dostęp do danych
  Support/Auth/            # weryfikacja JWT Supabase
```

Zasada nadrzędna: **kontrolery nigdy nie ufają wartościom z body** (gold/level/wynik) —
tożsamość bierze się z tokenu, a stan z bazy; wynik liczy serwer.
