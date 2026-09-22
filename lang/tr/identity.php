<?php

declare(strict_types=1);

return [
    'auth' => [
        'failed' => 'Bu bilgiler kayitlarimizla eslesmiyor.',
        'throttled' => 'Cok fazla deneme yapildi. Lutfen :seconds saniye sonra tekrar deneyin.',
        'account_unavailable' => 'Bu hesap kullanilamiyor. Lutfen destek ile iletisime gecin.',
        'invalid_code' => 'Bu kod gecerli degil.',
        'invalid_recovery_code' => 'Bu kurtarma kodu gecersiz veya daha once kullanilmis.',
        'reset_link_sent' => 'Bu adrese ait bir hesap varsa, sifirlama baglantisi gonderildi.',
        'password_reset' => 'Sifreniz sifirlandi. Simdi giris yapabilirsiniz.',
        'password_updated' => 'Sifreniz guncellendi.',
        'signed_out_others' => 'Diger :count oturum kapatildi.',
    ],

    'statuses' => [
        'active' => 'Aktif',
        'suspended' => 'Askida',
        'closed' => 'Kapali',
    ],

    'two_factor' => [
        'enabled' => 'Iki adimli dogrulama acik.',
        'disabled' => 'Iki adimli dogrulama kapali.',
        'confirm_failed' => 'Kod eslesmedi. Dogrulayici uygulamanizi kontrol edip tekrar deneyin.',
        'recovery_codes_regenerated' => 'Yeni kurtarma kodlari olusturuldu. Eski kodlariniz artik gecersiz.',
    ],

    'mail' => [
        'reset_subject' => 'Sifrenizi sifirlayin',
        'reset_intro' => 'Bu e-postayi, hesabiniz icin bir sifre sifirlama talebi aldigimiz icin aliyorsunuz.',
        'reset_action' => 'Sifreyi sifirla',
        'reset_expiry' => 'Bu baglanti :minutes dakika icinde gecerliligini yitirir.',
        'reset_ignore' => 'Sifre sifirlama talebinde bulunmadiysaniz herhangi bir islem yapmaniza gerek yok.',
    ],

    'impersonation' => [
        'active' => 'Bu hesabi :name olarak goruntuluyorsunuz.',
        'stop' => 'Durdur',
        'started' => 'Artik :name olarak islem yapiyorsunuz.',
        'stopped' => 'Hesap goruntuleme sonlandirildi.',
        'forbidden' => 'Bu hesap organizasyonunuzun disinda.',
        'no_portal_access' => 'Bu kisinin panel erisimi yok, bu nedenle islem yapilabilecek bir oturum bulunmuyor.',
        'blocked_action' => 'Bu islem hesap goruntuleme sirasinda kullanilamaz.',
    ],
];
