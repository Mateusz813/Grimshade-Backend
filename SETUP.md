# Grimshade Backend — setup krok po kroku

Przewodnik dla nietechnicznego właściciela. Rób sekcje po kolei.

---

## 1) Git — wypchnij backend na GitHub (Twoim kluczem SSH)

**Nie musisz dodawać nowego klucza.** Masz już `~/.ssh/id_ed25519_grimshade` (alias
`github.com-grimshade`), podpięty do konta `Mateusz813` — ten sam klucz autoryzuje
też nowe repo. Wystarczy użyć aliasu w adresie remote (dokładnie jak front).

```bash
cd ~/Desktop/Grimshade/grimshade-backend

# remote przez alias (NIE goły github.com — alias wybiera właściwy klucz):
git remote add origin git@github.com-grimshade:Mateusz813/Grimshade-Backend.git

# sprawdź, że klucz działa (ma napisać "Hi Mateusz813!"):
ssh -T git@github.com-grimshade

git add -A
git commit -m "feat: backend Laravel + Docker + CI/CD + port logiki (parytet golden)"
git branch -M main
git push -u origin main
```

Gdzie jest plik z konfiguracją kluczy per-repo: **`~/.ssh/config`** (otwórz np.
`code ~/.ssh/config` albo `open -e ~/.ssh/config`). Wpis `Host github.com-grimshade`
już tam jest — nic nie musisz dodawać.

---

## 2) Uruchom backend lokalnie (Docker), podłączony do TWOJEJ Supabase

Backend w Dockerze (nginx → PHP), a baza = **Twoja realna Supabase** (ten sam progres co front).
Lokalny Postgres jest wyłączony (profil `localdb`) — nie tworzysz osobnej bazy.

```bash
cd ~/Desktop/Grimshade/grimshade-backend

# 1. Uzupełnij .env danymi z Supabase (patrz sekcja 3): DB_HOST/DB_USERNAME/DB_PASSWORD + SUPABASE_JWT_SECRET.
# 2. Wstań (startuje tylko app + web, BEZ lokalnego Postgresa):
docker compose up -d --build

# 3. Sprawdź, że gada z bazą — lista Twoich postaci (podmień <TWÓJ-JWT> na token z frontu, patrz niżej):
curl http://localhost:8088/api/v1/content/version                       # {"version":"..."} (bez bazy)
curl -H "Authorization: Bearer <TWÓJ-JWT>" http://localhost:8088/api/v1/characters   # Twoje postaci z Supabase

docker compose down                     # zatrzymaj
```

**Skąd wziąć `<TWÓJ-JWT>`:** zaloguj się na froncie, w przeglądarce DevTools → Application →
Local Storage → skopiuj `access_token` z klucza sesji Supabase. To ten sam token, który
backend zweryfikuje.

> **Testy bez dotykania produkcji:** `./vendor/bin/pest` (na hoście) i tak używa sqlite in-memory —
> NIE łączy się z Supabase. Do offline-dev na lokalnym Postgresie:
> `docker compose --profile localdb up -d` (i w `.env` odkomentuj blok `DB_HOST=db`).

Port zmienisz zmienną `APP_PORT` (domyślnie 8088).

---

## 3) Podłącz swoją ISTNIEJĄCĄ Supabase (progres zachowany)

Backend gada z **tą samą bazą co front**, więc wszystkie Twoje obecne postaci/progres tam są.
Uzupełnij `.env` (gitignored, tylko u Ciebie) danymi z panelu Supabase:

1. **Auth** (Settings → API): `SUPABASE_URL` = Project URL; `SUPABASE_JWT_SECRET` =
   Settings → API → JWT Settings → **JWT Secret** (masz już wpisany).
2. **Baza** (Database → **Connect** → **Connection pooling**, tryb **Session**, port **5432**) —
   z podanego stringa przepisz do `.env`:
   - `DB_HOST` = host `...pooler.supabase.com`
   - `DB_USERNAME` = `postgres.<project-ref>`
   - `DB_PASSWORD` = hasło do bazy (to z zakładania projektu; jak nie pamiętasz → Database → Reset)
   - `DB_DATABASE=postgres`, `DB_PORT=5432`, `DB_SSLMODE=require`
3. Sprawdź połączenie: `docker compose up -d` → `curl -H "Authorization: Bearer <JWT>"
   http://localhost:8088/api/v1/characters` → powinny wrócić Twoje realne postaci.

**Czy trzeba migrować?** NIE — tabele już istnieją w Twojej bazie. Migracja `characters` jest
idempotentna (`Schema::hasTable` → **no-op**, nie ruszy danych). Jeśli odpalisz `migrate`, doda
tylko własną tabelkę `migrations` Laravela (nieszkodliwe).

> ⚠️ **Bezpieczeństwo — testuj zapisy tylko na koncie testowym.** Endpointy zapisujące
> (`/combat/resolve`) zmieniają kolumny `level/xp/gold`, które front czyta przy wczytaniu
> postaci. Rób to **tylko na `test@grimshade.pl`**, nie na głównej postaci. Najbezpieczniej:
> drugi projekt Supabase jako **staging** (klon) do zabaw.
>
> ⚠️ **NIE uruchamiaj** lockdown-SQL (`database/sql/...`) teraz — to sam koniec migracji
> (cutover). Wcześniej zepsułby obecny front, który pisze wprost do Supabase.

---

## 4) CI/CD — co zobaczysz na GitHubie

Po każdym push → zakładka **Actions** → pipeline z krokami (✓/✗ po kolei):

1. **Lint (Pint)** — styl kodu.
2. **Testy (Pest)** — unit + feature + golden parytet, z pokryciem.
3. **Build obrazu** — buduje Docker i publikuje do `ghcr.io` (tylko push do `main`).
4. **Deploy** — restart na serwerze przez SSH; **pomija się**, dopóki nie ustawisz sekretów.

Aby włączyć deploy (gdy masz serwer): repo → **Settings → Secrets and variables →
Actions** → dodaj: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` (klucz prywatny do
serwera), opcjonalnie `DEPLOY_PATH`. Wtedy krok 4 zrobi `docker compose pull && up -d`
+ migracje na serwerze.

---

## 5) Co dalej (mapa)

Pełny plan migracji (11 faz): plik planu w `~/.claude/plans/`. Skrót stanu i „co robić"
— patrz [README.md](README.md). Najbliższe: podłączenie realnej Supabase (sekcja 3) i
kolejne endpointy/porty logiki.
