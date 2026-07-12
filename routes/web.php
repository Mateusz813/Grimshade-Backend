<?php

use App\Domain\Content\ContentRepository;
use Illuminate\Support\Facades\Route;

Route::get('/', function (ContentRepository $content) {
    $isLocal = app()->environment('local');

    try {
        $contentVersion = $content->version();
    } catch (Throwable $e) {
        $contentVersion = '—';
    }

    $env = e(app()->environment());
    $envLabel = $isLocal ? 'LOKALNY' : mb_strtoupper($env);
    $php = e(PHP_VERSION);
    $laravel = e(app()->version());
    $contentShort = e(substr($contentVersion, 0, 16));
    $time = e(now()->toDayDateTimeString()).' UTC';
    $accent = $isLocal ? '#4caf50' : '#ffc107';

    $html = <<<HTML
    <!doctype html>
    <html lang="pl">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Grimshade — Backend {$envLabel}</title>
      <style>
        :root { --accent: {$accent}; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
          min-height: 100vh; display: grid; place-items: center; padding: 24px;
          font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #e8e8f0;
          background:
            radial-gradient(1200px 600px at 50% -10%, rgba(123,31,162,.35), transparent 60%),
            radial-gradient(900px 500px at 90% 110%, rgba(229,57,53,.18), transparent 55%),
            #0f0f18;
        }
        .card {
          width: 100%; max-width: 560px; text-align: center;
          background: rgba(26,26,46,.55); border: 1px solid rgba(255,255,255,.08);
          border-radius: 20px; padding: 40px 32px; backdrop-filter: blur(8px);
          box-shadow: 0 24px 60px rgba(0,0,0,.45);
        }
        .crest { font-size: 56px; line-height: 1; filter: drop-shadow(0 4px 12px rgba(0,0,0,.5)); }
        h1 {
          font-size: 40px; letter-spacing: 6px; margin: 14px 0 2px; font-weight: 800;
          background: linear-gradient(90deg, #fff, #c9a6ff, #fff);
          -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }
        .sub { font-size: 13px; letter-spacing: 3px; opacity: .55; text-transform: uppercase; }
        .status {
          display: inline-flex; align-items: center; gap: 10px; margin: 22px 0 6px;
          padding: 9px 18px; border-radius: 999px; font-weight: 700; letter-spacing: 1px;
          background: rgba(255,255,255,.04); border: 1px solid var(--accent);
          color: var(--accent);
        }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--accent);
          box-shadow: 0 0 0 0 var(--accent); animation: pulse 1.8s infinite; }
        @keyframes pulse {
          0% { box-shadow: 0 0 0 0 rgba(76,175,80,.55); }
          70% { box-shadow: 0 0 0 12px rgba(76,175,80,0); }
          100% { box-shadow: 0 0 0 0 rgba(76,175,80,0); }
        }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 26px; }
        .tile { background: rgba(0,0,0,.28); border: 1px solid rgba(255,255,255,.06);
          border-radius: 12px; padding: 14px; text-align: left; }
        .tile .k { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; opacity: .5; }
        .tile .v { font-size: 16px; margin-top: 4px; font-weight: 600; word-break: break-all; }
        .mono { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 14px; }
        .foot { margin-top: 24px; font-size: 12px; opacity: .5; line-height: 1.7; }
        .foot code { background: rgba(255,255,255,.07); padding: 2px 7px; border-radius: 6px; }
      </style>
    </head>
    <body>
      <div class="card">
        <div class="crest">⚔️</div>
        <h1>GRIMSHADE</h1>
        <div class="sub">Backend · Laravel</div>

        <div class="status"><span class="dot"></span> {$envLabel} · ODPALONY</div>

        <div class="grid">
          <div class="tile"><div class="k">Środowisko</div><div class="v">{$env}</div></div>
          <div class="tile"><div class="k">Wersja treści</div><div class="v mono">{$contentShort}</div></div>
          <div class="tile"><div class="k">PHP</div><div class="v">{$php}</div></div>
          <div class="tile"><div class="k">Laravel</div><div class="v">{$laravel}</div></div>
        </div>

        <div class="foot">
          Czas serwera: {$time}<br>
          API: <code>/api/v1</code> · health: <code>/up</code> · treść: <code>/api/v1/content/version</code>
        </div>
      </div>
    </body>
    </html>
    HTML;

    return response($html);
});
