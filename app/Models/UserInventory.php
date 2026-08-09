<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInventory extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'user_inventory';

    protected $fillable = [
        'user_id',
        'item_id',
        'is_equipped',
        'purchased_at',
    ];

    protected function casts(): array
    {
        return [
            'is_equipped' => 'boolean',
            'purchased_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'item_id');
    }
}
