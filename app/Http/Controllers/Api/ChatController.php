<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Chat\SystemChatMessages;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class ChatController extends Controller
{
    private const MAX_CONTENT_LENGTH = 300;

    private const RATE_LIMIT_SECONDS = 2;

    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 500;

    private const SYSTEM_CHANNEL = 'system';

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);

        $messages = Message::query()
            ->where('channel', $data['channel'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json($messages);
    }

    public function store(Request $request): JsonResponse
    {
        $character = $request->attributes->get('character');

        $data = $request->validate([
            'channel' => ['required', 'string', 'max:128'],
            'content' => ['required', 'string', 'max:'.self::MAX_CONTENT_LENGTH],
        ]);

        $content = trim($data['content']);
        if ($content === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Pusta wiadomość.');
        }

        $this->enforceRateLimit($character);

        $message = $this->insertMessage($character, $data['channel'], $content);

        return response()->json($message, Response::HTTP_CREATED);
    }

    public function systemEvent(Request $request): JsonResponse
    {
        $character = $request->attributes->get('character');

        $type = $request->input('type');
        if (! in_array($type, ['upgrade', 'skillUpgrade'], true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Nieznany typ zdarzenia systemowego.');
        }

        if ($type === 'upgrade') {
            $data = $request->validate([
                'itemId' => ['required', 'string', 'max:128'],
                'rarity' => ['required', 'string', 'max:32'],
                'upgradeLevel' => ['required', 'integer', 'min:0'],
                'itemName' => ['required', 'string', 'max:128'],
            ]);

            $this->assertMilestone((int) $data['upgradeLevel']);

            $payload = [
                'type' => 'upgrade',
                'itemId' => $data['itemId'],
                'rarity' => $data['rarity'],
                'upgradeLevel' => (int) $data['upgradeLevel'],
                'itemName' => $data['itemName'],
            ];
        } else {
            $data = $request->validate([
                'skillId' => ['required', 'string', 'max:128'],
                'skillName' => ['required', 'string', 'max:128'],
                'upgradeLevel' => ['required', 'integer', 'min:0'],
            ]);

            $this->assertMilestone((int) $data['upgradeLevel']);

            $payload = [
                'type' => 'skillUpgrade',
                'skillId' => $data['skillId'],
                'skillName' => $data['skillName'],
                'upgradeLevel' => (int) $data['upgradeLevel'],
            ];
        }

        $content = SystemChatMessages::formatSystemMessage($payload);
        $message = $this->insertMessage($character, self::SYSTEM_CHANNEL, $content);

        return response()->json($message, Response::HTTP_CREATED);
    }

    private function enforceRateLimit(Character $character): void
    {
        $key = "chat.cooldown.{$character->id}";
        if (! Cache::add($key, true, now()->addSeconds(self::RATE_LIMIT_SECONDS))) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, 'Zbyt szybko — odczekaj chwilę.');
        }
    }

    private function assertMilestone(int $upgradeLevel): void
    {
        if (! SystemChatMessages::isUpgradeMilestone($upgradeLevel)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Ten poziom nie jest progiem broadcastu.');
        }
    }

    private function insertMessage(Character $character, string $channel, string $content): Message
    {
        return Message::create([
            'user_id' => $character->user_id,
            'channel' => $channel,
            'character_name' => $character->name,
            'character_class' => $character->class,
            'character_level' => $character->level,
            'content' => $content,
            'created_at' => now(),
        ]);
    }
}
