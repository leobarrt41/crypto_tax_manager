<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BinanceAnnouncement extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'catalog_id',
        'release_at',
        'url',
        'pairs',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'release_at' => 'datetime',
        'processed_at' => 'datetime',
        'pairs' => 'array',
        'payload' => 'array',
    ];
}

