<?php

declare(strict_types=1);

/*
 * Kaynak grafiği ve bağdaştırıcıları için operatör sözlüğü.
 *
 * `capabilities` anahtarları noktalı yetenek değerleridir ve `CapabilityNames`
 * tarafından okunur; asla `__()` ile değil — noktalı bir anahtar çeviriciye var
 * olmayan bir yuvalanmayı gezdirir. İzinlerin tabi olduğu kuralın aynısı.
 */

return [
    'title' => 'Altyapı',

    'kinds' => [
        'organization' => 'Kuruluş',
        'server' => 'Sunucu',
        'service' => 'Hizmet',
        'customer' => 'Müşteri',
    ],

    'relations' => [
        'contains' => 'içerir',
        'hosts' => 'barındırır',
        'powers' => 'besler',
        'connects' => 'bağlanır',
        'serves' => 'sunar',
        'assigned_to' => 'atandı',
        'depends_on' => 'bağımlı',
    ],

    'areas' => [
        'monitoring' => 'İzleme',
        'network_device' => 'Ağ cihazları',
        'firewall' => 'Güvenlik duvarları',
        'switching' => 'Anahtarlar',
        'routing' => 'Yönlendirme',
        'flow' => 'Trafik akışı',
        'ddos' => 'DDoS',
        'dns' => 'DNS',
        'certificate' => 'Sertifikalar',
        'waf' => 'WAF',
        'cdn' => 'CDN',
        'storage' => 'Depolama',
        'database_telemetry' => 'Veritabanları',
        'load_balancer' => 'Yük dengeleyiciler',
        'hypervisor' => 'Sanallaştırma',
        'bmc' => 'Fiziksel sunucular',
        'pdu' => 'Güç',
        'ups' => 'UPS',
        'backup' => 'Yedekleme',
        'automation' => 'Yapılandırma',
        'log' => 'Günlükler',
        'secret' => 'Sırlar',
        'metering' => 'Kullanım ölçümü',
    ],

    'units' => [
        'ratio' => 'oran',
        'bytes' => 'bayt',
        'bits_per_second' => 'bit/sn',
        'seconds' => 'saniye',
        'milliseconds' => 'ms',
        'count' => '',
        'per_second' => '/sn',
        'celsius' => '°C',
        'watts' => 'W',
    ],

    'metrics' => [
        'cpu.utilisation' => 'CPU',
        'load.average' => 'Yük',
        'memory.used' => 'Kullanılan bellek',
        'memory.total' => 'Bellek',
        'disk.used' => 'Kullanılan disk',
        'disk.total' => 'Disk',
        'disk.iops' => 'Disk IOPS',
        'disk.latency' => 'Disk gecikmesi',
        'network.in' => 'Gelen trafik',
        'network.out' => 'Giden trafik',
        'network.packet_loss' => 'Paket kaybı',
        'uptime' => 'Çalışma süresi',
        'response.latency' => 'Yanıt süresi',
        'sessions' => 'Oturumlar',
        'accounts' => 'Hesaplar',
        'requests.rate' => 'İstekler',
        'requests.errors' => 'Hatalar',
        'queue.depth' => 'Kuyruk',
        'replication.lag' => 'Replikasyon gecikmesi',
        'cache.hit_ratio' => 'Önbellek isabet oranı',
        'temperature' => 'Sıcaklık',
        'power.draw' => 'Güç tüketimi',
        'battery.charge' => 'Batarya',
        'battery.runtime' => 'Batarya süresi',
    ],

    'capabilities' => [
        'monitoring.metrics.read' => [
            'label' => 'Ölçümleri oku',
            'description' => 'Bu kaynağa izlediği varlıklarla ilgili güncel sayıları sorar.',
        ],
        'monitoring.alerts.read' => [
            'label' => 'Uyarıları oku',
            'description' => 'Bu kaynağın şu anda neye uyarı verdiğini gösterir.',
        ],
        'monitoring.alerts.write' => [
            'label' => 'Uyarıyı onayla veya sustur',
            'description' => 'Uyarı durumunu izleme sisteminin kendisinde değiştirir.',
        ],
    ],

    'explorer' => [
        'title' => 'Gezgin',
        'intro' => 'Bu kurulumun bildiği her şey ve birbirleriyle ilişkileri.',
        'empty' => 'Grafikte henüz bir şey yok. Kaynak grafiği görevi saatlik olarak doldurur.',
        'search' => 'Ada veya anahtara göre ara',
        'all_kinds' => 'Her tür',
        'all_health' => 'Her durum',
        'columns' => [
            'label' => 'Kaynak',
            'kind' => 'Tür',
            'health' => 'Durum',
            'source' => 'Kaynak',
            'seen' => 'Son görülme',
        ],
        'stats' => [
            'nodes' => 'Kaynaklar',
            'unwatched' => 'Bildirim yok',
            'retired' => 'Emekli',
        ],
        'drawer' => [
            'sits_on' => 'Üzerinde durduğu',
            'contains' => 'İçerdiği',
            'impact' => 'Arızalanırsa',
            'history' => 'Geçmiş',
            'metrics' => 'Son ölçümler',
            'no_metrics' => 'Bunun için ölçüm bildiren bir şey yok.',
            'no_history' => 'Hiçbir ilişki değişmedi.',
            'services' => 'hizmet',
            'customers' => 'müşteri',
            'since' => ':when tarihinden beri',
            'until' => ':when tarihine kadar',
            'open_subject' => 'Kaydı aç',
            'truncated' => ':depth seviyede durdu; aşağıda daha fazlası olabilir.',
        ],
    ],

    'adapters' => [
        'title' => 'Bağdaştırıcılar',
        'intro' => 'Bu kurulumun neyi okuyabildiği ve neyi değiştirmesine izin verildiği.',
        'empty' => 'Henüz hiçbir modül bağdaştırıcı sağlamıyor.',
        'columns' => [
            'name' => 'Bağdaştırıcı',
            'vendor' => 'Üretici',
            'module' => 'Modül',
            'areas' => 'Kapsam',
            'health' => 'Durum',
            'writes' => 'Değişiklik',
        ],
        'read_only' => 'Yalnızca okuma',
        'writes_allowed' => 'Değişiklik yapabilir',
        'writes_none' => 'Tasarımı gereği yalnızca okur',
        'allow_writes' => 'Değişikliğe izin ver',
        'revoke_writes' => 'Değişiklik iznini kaldır',
        'enable' => 'Etkinleştir',
        'disable' => 'Devre dışı bırak',
        'check' => 'Şimdi denetle',
        'orphaned' => 'Bu bağdaştırıcıyı şu anda hiçbir modül sağlamıyor.',
        'unsupported' => ':version sürümü, bu bağdaştırıcının yazıldığı sürümden yeni.',
        'never_checked' => 'Hiç denetlenmedi',
        'high_risk' => 'Yüksek riskli',
        'confirm_writes' => [
            'title' => ':name değişiklik yapabilsin mi?',
            'body' => 'Canlı altyapıda aşağıdakileri yapabilecek. Bu kurulumda başka hiçbir şey değişmiyor.',
            'phrase' => 'DEĞİŞİKLİĞE İZİN VER',
        ],
    ],

    'telemetry' => [
        'title' => 'Telemetri',
        'intro' => 'Ne geliyor, nereden geliyor ve ne kesildi.',
        'empty' => 'Hiç ölçüm gelmedi. Önce bir bağdaştırıcı etkinleştirilmeli.',
        'columns' => [
            'resource' => 'Kaynak',
            'metric' => 'Ölçüm',
            'value' => 'Değer',
            'sampled' => 'Alındığı an',
            'source' => 'Kaynak',
        ],
        'stats' => [
            'measurements' => 'Ölçümler',
            'sources' => 'Kaynaklar',
            'stale' => 'Bayat',
            'unwatched' => 'Bildirim yok',
        ],
        'stale' => 'Bayat',
        'fresh' => 'Güncel',
        'unwatched_intro' => 'Hakkında hiçbir bildirim gelmeyen kaynaklar:',
    ],

    'errors' => [
        'not_permitted' => 'Bunu yapma izniniz yok.',
        'unknown_adapter' => 'Bu bağdaştırıcıyı şu anda hiçbir modül sağlamıyor.',
        'no_writes' => 'Bu bağdaştırıcının değiştirebileceği bir şey yok.',
    ],
];
