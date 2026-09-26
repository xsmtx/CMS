<?php

declare(strict_types=1);

return [
    'title' => 'Sistem durumu',
    'description' => 'Ne çalışıyor, ne çalışmamak üzere ve ne çalışmıyor.',

    'states' => [
        'ok' => 'Sağlıklı',
        'degraded' => 'Bakmakta fayda var',
        'failing' => 'Çalışmıyor',
        'unknown' => 'Bildirim yok',
    ],

    'checks' => [
        'licence' => 'Lisans',
        'database' => 'Veritabanı',
        'cache' => 'Önbellek',
        'queue' => 'Kuyruk',
        'failed_jobs' => 'Başarısız işler',
        'scheduler' => 'Zamanlayıcı',
        'mail' => 'E-posta',
        'providers' => 'Sunucular',
    ],

    'database' => [
        'slow' => 'Veritabanı yanıt veriyor ama yavaş.',
    ],

    'cache' => [
        'not_readable' => 'Önbellek yazmayı kabul etti ama geri vermedi.',
    ],

    'queue' => [
        'busy' => 'Her zamankinden fazla iş bekliyor.',
        'backed_up' => 'Kuyruk birikmiş. Çalışanların ayakta olduğunu kontrol edin.',
    ],

    'jobs' => [
        'some_failed' => 'Bazı arka plan işleri tüm denemelerini tüketti.',
        'many_failed' => 'Çok sayıda arka plan işi başarısız oldu.',
    ],

    'scheduler' => [
        'never_seen' => 'Zamanlayıcı henüz haber vermedi.',
        'stale' => 'Zamanlayıcı haber vermeyi bıraktı. Kendi başına hiçbir şey çalışmıyor.',
    ],

    'mail' => [
        'not_configured' => 'E-posta gerçek bir yere gitmiyor. Mesajlar bir günlüğe yazılıyor.',
        'some_failed' => 'Bazı mesajlar teslim edilemedi.',
        'failing' => 'Mesajlar teslim edilmiyor.',
    ],

    'providers' => [
        'some_down' => 'Son sorduğumuzda bazı sunucular yanıt vermedi.',
        'all_down' => 'Son sorduğumuzda hiçbir sunucu yanıt vermedi.',
    ],

    'runtime' => [
        'title' => 'Bu kurulum',
        'version' => 'Sürüm',
        'php' => 'PHP',
        'environment' => 'Ortam',
        'checked_at' => 'Kontrol',
    ],

    'licence' => [
        'unreadable' => 'Lisans durumu okunamadı.',
        'unlicensed' => 'Lisanssız',
        'not_active' => 'Lisans etkin değil. Sağlayıcı imzası geri geldi; başka hiçbir şey değişmedi.',
        'in_grace' => 'Sinyal zamanı geçtiğinden beri lisans sunucusuna ulaşılamadı. Ek süre bitene kadar her şey çalışmaya devam eder.',
    ],

    'measurements' => [
        'latency_ms' => 'Gidiş dönüş',
        'failed' => 'Başarısız',
        'sent' => 'Gönderilen',
        'total' => 'Toplam',
        'unreachable' => 'Ulaşılamayan',
        'minutes_ago' => 'Dakika önce',
        'licence' => 'Lisans',
        'edition' => 'Sürüm',
        'status' => 'Durum',
        'expires_in_days' => 'Bitmesine kalan gün',
        'last_contact_days_ago' => 'Son bağlantıdan bu yana gün',
    ],
];
