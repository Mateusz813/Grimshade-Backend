<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Content\ContentRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Wersja treści gry. Front porównuje swój hash z tym na starcie — mismatch =
 * klient i serwer nie zgadzają się co do balansu (np. HP potworów). Publiczne
 * (bez auth), bo sprawdzane jeszcze przed pełnym zalogowaniem.
 */
final class ContentController extends Controller
{
    public function version(ContentRepository $content): JsonResponse
    {
        return response()->json(['version' => $content->version()]);
    }
}
