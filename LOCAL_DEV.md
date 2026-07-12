# Grimshade — lokalny dev loop (front + backend, live-edit)

Cel: odpalasz front (`npm run dev`) i backend (kontenery), a **zmiany w kodzie backendu
widzisz u siebie na localhoscie od razu — bez restartu i bez rebuildu**. Baza pozostaje
**produkcyjna Supabase** (świadomie — patrz ⚠️ niżej), więc nie potrzebujesz lokalnego
Postgresa ani dumpa.

## Co jest lokalne, a co produkcyjne w tym trybie

| Element | Gdzie leci | Uwaga |
|---|---|---|
| Kod backendu (PHP/Laravel) | **lokalnie** w Dockerze (`:8088`) | to iterujesz — live-edit |
| Frontend (React/Vite) | **lokalnie** `npm run dev` (`:5170`) | HMR, natychmiast |
| Akcje gry (walka, market, itemy, commit stanu) | front → **lokalny backend** `:8088` | dzięki `VITE_BACKEND_DEFAULT=1` |
| Logowanie / Auth (GoTrue) | **produkcyjne** Supabase | prawdziwe konta |
| Lista postaci, odczyty, Realtime, czat | **produkcyjne** Supabase | front czyta wprost z Supabase |
| Zapisy z backendu (baza) | **produkcyjne** Supabase (przez lokalny backend) | ⚠️ realne dane |

Innymi słowy: **lokalny jest tylko kod** (front + backend). Dane i tożsamość są prawdziwe.

## Wymagania

- Docker Desktop uruchomiony.
- Node ≥ 22.12 (masz), `npm` w repo frontu.
- Backend `.env` wypełniony realnymi danymi Supabase (DB_* + `SUPABASE_JWT_SECRET`) — już jest.

## Start (2 terminale)

```bash
# Terminal 1 — backend (nginx :8088 → php-fpm → produkcyjna Supabase)
cd ~/Desktop/Grimshade/grimshade-backend
docker compose up -d          # --build tylko gdy zmieniłeś Dockerfile/zależności

# Terminal 2 — frontend (Vite :5170)
cd ~/Desktop/Grimshade/grimshade
npm run dev
```

Wejdź na http://localhost:5170. Front jest już wpięty w lokalny backend
(`grimshade/.env.local`: `VITE_API_BASE_URL=http://localhost:8088` + `VITE_BACKEND_DEFAULT=1`).

> Jeśli `npm run dev` już chodziło, **zrestartuj je** po zmianie `.env.local` — Vite czyta
> env tylko przy starcie. Alternatywa bez restartu: w DevTools →
> `localStorage.setItem('grimshade_backend_mode','1'); location.reload()`.

## Pętla „edytuję backend → widzę u siebie"

Katalog kodu jest bind-mountowany do kontenera (`.:/var/www/html`), a opcache ma
`validate_timestamps=On` / `revalidate_freq=2`, więc:

1. Edytujesz plik PHP (kontroler, `app/Domain/...`, trasa w `routes/api/*.php`).
2. **W ~2 s** kontener serwuje nowy kod — bez restartu.
3. Wyzwalasz akcję w grze na froncie (front jest w trybie backendu) **albo** uderzasz curl-em.
4. Widzisz nowe zachowanie.

Szybki test bez frontu i bez logowania:

- **http://localhost:8088/** — strona-wizytówka backendu (status „LOKALNY · ODPALONY",
  PHP/Laravel/hash treści, czas serwera). Świadoma środowiska: na prodzie pokaże „PRODUKCJA".
  Źródło: [routes/web.php](routes/web.php) — dobre miejsce na szybki live-edit sprawdzian.

```bash
curl http://localhost:8088/                            # strona-wizytówka (HTML)
curl http://localhost:8088/api/v1/content/version      # {"version":"..."}
curl http://localhost:8088/up                          # health Laravela
```

Z tożsamością (Twoje postaci z produkcyjnej Supabase):

```bash
# JWT: DevTools → Application → Local Storage → sesja Supabase → skopiuj access_token
curl -H "Authorization: Bearer <JWT>" http://localhost:8088/api/v1/characters
```

### Kiedy potrzebny restart / rebuild (a NIE zwykły edit)

| Zmiana | Komenda |
|---|---|
| Kod PHP / trasa | nic — widoczne od razu (~2 s, opcache revalidate) |
| **Zmienne w `.env`** | **`docker compose up -d`** (recreate) — `env_file` wstrzykuje `.env` przy STARCIE kontenera, więc edycja pliku sama nie wystarcza |
| Nowa zależność w `composer.json` | `docker compose exec app composer install` |
| `Dockerfile` / `php.ini` / obraz | `docker compose up -d --build` |
| Przypadkiem odpalony `php artisan config:cache`/`route:cache` | `docker compose exec app php artisan config:clear && ... route:clear` |

> **Auth lokalnie:** Twoja Supabase wydaje tokeny **ES256**, więc lokalny `.env` musi mieć
> `SUPABASE_JWT_DRIVER=jwks` (jak `.env.render`). Z `hmac` każdy zalogowany request = **401**.

Logi backendu na żywo: `docker compose logs -f app` oraz `storage/logs/laravel.log`.

## Przełącznik trybu backendu (skąd front wie, że ma iść do :8088)

`grimshade/src/config/backendMode.ts` decyduje, czy front woła backend czy gra
client-authoritative (wprost do Supabase). W dev sterują tym:

- `VITE_API_BASE_URL` ustawiony (jest) **oraz**
- `VITE_BACKEND_DEFAULT=1` → **domyślnie ON** (ustawione w `.env.local`).

Sterowanie w locie (DevTools, bez restartu):

```js
localStorage.setItem('grimshade_backend_mode','0'); // wymuś client-authoritative (pomiń backend)
localStorage.removeItem('grimshade_backend_mode');   // wróć do domyślnego (ON w dev)
```

## ⚠️ Baza jest PRODUKCYJNA — testuj na koncie testowym

W tym trybie akcje gry idą przez lokalny backend, ale **zapisują do produkcyjnej Supabase**.
Zmieniają `characters.level/xp/gold` i blob `game_saves`, które front czyta.

- **Graj/testuj na `test@grimshade.pl`**, nie na głównej postaci.
- **NIE uruchamiaj** lockdown-SQL (`database/sql/2026_client_write_lockdown.sql`) — to dopiero cutover.
- Chcesz w pełni bezpiecznie odciąć się od produkcji (własna baza + dump)? To osobny setup
  (lokalne Supabase CLI: Auth + Postgres + Realtime) — do zrobienia na życzenie.

## Powiązane dokumenty

- [SETUP.md](SETUP.md) — pierwsze podłączenie backendu do Supabase, CI/CD, klucze SSH.
- [README.md](README.md) — architektura, zmienne środowiskowe, komendy testowe.
