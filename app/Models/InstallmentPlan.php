<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPlan extends Model
{
    protected $fillable = [
        'bank_id',
        'months',
        'interest_rate',
        'admin_fee_percent',
        'min_order_amount',
        'is_zero_interest',
    ];

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'interest_rate' => 'decimal:2',
            'admin_fee_percent' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'is_zero_interest' => 'boolean',
        ];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Calculate the real monthly payment including interest and admin fees.
     */
    public function calculateMonthlyPayment(float $productPrice): float
    {
        $adminFee = $productPrice * ($this->admin_fee_percent / 100);
        $totalInterest = $productPrice * ($this->interest_rate / 100);
        $totalAmount = $productPrice + $adminFee + $totalInterest;

        return $totalAmount / max(1, (int) $this->months);
    }
}
