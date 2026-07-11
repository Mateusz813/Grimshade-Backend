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

/**
 * Czat miasta / system / party / guild / PM.
 *
 * Autorytet:
 *  - GET /chat/messages?channel=&limit — feed per kanał (odczyt globalny, auth).
 *  - POST /characters/{character}/chat/messages — wysyłka. Tożsamość nadawcy
 *    (character_name/class/level) bierze SERWER z postaci (owns.character), NIE
 *    z body. Serwer waliduje długość (≤300) i prosty rate-limit (cooldown/char).
 *  - POST /characters/{character}/chat/system-event — broadcast zdarzenia
 *    (upgrade/skillUpgrade) w formacie App\Domain\Chat\SystemChatMessages; serwer
 *    egzekwuje regułę progów (isUpgradeMilestone) i sam składa treść `[SYS]{...}`.
 *
 * Realtime broadcast (postgres_changes) robi Supabase po insercie — nie tutaj.
 */
final class ChatController extends Controller
{
    /** Limit długości treści (front slice(0,300)); dłuższa wiadomość → 422. */
    private const MAX_CONTENT_LENGTH = 300;

    /** Prosty rate-limit: minimalny odstęp między wiadomościami tej samej postaci. */
    private const RATE_LIMIT_SECONDS = 2;

    /** Domyślny / maksymalny rozmiar strony feedu. */
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 500;

    /** Kanał, na którym jadą serwerowe zdarzenia systemowe. */
    private const SYSTEM_CHANNEL = 'system';

    /** Feed kanału — najnowsze pierwsze (front sobie odwraca, jak w chatApi.ts). */
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

    /** Wyślij wiadomość — tożsamość z SERWERA, walidacja długości + rate-limit. */
    public function store(Request $request): JsonResponse
    {
        /** @var Character $character */
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

    /**
     * Broadcast zdarzenia systemowego (upgrade/skillUpgrade) do kanału `system`.
     * Serwer sam decyduje, czy poziom to próg broadcastu (isUpgradeMilestone),
     * i sam składa treść `[SYS]{...}` w formacie parytetowym z frontem.
     */
    public function systemEvent(Request $request): JsonResponse
    {
        /** @var Character $character */
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

            // Kolejność kluczy = interfejs TS (ISystemUpgradePayload) — bit-parity.
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

            // Kolejność kluczy = interfejs TS (ISystemSkillUpgradePayload).
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

    /** Prosty cooldown per-postać (array cache) — blokuje spam rapid-fire. */
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

    /** Wstawia wiersz z tożsamością postaci wziętą z SERWERA (anty-fałsz). */
    private function insertMessage(Character $character, string $channel, string $content): Message
    {
        return Message::create([
            'user_id' => $character->user_id,
            'channel' => $channel,
            // Tożsamość z SERWERA — nie z body:
            'character_name' => $character->name,
            'character_class' => $character->class,
            'character_level' => $character->level,
            'content' => $content,
            'created_at' => now(),
        ]);
    }
}
