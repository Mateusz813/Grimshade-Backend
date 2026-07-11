# Go-Live (publiczny cutover) — Grimshade backend

Cel: przełączyć całą grę na autorytatywny backend i **odciąć klientowi zapis** do Supabase.
Nieodwracalne (poza rollbackiem). Wykonuje **właściciel** na produkcji.

## Stan gotowości (2026-07-11)
- Werdykt audytu: **GO-WITH-GAPS**. Backend Pest 608 ✅, front typecheck+build+vitest 5302 ✅.
- Wszystkie mutacje idą przez backend w trybie backendu; lockdown SQL drift-proof + rollback.
- **Gaps (Faza 2, po cutoverze):** `mastery_points` i `character_weapon_skills` zamrożone
  (brak serwerowego writera). Reszta rankingów jest serwerowa. Zdecyduj: ukryć te 2 zakładki
  czy zostawić zamrożone do Fazy 2.

## Prerekwizyty (infra właściciela)
1. **Backend publicznie** — wdrożony pod HTTPS URL (np. `https://api.grimshade.pl`).
   Docker (`Dockerfile` + `docker-compose.yml`) i CI/CD (`.github/workflows/ci.yml`, deploy po SSH,
   gated na sekretach `DEPLOY_*`) są gotowe; potrzebny host + TLS + `.env` z creds Supabase.
2. **CORS** — w `.env` backendu: `CORS_ALLOWED_ORIGINS=https://<twoja-domena-vercel>` (i ew.
   `CORS_ALLOWED_ORIGINS_PATTERNS=#^https://.*\.vercel\.app$#` dla preview). Po zmianie: `php artisan config:clear`.
3. **Front (Vercel) env:**
   - `VITE_API_BASE_URL=https://<publiczny-backend>`
   - `VITE_BACKEND_DEFAULT=1`  ← włącza backend-mode DLA WSZYSTKICH (bez tego jest opt-in per localStorage).

## Sekwencja cutoveru (kolejność KRYTYCZNA)
1. **Pre-checks** — odpal `database/sql/2026_cutover_prechecks.sql` (zapytania 1 i 2) na prod.
   Potwierdź: tylko 5 znanych RPC mutuje; zero tabel gry poza `public`. Popraw lockdown jeśli trzeba.
2. **Backup** — pełny snapshot produkcyjnej Supabase (jedyna siatka pod REVOKE).
3. **Deploy backendu** na publiczny URL; sanity: `curl https://<backend>/api/v1/content/version` → 200.
4. **Deploy frontu** na Vercel z env z prerekwizytów (backend-mode DEFAULT-ON). Od tej chwili gracze
   piszą przez backend. Monitoruj kilka minut (logi backendu; brak błędów zapisu klienta).
5. **Finalny re-audyt** gotowości (opcjonalnie, jako ostatnia bramka).
6. **REVOKE** — odpal `database/sql/2026_client_write_lockdown.sql` na prod. Od teraz klient nie pisze.
7. **Post-check** — `2026_cutover_prechecks.sql` zapytanie 3 → 0 wierszy dla tabel gry.
8. **Smoke test na koncie TESTOWYM** (NIE Krasek): create/delete postaci, walka+loot, market
   kup/sprzedaj/edytuj/anuluj, guild create/join/accept/kick/leave + boss + skarbiec + odbiór nagrody,
   party create/join/leave/kick/handover, czat, arena, śmierć w walce, przegląd rankingów.
9. **Monitoring** — konsole klientów / logi Supabase pod kątem odmów zapisu (RLS/permission denied)
   na ścieżkach, które powinny iść przez backend.

## Rollback (jeśli smoke/monitoring pokaże zepsutą ścieżkę)
1. Vercel: cofnij `VITE_API_BASE_URL` / `VITE_BACKEND_DEFAULT` → redeploy (klient wraca na direct-Supabase).
2. Odpal zakomentowany ROLLBACK z `2026_client_write_lockdown.sql` (re-GRANT anon+authenticated + EXECUTE na 5 RPC).
3. Restore ze snapshotu (krok 2 sekwencji) TYLKO jeśli ucierpiała integralność danych.

## Po cutoverze
- Faza 2: mastery + weapon-skills autorytatywne (śledzenie kill→mastery i cios→skill-XP w serwerowym resolve) → odmraża 2 rankingi.
- Realtime broker (czat/party live) — obecnie odczyty zostają na Supabase Realtime; zapisy już przez backend.
