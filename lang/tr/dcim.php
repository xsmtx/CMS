<?php

declare(strict_types=1);

return [

    'title' => 'Veri merkezi',
    'intro' => 'Her şeyin fiziksel olarak nerede olduğu. Buradaki hiçbir şey keşfedilmez — kabinet bir API değildir, bunlar birinin yazdıklarıdır.',

    'empty' => 'Henüz bir şey kaydedilmedi',
    'empty_detail' => 'Bir veri merkezi, bir oda ve bir kabinet ekleyin; burası “nerede yer var” ve “birini hangi kabinete göndereyim” sorularının yanıtı olsun.',

    'no_racks' => 'Bu odada henüz kabinet yok.',
    'free_units' => ':units U’nun :free U’su boş',
    'placed' => 'Kaydedildi.',
    'removed' => 'Kabinetten çıkarıldı.',
    'rack_created' => 'Kabinet eklendi.',

    'rack' => [
        'elevation' => 'Kabinet görünümü',
        'elevation_hint' => 'Kabinetin önünde durur gibi yukarıdan aşağıya. Üniteler raylardaki gibi aşağıdan yukarıya numaralanır.',
        'free' => 'Boş',
        'continues' => 'devam ediyor',
        'add' => 'Bir şey yerleştir',
        'start_unit' => 'En alt ünite',
        'unit_height' => 'Ünite sayısı',
        'server' => 'Sunucu',
        'no_server' => 'Burada sunucu yok',
        'label' => 'Ya da bir ad',
        'label_hint' => 'Anahtar, patch paneli ya da bu platformun satmadığı başka bir şey için.',
        'save' => 'Kaydet',
        'remove' => 'Çıkar',
        'remove_title' => ':name, :rack kabinetinden çıkarılsın mı?',
        'remove_body' => 'Bu, ünitenin boş olduğunu kaydeder. Hiçbir şeyin gücü kesilmez ve kimse bir yere gönderilmez — makine hâlâ kabinetteyse bu, şemayı yanlış hâle getirir.',
    ],

    'racks' => [
        'add' => 'Kabinet ekle',
        'name' => 'Ad',
        'units' => 'U olarak yükseklik',
        'used' => 'Dolu',
        'room' => 'Oda',
        'save' => 'Ekle',
    ],
];
