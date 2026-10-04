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

        // Bu kurulumun kabul etmeyeceği bir belirteç. Her biri bir
        // yapılandırma sorunu değil bir güvenlik olayıdır; bu yüzden her
        // birinin kendi cümlesi vardır.
        'bad_signature' => 'Lisans belirteci bu sağlayıcı tarafından imzalanmamış.',
        'malformed' => 'Lisans belirteci okunamadı: :why.',
        'another_installation' => 'Lisans belirteci başka bir kuruluma verilmiş.',
        'issued_in_future' => 'Lisans belirtecinin tarihi gelecekte. Ya saatler uyuşmuyor ya da birisi belirteç üretiyor.',
        'replayed' => 'Lisans belirteci, bu kurulumun halihazırda tuttuğundan daha eski.',
        'no_public_key' => 'Bu dağıtımda lisans genel anahtarı yok, bu yüzden hiçbir belirteç doğrulanamaz.',
    ],
];
