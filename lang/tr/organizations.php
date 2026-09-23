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
        'availability_empty' => 'Hiçbir şey seçilmemiş bir bayi hiçbir şey satamaz. Burada yokluk bir reddetmedir, "hepsi" kısayolu değil.',
        'margin' => 'Kâr marjı %',
        'margin_hint' => 'Sağlayıcı fiyatına eklenir. Sağlayıcı fiyatı için boş bırakın; boş ile sıfır farklı cevaplardır.',
        'prices' => 'Birebir fiyatlar',
        'prices_hint' => 'Birebir fiyat her marjı geçer, çünkü bir sayı yazan o sayıyı kastetmiştir. Temizlemek satırı siler.',
        'price_saved' => 'Fiyat kaydedildi.',
        'no_prices' => 'Birebir fiyat yok. Her ürün, sağlayıcı fiyatı artı marjıyla satılır.',
        'balance' => 'Bakiye',
        'balance_hint' => 'Artı bakiye, bayinin sizde tuttuğu tutardır. Eksi, size borçlu olduğunu gösterir.',
        'no_balance' => 'Henüz hareket yok.',
        'record_entry' => 'Hareket kaydet',
        'entry_recorded' => 'Kaydedildi. Bakiye şimdi :balance.',
        'amount' => 'Tutar',
        'occurred_at' => 'Paranın hareket ettiği tarih',
        'description' => 'Açıklama',
        'statement' => 'Hesap özeti',
        'recorded_by' => 'Kaydeden',
        'enabled' => 'Satabilir',
    ],

    'ledger_kinds' => [
        'payment' => 'Alınan ödeme',
        'credit' => 'Verilen kredi',
        'charge' => 'Borçlandırma',
        'withdrawal' => 'Geri ödeme',
    ],

    'types' => [
        'provider' => 'Sağlayıcı',
        'reseller' => 'Bayi',
        'customer' => 'Müşteri',
    ],
];
