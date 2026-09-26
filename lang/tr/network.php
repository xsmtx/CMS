<?php

declare(strict_types=1);

return [
    'title' => 'Adresleme',
    'intro' => 'Bu kurulumun sahip olduğu adres alanı ve her adresi kimin tuttuğu.',

    'families' => [
        'v4' => 'IPv4',
        'v6' => 'IPv6',
    ],

    'pool_purposes' => [
        'infrastructure' => 'Altyapı',
        'customer' => 'Müşteri',
    ],

    'address_states' => [
        'available' => 'Boş',
        'reserved' => 'Ayrılmış',
        'assigned' => 'Atanmış',
        'quarantined' => 'Bekletiliyor',
    ],

    'pools' => [
        'title' => 'Havuzlar',
        'intro' => 'Havuz, adres alanının belirli bir ağdan önce gruplandığı yerdir.',
        'empty' => 'Henüz havuz yok. Bir havuz, tek bir ailenin ve tek bir amacın ağlarını tutar.',
        'name' => 'Ad',
        'family' => 'Aile',
        'purpose' => 'Kullanım',
        'add' => 'Yeni havuz',
        'created' => 'Havuz eklendi.',
    ],

    'prefixes' => [
        'title' => 'Ağlar',
        'empty' => 'Henüz ağ yok.',
        'empty_filtered' => 'Bu filtreyle eşleşen ağ yok.',
        'cidr' => 'Ağ',
        'pool' => 'Havuz',
        'vlan' => 'VLAN',
        'site' => 'Lokasyon',
        'gateway' => 'Ağ geçidi',
        'used' => 'Kullanımda',
        'capacity' => 'Kapasite',
        'utilisation' => 'Doluluk',
        'too_large' => 'Sayılamayacak kadar büyük',
        'parent' => 'İçinde olduğu',
        'children' => 'İçerdiği',
        'add' => 'Yeni ağ',
        'created' => 'Ağ eklendi.',
        'deleted' => 'Ağ kaldırıldı.',
        'delete' => 'Ağı kaldır…',
        'delete_title' => 'Bu ağ kaldırılsın mı?',
        'delete_body' => 'Ağ havuzdan kaldırılır. İçindeki ağlar kalır ve bu ağın içinde olduğu ağa taşınır. Hâlâ adres tutan bir ağ kaldırılamaz.',
        'delete_confirm' => 'Ağı kaldır',
        'note' => 'Not',
        'search' => 'Ağlarda ara',
    ],

    'addresses' => [
        'title' => 'Adresler',
        'intro' => 'Yalnızca üzerinde bir işlem yapılmış adresler listelenir. Ağın geri kalanı boştur.',
        'empty' => 'Bu ağdaki hiçbir adres üzerinde henüz işlem yapılmadı.',
        'address' => 'Adres',
        'state' => 'Durum',
        'holder' => 'Tutan',
        'held_since' => 'Tarih',
        'reverse_dns' => 'Ters DNS',
        'allocate' => 'Sıradaki boş adresi al',
        'allocated' => ':address ayrıldı.',
        'release' => 'Bırak…',
        'released' => 'Adres bırakıldı.',
        'release_title' => 'Bu adres bırakılsın mı?',
        'release_body' => 'Adres tutulmayı bırakır, kimin tuttuğu kaydı korunur. Doğrudan başkasına verilmek yerine bekletmeye alınır; çünkü yeni bırakılmış bir adres, önceki sahibinin itibarını taşır.',
        'release_confirm' => 'Adresi bırak',
        'reuse_now' => 'Doğrudan havuza geri koy',
        'free_left' => ':count boş',
    ],
];
