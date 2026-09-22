<?php

declare(strict_types=1);

return [
    'customer_created' => 'Musteri olusturuldu.',
    'customer_updated' => 'Musteri guncellendi.',
    'customer_anonymized' => 'Kisisel veriler silindi. Ticari kayit korundu.',
    'contact_saved' => 'Kisi kaydedildi.',
    'contact_deleted' => 'Kisi silindi.',
    'invalid_transition' => 'Bir musteri :from durumundan :to durumuna gecemez.',
    'contact_not_on_customer' => 'Bu kisi bu musteriye ait degil.',

    'statuses' => [
        'pending' => 'Beklemede',
        'active' => 'Aktif',
        'suspended' => 'Askida',
        'closed' => 'Kapali',
    ],

    'address_types' => [
        'billing' => 'Fatura',
        'technical' => 'Teknik',
        'legal' => 'Yasal',
    ],

    'custom_field_types' => [
        'text' => 'Metin',
        'textarea' => 'Uzun metin',
        'number' => 'Sayi',
        'boolean' => 'Evet veya hayir',
        'date' => 'Tarih',
        'select' => 'Secim',
    ],

    'custom_field_entities' => [
        'customer' => 'Musteri',
        'contact' => 'Kisi',
    ],
];
