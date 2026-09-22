<?php

declare(strict_types=1);

return [
    'customer_created' => 'Müşteri oluşturuldu.',
    'customer_updated' => 'Müşteri güncellendi.',
    'customer_anonymized' => 'Kişisel veriler silindi. Ticari kayıt korundu.',
    'contact_saved' => 'Kişi kaydedildi.',
    'contact_deleted' => 'Kişi silindi.',
    'profile_updated' => 'Bilgileriniz güncellendi.',
    'profile_not_editable' => 'Şirket bilgilerini yalnızca hesap sahibi değiştirebilir.',
    'contacts_not_manageable' => 'Bu hesaba kimlerin erişebileceğini yalnızca hesap sahibi yönetebilir.',
    'contact_not_removable' => 'Bu kişi buradan kaldırılamaz.',
    'invalid_transition' => 'Bir müşteri :from durumundan :to durumuna geçemez.',
    'contact_not_on_customer' => 'Bu kişi bu müşteriye ait değil.',

    'statuses' => [
        'pending' => 'Beklemede',
        'active' => 'Aktif',
        'suspended' => 'Askıda',
        'closed' => 'Kapalı',
    ],

    'address_types' => [
        'billing' => 'Fatura',
        'technical' => 'Teknik',
        'legal' => 'Yasal',
    ],

    'custom_field_types' => [
        'text' => 'Metin',
        'textarea' => 'Uzun metin',
        'number' => 'Sayı',
        'boolean' => 'Evet veya hayır',
        'date' => 'Tarih',
        'select' => 'Seçim',
    ],

    'custom_field_entities' => [
        'customer' => 'Müşteri',
        'contact' => 'Kişi',
    ],
];
