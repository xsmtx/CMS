<?php

declare(strict_types=1);

return [
    'roles_saved' => 'Rol kaydedildi.',
    'roles_deleted' => 'Rol silindi.',

    'scopes' => [
        'staff' => 'Personel',
        'customer' => 'Müşteri',
    ],

    'roles' => [
        'super-admin' => 'Süper Yönetici',
        'administrator' => 'Yönetici',
        'support' => 'Destek Temsilcisi',
        'account-owner' => 'Hesap Sahibi',
        'portal-member' => 'Panel Kullanıcısı',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission names
    |--------------------------------------------------------------------------
    |
    | Rol ekranında bir iznin adı ve ne yapmaya izin verdiğini anlatan bir
    | satır. Slug kodun kontrol ettiği şeydir; bir temsilcinin servisi
    | sonlandırabilmesine karar verecek kişinin okuması gereken şey değildir.
    |
    */

    'permissions' => [
        'platform.health.view' => [
            'label' => 'Platform sağlığını gör',
            'description' => 'Sağlık sayfasını açar ve tüm kontrolleri okur.',
        ],
        'platform.queue.view' => [
            'label' => 'Kuyruğu gör',
            'description' => 'Kuyruktaki ve başarısız işleri izler.',
        ],
        'platform.audit.view' => [
            'label' => 'Denetim kaydını oku',
            'description' => 'Kimin ne zaman ne yaptığını görür.',
        ],
        'automation.view' => [
            'label' => 'Otomasyon çalışmalarını gör',
            'description' => 'Zamanlanmış görevlerin ne yaptığını okur.',
        ],
        'automation.run' => [
            'label' => 'Otomasyon görevini şimdi çalıştır',
            'description' => 'Zamanlamayı beklemeden bir taramayı elle başlatır.',
        ],
        'automation.dunning.manage' => [
            'label' => 'Tahsilat adımlarını düzenle',
            'description' => 'Ödenmemiş faturalar için hatırlatma ve askıya alma adımlarını değiştirir.',
        ],
        'operations.view' => [
            'label' => 'İşlemleri gör',
            'description' => 'Uzun süren işleri ve sonuçlarını izler.',
        ],
        'operations.manage' => [
            'label' => 'İşlemi yeniden dene veya kapat',
            'description' => 'Başarısız bir işlemi yeniden çalıştırır ya da elle çözümlendi işaretler.',
        ],
        'infrastructure.resources.view' => [
            'label' => 'Kaynak grafiğini gör',
            'description' => 'Sunucuları, hizmetleri ve bağımlılıklarını, bir kesintinin kimi etkileyeceğini görür.',
        ],
        'infrastructure.telemetry.view' => [
            'label' => 'Telemetriyi gör',
            'description' => 'En son ölçümleri, nereden geldiklerini ve akmayı bırakanları okur.',
        ],
        'infrastructure.adapters.view' => [
            'label' => 'Altyapı bağdaştırıcılarını gör',
            'description' => 'Bu kurulumdaki bağdaştırıcıları ve her birinin ne yapabildiğini listeler.',
        ],
        'infrastructure.adapters.manage' => [
            'label' => 'Bir bağdaştırıcıya değişiklik izni ver',
            'description' => 'Bağdaştırıcının güvenlik duvarını, gücü veya depolamayı yalnızca okumak yerine değiştirmesine izin verir.',
        ],
        'network.ipam.view' => [
            'label' => 'IP adreslemesini gör',
            'description' => 'Havuzları, ağları ve adresleri, her adresi kimin tuttuğunu görür.',
        ],
        'network.ipam.manage' => [
            'label' => 'IP adreslemesini yönet',
            'description' => 'Ağ ekler, adres dağıtır ve geri alır.',
        ],
        'network.devices.view' => [
            'label' => 'Ağ cihazlarını gör',
            'description' => 'Keşfin bulduğu cihazları, portlarını ve yapılandırma değişikliklerini görüntüler.',
        ],
        'network.changes.request' => [
            'label' => 'Cihaz değişikliği talep et',
            'description' => 'Bir yapılandırma değişikliğini gerekçesiyle yazar. Talep etmek uygulamak değildir.',
        ],
        'network.changes.approve' => [
            'label' => 'Cihaz değişikliğini onayla',
            'description' => 'Başkasının değişikliğini onaylar veya reddeder. Asla kendininkini.',
        ],
        'network.changes.apply' => [
            'label' => 'Cihaz değişikliğini uygula',
            'description' => 'Onaylanmış yapılandırmayı cihaza gönderir. Bu platformun yapabileceği en ağır sonuçlu iş.',
        ],
        'network.ddos.view' => [
            'label' => 'Saldırıları gör',
            'description' => 'Bildirilen saldırıları ve adresin arkasındaki müşteriyi görüntüler.',
        ],
        'network.access.grant' => [
            'label' => 'Geçici erişim ver',
            'description' => 'Birine belirli bir süre için bir yetki verir. Süresi dolunca kendiliğinden biter, ve asla kendinize veremezsiniz.',
        ],
        'platform.maintenance.manage' => [
            'label' => 'Bakım modunu aç ve kapat',
            'description' => 'Mağazayı ve müşteri alanını kapatır. Yönetim alanı açık kalır.',
        ],
        'platform.modules.view' => [
            'label' => 'Kurulu modülleri gör',
            'description' => 'Nelerin kurulu olduğunu ve her birinin neyi kaydettiğini okur.',
        ],
        'platform.modules.manage' => [
            'label' => 'Modül kur ve etkinleştir',
            'description' => 'Bir modülü etkinleştirmek, bu platformun yazmadığı kodu çalıştırır.',
        ],
        'access.roles.view' => [
            'label' => 'Rolleri gör',
            'description' => 'Rolleri ve her birinin neye izin verdiğini okur.',
        ],
        'access.roles.manage' => [
            'label' => 'Rol oluştur ve düzenle',
            'description' => 'Herkesin neye izinli olduğuna karar verir.',
        ],
        'access.permissions.view' => [
            'label' => 'İzin listesini gör',
            'description' => 'Bu kurulumun tanımladığı tüm izinleri okur.',
        ],
        'identity.staff.view' => [
            'label' => 'Personel hesaplarını gör',
            'description' => 'Burada çalışan kişilerin listesini okur.',
        ],
        'identity.staff.manage' => [
            'label' => 'Personel hesabı oluştur ve düzenle',
            'description' => 'Meslektaş davet eder, rollerini değiştirir, hesabı kapatır.',
        ],
        'identity.contacts.view' => [
            'label' => 'Müşteri kullanıcılarını gör',
            'description' => 'Bir müşteri hesabındaki kişileri okur.',
        ],
        'identity.contacts.manage' => [
            'label' => 'Müşteri kullanıcısı oluştur ve düzenle',
            'description' => 'Bir müşteriye kişi ekler, erişimini değiştirir.',
        ],
        'identity.contacts.impersonate' => [
            'label' => 'Müşteri kullanıcısı olarak gir',
            'description' => 'Portalı onların gördüğü gibi görür. Her oturum kaydedilir.',
        ],
        'identity.sessions.manage' => [
            'label' => 'Oturumları sonlandır',
            'description' => 'Bir kişiyi tüm cihazlardan çıkarır.',
        ],
        'identity.login_history.view' => [
            'label' => 'Giriş geçmişini oku',
            'description' => 'Bir kişinin ne zaman ve nereden girdiğini görür.',
        ],
        'crm.customers.view' => [
            'label' => 'Müşterileri gör',
            'description' => 'Bir müşteri kaydını açar ve okur.',
        ],
        'crm.customers.manage' => [
            'label' => 'Müşteri oluştur ve düzenle',
            'description' => 'Müşteri ekler, bilgilerini değiştirir, hesabı kapatır.',
        ],
        'crm.customers.export' => [
            'label' => 'Müşteri verisini dışa aktar',
            'description' => 'Bir müşteri hakkında tutulan her şeyi indirir.',
        ],
        'crm.customers.anonymize' => [
            'label' => 'Müşteriyi anonimleştir',
            'description' => 'Kişisel veriyi siler, mali geçmişi bırakır. Geri alınamaz.',
        ],
        'crm.notes.view' => [
            'label' => 'Müşteri notlarını oku',
            'description' => 'Meslektaşların hesaba yazdıklarını görür.',
        ],
        'crm.notes.manage' => [
            'label' => 'Müşteri notu yaz',
            'description' => 'Hesaba not ekler ve düzenler.',
        ],
        'crm.tags.manage' => [
            'label' => 'Etiketleri yönet',
            'description' => 'Müşteri ve talepleri gruplayan etiketleri oluşturur.',
        ],
        'crm.custom_fields.manage' => [
            'label' => 'Özel alanları yönet',
            'description' => 'Müşteri kaydının hangi ek bilgileri sorduğuna karar verir.',
        ],
        'catalog.groups.view' => [
            'label' => 'Ürün gruplarını gör',
            'description' => 'Kataloğun nasıl düzenlendiğini okur.',
        ],
        'catalog.groups.manage' => [
            'label' => 'Ürün gruplarını düzenle',
            'description' => 'Katalog bölümlerini oluşturur ve sıralar.',
        ],
        'catalog.products.view' => [
            'label' => 'Ürünleri gör',
            'description' => 'Satıştaki ürünleri ve yapılandırmalarını okur.',
        ],
        'catalog.products.manage' => [
            'label' => 'Ürün oluştur ve düzenle',
            'description' => 'Paket ekler, seçeneklerini değiştirir, satıştan kaldırır.',
        ],
        'catalog.pricing.manage' => [
            'label' => 'Fiyat belirle',
            'description' => 'Herhangi bir para birimi ve dönemde fiyatları değiştirir.',
        ],
        'catalog.currencies.manage' => [
            'label' => 'Para birimlerini yönet',
            'description' => 'Bu kurulumun hangi para birimlerinde sattığına karar verir.',
        ],
        'orders.view' => [
            'label' => 'Siparişleri gör',
            'description' => 'Bir siparişi açar ve ne satın alındığını okur.',
        ],
        'orders.manage' => [
            'label' => 'Sipariş oluştur ve değiştir',
            'description' => 'Telefonla sipariş alır ya da bir siparişi ilerletir.',
        ],
        'orders.review' => [
            'label' => 'Beklemedeki siparişi serbest bırak veya reddet',
            'description' => 'Risk kontrolünün durdurduğu sipariş hakkında karar verir.',
        ],
        'promotions.view' => [
            'label' => 'Promosyonları gör',
            'description' => 'İndirim kodlarını ve nerelere uygulandığını okur.',
        ],
        'promotions.manage' => [
            'label' => 'Promosyon oluştur ve düzenle',
            'description' => 'İndirim kodu ekler, kurallarını değiştirir, durdurur.',
        ],
        'billing.invoices.view' => [
            'label' => 'Faturaları ve muhasebeyi gör',
            'description' => 'Faturaları, ödemeleri ve tüm para hareketlerini okur.',
        ],
        'billing.invoices.manage' => [
            'label' => 'Fatura oluştur ve kes',
            'description' => 'Fatura oluşturur, keser, taslağı iptal eder.',
        ],
        'billing.payments.record' => [
            'label' => 'Ödeme kaydet',
            'description' => 'Platform dışında gelen parayı kaydeder.',
        ],
        'billing.refunds.manage' => [
            'label' => 'Ödemeyi iade et',
            'description' => 'Müşteriye para geri gönderir.',
        ],
        'billing.credits.manage' => [
            'label' => 'Bakiye alacağını düzenle',
            'description' => 'Hesaba alacak ekler ya da düşer.',
        ],
        'organizations.view' => [
            'label' => 'Organizasyonları gör',
            'description' => 'Bayi ve müşteri ağacını okur.',
        ],
        'organizations.manage' => [
            'label' => 'Organizasyon oluştur ve düzenle',
            'description' => 'Bayi ekler, taşır, yetkilerini değiştirir.',
        ],
        'settings.view' => [
            'label' => 'Ayarları gör',
            'description' => 'Bu kurulumun nasıl yapılandırıldığını okur.',
        ],
        'settings.manage' => [
            'label' => 'Ayarları değiştir',
            'description' => 'Marka, tema, vergi, numaralandırma ve diğerlerini düzenler.',
        ],
        'services.view' => [
            'label' => 'Servisleri gör',
            'description' => 'Bir barındırma servisini açar ve ayrıntılarını okur.',
        ],
        'services.manage' => [
            'label' => 'Servisleri düzenle',
            'description' => 'Servisin paketini, fiyatını, dönemini veya yenileme tarihini değiştirir.',
        ],
        'services.provision' => [
            'label' => 'Servisi sunucuda kur',
            'description' => 'Sağlayıcıda hesabı oluşturur ya da hatayı yeniden dener.',
        ],
        'services.suspend' => [
            'label' => 'Servisi askıya al ve geri aç',
            'description' => 'Müşterinin barındırmasını kapatır ve geri açar.',
        ],
        'services.terminate' => [
            'label' => 'Servisi sonlandır',
            'description' => 'Sağlayıcıdaki hesabı siler. Geri alınamaz.',
        ],
        'infrastructure.view' => [
            'label' => 'Sunucuları gör',
            'description' => 'Sunucu listesini ve her birinde ne olduğunu okur.',
        ],
        'infrastructure.manage' => [
            'label' => 'Sunucu ekle ve düzenle',
            'description' => 'Sunucu kaydeder ve kimlik bilgilerini tutar.',
        ],
        'infrastructure.connect' => [
            'label' => 'Sunucu paneli aç',
            'description' => 'Parolası verilmeden sunucunun yönetim paneline girer. Her oturum kısa ömürlüdür ve denetim kaydına yazılır.',
        ],
        'domains.view' => [
            'label' => 'Alan adlarını gör',
            'description' => 'Alan adı listesini ve her adın durumunu okur.',
        ],
        'domains.manage' => [
            'label' => 'Alan adlarını düzenle',
            'description' => 'Ad sunucularını, yenilemeyi ve alan adı ek hizmetlerini değiştirir.',
        ],
        'domains.register' => [
            'label' => 'Alan adı kaydet, transfer et ve yenile',
            'description' => 'Müşteri adına kayıt kuruluşunda harcama yapar.',
        ],
        'catalog.tlds.view' => [
            'label' => 'Alan adı fiyatlarını gör',
            'description' => 'Hangi uzantıların satıldığını ve fiyatlarını okur.',
        ],
        'catalog.tlds.manage' => [
            'label' => 'Alan adı fiyatlarını düzenle',
            'description' => 'Hangi uzantıların ne fiyata satılacağına karar verir.',
        ],
        'support.tickets.view' => [
            'label' => 'Destek taleplerini gör',
            'description' => 'Kuyruğu açar ve bir yazışmayı okur.',
        ],
        'support.tickets.manage' => [
            'label' => 'Talepleri yanıtla ve yönlendir',
            'description' => 'Yanıtlar, atar, durum değiştirir, müşteri adına talep açar.',
        ],
        'support.tickets.delete' => [
            'label' => 'Talebi sil',
            'description' => 'Bir yazışmayı kalıcı olarak kaldırır.',
        ],
        'support.departments.manage' => [
            'label' => 'Destek departmanlarını yönet',
            'description' => 'Kuyruk oluşturur ve hizmet sözünü belirler.',
        ],
        'content.announcements.manage' => [
            'label' => 'Duyuru yayınla',
            'description' => 'Müşterilerin mağazada ve portalda göreceklerini yazar.',
        ],
        'content.kb.manage' => [
            'label' => 'Bilgi bankasını düzenle',
            'description' => 'Yardım makaleleri yazar ve yayınlar.',
        ],
        'notifications.view' => [
            'label' => 'Gönderilenleri gör',
            'description' => 'Her mesajın gönderim kaydını okur.',
        ],
        'notifications.manage' => [
            'label' => 'Mesaj şablonlarını düzenle',
            'description' => 'Müşterilere giden metinleri değiştirir.',
        ],
        'portal.dashboard.view' => [
            'label' => 'Müşteri alanını aç',
            'description' => 'Portal ana sayfasını görür.',
        ],
        'portal.profile.view' => [
            'label' => 'Kendi hesabını gör',
            'description' => 'Hesap bilgilerini okur.',
        ],
        'portal.profile.manage' => [
            'label' => 'Kendi hesabını düzenle',
            'description' => 'Hesap bilgilerini ve adresini değiştirir.',
        ],
        'portal.contacts.manage' => [
            'label' => 'Hesabın kullanıcılarını yönet',
            'description' => 'Meslektaş davet eder ve erişimlerini belirler.',
        ],
        'portal.security.manage' => [
            'label' => 'Kendi güvenliğini yönet',
            'description' => 'Şifre değiştirir, iki adımlı doğrulama kurar, oturumları kapatır.',
        ],
        'portal.billing.view' => [
            'label' => 'Kendi faturalarını gör',
            'description' => 'Faturaları ve ödenenleri okur.',
        ],
        'portal.billing.pay' => [
            'label' => 'Fatura öde',
            'description' => 'Bir faturayı ödeme adımına götürür.',
        ],
        'portal.orders.view' => [
            'label' => 'Kendi siparişlerini gör',
            'description' => 'Hesabın verdiği siparişleri okur.',
        ],
        'portal.payment_methods.manage' => [
            'label' => 'Kayıtlı kartları yönet',
            'description' => 'Kayıtlı ödeme yöntemi ekler ve kaldırır.',
        ],
        'portal.tokens.manage' => [
            'label' => 'API anahtarlarını yönet',
            'description' => 'API için anahtar oluşturur ve iptal eder.',
        ],
        'portal.services.view' => [
            'label' => 'Kendi servislerini gör',
            'description' => 'Hesabın barındırma servislerini okur.',
        ],
        'portal.domains.view' => [
            'label' => 'Kendi alan adlarını gör',
            'description' => 'Hesabın alan adlarını okur.',
        ],
        'portal.domains.manage' => [
            'label' => 'Kendi alan adlarını yönet',
            'description' => 'Hesabın alan adlarının ad sunucularını ve yenilemesini değiştirir.',
        ],
        'portal.tickets.view' => [
            'label' => 'Kendi taleplerini gör',
            'description' => 'Hesabın destek yazışmalarını okur.',
        ],
        'portal.tickets.create' => [
            'label' => 'Talep aç',
            'description' => 'Destek yazışması başlatır ve yanıtlar.',
        ],
    ],

    'groups' => [
        'platform' => 'Platform',
        'access' => 'Yetkilendirme',
        'billing' => 'Faturalama',
        'catalog' => 'Katalog',
        'crm' => 'Müşteriler',
        'identity' => 'Kimlik',
        'ordering' => 'Siparişler',
        'organizations' => 'Organizasyonlar',
        'settings' => 'Ayarlar',
        'services' => 'Hizmetler',
        'infrastructure' => 'Altyapı',
        'domains' => 'Alan adları',
        'support' => 'Destek',
        'content' => 'İçerik',
        'notifications' => 'Bildirimler',
        'automation' => 'Otomasyon',
        'portal' => 'Müşteri paneli',
    ],
    'screen' => [
        'edit' => 'Rolü düzenle',
        'form_intro' => 'Bir rolü değiştirmek, o rolü taşıyan herkes için anında geçerli olur.',
        'name' => 'Ad',
        'slug' => 'Kısa ad',
        'slug_hint' => 'Küçük harf, tire ile ayrılmış. Politikalar ve modüller buna atıf yapar.',
        'scope_hint' => 'Personel rolleri müşterilere, müşteri rolleri personele atanamaz.',
        'description' => 'Açıklama',
        'high_risk' => 'Yüksek risk',
        'title' => 'Roller',
        'subtitle' => 'Yetkiler roller üzerinden verilir. Personel rolleri ile müşteri rolleri ayrı tutulur.',
        'add' => 'Rol ekle',
        'role' => 'Rol',
        'scope' => 'Kapsam',
        'permissions' => 'İzinler',
        'system' => 'Sistem',
    ],
];
