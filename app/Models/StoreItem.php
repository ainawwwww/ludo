<?php

namespace App\Models;

use App\Enums\StoreItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreItem extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'store_items';

    protected $fillable = [
        'name',
        'type',
        'price',
        'currency_type',
        'image_url',
        'is_active',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => StoreItemType::class,
            'price' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
