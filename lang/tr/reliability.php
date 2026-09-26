<?php

declare(strict_types=1);

return [
    'alerts' => [
        'title' => 'Uyarılar',
        'intro' => 'Şu anda neyin yanlış olduğu ve neyin düzeldiği.',
        'empty' => 'Yanlış bir şey yok',
        'empty_detail' => 'Kuralların sorduğu hiçbir şey şu anda doğru değil. Buradaki boş liste iyi sonuçtur.',
        'no_rules' => 'Henüz kural yazılmamış',
        'no_rules_detail' => 'Bu kurulum hazır kural getirmez. Başkasının seçtiği bir eşik, sizi iki hafta boyunca sabahın üçünde uyandırıp sonra kapatılan bir eşiktir; o yüzden ilk kural sizin.',
        'show_all' => 'Hepsini göster',
        'show_open' => 'Açık olanları göster',
        'columns' => [
            'subject' => 'Ne',
            'rule' => 'Kural',
            'severity' => 'Önem',
            'observed' => 'Okuma',
            'state' => 'Durum',
            'since' => 'Başlangıç',
            'seen' => 'Görülme',
        ],
    ],

    'rules' => [
        'title' => 'Uyarı kuralları',
        'intro' => 'Bu kurulumun hakkında bir şey söylemeye değer saydığı şeyler.',
        'add' => 'Kural yaz',
        'add_intro' => 'Her dakika sorulan bir soru. Doğru kalmadığı sürece buradan kimseye bir şey ulaşmaz.',
        'name' => 'Ad',
        'subject' => 'Neyle ilgili',
        'target' => 'Hangisi',
        'target_hint' => 'Hepsini sormak için boş bırakın.',
        'comparison' => 'Şu olduğunda',
        'threshold' => 'Eşik',
        'threshold_hint' => 'Oran için yüzde, kapasite tahmini için gün sayısı.',
        'for_minutes' => 'En az şu kadar süre, dakika',
        'for_minutes_hint' => 'Sıfır, doğru olduğu anda açar. Dokuz saniye süren bir tepe uyarı değildir.',
        'severity' => 'Önem',
        'notify' => 'Açıldığında birine haber ver',
        'notify_hint' => 'Kapalı olursa bu ekranda kalır, kimsenin akşamına karışmaz.',
        'enabled' => 'Etkin',
        'note' => 'Not',
        'delete' => 'Kuralı sil…',
        'delete_title' => 'Bu kural silinsin mi?',
        'delete_body' => 'Kural artık sorulmaz. Daha önce açtığı uyarılar durur, çünkü doğru olan doğru kalır.',
        'saved' => 'Kural kaydedildi.',
        'deleted' => 'Kural silindi.',
        'columns' => [
            'name' => 'Kural',
            'subject' => 'Neyle ilgili',
            'threshold' => 'Ne zaman',
            'severity' => 'Önem',
            'open' => 'Şu an açık',
            'state' => 'Durum',
        ],
    ],

    'severities' => [
        'warning' => 'Gün içinde',
        'critical' => 'Hemen',
        'emergency' => 'Müşteriler etkileniyor',
    ],

    'alert_states' => [
        'raised' => 'Açık',
        'suppressed' => 'Bekletiliyor',
        'cleared' => 'Kapandı',
    ],

    'subjects' => [
        'metric' => 'Bir ölçüm',
        'health_check' => 'Bir sağlık kontrolü',
        'adapter_health' => 'Bir bağdaştırıcı',
        'automation_run' => 'Bir otomasyon görevi',
        'failed_operation' => 'Başarısız bir işlem',
        'capacity' => 'Tükenmekte olan bir şey',
    ],

    'comparisons' => [
        'above' => 'üstünde',
        'below' => 'altında',
    ],

    'observed' => [
        'late' => 'Son çalışma :at',
        'failed_items' => ':count başarısız',
        'days_left' => ':days gün kaldı',
    ],
];
