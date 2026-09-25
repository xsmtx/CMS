<?php

declare(strict_types=1);

return [
    'title' => 'Lisans',
    'subtitle' => 'Bu kurulumun neye lisanslı olduğu ve sağlayıcıyla en son ne zaman konuştuğu.',

    'activated' => 'Etkinleştirildi. Bu kurulum :edition sürümü için lisanslı.',
    'heartbeat_ok' => 'Lisans sunucusu yanıt verdi. Yapılacak bir şey yok.',
    'deactivated' => 'Lisans bu kurulumdan serbest bırakıldı.',

    'audit' => [
        'activated' => 'Lisans etkinleştirildi',
        'heartbeat' => 'Lisans doğrulandı',
        'heartbeat_failed' => 'Sağlayıcıya ulaşılamadı',
        'token_refused' => 'Belirteç reddedildi',
        'deactivated' => 'Lisans kaldırıldı',
        'deactivate_unreachable' => 'Sağlayıcıya ulaşılmadan kaldırıldı',
    ],
    'statuses' => [
        'active' => 'Etkin',
        'suspended' => 'Askıya alınmış',
        'revoked' => 'İptal edilmiş',
        'expired' => 'Süresi geçmiş',
    ],

    'errors' => [
        'not_permitted' => 'Bu kurulumun lisansını yalnızca kurulum sahibi görebilir.',
    ],
];
