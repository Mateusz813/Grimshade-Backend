-- ============================================================================
-- CUTOVER PRE-CHECKS — uruchom na PRODUKCYJNEJ Supabase (SQL Editor) PRZED
-- odpaleniem lockdownu (2026_client_write_lockdown.sql). Wszystkie są READ-ONLY.
-- Domykają 2 luki, których audyt nie mógł sprawdzić z repo (żyją w bazie).
-- ============================================================================

-- 1) Funkcje public z EXECUTE dla anon/authenticated.
--    Potwierdź, że MUTUJĄ dane TYLKO te znane 5: bump_arena_death, bump_arena_kill,
--    bump_market_sale, buy_market_listing, buy_item. Jeśli jest tu JAKAKOLWIEK
--    inna funkcja, która pisze do bazy — DOPISZ ją do listy RPC w lockdownie
--    (inaczej zostaje wywoływalna przez cheatera po cutoverze).
SELECT p.proname AS function_name,
       pg_get_function_identity_arguments(p.oid) AS args,
       array_agg(DISTINCT r.grantee ORDER BY r.grantee) AS grantees
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
JOIN information_schema.routine_privileges r
  ON r.specific_schema = 'public'
 AND r.routine_name = p.proname
 AND r.privilege_type = 'EXECUTE'
WHERE n.nspname = 'public'
  AND r.grantee IN ('anon', 'authenticated')
GROUP BY p.proname, p.oid
ORDER BY p.proname;

-- 2) Tabele gry POZA schematem public. Lockdown odcina zapis tylko w public,
--    więc jeśli któraś tabela gry żyje w innym schemacie — zostaje otwarta.
--    Oczekiwany wynik: SAME schematy systemowe/Supabase (auth/storage/realtime/...),
--    ZERO tabel gry (characters, game_saves, market_listings, guild_*, party_*, ...).
SELECT table_schema, table_name
FROM information_schema.tables
WHERE table_type = 'BASE TABLE'
  AND table_schema NOT IN (
    'pg_catalog', 'information_schema', 'auth', 'storage', 'realtime', 'vault',
    'extensions', 'graphql', 'graphql_public', 'pgsodium', 'pgsodium_masks',
    'supabase_functions', 'supabase_migrations', 'net', 'cron', '_realtime'
  )
ORDER BY table_schema, table_name;

-- 3) (URUCHOM PO LOCKDOWNIE) Weryfikacja: co anon/authenticated NADAL mogą pisać
--    w public. Oczekiwany wynik dla tabel gry: 0 wierszy (SELECT zostaje osobno).
SELECT table_name, grantee, privilege_type
FROM information_schema.role_table_grants
WHERE grantee IN ('anon', 'authenticated')
  AND privilege_type IN ('INSERT', 'UPDATE', 'DELETE')
  AND table_schema = 'public'
ORDER BY table_name, grantee, privilege_type;
