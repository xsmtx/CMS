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
        ],

        'nothing_said' => 'Sağlayıcı bir şey söylemedi',
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
    ],

    'classes' => [
        'healthy' => 'Uyuşuyor',
        'drift' => 'Farklı',
        'orphan' => 'Sahipsiz',
        'missing' => 'Orada yok',
        'unknown' => 'Kimseye sorulamadı',
    ],

    'resources' => [
        'service' => 'Hizmet',
    ],
];
