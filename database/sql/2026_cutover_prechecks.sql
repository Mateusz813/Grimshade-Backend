
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

SELECT table_schema, table_name
FROM information_schema.tables
WHERE table_type = 'BASE TABLE'
  AND table_schema NOT IN (
    'pg_catalog', 'information_schema', 'auth', 'storage', 'realtime', 'vault',
    'extensions', 'graphql', 'graphql_public', 'pgsodium', 'pgsodium_masks',
    'supabase_functions', 'supabase_migrations', 'net', 'cron', '_realtime'
  )
ORDER BY table_schema, table_name;

SELECT table_name, grantee, privilege_type
FROM information_schema.role_table_grants
WHERE grantee IN ('anon', 'authenticated')
  AND privilege_type IN ('INSERT', 'UPDATE', 'DELETE')
  AND table_schema = 'public'
ORDER BY table_name, grantee, privilege_type;
