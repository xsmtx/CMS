<?php

declare(strict_types=1);

return [
    /*
     * Bir paketin neden alınmadığı. Her biri kodda ayrı ayrı adlandırılır,
     * çünkü "indirme başarısız" demek, bozuk bir yansıyla bir saldırıyı
     * denetim kaydında aynı gösterirdi (ADR 0047).
     */
    'errors' => [
        'not_offered' => 'Mağazada :slug adında bir paket yok.',
        'disabled' => 'Bu kurulum için bir mağaza yapılandırılmamış, dolayısıyla alınacak bir yer yok.',
        'unreachable' => 'Sağlayıcı :slug için yanıt vermedi.',
        'too_large' => ':slug paketi, bu kurulumun indireceğinden büyük (:limitBytes bayt).',
        'digest' => ':slug paketi, katalogun onun için yayımladığı özetle eşleşmiyor.',
        'signature' => ':slug paketi bu sağlayıcı tarafından imzalanmamış.',
        'unsigned' => 'Bu dağıtımda paketleme genel anahtarı yok, bu yüzden hiçbir paket doğrulanamaz.',
        'unreadable_archive' => ':slug paketi, bu platformun açabileceği bir arşiv değil.',
        'unsafe_path' => ':slug paketi, kendi dizininin dışına yazılacak bir girdi içeriyor (:entry).',
        'slug_mismatch' => ':offered olarak sunulan paket kendisine :declared diyor.',
        'not_ours' => ':slug modülü diskten kurulmuş, bu yüzden mağaza onun yerine bir şey koymaz.',
    ],

    'title' => 'Mağaza',
    'intro' => 'Bu kurulumun ekleyebileceği paketler. Buradaki hiçbir şey siz kurup ardından etkinleştirmeden çalışmaz.',

    'installed' => 'İndirildi ve kuruldu. Henüz hiçbir kısmı çalışmıyor — Modüller ekranından etkinleştirin.',

    'empty' => [
        'title' => 'Sunulan bir şey yok',
        'description' => 'Sağlayıcının bu kurulum için bir şeyi yok ya da kendisine ulaşılamadı. Kurulu olan her şey iki durumda da çalışmaya devam eder.',
    ],

    'unconfigured' => [
        'title' => 'Tanımlı mağaza yok',
        'description' => 'Bu kurulumda mağaza adresi tanımlı değil, dolayısıyla gözatılacak bir şey yok. Bir modül yine de dizinini modules klasörüne koyarak eklenebilir.',
    ],

    'disabled' => [
        'title' => 'Burada modüller kapalı',
        'description' => 'Bu kurulum üçüncü taraf kod çalıştırmamaya karar vermiş. Bu değişmeden hiçbir şey indirilemez.',
    ],

    'unsigned' => [
        'title' => 'Paketleme anahtarı yok',
        'description' => 'Sağlayıcının paketleme anahtarı olmadan bir indirmenin ona ait olduğu kanıtlanamaz, bu yüzden hiçbiri kabul edilmez. Bu, kapatılacak bir ayar değildir.',
    ],

    'columns' => [
        'package' => 'Paket',
        'kind' => 'Tür',
        'version' => 'Sürüm',
        'size' => 'Boyut',
        'state' => 'Durum',
    ],

    'states' => [
        'available' => 'Kurulabilir',
        'installed' => 'Kurulu',
        'outdated' => 'Yeni sürüm var',
        'foreign' => 'Elle kurulmuş',
    ],

    'install' => 'Kur',
    'installing' => 'İndiriliyor',
    'read_more' => 'Bu paket hakkında',
    'needs' => 'Gereksinim: :packages',
    'by' => ':provider tarafından',

    'how_it_works' => 'Kurmak bir satır yazar ve dosyaları açar. Hiçbir şey çalıştırmaz: bir paketin çalıştığı an, Modüller ekranındaki etkinleştirmedir.',

    'foreign_note' => 'Bu modül modules klasörüne elle konulmuş, bu yüzden mağaza onu değiştirmez.',
];
