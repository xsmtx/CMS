<?php

declare(strict_types=1);

return [
    'title' => 'Ayarlar',
    'description' => 'Bu kurulumun kendine ne ad verdiği ve nasıl göründüğü.',

    'saved' => 'Marka ayarları kaydedildi.',
    'theme_saved' => 'Tema değiştirildi.',
    'legal' => 'Yasal',

    'identity' => [
        'title' => 'Kimlik',
        'description' => 'Müşterilerin gördüğü ad ve mahkemenin gördüğü ad. Bu ikisi yeterince sık farklı olur ki fatura her ikisini de ister.',
        'trading_name' => 'Ticari ad',
        'legal_name' => 'Yasal unvan',
        'legal_name_hint' => 'Faturalara basılır. Boşsa ticari ad kullanılır.',
        'tax_id' => 'Vergi numarası',
        'address' => 'Adres',
        'country' => 'Ülke',
        'portal_name' => 'Portal adı',
        'portal_name_hint' => 'Müşteri alanının kendine verdiği ad; boşsa ticari ad kullanılır.',
    ],

    'contact' => [
        'title' => 'İletişim',
        'description' => 'Mağaza alt bilgisinde ve belgelerde gösterilir.',
        'support_email' => 'Destek e-postası',
        'support_phone' => 'Destek telefonu',
        'website_url' => 'Web sitesi',
    ],

    'appearance' => [
        'title' => 'Görünüm',
        'description' => 'Renkler, her şeyin üzerine kurulduğu tasarım değişkenlerini geçersiz kılar; tek bir değişiklik her düğmeye, rozete ve bağlantıya ulaşır.',
        'logo_url' => 'Logo',
        'logo_dark_url' => 'Koyu zemin için logo',
        'favicon_url' => 'Favicon',
        'url_hint' => 'HTTPS adresi. Düz HTTP üzerinden bir logo ödeme sayfasında engellenir.',
        'accent_color' => 'Vurgu rengi',
        'accent_contrast' => 'Vurgu üzerindeki yazı',
        'colour_hint' => 'Hex, örneğin #2563eb.',
        'font_family' => 'Yazı tipi yığını',
        'font_hint' => 'CSS yazı tipi yığını. Web fontu yüklemek temanın işidir.',
    ],

    'documents' => [
        'title' => 'E-posta ve faturalar',
        'description' => 'Mesajların hangi kimlikle çıkacağı. Onları gönderen kimlik bilgileri yapılandırmada kalır.',
        'email_from_name' => 'Gönderen adı',
        'email_from_address' => 'Gönderen adresi',
        'email_footer' => 'E-posta alt bilgisi',
        'invoice_footer' => 'Fatura alt bilgisi',
        'invoice_footer_hint' => 'Ödeme koşulları, sicil numarası; hukuk düzeninizin beklediği ne varsa.',
    ],

    'legal_links' => [
        'title' => 'Yasal bağlantılar',
        'description' => 'Mağaza alt bilgisinde gösterilir. Her ülke farklı bir küme ister.',
        'label' => 'Etiket',
        'url' => 'Adres',
        'add' => 'Bağlantı ekle',
        'remove' => 'Kaldır',
        'none' => 'Henüz bağlantı yok.',
    ],

    'vendor' => [
        'title' => 'Platform imzası',
        'description' => 'Mağaza alt bilgisinde bu platforma atıfta bulunan satır.',
        'hide' => ':mark yazısını gizle',
        'not_entitled' => 'Platform imzasını kaldırmak bu lisansa dahil değil.',
    ],

    'themes' => [
        'title' => 'Temalar',
        'description' => 'Tema; şablonlar, varlıklar ve bir manifestodur. Davranış modülün işidir.',
        'current' => 'Kullanımda',
        'parent' => ':parent temasını genişletir',
        'by' => ':author tarafından',
        'apply' => 'Bu temayı kullan',
        'refused' => 'Bu tema kullanılamaz:',
        'none' => 'Yalnızca çekirdek tema kurulu.',
        'inherits' => 'Geçersiz kılmadığı her şey için genişlettiği temaya düşer.',
    ],

    'inherited' => [
        'title' => 'Müşterilerin gördüğü',
        'description' => 'Sizin belirlemediğiniz her şey üstünüzdeki organizasyondan doldurulmuş hâliyle.',
        'from_parent' => 'Devralındı',
    ],

    'surfaces' => [
        'storefront' => 'Mağaza',
        'client' => 'Müşteri alanı',
        'admin' => 'Yönetim paneli',
    ],

    'features' => [
        'branding_remove_vendor_mark' => 'Platform imzasını kaldır',
    ],

    'errors' => [
        'not_permitted' => 'Ayarları değiştirme izniniz yok.',
        'no_organization' => 'Bu hesabın markalayacağı bir organizasyon yok.',
    ],
];
