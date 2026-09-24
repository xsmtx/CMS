<?php

declare(strict_types=1);

return [
    'heading' => 'Kurulum',
    'description' => 'Yapılandırdığınız her şey ve bu platformu başka bir şeye bağlayan her şey.',

    'sections' => [
        'setup' => [
            'title' => 'Kurulum',
            'description' => 'Katalog, burada çalışan kişiler ve bu kurulumun söyledikleri.',
        ],
        'integrations' => [
            'title' => 'Uygulamalar ve Entegrasyonlar',
            'description' => 'Yalnızca kurulumun sahibine açıktır. Buradakilerin her biri platformun dışına ya da ötesine uzanır.',
        ],
    ],

    'errors' => [
        'super_admin_only' => 'Uygulamalar ve Entegrasyonlar yalnızca süper yöneticilere açıktır. Bir modülü etkinleştirmek bu platformun göndermediği kodu çalıştırır, sunucu eklemek ise bir makineye ait kimlik bilgilerini devreder.',
        'nothing_to_setup' => 'Rolünüz kurulum ekranlarının hiçbirini açmıyor.',
    ],

    'areas' => [
        'modules' => [
            'label' => 'Modüller',
            'description' => 'Ödeme yöntemi, kurulum, alan adı sağlayıcısı ve fazlasını ekleyen paketler. Siz etkinleştirene kadar hiçbiri çalışmaz.',
            'unit' => 'etkin',
        ],
        'marketplace' => [
            'label' => 'Mağaza',
            'description' => 'Sağlayıcının bu kuruluma sunduğu paketler. Birini indirmek hiçbir şey çalıştırmaz.',
            'unit' => 'kurulu',
        ],
        'servers' => [
            'label' => 'Sunucular',
            'description' => 'Hesapların açıldığı makineler ve onlara ulaşan kimlik bilgileri.',
            'unit' => 'tanımlı',
        ],
        'tax' => [
            'label' => 'Vergi',
            'description' => 'Nerede ne tahsil edileceği. Hiçbir oran hazır gelmez, kuralları siz yazarsınız.',
            'unit' => 'kural',
        ],
        'billing_settings' => [
            'label' => 'Fatura koşulları',
            'description' => 'Faturanın vadesi, gecikmenin bedeli ve belge numarasının biçimi.',
            'unit' => '',
        ],
        'licence' => [
            'label' => 'Lisans',
            'description' => 'Bu kurulumun neye lisanslı olduğu ve sağlayıcıyla en son ne zaman konuştuğu.',
            'unit' => '',
        ],
        'import' => [
            'label' => 'İçe aktarma',
            'description' => 'Müşterileri, hizmetleri ve faturaları başka bir sistemden getirir. Satırları doğrudan yazar.',
            'unit' => '',
        ],
        'products' => [
            'label' => 'Ürünler',
            'description' => 'Ne satılıyor, hangi fiyatla, hangi faturalama döngüsünde.',
            'unit' => 'ürün',
        ],
        'product_groups' => [
            'label' => 'Ürün grupları',
            'description' => 'Ürünlerin mağazada ve sipariş formunda nasıl dizildiği.',
            'unit' => 'grup',
        ],
        'promotions' => [
            'label' => 'Promosyonlar',
            'description' => 'İndirim kodları, neye uygulandıkları ve kaç kez kullanılabildikleri.',
            'unit' => 'promosyon',
        ],
        'tlds' => [
            'label' => 'Alan adı uzantıları',
            'description' => 'Sattığınız uzantılar, sağlayıcıları ve maliyetleri.',
            'unit' => 'uzantı',
        ],
        'staff' => [
            'label' => 'Personel',
            'description' => 'Bu panele giren kişiler ve hangi rolleri taşıdıkları.',
            'unit' => 'kişi',
        ],
        'roles' => [
            'label' => 'Roller',
            'description' => 'Kim neyi yapabilir. Rol bir izin kümesidir, bir kişi değil.',
            'unit' => 'rol',
        ],
        'settings' => [
            'label' => 'Genel ayarlar',
            'description' => 'Bu kurulumun adı, yeri ve nasıl davrandığı.',
            'unit' => '',
        ],
        'notification_templates' => [
            'label' => 'Bildirim şablonları',
            'description' => 'Platformun gönderdiği her şeyin metni. Birini değiştirmek her müşteriye söyleneni değiştirir.',
            'unit' => 'şablon',
        ],
    ],
];
