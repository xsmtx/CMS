<?php

declare(strict_types=1);

return [
    'heading' => 'Uygulamalar ve Entegrasyonlar',
    'description' => 'Bu platformu başka bir şeye bağlayan her şey. Yalnızca kurulumun sahibine açıktır.',

    'errors' => [
        'super_admin_only' => 'Uygulamalar ve Entegrasyonlar yalnızca süper yöneticilere açıktır. Bir modülü etkinleştirmek bu platformun göndermediği kodu çalıştırır, sunucu eklemek ise bir makineye ait kimlik bilgilerini devreder.',
    ],

    'areas' => [
        'modules' => [
            'label' => 'Modüller',
            'description' => 'Ödeme yöntemi, kurulum, alan adı sağlayıcısı ve fazlasını ekleyen paketler. Siz etkinleştirene kadar hiçbiri çalışmaz.',
            'unit' => 'etkin',
        ],
        'servers' => [
            'label' => 'Sunucular',
            'description' => 'Hesapların açıldığı makineler ve onlara ulaşan kimlik bilgileri.',
            'unit' => 'tanımlı',
        ],
    ],
];
