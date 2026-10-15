<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backup diário de todos os acervos (ative o Scheduler no Laravel Cloud)
Illuminate\Support\Facades\Schedule::command('datafox:backup')->dailyAt('03:30')->timezone('America/Sao_Paulo')->withoutOverlapping();

// Lixeira: apaga de vez (com os arquivos) o que foi excluído há mais de 30 dias
Illuminate\Support\Facades\Schedule::command('model:prune')->dailyAt('04:10')->timezone('America/Sao_Paulo');
