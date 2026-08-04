<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\InstallmentPlan;
use Illuminate\Database\Seeder;

class AmazonEgyptInstallmentSeeder extends Seeder
{
    public function run(): void
    {
        // بنك الإسكندرية (0% فائدة على كل الخطط)
        $alex = Bank::query()->updateOrCreate(['code' => 'alex'], [
            'name_ar' => 'بنك الإسكندرية',
            'name_en' => 'Alex Bank',
            'is_active' => true,
        ]);
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $alex->id, 'months' => 6],
            ['interest_rate' => 0, 'admin_fee_percent' => 8, 'min_order_amount' => 500, 'is_zero_interest' => true]
        );
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $alex->id, 'months' => 12],
            ['interest_rate' => 0, 'admin_fee_percent' => 14, 'min_order_amount' => 500, 'is_zero_interest' => true]
        );

        // البنك التجاري الدولي (CIB)
        $cib = Bank::query()->updateOrCreate(['code' => 'cib'], [
            'name_ar' => 'البنك التجاري الدولي',
            'name_en' => 'CIB',
            'is_active' => true,
        ]);
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $cib->id, 'months' => 6],
            ['interest_rate' => 11.06, 'admin_fee_percent' => 0, 'min_order_amount' => 500, 'is_zero_interest' => false]
        );
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $cib->id, 'months' => 12],
            ['interest_rate' => 18.76, 'admin_fee_percent' => 0, 'min_order_amount' => 500, 'is_zero_interest' => false]
        );
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $cib->id, 'months' => 24],
            ['interest_rate' => 37.91, 'admin_fee_percent' => 0, 'min_order_amount' => 500, 'is_zero_interest' => false]
        );

        // بنك مصر (حد أدنى يختلف حسب الشهور)
        $misr = Bank::query()->updateOrCreate(['code' => 'misr'], [
            'name_ar' => 'بنك مصر',
            'name_en' => 'Banque Misr',
            'is_active' => true,
        ]);
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $misr->id, 'months' => 6],
            ['interest_rate' => 0, 'admin_fee_percent' => 7, 'min_order_amount' => 2000, 'is_zero_interest' => true]
        );
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $misr->id, 'months' => 12],
            ['interest_rate' => 0, 'admin_fee_percent' => 10, 'min_order_amount' => 2000, 'is_zero_interest' => true]
        );
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $misr->id, 'months' => 24],
            ['interest_rate' => 0, 'admin_fee_percent' => 27, 'min_order_amount' => 4000, 'is_zero_interest' => true]
        );

        // التجاري وفا بنك (عروض 0% فائدة ورسوم)
        $wafa = Bank::query()->updateOrCreate(['code' => 'wafa'], [
            'name_ar' => 'التجاري وفا بنك',
            'name_en' => 'Attijariwafa Bank',
            'is_active' => true,
        ]);
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $wafa->id, 'months' => 6],
            ['interest_rate' => 0, 'admin_fee_percent' => 0, 'min_order_amount' => 500, 'is_zero_interest' => true]
        );
        InstallmentPlan::query()->updateOrCreate(
            ['bank_id' => $wafa->id, 'months' => 12],
            ['interest_rate' => 0, 'admin_fee_percent' => 0, 'min_order_amount' => 500, 'is_zero_interest' => true]
        );

        $this->command?->info('Amazon Egypt installment data seeded: '.Bank::count().' banks, '.InstallmentPlan::count().' plans.');
    }
}
