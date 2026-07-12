
DO $$
DECLARE t record;
BEGIN
  FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename LOOP
    EXECUTE format('REVOKE INSERT, UPDATE, DELETE ON public.%I FROM anon, authenticated;', t.tablename);
    RAISE NOTICE 'locked (brak zapisu klienta): public.%', t.tablename;
  END LOOP;
END $$;


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

