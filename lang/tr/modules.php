<?php

declare(strict_types=1);

return [
    'installed' => 'Modül kuruldu. Siz etkinleştirene kadar çalışmaz.',
    'enabled' => 'Modül etkinleştirildi.',
    'disabled' => 'Modül devre dışı bırakıldı.',
    'upgraded' => 'Modül güncellendi.',
    'uninstalled' => 'Modül kaldırıldı. Kendi tabloları yerinde bırakıldı.',
    'configured' => 'Modül ayarları kaydedildi.',

    'errors' => [
        'not_permitted' => 'Modülleri yönetme yetkiniz yok.',
    ],

    'types' => [
        'payment_gateway' => [
            'label' => 'Ödeme yöntemi',
            'description' => 'Para tahsil eder ve faturanın neye ait olduğunu görebilir.',
        ],
        'provisioning' => [
            'label' => 'Kurulum',
            'description' => 'Sunucularınızda hesap açar ve askıya alır.',
        ],
        'registrar' => [
            'label' => 'Alan adı sağlayıcısı',
            'description' => 'Alan adı kaydeder ve yeniler.',
        ],
        'notification_channel' => [
            'label' => 'Bildirim kanalı',
            'description' => 'Platformun göndermeye karar verdiği mesajları iletir.',
        ],
        'fraud' => [
            'label' => 'Risk',
            'description' => 'Bir siparişin incelemeye alınıp alınmayacağına karar verir.',
        ],
        'tax' => [
            'label' => 'Vergi',
            'description' => 'Hangi verginin uygulanacağını hesaplar.',
        ],
        'report' => [
            'label' => 'Rapor',
            'description' => 'Zaten kayıtlı olanı okuyan bir ekran ekler.',
        ],
        'admin_widget' => [
            'label' => 'Yönetim bileşeni',
            'description' => 'Yönetim panosuna bir panel ekler.',
        ],
        'client_widget' => [
            'label' => 'Müşteri bileşeni',
            'description' => 'Müşteri panosuna bir panel ekler.',
        ],
        'infrastructure' => [
            'label' => 'Altyapı',
            'description' => 'Sunucuları, adresleri ve onlara bağlı şeyleri keşfeder.',
        ],
        'addon' => [
            'label' => 'Eklenti',
            'description' => 'Yukarıdakilerden birkaçı. Neyi kaydettiğini okuyun.',
        ],
    ],

    'states' => [
        'installed' => 'Kurulu, çalışmıyor',
        'enabled' => 'Etkin',
        'disabled' => 'Devre dışı',
        'failed' => 'Durduruldu',
    ],

    'extension_points' => [
        'gateway' => 'Ödeme yöntemi',
        'provisioning_module' => 'Kurulum modülü',
        'registrar' => 'Alan adı sağlayıcısı',
        'channel' => 'Bildirim kanalı',
        'risk_evaluator' => 'Risk kontrolü',
        'tax_calculator' => 'Vergi hesaplaması',
        'health_check' => 'Sağlık kontrolü',
        'permission' => 'Yetki',
        'navigation' => 'Menü öğesi',
        'widget' => 'Pano bileşeni',
        'infrastructure_adapter' => 'Altyapı bağdaştırıcısı',
    ],
];
