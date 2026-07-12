<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MarketListing extends Model
{
    use HasUuids;

    protected $table = 'market_listings';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

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
