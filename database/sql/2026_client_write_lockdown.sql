-- ============================================================================
-- FAZA 10 — LOCKDOWN: odebranie klientowi prawa ZAPISU do Supabase.
-- ============================================================================
-- Sedno całego projektu. Po tym skrypcie cheater z anon key + swoim JWT może
-- tylko CZYTAĆ; każdy zapis musi przejść przez Laravel, który przelicza autorytet.
--
-- Backend łączy się jako `postgres.<projekt>` (właściciel/superuser poolera) —
-- ma pełny zapis NIEZALEŻNIE od tych grantów, więc NIE tworzymy osobnej roli
-- `grimshade_app`. Ten skrypt WYŁĄCZNIE odbiera klientowi (anon/authenticated)
-- prawo zapisu. SELECT klienta ZOSTAJE (leaderboard/czat/rostery/deaths czytane wprost).
--
-- ⚠️ URUCHAMIAĆ DOPIERO NA CUTOVER, ATOMOWO z przełączeniem frontu na Laravel
--    (VITE_API_BASE_URL). Wcześniej stary klient pisze wprost — ten REVOKE go
--    zepsuje. Kolejność: (1) deploy nowego frontu na Laravel → (2) ten skrypt.
--
-- Wykonać w Supabase → SQL Editor jako właściciel (postgres). Idempotentny.
-- ROLLBACK na dole (gdyby trzeba było wrócić do bezpośrednich zapisów klienta).
-- ============================================================================

-- 1) Odbierz DML klientowi na WSZYSTKICH tabelach schematu `public`.
--    DRIFT-PROOF: obejmuje też tabele spoza jawnej listy (utworzone ręcznie w
--    konsoli / legacy) — żadna client-writable tabela nie zostaje otwarta.
--    Supabase trzyma swoje tabele w schematach auth/storage/realtime/... (nie public),
--    więc to dotyka tylko tabel gry. RAISE NOTICE wypisuje każdą zablokowaną tabelę
--    (audyt na żywo tego, co realnie się zamyka).
DO $$
DECLARE t record;
BEGIN
  FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename LOOP
    EXECUTE format('REVOKE INSERT, UPDATE, DELETE ON public.%I FROM anon, authenticated;', t.tablename);
    RAISE NOTICE 'locked (brak zapisu klienta): public.%', t.tablename;
  END LOOP;
END $$;

-- Referencyjnie, znane tabele gry (dokumentacja; lista dynamiczna wyżej i tak je obejmuje):
--   characters, inventory, game_saves, character_skills, character_weapon_skills,
--   character_deaths, character_death_totals, shop_items, messages,
--   market_listings, market_sale_notifications, guilds, guild_members, guild_boss_state,
--   guild_boss_attempts, guild_boss_contributions, guild_treasury_items, guild_treasury_logs,
--   guild_join_requests, parties, party_members, session_locks, offline_sessions.

-- 2) Odbierz EXECUTE na klienckich RPC (cross-player pisanie / dupe). Stają się
--    intent-endpointami w Laravel. OVERLOAD-SAFE: iterujemy po konkretnych
--    sygnaturach z pg_proc, więc przeciążona funkcja NIE wywali całego bloku.
DO $$
DECLARE r record;
BEGIN
  FOR r IN
    SELECT p.oid::regprocedure AS sig
    FROM pg_proc p
    JOIN pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname = 'public'
      AND p.proname = ANY(ARRAY[
        'bump_arena_death', 'bump_arena_kill', 'bump_market_sale', 'buy_market_listing', 'buy_item'
      ])
  LOOP
    EXECUTE format('REVOKE EXECUTE ON FUNCTION %s FROM anon, authenticated;', r.sig);
    RAISE NOTICE 'revoked EXECUTE: %', r.sig;
  END LOOP;
END $$;

-- ============================================================================
-- ROLLBACK (przywraca bezpośrednie zapisy klienta — sparować z flipem
-- VITE_API_BASE_URL z powrotem na Supabase). Symetryczny do REVOKE powyżej.
-- RLS nadal gejtuje anon — grant przywraca tylko MOŻLIWOŚĆ zapisu tam, gdzie
-- polityki na to pozwalają. Odkomentować i uruchomić w razie potrzeby:
-- ============================================================================
-- DO $$
-- DECLARE t record;
-- BEGIN
--   FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename LOOP
--     EXECUTE format('GRANT INSERT, UPDATE, DELETE ON public.%I TO anon, authenticated;', t.tablename);
--   END LOOP;
-- END $$;
--
-- DO $$
-- DECLARE r record;
-- BEGIN
--   FOR r IN
--     SELECT p.oid::regprocedure AS sig
--     FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
--     WHERE n.nspname = 'public'
--       AND p.proname = ANY(ARRAY['bump_arena_death','bump_arena_kill','bump_market_sale','buy_market_listing','buy_item'])
--   LOOP
--     EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO anon, authenticated;', r.sig);
--   END LOOP;
-- END $$;
