<?php

declare(strict_types=1);

return [
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
