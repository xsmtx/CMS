<?php

declare(strict_types=1);

return [
    'title' => 'İşlemler',
    'description' => 'Uzun süren işler; çalışırken ve durduktan sonra.',

    'none' => 'Çalışan bir şey yok',
    'none_description' => 'Kurulumlar, kayıtlar, transferler ve yenilemeler gerçekleştikçe burada görünür.',

    'states' => [
        'pending' => 'Bekliyor',
        'running' => 'Çalışıyor',
        'retrying' => 'Yeniden denenecek',
        'failed' => 'Başarısız',
        'manual_intervention' => 'Kişi gerekiyor',
        'completed' => 'Tamamlandı',
    ],

    'types' => [
        'service_provision' => 'Hizmeti kur',
        'service_suspend' => 'Hizmeti askıya al',
        'service_unsuspend' => 'Askıyı kaldır',
        'service_terminate' => 'Hizmeti sonlandır',
        'service_change_package' => 'Paketi değiştir',
        'service_sync' => 'Hizmeti eşitle',
        'domain_register' => 'Alan adını kaydet',
        'domain_transfer' => 'Alan adını transfer et',
        'domain_renew' => 'Alan adını yenile',
        'domain_sync' => 'Alan adını eşitle',
    ],

    'attempt' => ':max denemeden :attempt. deneme',
    'next_attempt' => 'Sonraki deneme :time',
    'needs_attention' => 'İlgilenilmesi gerekiyor',
    'retry' => 'Tekrar dene',
    'resolve' => 'Halledildi olarak işaretle',
    'retried' => 'İşlem tekrar kuyruğa alındı.',
    'resolved' => 'Halledildi olarak işaretlendi.',
    'resolved_at' => ':time tarihinde halledildi',
    'cannot_retry' => 'Bu işlem buradan yeniden denenemez.',
    'started' => 'Başlangıç',
    'finished' => 'Bitiş',
    'error' => 'Ne ters gitti',

    'errors' => [
        'not_permitted' => 'Bunu yapma izniniz yok.',
    ],
    'todo' => [
        'title' => 'Yapılacaklar',
        'subtitle' => 'Birinin sonra dönmeyi düşündüğü şeyler. Bir talep sistemi değil: üç durum, varsa bir tarih, gerekiyorsa bir isim.',
        'add' => 'Madde ekle',
        'save' => 'Kaydet',
        'edit' => 'Düzenle',
        'delete' => 'Sil',
        'done' => 'Bitti',
        'reopen' => 'Yeniden aç',
        'delete_title' => '":title" silinsin mi?',
        'delete_detail' => 'Not gider. Bu platformda ona atıfta bulunan başka bir şey yok; zaten bu yüzden içeriğini hiçbir şey size hatırlatmayacak.',
        'empty' => 'Listede bir şey yok',
        'empty_description' => 'Aksi hâlde sabahın ikisinde hatırlayacağınız şeyi yazın.',
    ],
];
