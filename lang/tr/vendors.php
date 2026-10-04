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
    ],
];
