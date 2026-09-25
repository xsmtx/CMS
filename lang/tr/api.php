<?php

declare(strict_types=1);

return [
    'title' => 'Geliştirici',
    'description' => 'Tokenlar, webhooklar ve bu kurulumdan neler istendiği.',

    'tokens' => [
        'revoke_title' => ':name iptal edilsin mi?',
        'revoke_detail' => 'Bu belirteci kullanan her şey, o gittiği anda çalışmayı bırakır ve bir belirteç geri getirilemez. Bir şey buna bağlıysa önce yeni bir belirteç oluşturup geçişi yapın.',
        'title' => 'API tokenları',
        'description' => 'Token, hiç yazılmayan bir paroladır. Her entegrasyona kendi tokenını ve yalnızca ihtiyaç duyduğu yetkileri verin.',
        'name' => 'Ne için',
        'expires' => 'Geçerlilik sonu',
        'expires_hint' => 'Bitiş tarihi olmayan bir token, verilme sebebinden daha uzun yaşar.',
        'scopes' => 'Neleri yapabilir',
        'scopes_hint' => 'Yetki daraltır, asla genişletmez. Bir token, onu veren kişinin yapabildiğinden fazlasını yapamaz.',
        'create' => 'Token oluştur',
        'created' => 'Bu tokenı şimdi kopyalayın. Bir daha gösterilmeyecek.',
        'revoke' => 'İptal et',
        'revoked' => 'Token iptal edildi.',
        'last_used' => 'Son kullanım',
        'never_used' => 'Hiç kullanılmadı',
        'none' => 'Token yok',
        'none_description' => 'Bu hesapta API kullanan bir şey yok.',
        'no_scopes' => 'Yetki seçilmedi — bu token hiçbir şeye erişemez.',
    ],

    'scopes' => [
        'profile_read' => [
            'label' => 'Hesabı oku',
            'description' => 'Bu tokenın ait olduğu kişi ve müşteri.',
        ],
        'profile_write' => [
            'label' => 'Hesabı değiştir',
            'description' => 'İletişim bilgilerini güncelle.',
        ],
        'services_read' => [
            'label' => 'Hizmetleri oku',
            'description' => 'Hosting hesaplarını ve durumlarını listele.',
        ],
        'services_write' => [
            'label' => 'Hizmetleri askıya al ve geri aç',
            'description' => 'Sonlandırmak hariç. Bir hesabı yok etmek, tokenın başıboş yapacağı bir iş değildir.',
        ],
        'domains_read' => [
            'label' => 'Alan adlarını oku',
            'description' => 'Alan adlarını, sürelerini ve ad sunucularını listele.',
        ],
        'domains_write' => [
            'label' => 'Alan adlarını değiştir',
            'description' => 'Ad sunucularını ayarla ve yenile.',
        ],
        'invoices_read' => [
            'label' => 'Faturaları oku',
            'description' => 'Faturalar, kalemleri ve bakiye. Token ile ödeme yapılamaz.',
        ],
        'orders_read' => [
            'label' => 'Siparişleri oku',
            'description' => 'Siparişler ve içerikleri.',
        ],
        'tickets_read' => [
            'label' => 'Talepleri oku',
            'description' => 'Destek yazışmaları; dahili notlar hariç.',
        ],
        'tickets_write' => [
            'label' => 'Talep aç ve yanıtla',
            'description' => 'Bir sorunu insandan önce fark eden izleme sistemleri için.',
        ],
        'webhooks_read' => [
            'label' => 'Webhookları oku',
            'description' => 'Uç noktalar ve onlara ne gönderildiği.',
        ],
        'webhooks_write' => [
            'label' => 'Webhookları yönet',
            'description' => 'Uç nokta ekle, kaldır ve bir olayı yeniden gönder.',
        ],
    ],

    'webhooks' => [
        'delete_title' => 'Bu uç nokta silinsin mi?',
        'delete_detail' => ':url adresine gönderimler anında durur. Daha önce gönderilenler kayıtta kalır ve adresi sonra yeniden ekleyebilirsiniz.',
        'title' => 'Webhooklar',
        'description' => 'Bu platformun olayları gerçekleştikçe nereye göndereceği.',
        'url' => 'Uç nokta adresi',
        'url_hint' => 'Yalnızca HTTPS. İmzalı JSON gönderiyoruz ve yönlendirme takip etmiyoruz.',
        'what_for' => 'Ne için',
        'events' => 'Olaylar',
        'events_hint' => 'Hiçbirini seçmezseniz hepsi gönderilir.',
        'create' => 'Uç nokta ekle',
        'created' => 'Bu imza anahtarını şimdi kopyalayın. Bir daha gösterilmeyecek.',
        'secret' => 'İmza anahtarı',
        'delete' => 'Kaldır',
        'deleted' => 'Uç nokta kaldırıldı.',
        'none' => 'Uç nokta yok',
        'none_description' => 'Bu hesapta bir şey olduğunda haber verilen bir sistem yok.',
        'last_delivered' => 'Son gönderim',
        'failures' => 'Ardışık hata',
        'disabled' => 'Çok fazla hatadan sonra kapatıldı',
        'deliveries' => 'Gönderimler',
        'redeliver' => 'Tekrar gönder',
        'redelivered' => 'Tekrar gönderilmek üzere kuyruğa alındı.',
        'verify_title' => 'Bir gönderimi doğrulama',
        'verify_body' => 'Her istek X-InfraCMS-Timestamp ve X-InfraCMS-Signature başlıklarını taşır. İmza, imza anahtarınızla hesaplanan "<zaman damgası>.<ham gövde>" ifadesinin HMAC-SHA256 değeridir. Gövdeyi ayrıştırmadan önce aldığınız baytların tam karşılığıyla karşılaştırın ve beş dakikadan eskisini reddedin.',
    ],

    'deliveries' => [
        'event' => 'Olay',
        'status' => 'Durum',
        'attempt' => 'Deneme',
        'when' => 'Ne zaman',
        'states' => [
            'pending' => 'Bekliyor',
            'delivered' => 'Gönderildi',
            'failed' => 'Başarısız',
            'retrying' => 'Yeniden denenecek',
        ],
    ],

    'activity' => [
        'title' => 'API etkinliği',
        'description' => 'Bu kurulumdan istenen her istek. Hiçbirinin gövdesi değil.',
        'none' => 'API çağrısı yok',
        'none_description' => 'Bir entegrasyon başlar başlamaz istekler burada görünür.',
        'token' => 'Token',
        'route' => 'Rota',
        'status' => 'Durum',
        'duration' => 'Süre',
        'when' => 'Ne zaman',
        'refused_only' => 'Yalnızca reddedilenler',
        'all' => 'Hepsi',
    ],

    'errors' => [
        'unauthenticated' => 'Geçerli bir API tokenı sunulmadı.',
        'forbidden' => 'Bu token bunu yapamaz.',
        'scope_missing' => 'Bu token :scope yetkisini taşımıyor veya sahibinin bu yetkiyi kullanma izni yok.',
        'rate_limited' => 'Çok fazla istek. Bekleyip tekrar deneyin.',
        'unknown_filter' => ':field adında bir filtre yok.',
        'filterable' => 'Burada kullanılabilir filtreler: :fields.',
        'unknown_sort' => ':field adında sıralanabilir bir alan yok.',
        'sortable' => 'Sıralayabileceğiniz alanlar: :fields.',
        'unknown_action' => ':action adında bir eylem yok.',
        'allowed_actions' => 'Burada kullanılabilir eylemler: :actions.',
        'unknown_department' => 'Böyle bir departman yok.',
        'idempotency_conflict' => 'Bu idempotency anahtarı farklı bir istek için kullanılmıştı.',
        'idempotency_in_flight' => 'Bu idempotency anahtarına sahip bir istek hâlâ işleniyor.',
        'https_required' => 'Webhook uç noktası bir HTTPS adresi olmalıdır.',
        'endpoint_gone' => 'Uç nokta artık mevcut değil.',
    ],
];
