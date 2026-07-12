<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Content\ContentRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ContentController extends Controller
{
    public function version(ContentRepository $content): JsonResponse
    {
        return response()->json(['version' => $content->version()]);
    }
}
