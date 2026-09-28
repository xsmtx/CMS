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

        'errors' => [
            'does_not_fit' => ':start biriminden başlayan :heightU yükseklikte bir cihaz, :units U olan :rack kabinetinin tepesini aşar.',
            'overlaps' => ':device zaten :from ile :to birimleri arasında.',
            'overlaps_unnamed' => ':from ile :to birimleri zaten dolu.',
            'occupied' => ':rack kabinetinin :start birimi, bu kaydedilirken doldu.',
            'bad_height' => 'Bir cihaz en az bir birim kaplar.',
            'nothing_to_place' => 'Bir sunucu seçin ya da bu konuma bir ad verin.',
        ],
    ],

    'remote_hands' => [
        'title' => 'Saha desteği',
        'intro' => 'Veri merkezindeki birinden bir makineye dokunmasını istemek. Talep olmadan önce bir kayıt — bir makinenin açıldığını söyleyen denetim kaydı, birinin açmasının istendiğini söyleyen kayıttan değerlidir.',
        'empty' => 'Bekleyen yok',
        'empty_detail' => 'Açık iş yok. Binada bir çift ele ihtiyaç olduğunda bir iş açın.',
        'requested' => 'Açıldı.',
        'moved' => 'Kaydedildi.',
        'add' => 'Bir şey iste',
        'show_all' => 'Hepsini göster',
        'show_open' => 'Açık olanları göster',
        'waiting_since' => ':date tarihinden beri bekliyor',
        'scheduled_for' => ':date için',
        'serials' => ':old çıktı, :new takıldı',
        'no_serials' => 'Seri numarası kaydedilmedi',
        'unplaced' => 'Kabinet ya da makine belirtilmedi',

        'fields' => [
            'summary' => 'Yapılması gereken',
            'instructions' => 'Yönerge',
            'instructions_hint' => 'Korídorda duran birinin ihtiyacı olan sözlerle. Yuva numarası, ışık rengi, hangi yönü.',
            'rack' => 'Kabinet',
            'server' => 'Makine',
            'part' => 'Parça',
            'technician' => 'Teknisyen',
            'technician_hint' => 'Bir ad. Veri merkezinde çalışır ve burada hesabı yoktur.',
            'scheduled_for' => 'Kararlaştırılan zaman',
            'old_serial' => 'Çıkan seri numarası',
            'new_serial' => 'Takılan seri numarası',
            'outcome' => 'Ne oldu',
            'evidence' => 'Kanıt',
            'evidence_hint' => 'Başka bir yerdeki bir kayda ya da fotoğrafa bağlantı. Buraya hiçbir dosya yüklenmez.',
            'save' => 'Aç',
            'none' => 'Yok',
        ],

        'states' => [
            'requested' => 'Bekliyor',
            'scheduled' => 'Kararlaştırıldı',
            'in_progress' => 'Biri orada',
            'done' => 'Tamamlandı',
            'cancelled' => 'Vazgeçildi',
        ],

        'moves' => [
            'requested' => 'Yeniden beklemeye al',
            'scheduled' => 'Bir zaman kararlaştır',
            'in_progress' => 'Biri şu an orada',
            'done' => 'Kapat',
            'cancelled' => 'İşi iptal et',
        ],

        'errors' => [
            'cannot_move' => '“:from” durumundaki bir iş “:to” olamaz.',
            'needs_outcome' => 'Kapatmadan önce ne olduğunu yazın.',
        ],

        'columns' => [
            'task' => 'İş',
            'where' => 'Nerede',
            'state' => 'Durum',
            'waiting' => 'Açıldı',
        ],
    ],

    'power' => [
        'feeds' => [
            'a' => 'A hattı',
            'b' => 'B hattı',
            'unknown' => 'Hat belirtilmemiş',
        ],

        'sensors' => [
            'temperature' => 'Sıcaklık',
            'humidity' => 'Nem',
            'airflow' => 'Hava akışı',
            'leak' => 'Su',
            'smoke' => 'Duman',
            'door' => 'Kapı',
        ],
    ],

    'parts' => [
        'title' => 'Donanım',
        'intro' => 'Parçalar ve her birinin nerelerde bulunduğu. Bir disk, ilk takıldığı makineden daha uzun yaşar; garanti talebi de buna dayanır.',
        'empty' => 'Kayıtlı parça yok',
        'empty_detail' => 'Bir seri numarası ve garanti tarihi kaydedin; burası “bu hâlâ kapsamda mı” ve “bu disk nerelerde bulundu” sorularının yanıtı olsun.',
        'add' => 'Parça kaydet',
        'created' => 'Kaydedildi.',
        'fitted' => 'Takıldı.',
        'removed' => 'Çıkarıldı.',
        'fit' => 'Tak',
        'remove' => 'Çıkar',
        'remove_title' => ':name çıkarılsın mı?',
        'remove_body' => 'Bu, parçanın artık o makinede olmadığını kaydeder ve geçmişi korur. Hiçbir vida sökülmez — parça hâlâ takılıysa bu, kaydı yanlış hale getirir.',
        'on_the_shelf' => 'Rafta',
        'in_warranty' => 'Garantide',
        'out_of_warranty' => 'Garantisi bitmiş',
        'no_warranty' => 'Garanti kaydı yok',
        'history' => 'Nerelerde bulundu',
        'no_history' => 'Bu parça hiçbir şeye takılmadı.',
        'fitted_on' => ':date takıldı',
        'removed_on' => ':date çıkarıldı',
        'still_fitted' => 'Hâlâ takılı',
        'search' => 'Seri numarası, demirbaş no ya da model',
        'any_kind' => 'Her tür',

        'columns' => [
            'part' => 'Parça',
            'kind' => 'Tür',
            'where' => 'Nerede',
            'warranty' => 'Garanti',
        ],

        'fields' => [
            'kind' => 'Tür',
            'model' => 'Model',
            'serial' => 'Seri numarası',
            'asset_tag' => 'Demirbaş no',
            'vendor' => 'Üretici',
            'purchased_on' => 'Alındı',
            'warranty_until' => 'Garanti bitişi',
            'server' => 'Makine',
            'choose_server' => 'Bir makine seçin',
            'note' => 'Not',
            'save' => 'Kaydet',
        ],

        'kinds' => [
            'disk' => 'Disk',
            'memory' => 'Bellek',
            'cpu' => 'İşlemci',
            'power_supply' => 'Güç kaynağı',
            'network_card' => 'Ağ kartı',
            'optic' => 'Optik',
            'other' => 'Diğer',
        ],
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
