<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Wiadomość czatu (`messages`). Kształt: src/api/v1/chatApi.ts (IMessage).
 *
 * Tożsamość nadawcy (character_name/class/level) SERWER bierze z postaci przy
 * insercie (ChatController) — NIE z body. Realtime broadcast robi Supabase po
 * stronie bazy; backend tylko autorytatywnie wstawia wiersz i czyta feed.
 *
 * @property string $id
 * @property string $user_id
 * @property string $channel
 * @property string $character_name
 * @property string $content
 */
class Message extends Model
{
    use HasUuids;

    protected $table = 'messages';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tabela ma tylko created_at (ustawiamy jawnie)

    protected $fillable = [
        'user_id', 'channel', 'character_name', 'character_class',
        'character_level', 'content', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'character_level' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
