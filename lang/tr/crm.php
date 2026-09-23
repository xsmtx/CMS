<?php

declare(strict_types=1);

return [
    'cancellations' => [
        'completed' => 'İptal tamamlandı.',
        'withdrawn' => 'İptal geri alındı. Hizmet yeniden çalışıyor.',
        'types' => [
            'immediate' => 'Hemen',
            'end_of_term' => 'Dönem sonunda',
        ],
        'statuses' => [
            'pending' => 'Bekliyor',
            'completed' => 'Tamamlandı',
            'withdrawn' => 'Geri alındı',
        ],
    ],
    'errors' => [
        'information_required' => 'Portalın geri kalanını kullanabilmeniz için hesap bilgilerinizin düzeltilmesi gerekiyor. Bir destek bileti açın, halledelim.',
    ],

    'search' => [
        'advanced' => 'Gelişmiş',
        'basic' => 'Ara',
        'apply' => 'Ara',
        'clear' => 'Temizle',
        'show_inactive' => 'Kapatılmış hesapları da göster',
        'name' => 'Müşteri veya firma adı',
        'wildcard' => 'Ad soyad birlikte aranabilir. % ile: Zeyn% veya %nep.',
        'email' => 'E-posta adresi',
        'phone' => 'Telefon numarası',
        'tag' => 'Müşteri grubu',
        'status' => 'Durum',
        'address_one' => 'Adres 1',
        'address_two' => 'Adres 2',
        'city' => 'Şehir',
        'region' => 'İl veya bölge',
        'postcode' => 'Posta kodu',
        'country' => 'Ülke',
        'gateway' => 'Ödeme yöntemi',
        'card_brand' => 'Kart tipi',
        'card_last_four' => 'Kartın son dört hanesi',
        'has_card' => 'Kayıtlı kartı var',
        'currency' => 'Para birimi',
        'signed_up_from' => 'Kayıt tarihi (başlangıç)',
        'signed_up_to' => 'Kayıt tarihi (bitiş)',
        'locale' => 'Dil',
        'marketing_opt_in' => 'Kampanya izni',
        'corporate' => 'Kurumsal',
        'tax_id' => 'Vergi/kimlik numarası',
        'tax_id_validated' => 'Vergi numarası doğrulanmış',
        'any' => 'Farketmez',
        'yes' => 'Evet',
        'no' => 'Hayır',
        'permissions_title' => 'Şunu yapabilen bir kişisi var',
        'custom_fields' => 'Özel alanlar',
        'custom_fields_hint' => 'Yerel olan her şey — T.C. kimlik numarası, vergi dairesi, ikinci bir telefon — bir özel alandır ve bu kurulumda tanımlı her özel alan burada aranabilir.',
        'permissions' => [
            'portal_tickets_create' => 'Destek bileti açma',
            'portal_tickets_view' => 'Destek bileti okuma ve cevaplama',
            'portal_orders_view' => 'Sipariş verme',
            'portal_payment_methods_manage' => 'Kart kaydetme',
            'portal_billing_pay' => 'Fatura ödeme',
        ],
    ],

    'list' => [
        'id' => 'ID',
        'first_name' => 'Ad',
        'last_name' => 'Soyad',
        'company' => 'Firma',
        'name' => 'Ad',
        'email' => 'E-posta adresi',
        'services' => 'Hizmetler',
        'services_hint' => 'Aktif olanlar; aktif olmayanlar parantez içinde.',
        'created_at' => 'Kayıt',
        'status' => 'Durum',
    ],

    'save' => 'Kaydet',

    'fields' => [
        'company_name' => 'Şirket',
        'legal_name' => 'Ticari unvan',
        'tax_id' => 'Vergi numarası',
        'line_one' => 'Adres',
        'line_two' => 'Adres satırı 2',
        'city' => 'Şehir',
        'region' => 'Bölge',
        'postal_code' => 'Posta kodu',
        'country' => 'Ülke',
    ],

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
        'information_required' => 'Eksik/hatalı bilgi',
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
