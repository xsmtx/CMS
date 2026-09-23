<?php

declare(strict_types=1);

return [
    'title' => 'İçe aktarma',
    'subtitle' => 'Önceki bir sistemi aktarın. Canlı çalıştırma istemediğiniz sürece hiçbir şey yazılmaz.',

    'started' => 'İçe aktarma kuyruğa alındı. Bu ekran ilerleyişi gösterir.',

    'domains' => [
        'customers' => 'Müşteriler',
        'contacts' => 'Kişiler',
        'products' => 'Ürünler',
        'services' => 'Ürünler/Hizmetler',
        'domains' => 'Alan adları',
        'invoices' => 'Faturalar',
        'transactions' => 'İşlemler',
        'tickets' => 'Destek talepleri',
    ],

    'modes' => [
        'dry_run' => 'Deneme çalıştırması',
        'live' => 'Canlı aktarma',
    ],

    'statuses' => [
        'pending' => 'Kuyrukta',
        'running' => 'Çalışıyor',
        'completed' => 'Tamamlandı',
        'failed' => 'Çalıştırılamadı',
    ],

    'outcomes' => [
        'created' => 'Oluşturuldu',
        'updated' => 'Güncellendi',
        'skipped' => 'Zaten aktarılmış',
        'failed' => 'Aktarılamadı',
    ],

    'errors' => [
        'not_permitted' => 'Başka bir sistemden içe aktarmayı yalnızca kurulum sahibi yapabilir.',
    ],
];
