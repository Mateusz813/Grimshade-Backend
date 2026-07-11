# Deploy backendu na Render (free) — klik po kliku

Render buduje `Dockerfile.render` (jeden kontener FrankenPHP serwujący Laravel po HTTP)
i daje publiczny URL `https://<nazwa>.onrender.com`. Zweryfikowane lokalnie: content/version → 200,
auth-gate → 401, CORS → OK.

> Free tier: usypia po ~15 min bezczynności → pierwsze wejście po przerwie ~30-60s (budzenie),
> potem szybko. Dla gry, w którą gra kilka osób, akceptowalne.

## 0) Prerekwizyt — wypchnij repo na GitHub
Render deployuje z GitHuba, więc backend musi tam być z aktualnym kodem (Dockerfile.render,
config/cors.php, nowe endpointy). Zacommituj + wypchnij `Grimshade-Backend`.

## 1) Konto
- Wejdź na https://render.com → **Get Started** → zaloguj przez **GitHub**.
- Autoryzuj Render do repo `Grimshade-Backend` (może być tylko to jedno repo).

## 2) Nowy Web Service
- Dashboard → **New +** → **Web Service**.
- Wybierz repo **Grimshade-Backend** → **Connect**.

## 3) Ustawienia
| Pole | Wartość |
|---|---|
| **Name** | `grimshade-backend` (→ URL `grimshade-backend.onrender.com`) |
| **Region** | **Frankfurt (EU Central)** — najbliżej Twojej Supabase |
| **Branch** | `main` (lub Twój domyślny) |
| **Language / Runtime** | **Docker** |
| **Dockerfile Path** | **`Dockerfile.render`** ← WAŻNE, nie domyślny `Dockerfile` |
| **Instance Type** | **Free** |
| **Health Check Path** | `/api/v1/content/version` |

## 4) Environment Variables
Najprościej: skopiuj CAŁY swój lokalny `.env` (Render ma "Add from .env" / wklejanie).
Potem USTAW/NADPISZ te klucze:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://grimshade-backend.onrender.com   # zaktualizuj po poznaniu URL
PORT=8080                                          # nasłuchujemy na 8080 (SERVER_NAME=:8080)

# Bez zależności od dodatkowych tabel (free = jeden kontener):
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=stderr                                 # logi widoczne w panelu Render

# Baza (Twoja Supabase — te same co lokalnie, SSL wymuszony):
DB_CONNECTION=pgsql
DB_HOST=aws-1-eu-central-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.vcjivlkyppqtfetdwrcz
DB_PASSWORD=...            # z Twojego .env
DB_SSLMODE=require

# Supabase auth (te same co lokalnie):
SUPABASE_URL=https://vcjivlkyppqtfetdwrcz.supabase.co
SUPABASE_JWT_SECRET=...    # z Twojego .env
SUPABASE_JWT_DRIVER=...    # z Twojego .env (jeśli ustawiony)

# APP_KEY — SKOPIUJ ten sam co w lokalnym .env (base64:...). NIE generuj nowego na ślepo.
APP_KEY=base64:...

# CORS — dokładna domena frontu (Vercel):
CORS_ALLOWED_ORIGINS=https://<twoja-domena-vercel>
```

## 5) Deploy
- **Create Web Service**. Render zbuduje `Dockerfile.render` (kilka minut) i uruchomi.
- Log budowania + runtime widać na żywo w panelu.

## 6) Test po deployu
```
curl https://grimshade-backend.onrender.com/api/v1/content/version
# → {"version":"..."}  (HTTP 200)
```
(pierwsze wejście może budzić uśpiony serwer ~40s)

## 7) Spięcie z frontem (Vercel)
W projekcie frontu na Vercel (Settings → Environment Variables):
```
VITE_API_BASE_URL=https://grimshade-backend.onrender.com
VITE_BACKEND_DEFAULT=1
```
→ Redeploy frontu. Od tej chwili front gada z backendem na Render.

## 8) DOPIERO POTEM lockdown
NIE odpalaj `database/sql/2026_client_write_lockdown.sql`, dopóki nie przejdziesz smoke-testu
przez wdrożony backend (patrz `GO_LIVE.md`). Kolejność: deploy backend → deploy front → test →
REVOKE. Rollback zawsze gotowy (env-flip + re-GRANT).

## Uwagi
- Zmieniłeś env? Render → **Manual Deploy / Save** przeładuje (albo `Clear build cache & deploy`).
- Auto-deploy: Render domyślnie redeployuje po każdym push na wybrany branch.
- Update CORS: zmień `CORS_ALLOWED_ORIGINS` w env i redeploy (config czyta się na starcie).
