<?php

declare(strict_types=1);

return [
    'groups' => [
        'title' => 'Ürün grupları',
        'subtitle' => 'Ürünlerin mağazada nasıl sıralandığı.',
        'create' => 'Yeni grup',
        'edit' => 'Grubu düzenle',
        'empty' => 'Henüz ürün grubu yok.',
        'saved' => 'Grup kaydedildi.',
        'deleted' => 'Grup silindi.',
        'products_count' => ':count ürün',
    ],

    'products' => [
        'title' => 'Ürünler',
        'subtitle' => 'Müşterilerin satın alabileceği şeyler.',
        'create' => 'Yeni ürün',
        'edit' => 'Ürünü düzenle',
        'empty' => 'Bu grupta henüz ürün yok.',
        'sold_out' => 'Tükendi',
        'stock_remaining' => ':count adet kaldı',
        'unlimited_stock' => 'Sınırsız',
        'requires_domain' => 'Ödeme adımında alan adı ister',
        'saved' => 'Ürün kaydedildi.',
        'deleted' => 'Ürün silindi.',
    ],

    'pricing' => [
        'title' => 'Fiyatlandırma',
        'subtitle' => 'Her fatura dönemi bir satır, her para birimi bir sütun.',
        'recurring' => 'Dönemsel',
        'setup' => 'Kurulum ücreti',
        'not_sold' => 'Satışta değil',
        'not_sold_hint' => 'Bir hücreyi boş bırakmak o dönemde satışı durdurur.',
        'add_currency' => 'Para birimi ekle',
        'save' => 'Fiyatları kaydet',
        'saved' => 'Fiyatlar kaydedildi.',
        'negative_not_allowed' => 'Ürün fiyatı negatif olamaz.',
    ],

    'options' => [
        'title' => 'Yapılandırılabilir seçenekler',
        'subtitle' => 'Ürünün ne olduğunu değiştiren, farkı fiyatlanan seçimler.',
        'create' => 'Yeni seçenek grubu',
        'edit' => 'Seçenek grubunu düzenle',
        'empty' => 'Henüz yapılandırılabilir seçenek yok.',
        'required' => 'Zorunlu',
        'default' => 'Varsayılan',
        'add_choice' => 'Seçenek ekle',
        'saved' => 'Seçenekler kaydedildi.',
        'deleted' => 'Seçenek grubu silindi.',
    ],

    'addons' => [
        'title' => 'Ek hizmetler',
        'subtitle' => 'Müşterinin sonradan ekleyip çıkarabileceği ayrı kalemler.',
        'create' => 'Yeni ek hizmet',
        'edit' => 'Ek hizmeti düzenle',
        'empty' => 'Henüz ek hizmet yok.',
        'saved' => 'Ek hizmet kaydedildi.',
        'deleted' => 'Ek hizmet silindi.',
    ],

    'currencies' => [
        'title' => 'Para birimleri',
        'subtitle' => 'Kurulumun hangi para biriminde, hangi kurla işlem yaptığı.',
        'create' => 'Para birimi ekle',
        'edit' => 'Para birimini düzenle',
        'base' => 'Ana para birimi',
        'base_hint' => 'Diğer bütün kurlar buna göre verilir.',
        'rate' => 'Kur',
        'rate_hint' => 'Raporlama için kullanılır. Ödemede fiyatlar çevrilmez.',
        'active' => 'Aktif',
        'history' => 'Kur geçmişi',
        'rate_format' => 'Kur, 42.12345678 gibi ondalık bir sayı olmalıdır.',
        'captured_at' => 'Kaydedildi',
        'saved' => 'Para birimi kaydedildi.',
        'deleted' => 'Para birimi silindi.',
    ],

    'status' => [
        'active' => 'Aktif',
        'hidden' => 'Gizli',
        'retired' => 'Kaldırıldı',
        'hidden_hint' => 'Menüde görünmez, doğrudan bağlantıyla erişilebilir.',
        'retired_hint' => 'Sipariş edilemez.',
    ],

    'cycles' => [
        'one_time' => 'Tek seferlik',
        'monthly' => 'Aylık',
        'quarterly' => '3 aylık',
        'semi_annually' => '6 aylık',
        'annually' => 'Yıllık',
        'biennially' => '2 yıllık',
        'triennially' => '3 yıllık',
    ],

    'cycle_short' => [
        'one_time' => 'tek sefer',
        'monthly' => '/ay',
        'quarterly' => '/3 ay',
        'semi_annually' => '/6 ay',
        'annually' => '/yıl',
        'biennially' => '/2 yıl',
        'triennially' => '/3 yıl',
    ],

    'types' => [
        'shared_hosting' => 'Paylaşımlı hosting',
        'reseller' => 'Bayi hosting',
        'vps' => 'VPS',
        'dedicated' => 'Fiziksel sunucu',
        'ssl' => 'SSL sertifikası',
        'email' => 'E-posta',
        'license' => 'Lisans',
        'service' => 'Hizmet',
        'other' => 'Diğer',
    ],

    'option_types' => [
        'select' => 'Açılır liste',
        'radio' => 'Seçim düğmeleri',
        'checkbox' => 'Onay kutusu',
        'quantity' => 'Adet',
    ],

    'storefront' => [
        'title' => 'Hosting paketleri',
        'subtitle' => 'Bir paket seçin. İstediğiniz zaman değiştirin.',
        'starting_at' => 'Başlangıç',
        'order_now' => 'Sipariş ver',
        'configure' => 'Yapılandır',
        'features' => 'Pakette neler var',
        'empty' => 'Burada henüz satışta bir şey yok.',
        'setup_fee' => ':amount kurulum',
        'no_setup_fee' => 'Kurulum ücreti yok',
    ],

    'errors' => [
        'duplicate_price_cell' => 'Fiyat matrisinde :currency para biriminde :cycle dönemi için iki kayıt var.',
        'group_not_empty' => 'Bu grupta hâlâ :count ürün var. Önce taşıyın ya da kaldırın.',
        'unknown_group' => 'Böyle bir ürün grubu yok.',
        'base_currency_locked' => ':code ana para birimi olduğu için silinemez.',
        'currency_in_use' => ':code hâlâ :count fiyatta kullanılıyor. Silmek yerine pasife alın.',
    ],
];
