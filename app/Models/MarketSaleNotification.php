<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Powiadomienie sprzedawcy o sprzedaży (`market_sale_notifications`). Kształt:
 * src/api/v1/marketApi.ts. Tworzone atomowo w transakcji kupna; gold_received =
 * kwota NETTO po 5% podatku marketowym (faktyczny przychód sprzedawcy).
 *
 * @property string $id
 * @property string $seller_id
 * @property int $quantity_sold
 * @property int $gold_received
 */
class MarketSaleNotification extends Model
{
    use HasUuids;

    protected $table = 'market_sale_notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // tabela ma tylko sold_at (ustawiamy jawnie)

    protected $fillable = [
        'seller_id', 'item_id', 'item_name', 'rarity', 'quantity_sold',
        'gold_received', 'sold_at', 'seen',
    ];

    protected function casts(): array
    {
        return [
            'quantity_sold' => 'integer',
            'gold_received' => 'integer',
            'seen' => 'boolean',
            'sold_at' => 'datetime',
        ];
    }
}
