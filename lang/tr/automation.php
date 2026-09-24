<?php

declare(strict_types=1);

return [
    'title' => 'Otomasyon',
    'description' => 'Bu platformun kendi başına yaptıkları ve en son ne yaptığı.',

    'tasks' => [
        'renewals' => [
            'label' => 'Yenileme faturaları',
            'description' => 'Yenilenmek üzere olan her hizmet ve alan adı için fatura oluşturur.',
        ],
        'dunning' => [
            'label' => 'Ödenmemiş faturalar',
            'description' => 'Ödenmemiş faturalar için hatırlatma sırasını işletir.',
        ],
        'overdue' => [
            'label' => 'Vadesi geçenler',
            'description' => 'Vadesi geçmiş faturaları gecikmiş olarak işaretler.',
        ],
        'domain_expiry' => [
            'label' => 'Alan adı süresi',
            'description' => 'Bir alan adının süresi dolmadan önce sahibini uyarır.',
        ],
        'retries' => [
            'label' => 'Yeniden denemeler',
            'description' => 'Yeniden denenmeyi bekleyen işlemleri tekrar kuyruğa alır.',
        ],
        'sync' => [
            'label' => 'Sağlayıcı eşitlemesi',
            'description' => 'Kontrol panellerine ve kayıt kuruluşlarına neyin doğru olduğunu sorar.',
        ],
        'resources' => [
            'label' => 'Kaynak grafiği',
            'description' => 'Grafiği kuruluşlar, sunucular ve hizmetlerle aynı çizgide tutar.',
        ],
        'telemetry' => [
            'label' => 'Telemetri',
            'description' => 'Etkin her izleme bağdaştırıcısına şu anda ne bildiğini sorar.',
        ],
        'cleanup' => [
            'label' => 'Temizlik',
            'description' => 'Süresi dolmuş sepetleri, okunmuş bildirimleri ve eski çalışma ayrıntılarını siler.',
        ],
    ],

    'status' => [
        'running' => 'Çalışıyor',
        'completed' => 'Tamamlandı',
        'failed' => 'Tamamlanamadı',
    ],

    'outcome' => [
        'changed' => 'Değiştirildi',
        'skipped' => 'Atlandı',
        'failed' => 'Başarısız',
    ],

    'runs' => [
        'title' => 'Çalışma geçmişi',
        'none' => 'Henüz hiçbir şey çalışmadı',
        'none_description' => 'Görevler zamanlanmış olarak çalışır. Dilerseniz şimdi çalıştırıp sonucu izleyebilirsiniz.',
        'examined' => 'İncelenen',
        'changed' => 'Değişen',
        'skipped' => 'Atlanan',
        'failed' => 'Başarısız',
        'started' => 'Başlangıç',
        'duration' => 'Süre',
        'run_now' => 'Şimdi çalıştır',
        'queued' => 'Görev çalıştırıldı. Sonucu aşağıdaki geçmişte.',
        'never' => 'Hiç çalışmadı',
        'last_run' => 'Son çalışma',
        'every' => ':minutes dakikada bir',
        'daily' => 'Günde bir',
        'nothing_to_do' => 'Yapılacak bir şey yok',
        'detail' => 'Neye dokundu',
    ],

    'dunning' => [
        'title' => 'Ödenmemiş fatura sırası',
        'description' => 'Ödenmemiş bir faturaya ne zaman ne olacağı. Askıya alma adımı olmayan bir sıra da geçerli bir seçimdir.',
        'none' => 'Tanımlı sıra yok',
        'none_description' => 'Bir adım eklenene kadar ödenmemiş faturaların peşine kimse düşmez.',
        'add' => 'Adım ekle',
        'offset' => 'Gün',
        'offset_hint' => 'Negatif değer vadeden önce, pozitif değer vadeden sonra demektir.',
        'action' => 'Eylem',
        'event' => 'Mesaj',
        'before_due' => 'Vadeden :days gün önce',
        'after_due' => 'Vadeden :days gün sonra',
        'on_due' => 'Vade gününde',
        'saved' => 'Sıra kaydedildi.',
        'removed' => 'Adım kaldırıldı.',
        'actions' => [
            'notify' => 'Mesaj gönder',
            'suspend' => 'Hizmetleri askıya al',
            'terminate' => 'Hizmetleri sonlandır',
        ],
        'suspended_reason' => 'Ödeme yapılmadığı için askıya alındı',
    ],

    'maintenance' => [
        'title' => 'Bakım modu',
        'description' => 'Mağazayı ve müşteri alanını kapatır. Personel yine de giriş yapabilir.',
        'message' => 'Ziyaretçilere gösterilecek mesaj',
        'until' => 'Bitiş',
        'until_hint' => 'Siz kapatana kadar açık kalması için boş bırakın.',
        'enable' => 'Aç',
        'disable' => 'Kapat',
        'enabled' => 'Bakım modu açık.',
        'disabled' => 'Bakım modu kapalı.',
        'active_since' => ':time tarihinden beri açık',
        'default_message' => 'Bakım çalışması yapıyoruz, kısa süre içinde döneceğiz.',
    ],

    'errors' => [
        'not_permitted' => 'Bunu yapma izniniz yok.',
        'unknown_task' => 'Böyle bir görev yok.',
    ],
];
