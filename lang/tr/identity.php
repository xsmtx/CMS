<?php

declare(strict_types=1);

return [
    'auth' => [
        'failed' => 'Bu bilgiler kayıtlarımızla eşleşmiyor.',
        'throttled' => 'Çok fazla deneme yapıldı. Lütfen :seconds saniye sonra tekrar deneyin.',
        'account_unavailable' => 'Bu hesap kullanılamıyor. Lütfen destek ile iletişime geçin.',
        'invalid_code' => 'Bu kod geçerli değil.',
        'invalid_recovery_code' => 'Bu kurtarma kodu geçersiz veya daha önce kullanılmış.',
        'reset_link_sent' => 'Bu adrese ait bir hesap varsa, sıfırlama bağlantısı gönderildi.',
        'password_reset' => 'Şifreniz sıfırlandı. Şimdi giriş yapabilirsiniz.',
        'password_updated' => 'Şifreniz güncellendi.',
        'signed_out_others' => 'Diğer :count oturum kapatıldı.',
    ],

    'staff' => [
        'created' => 'Personel hesabı oluşturuldu. Şifre sıfırlama bağlantısı ile şifre belirleyebilirler.',
        'updated' => 'Personel hesabı güncellendi.',
        'deleted' => 'Personel hesabı silindi.',
        'last_super_admin' => 'Bu son süper yönetici; kurulumu yönetebilecek kimse kalmaz.',
        'self_delete' => 'Kendi hesabınızı silemezsiniz.',
    ],

    'statuses' => [
        'active' => 'Aktif',
        'suspended' => 'Askıda',
        'closed' => 'Kapalı',
    ],

    'two_factor' => [
        'enabled' => 'İki adımlı doğrulama açık.',
        'disabled' => 'İki adımlı doğrulama kapalı.',
        'confirm_failed' => 'Kod eşleşmedi. Doğrulayıcı uygulamanızı kontrol edip tekrar deneyin.',
        'recovery_codes_regenerated' => 'Yeni kurtarma kodları oluşturuldu. Eski kodlarınız artık geçersiz.',
    ],

    'mail' => [
        'reset_subject' => 'Şifrenizi sıfırlayın',
        'reset_intro' => 'Bu e-postayı, hesabınız için bir şifre sıfırlama talebi aldığımız için alıyorsunuz.',
        'reset_action' => 'Şifreyi sıfırla',
        'reset_expiry' => 'Bu bağlantı :minutes dakika içinde geçerliliğini yitirir.',
        'reset_ignore' => 'Şifre sıfırlama talebinde bulunmadıysanız herhangi bir işlem yapmanıza gerek yok.',
    ],

    'impersonation' => [
        'active' => 'Bu hesabı :name olarak görüntülüyorsunuz.',
        'stop' => 'Durdur',
        'started' => 'Artık :name olarak işlem yapıyorsunuz.',
        'stopped' => 'Hesap görüntüleme sonlandırıldı.',
        'forbidden' => 'Bu hesap organizasyonunuzun dışında.',
        'no_portal_access' => 'Bu kişinin panel erişimi yok, bu nedenle işlem yapılabilecek bir oturum bulunmuyor.',
        'blocked_action' => 'Bu işlem hesap görüntüleme sırasında kullanılamaz.',
    ],
];
