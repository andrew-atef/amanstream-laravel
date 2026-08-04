<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('amazon:sync-prices')->everySixHours();

Schedule::command('catalog:reset-sync-queue')->daily();
