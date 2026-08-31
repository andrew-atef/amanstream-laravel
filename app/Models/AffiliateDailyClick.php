<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateDailyClick extends Model
{
    protected $fillable = [
        'asin',
        'date',
        'clicks',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clicks' => 'integer',
        ];
    }
}
