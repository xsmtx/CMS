<?php

declare(strict_types=1);

return [
    'validation_failed' => 'Gönderilen veriler kabul edilemedi.',
    'unauthenticated' => 'Devam etmek için giriş yapmalısınız.',
    'forbidden' => 'Bu işlemi gerçekleştirme yetkiniz yok.',
    'not_found' => 'İstenen kayıt bulunamadı.',
    'method_not_allowed' => 'Bu işlem bu kayıt üzerinde kullanılamaz.',
    'conflict' => 'İstek, kaydın mevcut durumuyla çatışıyor.',
    'invalid_state_transition' => 'Bu kayıt bulunduğu durumdan o duruma geçemez.',
    'precondition_failed' => 'Gerekli bir ön koşul sağlanmadı.',
    'idempotency_key_conflict' => 'Bu idempotency anahtarı farklı bir istekte kullanılmış.',
    'payload_too_large' => 'Gönderilen veri çok büyük.',
    'unsupported_media_type' => 'Bu içerik türü desteklenmiyor.',
    'rate_limited' => 'Çok fazla istek gönderildi. Lütfen kısa bir süre sonra tekrar deneyin.',
    'external_service_failure' => 'Üst sağlayıcı doğru yanıt vermedi. Lütfen tekrar deneyin.',
    'service_unavailable' => 'Servis geçici olarak kullanılamıyor. Lütfen kısa bir süre sonra tekrar deneyin.',
    'server_error' => 'Tarafımızda bir hata oluştu. Kayıt altına alındı.',
];
