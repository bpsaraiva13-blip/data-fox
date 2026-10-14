<?php

return [
    // Disco onde ficam as fotos (s3 em produção, public em desenvolvimento)
    'disk' => env('ACERVO_DISK') ?: env('FILESYSTEM_DISK', 's3'),
    'photos_dir' => 'acervo/fotos',
    'thumbs_dir' => 'acervo/thumbs',
    'thumb_size' => 520,

    // Prefixo do Nº de tombo
    'code_prefix' => env('ACERVO_CODE_PREFIX', 'ACV'),

    // Similaridade mínima (0–1) para avisar possível duplicata no cadastro
    'duplicate_threshold' => 0.86,

    'categories' => [
        'Porcelana e louça', 'Prataria e metais', 'Numismática', 'Filatelia', 'Mobiliário',
        'Arte sacra', 'Relojoaria', 'Livros e documentos raros', 'Vidro e cristal',
        'Brinquedos antigos', 'Militaria', 'Fotografia antiga', 'Pintura e gravura',
        'Joalheria', 'Instrumentos científicos', 'Têxteis', 'Outros',
    ],

    'conditions' => ['Excelente', 'Muito bom', 'Bom', 'Regular', 'Precisa de restauro'],

    // Backup automático diário (data.json + fotos), compatível com "datafox:import"
    'backup' => [
        'disk' => env('ACERVO_BACKUP_DISK') ?: env('ACERVO_DISK') ?: env('FILESYSTEM_DISK', 's3'),
        'dir' => 'backups',
        'keep' => (int) env('ACERVO_BACKUP_KEEP', 14),   // quantos arquivos manter por acervo
        'remind_after_days' => 7,
    ],

    'box_colors' => ['#2A6A5C', '#97752A', '#8A4B3A', '#4A5E8C', '#6E4A7E', '#3F7A3A', '#9A3F5C', '#5A5A5A'],

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // Identificar a peça pelas fotos e conferir imagens: precisão importa
        'model' => env('ACERVO_AI_MODEL', 'claude-sonnet-5-5'),
        // Busca inteligente: modelo rápido e barato
        'search_model' => env('ACERVO_AI_SEARCH_MODEL', 'claude-haiku-5-5'),
        // Quantas peças a busca envia para a IA (pré-filtradas no banco)
        'search_candidates' => 80,
        'max_images' => 5,
        'timeout' => 90,
    ],
];
