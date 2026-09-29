<?php

declare(strict_types=1);

/*
 * Intelligence (§21, §22).
 *
 * Beş sınıfın sözcükleri iki kez okunmaya değer. "Kimseye sorulamadı" bir
 * arıza değildir ve öyle okunmamalıdır: yanıt vermeyen bir sağlayıcı hesap
 * hakkında bize hiçbir şey söylememiştir.
 */
return [
    'reconciliation' => [
        'title' => 'Mutabakat',
        'intro' => 'Bu platformun inandığı ile her sağlayıcının bildirdiği. Buradaki hiçbir şey kendiliğinden düzeltilmez — bulduğunu düzelten bir tarama, panel geç yanıt verdi diye bir müşteriyi askıya alırdı.',
        'empty' => 'Her şey uyuşuyor',
        'empty_detail' => 'Bu platformun sorabildiği her hizmet, sağlayıcının söylediği durumda.',
        'empty_all' => 'Henüz hiçbir şey karşılaştırılmadı',
        'empty_all_detail' => 'Tarama saatte bir çalışır. Beklemek istemiyorsanız Araçlar → Otomasyon ekranından şimdi çalıştırın.',

        'show_all' => 'Kapananları da göster',
        'show_open' => 'Hâlâ açık olanları göster',
        'dismiss' => 'Bilerek böyle',
        'dismissed' => 'Kaydedildi. Bu geçerli olduğu sürece yeniden açılmayacak.',
        'undismiss' => 'Yeniden aç',
        'undismissed' => 'Bir sonraki taramada yeniden açılacak.',

        'columns' => [
            'subject' => 'Ne',
            'class' => 'Sonuç',
            'expected' => 'Biz diyoruz',
            'found' => 'Onlar diyor',
            'since' => 'Ne zamandır',
            'answer' => 'Yanıt',
        ],

        'nothing_said' => 'Sağlayıcı bir şey söylemedi',
        'nobody_here' => 'Burada kaydı yok',
        'matched_on' => ':field arandı',
        'on_server' => ':server üzerinde',
        'through' => ':module ile',
        'unknown_detail' => 'Bu, hesabın kendisi hakkında bir şey söylemez.',

        'dismiss_title' => 'Bu bir daha açılmasın mı?',
        'dismiss_body' => 'Karşılaştırma yine yapılır ve düzeltildiğinde yine kendiliğinden kapanır. Değişen tek şey kimseye gösterilmemesidir.',
        'reason' => 'Bu neden bilerek böyle',
        'reason_hint' => 'Bu listeyi sizden sonra devralan kişi okuyacak. “Göç için elle kuruldu, mart ayında kaldırılacak.”',
        'until' => 'Şu tarihe kadar',
        'until_hint' => 'Biri geri alana kadar kapalı kalması için boş bırakın.',
        'dismissed_until' => ':date tarihine kadar kapalı',
        'dismissed_indefinitely' => 'Kapatıldı',
        'chosen' => 'Kaydedildi. Henüz bir şey yapılmadı.',
        'decided' => 'Kaydedildi.',
        'applied' => 'Yapıldı.',
        'apply_failed' => 'Olmadı. Sağlayıcının ne dediği satırda yazıyor.',
        'suggestion' => 'Önerilen',
        'choose' => 'Başka bir şey',
        'approve' => 'Onayla',
        'reject' => 'Reddet',
        'apply' => 'Yap',
        'decide_reason' => 'Neden (isteğe bağlı)',
        'chosen_by_operator' => 'Bir operatör seçti',
        'confirm_remote_title' => 'Bu, müşterinin hesabını değiştirir',
        'confirm_local_title' => 'Bu, bizim kaydımızı değiştirir',
        'type_to_confirm' => 'Onaylamak için hizmetin adını yazın.',
    ],

    'health' => [
        'title' => 'Müşteri durumu',
        'intro' => 'Kime bakılması gerektiği ve nedeni. Puan yok — 0 ile 100 arası bir sayı, birinin ona göre hareket edip kimsenin yeniden üretemediği bir sayıdır. Her işaret kendi hesabını taşır.',
        'empty' => 'Bakılacak bir şey yok',
        'empty_detail' => 'İncelenen her müşteride bir sorun yok: vadesi geçmiş fatura, kurulamamış hizmet, süresi aşan kayıt ya da açık kötüye kullanım dosyası yok.',
        'examined' => ':count müşteri incelendi.',
        'worst' => 'En kötüsü',
        'no_score' => 'Her müşteri için doğru olan en kötü şeye göre sıralanır, hiçbir toplama göre değil. Bu işaretlerin ikisi toplanamaz ve bir puan tam da bunu yapardı.',

        'columns' => [
            'customer' => 'Müşteri',
            'signals' => 'Ne doğru',
        ],

        'signals' => [
            'overdue' => 'Borçlu',
            'service_failed' => 'Bir hizmet hiç açılmadı',
            'operation_failed' => 'Bir işlem birini bekliyor',
            'ticket_breached' => 'Bir kaydın süresi aştı',
            'card_expiring' => 'Kartının süresi doluyor',
            'abuse_open' => 'Açık kötüye kullanım dosyası',
            'not_billed' => 'Faturalanmayan bir şey var',
        ],

        'descriptions' => [
            'overdue' => 'Kesilmiş, vadesi geçmiş ve ödenmemiş. Vade tarihinden ve kalan tutar üzerinden ölçülür.',
            'service_failed' => 'Kurulamamış ve başarısız durumda duran bir hizmet — müşteri hiç gelmemiş bir şey için ödüyor.',
            'operation_failed' => 'Kötü biten ya da bir kişiyi bekleyen bir işlem. Kimse bakmazsa kimseye söylenmez.',
            'ticket_breached' => 'Departmanın belirttiği süre aşıldı. Bekliyorlar ve bekletmeyeceğimizi söylemiştik.',
            'card_expiring' => 'Bir sonraki yenilemenin çekileceği kart. Süresi dolmuş bir kartta başarısız olan yenileme, kimsenin istemediği bir askıya almadır.',
            'abuse_open' => 'Birinin hâlâ yanıtlaması gereken bir şikâyet. Her durumda bir kararla biter.',
            'not_billed' => 'Bu müşteriye ait gelir kaçağı — faturalanmamış bir hizmet, alan adı ya da ek.',
        ],

        // A counted noun takes no plural in Turkish, so one form is right
        // for every number — `trans_choice` returns a string with no pipe
        // in it unchanged.
        'counts' => [
            'invoices' => ':count fatura, en eskisi :days gün gecikmiş',
            'services' => ':count hizmet',
            'operations' => ':count işlem',
            'tickets' => ':count kayıt',
            'card' => ':count gün kaldı',
            'cases' => ':count dosya',
            'findings' => ':count bulgu',
        ],
    ],

    'costs' => [
        'title' => 'Maliyetler',
        'intro' => 'Bu işletmenin başkasına ödedikleri. Buradaki hiçbir şey keşfedilmez — hiçbir adaptör bir barındırma faturasını ya da kirayı bildirmez — ve hiçbiri muhasebe defterine dokunmaz.',
        'empty' => 'Kayıtlı maliyet yok',
        'empty_detail' => 'Bir sunucunun, bir lisansın ya da ofisin ne tuttuğunu girin; kârlılık raporunun çalışacağı bir şey olsun.',
        'add' => 'Maliyet kaydet',
        'saved' => 'Kaydedildi.',
        'removed' => 'Kaldırıldı.',
        'remove' => 'Kaldır',
        'remove_title' => ':label kaldırılsın mı?',
        'remove_body' => 'Rakamlar bir sonraki rapordan itibaren bunu içermez. Kesilmiş faturalarda hiçbir şey değişmez — maliyet hiçbir zaman defterde değildi.',
        'edit' => 'Düzenle',

        'columns' => [
            'label' => 'Maliyet',
            'scope' => 'Neye karşılık',
            'amount' => 'Tutar',
            'shared' => 'Paylaştırma',
            'period' => 'Sıklık',
        ],

        'fields' => [
            'label' => 'Bu nedir',
            'label_hint' => 'Faturada nasıl söylerdiniz. “Hetzner AX102 — web-7”.',
            'vendor' => 'Kime ödeniyor',
            'scope' => 'Neye karşılık',
            'server' => 'Hangi sunucu',
            'product' => 'Hangi ürün',
            'amount' => 'Tutar',
            'currency' => 'Para birimi',
            'period' => 'Ne sıklıkta',
            'strategy' => 'Nasıl paylaştırılıyor',
            'metric' => 'Şuna göre ağırlıklı',
            'metric_hint' => 'Bu makine aslında neye göre satılıyor. Ölçümü olmayan bir hizmet bedava değil, ortalama sayılır.',
            'starts_on' => 'Başlangıç',
            'ends_on' => 'Bitiş',
            'dates_hint' => 'Haziranda alınan bir sunucu mayısta bir şey tutmuyordu. “Her zaman” için boş bırakın.',
            'note' => 'Not',
            'save' => 'Kaydet',
            'none' => 'Yok',
        ],

        'scopes' => [
            'server' => 'Bir sunucu',
            'product' => 'Bir ürün',
            'licence' => 'Bir lisans',
            'installation' => 'Tüm kurulum',
        ],

        'periods' => [
            'monthly' => 'Ay',
            'yearly' => 'Yıl',
            'one_off' => 'Bir kez',
        ],

        'strategies' => [
            'even' => 'Eşit olarak',
            'weighted' => 'Bir ölçüme göre',
        ],

        'strategy_descriptions' => [
            'even' => 'Üzerindeki her hizmet aynı payı alır. Kimin ne kullandığı hakkında hiçbir iddiası yoktur; makul varsayılan olmasının nedeni budur.',
            'weighted' => 'Grafiğin zaten sahip olduğu bir ölçümle orantılı paylaştırılır. Hiçbir ölçümü olmayan bir hizmet bedava değil, ortalama sayılır.',
        ],
    ],

    'profit' => [
        'title' => 'Kârlılık',
        'intro' => 'Bir ayda ne kazanıldığı ve bunu kazanmanın ne tuttuğu. Gelir, fatura satırlarından değil hizmetlerin kendisinden gelir — bir satır açıklamayı kopyalar ve ona göre gruplamak aynı ada getirilmiş iki ürünü birleştirir.',
        'empty' => 'Raporlanacak bir şey yok',
        'empty_detail' => 'Bu ay hiçbir hizmet bir şey kazanmıyor. Bir maliyet girin ve bir şey satın; burası kendiliğinden dolar.',
        'revenue' => 'Kazanılan',
        'cost' => 'Maliyet',
        'margin' => 'Kalan',
        'unallocated' => 'Paylaştırılmayan',
        'unallocated_hint' => 'Hiçbir hizmete ulaşmayan maliyetler — boş bir sunucu, kullanılmayan bir lisans. Ayın toplamında var, kimsenin satırında yok.',
        'previous' => 'Önceki ay',
        'next' => 'Sonraki ay',
        'mixed' => 'Karşılaştırılamaz',
        'mixed_hint' => 'Burada, hiçbir şey kazanılmayan bir para biriminde maliyet var ve bu ürünün hiçbir yerinde kur yok. İki rakam da doğru; aralarındaki fark bir sayı değil.',
        'services' => 'Hizmet',

        'columns' => [
            'group' => 'Ad',
            'services' => 'Hizmet',
            'revenue' => 'Kazanılan',
            'cost' => 'Maliyet',
            'margin' => 'Kalan',
        ],

        'groupings' => [
            'customer' => 'Müşteriye göre',
            'product' => 'Ürüne göre',
            'server' => 'Sunucuya göre',
        ],
    ],

    'leakage' => [
        'title' => 'Gelir kaçağı',
        'intro' => 'Sessizce gelmeyi bırakan para. Buradaki her satır, bu platformun zaten sahip olduğu kayıtlar üzerinde bir hesaptan ibaret — hiçbir sağlayıcıya sorulmaz ve hiçbir şey varsayılmaz.',
        'empty' => 'Kaçak yok',
        'empty_detail' => 'Her aktif hizmet, alan adı ve ek için fatura kesilmiş ve her ödeme bir şeye bağlanmış.',
        'at_stake' => 'Söz konusu tutar',
        'count' => ':count açık',
        'show_all' => 'Kapananları da göster',
        'show_open' => 'Hâlâ açık olanları göster',
        'total_hint' => 'Fatura kesilseydi ne kadar olacağı; borç tutarı değil.',
        'dismiss' => 'Bilerek böyle',
        'undismiss' => 'Yeniden aç',

        'columns' => [
            'subject' => 'Ne',
            'kind' => 'Neden',
            'customer' => 'Kimin',
            'amount' => 'Söz konusu',
            'since' => 'Ne zamandır',
        ],

        'kinds' => [
            'service_not_billed' => 'Faturalanmadı',
            'domain_not_renewed' => 'Yenileme faturası yok',
            'addon_not_billed' => 'Faturada yok',
            'unmatched_payment' => 'Ödendi, hiçbir şeye bağlı değil',
        ],

        'descriptions' => [
            'service_not_billed' => 'Aktif ve fiyatlı; vadesi geçtiğinden beri hiçbir fatura bundan söz etmedi.',
            'domain_not_renewed' => 'Yenileme tarihi geçmiş, otomatik yenilemeye açık ve yenileme faturası kesilmemiş.',
            'addon_not_billed' => 'Bağlı olduğu hizmet için fatura kesilmiş ve bu, o faturada yer almamış.',
            'unmatched_payment' => 'Alınan ve hiçbir faturaya bağlanmamış para — ödemesini yapmış ve hâlâ takip edilebilecek bir müşteri.',
        ],

        'no_customer' => 'Burada kimse yok',
    ],

    'actions' => [
        'accept_suspension' => 'Askıya alınmış diye kaydet',
        'accept_activation' => 'Aktif diye kaydet',
        'accept_termination' => 'Sonlandırılmış diye kaydet',
        'restore_service' => 'Sağlayıcıdan geri açmasını iste',
        'suspend_service' => 'Sağlayıcıdan askıya almasını iste',
        'remove_service' => 'Sağlayıcıdan yok etmesini iste',
        'investigate' => 'Birinin bakması gerek',
    ],

    'action_descriptions' => [
        'accept_suspension' => 'Yalnızca bizim kaydımızı değiştirir. Hesap sağlayıcıda olduğu gibi kalır.',
        'accept_activation' => 'Yalnızca bizim kaydımızı değiştirir. Hesap sağlayıcıda olduğu gibi kalır.',
        'accept_termination' => 'Yalnızca bizim kaydımızı değiştirir. Bundan sonra bunun için fatura kesilmez.',
        'restore_service' => 'Sağlayıcıya gider ve hesabın askıdan indirilmesini ister.',
        'suspend_service' => 'Sağlayıcıya gider ve hesabın askıya alınmasını ister. Müşteri sitesini kaybeder.',
        'remove_service' => 'Sağlayıcıya gider ve hesabı ile verilerini yok eder. Geri alınmaz ve buradaki hiçbir şey onu geri getiremez.',
        'investigate' => 'Birinin bunu okuduğunu ve otomatik bir yanıtın uymadığını kaydeder.',
    ],

    'proposal_states' => [
        'proposed' => 'Öneriliyor',
        'approved' => 'Onaylandı, henüz yapılmadı',
        'applied' => 'Yapıldı',
        'failed' => 'Olmadı',
        'rejected' => 'Reddedildi',
        'stale' => 'Güncelliğini yitirdi',
    ],

    'errors' => [
        'not_open' => '“:state” durumundaki bir öneri yeniden karara bağlanamaz.',
        'not_approved' => 'Biri onaylamadan hiçbir şey yapılmaz.',
        'stale' => 'Bunun yazıldığı fark artık eskisi gibi değil. Bir sonraki tarama şu an doğru olan için bir şey önerecek.',
        'not_available' => 'Bu türdeki bir bulgu “:action” ile yanıtlanamaz.',
        'nothing_to_act_on' => 'Bu bulgunun arkasında üzerinde işlem yapılacak bir hizmet yok.',
    ],

    'classes' => [
        'healthy' => 'Uyuşuyor',
        'drift' => 'Farklı',
        'orphan' => 'Sahipsiz',
        'missing' => 'Orada yok',
        'unknown' => 'Kimseye sorulamadı',
    ],

    'matched' => [
        'external_id' => 'sağlayıcının kendi kimliği',
        'domain' => 'eşleşen bir alan adı',
        'address' => 'adresin kendisi',
    ],

    'resources' => [
        'virtual_machine' => 'Sanal makine',
        'site' => 'Site',
        'ip_address' => 'Adres',
        'certificate' => 'Sertifika',
        'service' => 'Hizmet',
    ],
];
