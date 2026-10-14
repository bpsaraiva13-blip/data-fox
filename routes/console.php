<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backup diário de todos os acervos (ative o Scheduler no Laravel Cloud)
Illuminate\Support\Facades\Schedule::command('datafox:backup')->dailyAt('03:30')->timezone('America/Sao_Paulo')->withoutOverlapping();
