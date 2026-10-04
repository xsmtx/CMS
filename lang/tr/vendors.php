<?php

declare(strict_types=1);

/*
 * Bu işletmenin kimden satın aldığı ve neyi kabul ettiği (§24).
 *
 * Baştan sona operatör sözleri. Hiçbiri bir müşteriye gösterilmez: bir
 * satıcının transit için ne ödediği müşterinin işi değildir ve bayi
 * kurulumlarında bunu sağlayan şey sınırdır.
 */

return [
    'title' => 'Tedarikçiler',
    'intro' => 'Kimden satın aldığınız ve neyi kabul ettiğiniz. Buradaki hiçbir şey keşfedilmez — hiçbir API bir transit sözleşmesinin ne tuttuğunu ya da lisansların ne zaman yenilendiğini söylemez.',

    'empty' => 'Henüz tedarikçi yok',
    'empty_detail' => 'Satın aldığınız şirketleri ekleyin: veri merkezi, transit, donanım, lisanslar. Sözleşmeler bunlara bağlanır.',

    'add' => 'Tedarikçi ekle',
    'add_submit' => 'Ekle',
    'delete' => 'Sil',
    'add_contract' => 'Sözleşme ekle',
    'saved' => 'Kaydedildi.',
    'deleted' => 'Silindi.',

    'name' => 'Ad',
    'kind' => 'Ne sağlıyor',
    'contact_name' => 'Kimi arayacaksınız',
    'contact_email' => 'E-posta',
    'contact_phone' => 'Telefon',
    'account_reference' => 'Onlardaki hesap numaranız',
    'account_reference_hint' => 'Her destek görüşmesinin ilk sorduğu şey.',
    'note' => 'Not',

    'kinds' => [
        'datacenter' => 'Veri merkezi',
        'transit' => 'Transit',
        'hardware' => 'Donanım',
        'software' => 'Yazılım',
        'registrar' => 'Alan adı sağlayıcısı',
        'backup' => 'Yedekleme',
        'cloud' => 'Bulut',
        'ddos' => 'DDoS koruması',
        'other' => 'Başka bir şey',
    ],

    'terms' => [
        'monthly' => 'Aylık',
        'quarterly' => 'Üç aylık',
        'yearly' => 'Yıllık',
        'triennial' => 'Üç yılda bir',
        'once' => 'Tek seferlik',
    ],

    'contracts' => [
        'title' => 'Sözleşmeler',
        'intro' => 'Neyin kabul edildiği, ne tuttuğu ve ne zaman yeniden karar verilmesi gerektiği.',
        'empty' => 'Kayıtlı sözleşme yok',
        'empty_detail' => 'Sözleşme, kabul edilen şeydir. Bir ayın gerçekte ne faturalandığı bir maliyet kaydıdır ve başka bir ekranda başka bir satırdır.',

        'vendor' => 'Tedarikçi',
        'add_submit' => 'Ekle',
        'contract_title' => 'Neyi kapsıyor',
        'reference' => 'Onların referansı',
        'term' => 'Dönem',
        'amount' => 'Dönem başına fiyat',
        'currency' => 'Para birimi',
        'starts_on' => 'Başlangıç',
        'ends_on' => 'Bitiş',
        'ends_on_hint' => 'Bitiş tarihi olmayan süresiz bir anlaşma için boş bırakın. Bitişi olmayan bir sözleşme hiçbir zaman süre listesine düşmez.',
        'auto_renews' => 'Kendini yeniler',
        'auto_renews_hint' => 'Kimse bildirimde bulunmazsa devam eder. Bu, uyarı alıp almayacağınızı değil uyarının ne anlama geldiğini değiştirir.',
        'notice_days' => 'İstedikleri bildirim süresi (gün)',
        'notice_days_hint' => 'Kendini yenileyen bir sözleşmede önemli olan tarih, bitiş değil hayır demek için son gündür.',

        'decide_by' => 'Karar tarihi',
        'rolling' => 'Bitiş tarihi yok',
        'renews' => 'Kendini yeniler',
        'ends' => 'Biter',
        'days_left' => ':count gün',
        'overdue' => 'Geçti',
    ],

    'licences' => [
        'title' => 'Lisanslar',
        'intro' => 'Toplu alınan lisanslar ve hangi makinelerinizin bunları kullandığı. İlk yarısını tedarikçiniz bilir; ikincisini yalnızca bu kurulum bilir.',

        'empty' => 'Kayıtlı lisans yok',
        'empty_detail' => 'Toplu aldıklarınızı girin — cPanel, CloudLinux, LiteSpeed, Imunify, Windows — ve her koltuğun hangi makinede olduğunu. Bu ekran, ikisi arasındaki fark için var.',

        'add' => 'Lisans ekle',
        'add_submit' => 'Ekle',
        'allocate' => 'Bir makineye ver',
        'allocate_submit' => 'Ata',
        'release' => 'Geri al',

        'name' => 'Ne olduğu',
        'vendor' => 'Kimden alındı',
        'contract' => 'Hangi sözleşme kapsamında',
        'contract_hint' => 'İsteğe bağlı. Arkasında kâğıt olmadan kartla alınan lisanslar olağandır.',
        'for_module' => 'Hangi makineler buna ihtiyaç duyar',
        'for_module_hint' => 'Bunu çalıştıran bir makinenin yapılandırılacağı sağlama modülü. Belirtmezseniz bu platform hangi makinelerin buna ihtiyacı olduğu konusunda bir iddiada bulunmaz — ve eksik olanların listesini de sunmaz.',
        'for_module_any' => 'Belirtme',
        'contract_any' => 'Bir sözleşme kapsamında değil',
        'for_module_none' => 'Belirtilmemiş',
        'seats' => 'Alınan koltuk',
        'unit_price' => 'Koltuk başına fiyat',
        'currency' => 'Para birimi',
        'server' => 'Makine',
        'reason' => 'Neden',
        'reference' => 'Onların referansı',
        'reference_hint' => 'Tedarikçinin bu koltuk için kendi satırı. Asla lisans anahtarı değil: buradaki hiçbir şey onu okumaz ve anahtar bir kimlik bilgisidir.',

        'used' => 'Kullanımda',
        'spare' => 'Boşta',
        'overage' => 'Aşım',
        'total' => 'Dönem başına',

        'sections' => [
            'spare' => 'Parası ödenmiş ve boşta',
            'spare_detail' => 'Hiçbir şeyin kullanmadığı koltuklar. Ya biri bunları geri verebilir ya da bir makine lisanssız çalışıyordur.',
            'orphaned' => 'Artık olmayan bir makinede',
            'orphaned_detail' => 'Aynı para, daha kötü bir hikâyeyle: birileri bunların kullanımda olduğunu sanıyordu.',
            'missing' => 'Lisanssız çalışıyor',
            'missing_detail' => 'Bir modül için yapılandırılmış ama o lisans havuzunda koltuğu olmayan makineler. Bu, paraya değil kesintiye mal olan tarafı.',
            'pools' => 'Alınan her şey',
        ],

        'reasons' => [
            'gone' => 'Artık filoda değil',
            'offline' => 'Kapatılmış',
        ],

        'nothing_spare' => 'Her koltuk bir makinede.',
        'nothing_orphaned' => 'Her koltuk hâlâ burada olan bir makinede.',
        'nothing_missing' => 'Bu platformun görebildiği kadarıyla lisanssız çalışan bir şey yok.',

        'confirm' => [
            'release_title' => 'Bu koltuk geri alınsın mı?',
            'release_body' => ':name üzerindeki koltuk boşa çıkar. Makinenin kendisinde hiçbir şey olmaz — burası ne aldığınızın kaydı, bir lisans sunucusu değil.',
            'delete_title' => 'Bu lisans silinsin mi?',
            'delete_body' => ':name, koltuk sayısı ve fiyatıyla birlikte gider. Koltukları atanmış bir lisans, onlar geri alınmadan kaldırılamaz.',
        ],
    ],

    'confirm' => [
        'vendor_title' => 'Bu tedarikçi silinsin mi?',
        'vendor_body' => ':name listeden çıkar. Sözleşmeleri silinmez — sözleşmesi olan bir tedarikçi, önce onlar silinmeden kaldırılamaz.',
        'contract_title' => 'Bu sözleşme silinsin mi?',
        'contract_body' => ':name, yenileme tarihi ve bildirim süresiyle birlikte gider. Ona işaret eden maliyet kayıtları kendi rakamlarını korur.',
    ],

    'errors' => [
        'not_permitted' => 'Tedarikçilere ve sözleşmelere erişiminiz yok.',
        'ends_before_start' => 'Bir sözleşme, başladıktan sonra bitmelidir.',
        'has_contracts' => 'Bu tedarikçinin hâlâ sözleşmeleri var. Önce onları silin ya da tedarikçiyi bırakın.',
        'has_allocations' => 'Bu lisansın hâlâ makinelerde koltukları var. Önce onları geri alın.',
        'already_allocated' => 'O makinede bu lisansın bir koltuğu zaten var.',
    ],
];
