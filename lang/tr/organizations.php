<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_permitted' => 'Organizasyonlara erişiminiz yok.',
        'email_taken' => 'Bu adresin bu kurulumda zaten bir hesabı var.',
        'no_provider' => 'Bu kurulumda sağlayıcı organizasyonu yok. Bayi eklemeden önce oluşturun.',
        'no_role' => 'Bu kurulumda yönetici rolü yok. Bayi eklemeden önce sistem rollerini oluşturun.',
        'reseller_only' => 'Bu organizasyon bir bayi değil.',
    ],

    'resellers' => [
        'title' => 'Bayiler',
        'subtitle' => 'Ürünlerinizi kendi adıyla satanlar. Bir bayi kendi müşterilerinin sahibidir ve başka hiçbir şeyi görmez.',
        'add' => 'Bayi Ekle',
        'created' => ':name oluşturuldu. Sahibi panele şifre sıfırlama akışıyla ulaşır.',
        'empty' => 'Henüz bayi yok.',
        'name' => 'Ticari ad',
        'slug' => 'Kısa ad',
        'owner' => 'Sahip',
        'owner_name' => 'Sahip adı',
        'owner_email' => 'Sahip e-postası',
        'customers' => 'Müşteriler',
        'products' => 'Ürünler',
        'created_at' => 'Oluşturuldu',
        'availability' => 'Satabilecekleri',
        'availability_saved' => 'Satış izinleri kaydedildi.',
        'availability_empty' => 'Hiçbir şey işaretlenmemiş bir bayi hiçbir şey satmaz. Burada yokluk bir rettir, her şey demek değildir.',
    ],

    'types' => [
        'provider' => 'Sağlayıcı',
        'reseller' => 'Bayi',
        'customer' => 'Müşteri',
    ],
];
