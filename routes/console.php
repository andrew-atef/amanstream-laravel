<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('amazon:sync-prices')->everySixHours();

// إعادة تدوير المنتجات المزامنة إلى طابور المزامنة كل 6 ساعات تلقائياً
Schedule::command('catalog:reset-sync-queue')->everySixHours();
