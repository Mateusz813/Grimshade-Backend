<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MarketSaleNotification extends Model
{
    use HasUuids;

    protected $table = 'market_sale_notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

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
