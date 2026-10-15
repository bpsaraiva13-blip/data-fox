<?php

// Só o que o Data Fox muda em relação ao padrão do Livewire.
return [
    'temporary_file_upload' => [
        // com bucket S3/R2 o navegador envia direto para o bucket (bom para .zip grandes)
        'disk' => env('LIVEWIRE_UPLOAD_DISK'),
        // até 4 GB por arquivo (o .zip do cadastro em lote); fotos têm limite próprio no formulário
        'rules' => ['required', 'file', 'max:4194304'],
        'directory' => null,
        'middleware' => 'throttle:120,1',
        'preview_mimes' => ['png', 'gif', 'bmp', 'svg', 'wav', 'mp4', 'mov', 'avi', 'wmv', 'mp3', 'm4a', 'jpg', 'jpeg', 'mpga', 'webp', 'wma'],
        'max_upload_time' => 30,      // minutos
        'cleanup' => true,
    ],
];
