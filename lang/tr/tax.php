<?php

declare(strict_types=1);

return [
    'title' => 'Vergi',
    'intro' => 'Nerede ne tahsil edileceği. Bu platform hiçbir oran göndermez: bunları siz yazarsınız, mali müşaviriniz denetler.',

    'rules' => [
        'title' => 'Vergi kuralları',
        'intro' => 'Tahsil ettiğiniz her şey için bir satır. En özel kural kazanır — bölge ülkeyi, ülke de yer belirtmeyen kuralı geçer.',
        'empty' => 'Henüz kural yok, dolayısıyla vergi alınmıyor. Önce satış yaptığınız ülkenin oranını ekleyin.',
        'saved' => 'Kaydedildi.',
        'deleted' => 'Kaldırıldı.',
        'add' => 'Kural ekle',
        'edit' => 'Kuralı düzenle',
        'anywhere' => 'Her yer',
        'columns' => [
            'name' => 'Adı',
            'rate' => 'Oran',
            'place' => 'Nerede',
            'level' => 'Seviye',
            'applies_to' => 'Neye',
            'customer' => 'Kime',
            'dates' => 'Tarihler',
            'state' => 'Durum',
        ],
        'fields' => [
            'name' => 'Faturada görünecek adı',
            'name_hint' => 'KDV, VAT, GST, PST — müşterilerinizin okumayı beklediği ad.',
            'rate' => 'Oran (%)',
            'rate_hint' => 'Dört ondalık basamağa kadar; 9.975 tam olarak yazılabilir.',
            'country' => 'Ülke (ISO kodu)',
            'country_hint' => 'İki harf. Her yerde uygulanması için boş bırakın.',
            'region' => 'Bölge veya eyalet',
            'region_hint' => 'Ülkenin tamamı için boş bırakın.',
            'postcode' => 'Posta kodu',
            'postcode_hint' => 'Tam posta kodu ya da * ile ön ek — 100* kodu 10001 ile eşleşir.',
            'level' => 'Seviye',
            'level_hint' => 'Aynı işleme uygulanan ikinci vergi 2. seviyededir.',
            'compound' => 'Bunu tutarın yanı sıra 1. seviye vergi üzerinden de al',
            'applies_to' => 'Uygulandığı kalemler',
            'customer_kind' => 'Uygulandığı müşteriler',
            'exempts' => 'Vergi numarası olan yurt dışı şirket ödeme yapmaz',
            'exemption_note' => 'Bu durumda faturada yazacak metin',
            'exemption_note_hint' => 'Örnek: Reverse charge, article 196.',
            'priority' => 'Öncelik',
            'priority_hint' => 'Aynı özellikte iki kural varsa yüksek olan kazanır.',
            'starts_on' => 'Başlangıç',
            'ends_on' => 'Bitiş',
            'dates_hint' => 'Oran değişikliği yeni bir kuraldır. Eskisine bitiş tarihi verin, böylece geçmiş faturalar açıklanabilir kalır.',
            'is_active' => 'Etkin',
            'notes' => 'Notlar',
        ],
    ],

    'settings' => [
        'title' => 'Vergi nasıl işlesin',
        'save' => 'Vergi davranışını kaydet',
        'intro' => 'Oran olmayan birkaç yanıt.',
        'saved' => 'Kaydedildi.',
        'prices_include_tax' => 'Katalog fiyatları vergi dahil',
        'prices_include_tax_hint' => 'Fiyata eklenen tutarı değil, fiyatın anlamını değiştirir. Son kullanıcı fiyatlarında yaygındır, şirketler arası satışta çoğunlukla yanlıştır.',
        'rounding' => 'Vergi yuvarlaması',
        'rounding_hint' => 'Satır başına ya da fatura toplamında bir kez. İkisi arasında bir iki kuruş fark olur ve hangisinin gerektiği gerçek bir farktır.',
        'tax_id_label' => 'Formlarda vergi numarasının adı',
        'tax_id_label_hint' => 'Vergi No, VAT number, ABN, GSTIN. "VAT number" dünyanın büyük kısmında yanlıştır.',
        'require_tax_id_for_business' => 'Şirket müşteriden vergi numarası iste',
        'exemption_note' => 'Varsayılan muafiyet metni',
    ],

    'preview' => [
        'title' => 'Dene',
        'intro' => 'Kurallarınızın bir tutara ne yaptığı — faturanın kullandığı hesaplayıcının aynısıyla.',
        'amount' => 'Tutar (kuruş)',
        'amount_hint' => '1000 = 10,00.',
        'currency' => 'Para birimi',
        'country' => 'Ülke',
        'region' => 'Bölge',
        'postcode' => 'Posta kodu',
        'is_business' => 'Şirket',
        'has_tax_id' => 'Vergi numarası verdi',
        'has_tax_id_hint' => 'Verilip verilmediği, hangisi olduğu değil: bu panel sorduğunu adres çubuğuna yazıyor ve kurallar yalnızca bir tane olup olmadığına bakıyor.',
        'applies_to' => 'Satılan',
        'run' => 'Hesapla',
        'net' => 'Vergisiz',
        'tax' => 'Vergi',
        'gross' => 'Toplam',
        'nothing' => 'Hiçbir kural uygulanmıyor, vergi alınmıyor.',
        'exempt' => 'Vergi alınmadı: :reason',
    ],

    'applies_to' => [
        'all' => 'Her şey',
        'products' => 'Ürünler',
        'domains' => 'Alan adları',
        'addons' => 'Ek hizmetler',
        'manual' => 'Elle eklenen fatura satırları',
    ],

    'customer_kinds' => [
        'all' => 'Herkes',
        'individual' => 'Bireyler',
        'business' => 'Şirketler',
    ],

    'rounding' => [
        'per_line' => 'Satır başına',
        'per_invoice' => 'Toplamda bir kez',
    ],

    'identity' => [
        'default_label' => 'Vergi numarası',
        'required' => 'Şirket müşteri için :label zorunludur.',
    ],

    'errors' => [
        'not_permitted' => 'Vergiyi yalnızca kurulum sahibi değiştirebilir.',
    ],
];
