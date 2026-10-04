<?php

declare(strict_types=1);

/*
 * Asistanın söz dağarcığı (ADR 0050).
 *
 * Operatör sözleri. Buradaki her cümleyi ya bir müşterinin sözlerinin bir
 * sağlayıcıya gönderilip gönderilemeyeceğine karar veren biri okur ya da
 * çalışmamış bir düğmeye bakan biri — bu yüzden hiçbiri neşeli değil.
 */

return [
    'title' => 'Asistan',
    'intro' => 'Bir model yanıt taslağı yazabilir, bir yazışmayı özetleyebilir ya da kaydın hangi departmana ait olduğunu önerebilir. Hiçbir şey göndermez: her taslak zaten yazacağınız kutuya düşer, siz düzeltir ve gönder’e basarsınız.',

    'off' => 'Kurulu bir asistan yok',
    'off_detail' => 'Hiçbir yere hiçbir şey gönderilmiyor. Bir yapay zekâ sağlayıcı modülü kurun, buradan seçin ve istediğiniz özellikleri açın.',

    'provider' => 'Sağlayıcı',
    'provider_hint' => 'Hangi etkin modülün yanıtlayacağı. Burada yalnızca bu kurulumun etkinleştirdiği modüller görünür.',
    'provider_none' => 'Yok — asistan kapalı',

    'instructions' => 'Nasıl yazmalı',
    'instructions_hint' => 'Her taslağa eklenen kendi yönergeniz. “Türkçe, resmi bir dille yanıtla ve asla iade sözü verme” bunun kalıbıdır.',

    'features' => [
        'ticket_reply' => [
            'label' => 'Kayda yanıt taslağı yaz',
            'description' => 'Konu, departman, müşterinin adı, hizmet ve yazışma gönderilir. Dahili notlar gönderilmez.',
        ],
        'ticket_summary' => [
            'label' => 'Yazışmayı özetle',
            'description' => 'Aynısı, kaydı devralacak kişi için. Özeti yalnızca personeliniz okur, başka hiçbir yere gitmez.',
        ],
        'ticket_triage' => [
            'label' => 'Departman öner',
            'description' => 'Yönlendirilmemiş bir kaydın nereye ait olduğunu önermek için konu ve ilk mesaj. Alanın yanında bir öneri, asla kaydedilen değer değil.',
        ],
        'incident_update' => [
            'label' => 'Olay güncellemesi taslağı yaz',
            'description' => 'Olayın başlığı ve zaman çizelgesi. Yayımlayan yine bir insandır.',
        ],
    ],

    'reaches_customer' => 'Bunu bir müşteri okuyabilir',
    'internal_only' => 'Yalnızca personeliniz okur',

    'draft' => 'Yanıt taslağı yaz',
    'drafting' => 'Yazıyor…',
    'drafted' => ':model tarafından yazıldı. Göndermeden önce okuyun.',
    'summarise' => 'Özetle',

    // Modele gerçekten gönderilen görev cümleleri. Yönergede değil burada,
    // çünkü bunlar da birer metin ve Türkçe bir destek masası işleten biri
    // taslağın Türkçe gelmesini kendi yönergesinde belirtmek zorunda kalmamalı.
    'tasks' => [
        'ticket_reply' => 'Destek ekibi olarak bu müşteriye bir sonraki yanıtı yaz. Kısa ve somut ol. Olmayan bilgi, fiyat, tarih ya da söz uydurma. Bilinmeyen bir şey varsa neyi araştıracağını yaz.',
        'ticket_summary' => 'Bu yazışmayı, kaydı devralacak bir meslektaş için özetle: müşteri ne istiyor, neler denendi ve geriye ne kaldı.',
    ],

    'errors' => [
        'no_provider' => 'Bu kurulumda kurulu bir yapay zekâ sağlayıcısı yok.',
        'not_enabled' => ':feature asistanı kapalı.',
        'no_credential' => ':provider için kayıtlı bir API anahtarı yok.',
        'unreachable' => ':provider yanıt vermedi. Yanıtı kendiniz yazın — hiçbir şey gönderilmedi.',
        'refused' => ':provider bunu yanıtlamadı.',
        'empty_answer' => ':provider boş bir yanıt verdi.',
        'not_permitted' => 'Asistan ayarlarına erişiminiz yok.',
    ],

    'usage' => [
        'title' => 'Asistanın maliyeti',
        'intro' => 'Her çağrı için bir satır. Ne istendiği ve ne tuttuğu — asla ne yazıldığı değil.',
        'empty' => 'Asistan hiç kullanılmamış.',
        'feature' => 'İstenen',
        'who' => 'İsteyen',
        'model' => 'Model',
        'tokens' => 'Jeton',
        'unreported' => 'Bildirilmedi',
        'when' => 'Ne zaman',
        'outcomes' => [
            'answered' => 'Yanıtlandı',
            'refused' => 'Reddedildi',
        ],
    ],
];
