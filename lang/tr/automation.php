<?php

declare(strict_types=1);

return [
    'title' => 'Otomasyon',
    'description' => 'Bu platformun kendi başına yaptıkları ve en son ne yaptığı.',

    'tasks' => [
        'adapter_health' => [
            'label' => 'Adaptör sağlığı',
            'description' => 'Etkin her adaptöre, karşı taraftaki sistemin hâlâ yanıt verip vermediğini adaptörün kendi bildirdiği hızda sorar.',
        ],
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
        'zone_health' => [
            'label' => 'Bölge sağlığı',
            'description' => 'İlgili her DNS kaynağına bu kurulumun tuttuğu bölgeleri sorar ve yanlış olanları bildirir.',
        ],
        'reputation' => [
            'label' => 'Gönderim itibarı',
            'description' => 'İlgili her itibar kaynağına bu kurulumun kendi adreslerinin kara listede olup olmadığını sorar ve kaldırılanları kapatır.',
        ],
        'backups' => [
            'label' => 'Yedek kapsamı',
            'description' => 'İlgili her yedek kaynağına şu anda neyi koruduğunu sorar ve işten çıkanları emekliye ayırır.',
        ],
        'reconcile' => [
            'label' => 'Mutabakat',
            'description' => 'Her sağlayıcıya her hizmet hakkında neyi doğru saydığını sorar ve bizimle uyuşmadığı yerleri kaydeder. Bildirir, asla düzeltmez.',
        ],
        'leakage' => [
            'label' => 'Gelir kaçağı',
            'description' => 'Bu kurulumun kendi kayıtlarına dört soru sorar: hangi aktif hizmet, alan adı ve ek için fatura kesilmemiş ve hangi ödemeler hiçbir faturaya bağlanmamış.',
        ],
        'kubernetes' => [
            'label' => 'Küme envanteri',
            'description' => 'Her kümeye hangi makinelere sahip olduğunu ve üzerlerinde ne çalıştığını sorar; böylece bir düğüm boşaltıldığında kimin etkilendiğini grafik söyleyebilir.',
        ],
        'workspaces' => [
            'label' => 'Çalışma alanı envanteri',
            'description' => 'Her kod-olarak-altyapı aracına hangi çalışma alanlarına sahip olduğunu sorar; böylece biri için değişiklik istenebilir.',
        ],
        'sites' => [
            'label' => 'Site envanteri',
            'description' => 'Her panele hangi web uygulamalarını barındırdığını, her birinin ne kadar geride kaldığını ve bir eklenti ya da tema hakkında bir şey yayımlanıp yayımlanmadığını sorar.',
        ],
        'power' => [
            'label' => 'Güç ve ortam',
            'description' => 'Her PDU’ya hangi prizin hangi cihazı beslediğini, her kesintisiz güç kaynağına şebekenin yerinde olup olmadığını ve her kabinet sensörüne odanın ne kadar sıcak olduğunu sorar.',
        ],
        'usage' => [
            'label' => 'Kullanım ölçümü',
            'description' => 'Her ölçüm kaynağına geçen ay her hizmetin ne kadar kullandığını sorar ve bir sonraki faturanın alıntılayacağı bir anı yazar.',
        ],
        'machines' => [
            'label' => 'Makine envanteri',
            'description' => 'Her hipervizore hangi ana makinelere ve her birinin üzerinde hangi makinelere sahip olduğunu sorar.',
        ],
        'load_balancers' => [
            'label' => 'Yük dengeleyici envanteri',
            'description' => 'Her yük dengeleyiciye neyi dinlediğini ve her dinleyicinin arkasında hangi makinelerin olduğunu sorar.',
        ],
        'storage' => [
            'label' => 'Depolama envanteri',
            'description' => 'Her depolama sistemine hangi havuz ve birimleri sunduğunu sorar ve bunları kaynak grafiğine yazar.',
        ],
        'certificates' => [
            'label' => 'Sertifikalar',
            'description' => 'İlgili her kaynağa şu anda hangi sertifikaları sunduğunu sorar ve artık sunulmayanları emekliye ayırır.',
        ],
        'abuse_retention' => [
            'label' => 'Kötüye kullanım saklama',
            'description' => 'Saklama süresi dolan kötüye kullanım kanıtlarını siler. Buradaki işi unutmak olan tek görev.',
        ],
        'alerts' => [
            'label' => 'Uyarılar',
            'description' => 'Etkin her kurala doğru olup olmadığını sorar; yanlış olanı açar, düzelen kapanır.',
        ],
        'ddos' => [
            'label' => 'Saldırılar',
            'description' => 'Her DDoS kaynağına ne gördüğünü sorar ve adresin arkasında kimin hizmeti olduğunu bulur.',
        ],
        'access_grants' => [
            'label' => 'Geçici erişim',
            'description' => 'Süresi dolan geçici erişimleri kayda geçirir. Hiçbir şey bunun çalışmasına bağlı değildir: kontrol doğrudan erişimin kendisine sorar.',
        ],
        'topology' => [
            'label' => 'Topoloji',
            'description' => "Her ağ cihazına ne olduğunu sorar, portlarını ve VLAN'larını grafiğe yazar.",
        ],
        'webhooks' => [
            'label' => 'Webhook gönderimleri',
            'description' => 'Müşterinin uç noktasının henüz kabul etmediği gönderimleri yeniden dener.',
        ],
        'licence' => [
            'label' => 'Lisans sinyali',
            'description' => 'Bu kurulumun ayağında olduğunu sağlayıcıya bildirir ve neye izin verildiğini geri okur.',
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
        'task' => 'Görev',
        'cadence' => 'Sıklık',
        'result' => 'Sonuç',
        'dunning_link' => 'Ödenmemiş fatura dizisi',
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
        'remove' => 'Kaldır',
        'remove_title' => 'Bu adım kaldırılsın mı?',
        'remove_detail' => ':step artık gerçekleşmeyecek. O noktayı geçmiş faturalar bunun için tekrar takip edilmez.',
        'choose_event' => 'Bir mesaj seçin',
        'add_step' => 'Ekle',
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
            'late_fee' => 'Gecikme bedeli al',
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
    'operations' => [
        'title' => 'İşlemler',
        'subtitle' => 'Uzun süren işler; çalışırken ve durduktan sonra.',
        'needs_attention' => 'İlgi bekleyen',
        'subject' => 'Müşteri / hizmet',
        'type' => 'Modül / eylem',
        'error' => 'Hata nedeni',
        'attempt' => 'Deneme',
        'started' => 'Başlangıç',
        'next_attempt' => 'sonraki :when',
        'retry' => 'Yeniden dene',
        'resolve' => 'Çözüldü işaretle',
        'empty' => 'İlgi bekleyen bir şey yok',
        'empty_description' => 'Kurulumlar, kayıtlar, transferler ve yenilemeler gerçekleştikçe burada görünür.',
    ],
];
