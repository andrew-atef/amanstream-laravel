<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    protected $fillable = ['name_ar', 'name_en', 'code', 'logo_path', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(InstallmentPlan::class);
    }
}
