<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Aukcja gracz→gracz (`market_listings`). Kształt: src/api/v1/marketApi.ts.
 *
 * Wiersz JEST autorytatywnym escrow: przy wystawieniu item schodzi z bloba
 * game_saves i zostaje odwzorowany w snapshot tu; przy kupnie serwer atomowo
 * (lockForUpdate) dekrementuje/usuwa i transferuje kupującemu. Klient NIGDY
 * nie mutuje tej tabeli bezpośrednio — tylko przez intencje kontrolera.
 *
 * @property string $id
 * @property string $seller_id
 * @property string $kind
 * @property int $price
 * @property int $quantity
 */
class MarketListing extends Model
{
    use HasUuids;

    protected $table = 'market_listings';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tabela ma tylko listed_at (ustawiamy jawnie)

    protected $fillable = [
        'seller_id', 'seller_name', 'kind', 'item_id', 'item_name', 'item_level',
        'rarity', 'slot', 'price', 'quantity', 'quantity_initial', 'bonuses',
        'upgrade_level', 'listed_at',
    ];

    protected function casts(): array
    {
        return [
            'item_level' => 'integer',
            'price' => 'integer',
            'quantity' => 'integer',
            'quantity_initial' => 'integer',
            'bonuses' => 'array',
            'upgrade_level' => 'integer',
            'listed_at' => 'datetime',
        ];
    }
}
