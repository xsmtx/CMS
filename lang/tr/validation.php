<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Doğrulama Mesajları
    |--------------------------------------------------------------------------
    |
    | Bir formun neden kabul edilmediğini söyleyen cümleler. Türkçede alan adı
    | cümlenin başında durur ve çekim eki gerektirir; Laravel yalın hâli
    | yerleştirdiği için cümleler eki taşımayacak şekilde kurulmuştur
    | ("… alanı zorunludur" gibi).
    |
    */

    'accepted' => ':attribute alanı kabul edilmelidir.',
    'accepted_if' => ':other :value olduğunda :attribute alanı kabul edilmelidir.',
    'active_url' => ':attribute alanı geçerli bir adres olmalıdır.',
    'after' => ':attribute alanı :date tarihinden sonra olmalıdır.',
    'after_or_equal' => ':attribute alanı :date tarihine eşit veya ondan sonra olmalıdır.',
    'alpha' => ':attribute alanı yalnızca harf içerebilir.',
    'alpha_dash' => ':attribute alanı yalnızca harf, rakam, tire ve alt çizgi içerebilir.',
    'alpha_num' => ':attribute alanı yalnızca harf ve rakam içerebilir.',
    'any_of' => ':attribute alanı geçersiz.',
    'array' => ':attribute alanı bir dizi olmalıdır.',
    'array_keys' => ':attribute alanı yalnızca şu anahtarları içerebilir: :values.',
    'ascii' => ':attribute alanı yalnızca tek baytlık harf, rakam ve sembol içerebilir.',
    'base64' => ':attribute alanı geçerli bir Base64 metni olmalıdır.',
    'before' => ':attribute alanı :date tarihinden önce olmalıdır.',
    'before_or_equal' => ':attribute alanı :date tarihine eşit veya ondan önce olmalıdır.',
    'between' => [
        'array' => ':attribute alanı :min ile :max arasında öge içermelidir.',
        'file' => ':attribute alanı :min ile :max kilobayt arasında olmalıdır.',
        'numeric' => ':attribute alanı :min ile :max arasında olmalıdır.',
        'string' => ':attribute alanı :min ile :max karakter arasında olmalıdır.',
    ],
    'boolean' => ':attribute alanı doğru veya yanlış olmalıdır.',
    'can' => ':attribute alanı izin verilmeyen bir değer içeriyor.',
    'confirmed' => ':attribute alanı doğrulamasıyla eşleşmiyor.',
    'contains' => ':attribute alanı gerekli bir değeri içermiyor.',
    'current_password' => 'Şifre yanlış.',
    'date' => ':attribute alanı geçerli bir tarih olmalıdır.',
    'date_equals' => ':attribute alanı :date tarihine eşit olmalıdır.',
    'date_format' => ':attribute alanı :format biçimine uymalıdır.',
    'decimal' => ':attribute alanı :decimal ondalık basamak içermelidir.',
    'declined' => ':attribute alanı reddedilmelidir.',
    'declined_if' => ':other :value olduğunda :attribute alanı reddedilmelidir.',
    'different' => ':attribute alanı ile :other alanı farklı olmalıdır.',
    'digits' => ':attribute alanı :digits basamaklı olmalıdır.',
    'digits_between' => ':attribute alanı :min ile :max basamak arasında olmalıdır.',
    'dimensions' => ':attribute alanının görsel boyutları geçersiz.',
    'distinct' => ':attribute alanı yinelenen bir değer içeriyor.',
    'doesnt_contain' => ':attribute alanı şunlardan hiçbirini içeremez: :values.',
    'doesnt_end_with' => ':attribute alanı şunlardan biriyle bitemez: :values.',
    'doesnt_start_with' => ':attribute alanı şunlardan biriyle başlayamaz: :values.',
    'email' => ':attribute alanı geçerli bir e-posta adresi olmalıdır.',
    'encoding' => ':attribute alanı :encoding kodlamasında olmalıdır.',
    'ends_with' => ':attribute alanı şunlardan biriyle bitmelidir: :values.',
    'enum' => 'Seçilen :attribute geçersiz.',
    'exists' => 'Seçilen :attribute geçersiz.',
    'extensions' => ':attribute alanı şu uzantılardan birini taşımalıdır: :values.',
    'file' => ':attribute alanı bir dosya olmalıdır.',
    'filled' => ':attribute alanı boş bırakılamaz.',
    'gt' => [
        'array' => ':attribute alanı :value ögeden fazlasını içermelidir.',
        'file' => ':attribute alanı :value kilobayttan büyük olmalıdır.',
        'numeric' => ':attribute alanı :value değerinden büyük olmalıdır.',
        'string' => ':attribute alanı :value karakterden uzun olmalıdır.',
    ],
    'gte' => [
        'array' => ':attribute alanı en az :value öge içermelidir.',
        'file' => ':attribute alanı :value kilobayta eşit veya ondan büyük olmalıdır.',
        'numeric' => ':attribute alanı :value değerine eşit veya ondan büyük olmalıdır.',
        'string' => ':attribute alanı en az :value karakter olmalıdır.',
    ],
    'hex_color' => ':attribute alanı geçerli bir onaltılık renk olmalıdır.',
    'image' => ':attribute alanı bir görsel olmalıdır.',
    'in' => 'Seçilen :attribute geçersiz.',
    'in_array' => ':attribute alanı :other içinde bulunmuyor.',
    'in_array_keys' => ':attribute alanı şu anahtarlardan en az birini içermelidir: :values.',
    'integer' => ':attribute alanı tam sayı olmalıdır.',
    'ip' => ':attribute alanı geçerli bir IP adresi olmalıdır.',
    'ipv4' => ':attribute alanı geçerli bir IPv4 adresi olmalıdır.',
    'ipv6' => ':attribute alanı geçerli bir IPv6 adresi olmalıdır.',
    'json' => ':attribute alanı geçerli bir JSON metni olmalıdır.',
    'list' => ':attribute alanı bir liste olmalıdır.',
    'lowercase' => ':attribute alanı küçük harf olmalıdır.',
    'lt' => [
        'array' => ':attribute alanı :value ögeden azını içermelidir.',
        'file' => ':attribute alanı :value kilobayttan küçük olmalıdır.',
        'numeric' => ':attribute alanı :value değerinden küçük olmalıdır.',
        'string' => ':attribute alanı :value karakterden kısa olmalıdır.',
    ],
    'lte' => [
        'array' => ':attribute alanı en çok :value öge içerebilir.',
        'file' => ':attribute alanı :value kilobayta eşit veya ondan küçük olmalıdır.',
        'numeric' => ':attribute alanı :value değerine eşit veya ondan küçük olmalıdır.',
        'string' => ':attribute alanı en çok :value karakter olabilir.',
    ],
    'mac_address' => ':attribute alanı geçerli bir MAC adresi olmalıdır.',
    'max' => [
        'array' => ':attribute alanı en çok :max öge içerebilir.',
        'file' => ':attribute alanı en çok :max kilobayt olabilir.',
        'numeric' => ':attribute alanı en çok :max olabilir.',
        'string' => ':attribute alanı en çok :max karakter olabilir.',
    ],
    'max_digits' => ':attribute alanı en çok :max basamak içerebilir.',
    'mimes' => ':attribute alanı şu türlerden biri olmalıdır: :values.',
    'mimetypes' => ':attribute alanı şu türlerden biri olmalıdır: :values.',
    'min' => [
        'array' => ':attribute alanı en az :min öge içermelidir.',
        'file' => ':attribute alanı en az :min kilobayt olmalıdır.',
        'numeric' => ':attribute alanı en az :min olmalıdır.',
        'string' => ':attribute alanı en az :min karakter olmalıdır.',
    ],
    'min_digits' => ':attribute alanı en az :min basamak içermelidir.',
    'missing' => ':attribute alanı bulunmamalıdır.',
    'missing_if' => ':other :value olduğunda :attribute alanı bulunmamalıdır.',
    'missing_unless' => ':other :value olmadıkça :attribute alanı bulunmamalıdır.',
    'missing_with' => ':values varken :attribute alanı bulunmamalıdır.',
    'missing_with_all' => ':values varken :attribute alanı bulunmamalıdır.',
    'multiple_of' => ':attribute alanı :value değerinin katı olmalıdır.',
    'not_in' => 'Seçilen :attribute geçersiz.',
    'not_regex' => ':attribute alanının biçimi geçersiz.',
    'numeric' => ':attribute alanı bir sayı olmalıdır.',
    'password' => [
        'letters' => ':attribute alanı en az bir harf içermelidir.',
        'mixed' => ':attribute alanı en az bir büyük ve bir küçük harf içermelidir.',
        'numbers' => ':attribute alanı en az bir rakam içermelidir.',
        'symbols' => ':attribute alanı en az bir sembol içermelidir.',
        'uncompromised' => 'Girilen :attribute bir veri sızıntısında görüldü. Lütfen başka bir :attribute seçin.',
    ],
    'present' => ':attribute alanı gönderilmelidir.',
    'present_if' => ':other :value olduğunda :attribute alanı gönderilmelidir.',
    'present_unless' => ':other :value olmadıkça :attribute alanı gönderilmelidir.',
    'present_with' => ':values varken :attribute alanı gönderilmelidir.',
    'present_with_all' => ':values varken :attribute alanı gönderilmelidir.',
    'prohibited' => ':attribute alanı gönderilemez.',
    'prohibited_if' => ':other :value olduğunda :attribute alanı gönderilemez.',
    'prohibited_if_accepted' => ':other kabul edildiğinde :attribute alanı gönderilemez.',
    'prohibited_if_declined' => ':other reddedildiğinde :attribute alanı gönderilemez.',
    'prohibited_unless' => ':other :values içinde olmadıkça :attribute alanı gönderilemez.',
    'prohibits' => ':attribute alanı :other alanının gönderilmesini engeller.',
    'regex' => ':attribute alanının biçimi geçersiz.',
    'required' => ':attribute alanı zorunludur.',
    'required_array_keys' => ':attribute alanı şu anahtarları içermelidir: :values.',
    'required_if' => ':other :value olduğunda :attribute alanı zorunludur.',
    'required_if_accepted' => ':other kabul edildiğinde :attribute alanı zorunludur.',
    'required_if_declined' => ':other reddedildiğinde :attribute alanı zorunludur.',
    'required_unless' => ':other :values içinde olmadıkça :attribute alanı zorunludur.',
    'required_with' => ':values varken :attribute alanı zorunludur.',
    'required_with_all' => ':values varken :attribute alanı zorunludur.',
    'required_without' => ':values yokken :attribute alanı zorunludur.',
    'required_without_all' => ':values hiçbiri yokken :attribute alanı zorunludur.',
    'same' => ':attribute alanı ile :other alanı aynı olmalıdır.',
    'size' => [
        'array' => ':attribute alanı :size öge içermelidir.',
        'file' => ':attribute alanı :size kilobayt olmalıdır.',
        'numeric' => ':attribute alanı :size olmalıdır.',
        'string' => ':attribute alanı :size karakter olmalıdır.',
    ],
    'starts_with' => ':attribute alanı şunlardan biriyle başlamalıdır: :values.',
    'string' => ':attribute alanı bir metin olmalıdır.',
    'timezone' => ':attribute alanı geçerli bir saat dilimi olmalıdır.',
    'unique' => 'Bu :attribute zaten alınmış.',
    'uploaded' => ':attribute alanı yüklenemedi.',
    'uppercase' => ':attribute alanı büyük harf olmalıdır.',
    'url' => ':attribute alanı geçerli bir adres olmalıdır.',
    'ulid' => ':attribute alanı geçerli bir ULID olmalıdır.',
    'uuid' => ':attribute alanı geçerli bir UUID olmalıdır.',

    /*
    |--------------------------------------------------------------------------
    | Alana Özel Mesajlar
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alan Adları
    |--------------------------------------------------------------------------
    |
    | Boş bırakılmıştır: bu ürünün formları alan adlarını kendi istek
    | sınıflarında `attributes()` ile verir, ve burada ikinci bir liste
    | tutmak iki yerin aynı alan hakkında farklı şey söylemesi demektir.
    |
    */

    'attributes' => [],

];
