<?php

declare(strict_types=1);

return [
    'events' => [
        'order_placed' => 'Sipariş verildi',
        'order_paid' => 'Sipariş ödendi',
        'invoice_issued' => 'Fatura düzenlendi',
        'payment_received' => 'Ödeme alındı',
        'payment_failed' => 'Ödeme başarısız',
        'service_provisioned' => 'Hizmet kuruldu',
        'service_suspended' => 'Hizmet askıya alındı',
        'service_terminated' => 'Hizmet sonlandırıldı',
        'domain_registered' => 'Alan adı kaydedildi',
        'domain_expiring' => 'Alan adı süresi doluyor',
        'ticket_opened' => 'Destek talebi açıldı',
        'ticket_replied' => 'Destek talebine yanıt verildi',
    ],

    'categories' => [
        'invoices' => 'Faturalandırma',
        'support' => 'Destek',
        'product' => 'Hizmet bildirimleri',
        'marketing' => 'Kampanya ve haberler',
    ],

    'channels' => [
        'mail' => 'E-posta',
        'database' => 'Uygulama içi',
        'webhook' => 'Webhook',
        'sms' => 'SMS',
        'chat' => 'Sohbet odası',
    ],

    'delivery_statuses' => [
        'pending' => 'Bekliyor',
        'sent' => 'Gönderildi',
        'failed' => 'Başarısız',
        'suppressed' => 'Gönderilmedi',
    ],

    'mail' => [
        'signature' => 'Teşekkürler, :brand',
    ],

    'admin' => [
        'templates_title' => 'Bildirim şablonları',
        'templates_subtitle' => 'Her mesajın metni. Burada yaptığınız değişiklik tüm müşteriler için geçerlidir.',
        'log_title' => 'Gönderim kaydı',
        'log_subtitle' => 'Bu platformun gönderdiği her şey ve sonucu.',
        'event' => 'Mesaj',
        'locale' => 'Dil',
        'subject' => 'Konu',
        'body' => 'İçerik',
        'action_label' => 'Düğme metni',
        'channel' => 'Kanal',
        'status' => 'Durum',
        'recipient' => 'Alıcı',
        'sent_at' => 'Gönderim',
        'error' => 'Gerekçe',
        'placeholders' => 'Yer tutucular',
        'placeholders_hint' => ':ad biçiminde yazın. Değeri olmayan bir yer tutucu boşaltılmaz, olduğu gibi kalır; böylece hata görünür olur.',
        'preview' => 'Önizleme',
        'send_test' => 'Kendime test gönder',
        'test_sent' => 'Test mesajı gönderildi.',
        'saved' => 'Şablon kaydedildi.',
        'reset' => 'Varsayılan metne döndür',
        'reset_done' => 'Şablon sıfırlandı.',
        'customised' => 'Düzenlenmiş',
        'shipped' => 'Varsayılan',
        'empty_log' => 'Henüz bir şey gönderilmedi.',
        'not_permitted' => 'Bildirimlere erişiminiz yok.',
    ],

    'portal' => [
        'title' => 'Bildirimler',
        'none' => 'Yeni bir şey yok.',
        'none_description' => 'Hizmetleriniz, faturalarınız ve destek talepleriniz hakkındaki güncellemeler burada görünür.',
        'mark_read' => 'Tümünü okundu işaretle',
        'preferences' => 'Size hangi konularda e-posta gönderelim',
        'preferences_hint' => 'Bazı mesajlar hizmetin bir parçasıdır — başarısız ödeme, askıya alma — ve her zaman gönderilir.',
        'always_sent' => 'Her zaman gönderilir',
    ],

    'messages' => [
        'order_placed' => [
            'subject' => ':order_number numaralı siparişinizi aldık',
            'body' => "Siparişiniz için teşekkürler.\n\n:total tutarındaki :order_number numaralı sipariş bize ulaştı. Kurulum tamamlanır tamamlanmaz size haber vereceğiz.",
            'action' => 'Siparişi görüntüle',
        ],
        'order_paid' => [
            'subject' => ':order_number numaralı sipariş için ödeme alındı',
            'body' => "Teşekkürler — :order_number numaralı sipariş için :total tutarını aldık.\n\nKurulumu şimdi yapıyoruz, hazır olduğunda tekrar yazacağız.",
            'action' => 'Siparişi görüntüle',
        ],
        'invoice_issued' => [
            'subject' => ':invoice_number numaralı fatura',
            'body' => ":total tutarındaki :invoice_number numaralı fatura hazır.\n\nSon ödeme tarihi :due_date. Hesabınızdan dilediğiniz zaman ödeyebilirsiniz.",
            'action' => 'Faturayı öde',
        ],
        'payment_received' => [
            'subject' => 'Ödeme alındı',
            'body' => ':invoice_number numaralı fatura için :amount tutarını aldık. Teşekkür ederiz.',
            'action' => 'Faturayı görüntüle',
        ],
        'payment_failed' => [
            'subject' => 'Ödemeniz tamamlanamadı',
            'body' => ":invoice_number numaralı fatura için :amount tutarını tahsil edemedik.\n\n:reason\n\nHesabınızdan herhangi bir tahsilat yapılmadı. Dilediğiniz zaman tekrar deneyebilirsiniz.",
            'action' => 'Tekrar dene',
        ],
        'service_provisioned' => [
            'subject' => ':service_name hazır',
            'body' => ":service_name kuruldu ve çalışıyor.\n\nGiriş bilgileriniz hesabınızda — parolaları e-posta ile göndermiyoruz.",
            'action' => 'Hizmeti aç',
        ],
        'service_suspended' => [
            'subject' => ':service_name askıya alındı',
            'body' => ":service_name askıya alındı.\n\n:reason\n\nBizimle iletişime geçin, birlikte çözelim.",
            'action' => 'Hizmeti görüntüle',
        ],
        'service_terminated' => [
            'subject' => ':service_name sonlandırıldı',
            'body' => ':service_name sonlandırıldı ve verileri kaldırıldı.',
        ],
        'domain_registered' => [
            'subject' => ':domain kaydedildi',
            'body' => ":domain :expires_on tarihine kadar size kayıtlı.\n\nHesabınızdan dilediğiniz yere yönlendirebilirsiniz.",
            'action' => 'Alan adını yönet',
        ],
        'domain_expiring' => [
            'subject' => ':domain :expires_on tarihinde doluyor',
            'body' => ":domain :expires_on tarihinde doluyor.\n\nElinizde kalması için o tarihten önce yenileyin — süresi dolan bir alan adını herkes kaydedebilir.",
            'action' => 'Alan adını yenile',
        ],
        'ticket_opened' => [
            'subject' => '[:ticket_number] :subject',
            'body' => "Mesajınızı aldık, kısa süre içinde yanıtlayacağız.\n\nTalep :ticket_number — :subject",
            'action' => 'Talebi görüntüle',
        ],
        'ticket_replied' => [
            'subject' => 'Yanıt: [:ticket_number] :subject',
            'body' => ':ticket_number numaralı talebe yeni bir yanıt var.',
            'action' => 'Yanıtı oku',
        ],
    ],

    'errors' => [
        'opted_out' => 'Alıcı bu tür mesajları kapatmış.',
        'no_address' => 'Alıcının e-posta adresi yok.',
        'no_subject' => 'Bu kanal mesajın gönderileceği bir kişi gerektiriyor.',
        'endpoint_refused' => 'Uç nokta HTTP :status yanıtı döndürdü.',
    ],
];
