# ربط WhatsApp Business Platform بالـ ERP

## طريقة الربط الجديدة

يستخدم النظام **WhatsApp Embedded Signup v4**. مسؤول المنصة يجهّز تطبيق Meta مرة واحدة، ثم يضغط العميل «ربط واتساب من Meta» ويسجل الدخول ويختار نشاطه ورقمه. لا ينسخ العميل Access Token أو App Secret أو Phone Number ID يدويًا. يبدّل الـ Backend رمز التسجيل قصير الصلاحية مباشرة مع Meta، يتحقق من الرقم وWABA، يشترك في التطبيق ويكمل إعداد Cloud API. تحفظ بيانات الاعتماد ورمز التسجيل المشفرين داخل سجل الشركة، ولا يرجعان للواجهة.

إعداد Meta خطوة بخطوة موجود في [`meta-embedded-signup-v4-checklist-ar.md`](meta-embedded-signup-v4-checklist-ar.md). التنفيذ التقني ومفاتيح الخادم موثقة في [`whatsapp-embedded-signup.md`](whatsapp-embedded-signup.md).

## متطلبات Meta قبل تجربة العميل

- تطبيق WhatsApp مربوط بـ Business Portfolio، وإعداد Facebook Login for Business Embedded Signup v4 مع App ID وConfiguration ID.
- نطاق الواجهة والـ Backend منشوران عبر HTTPS؛ عنوان HTTP الحالي لا يكفي لتسجيل Meta.
- تكوين Webhook واحد عام للمنصة: `https://<backend-host>/api/integrations/whatsapp/webhook`، مع `WHATSAPP_WEBHOOK_VERIFY_TOKEN` الخادمي. اشترك على مستوى التطبيق في حقلي `messages` و`account_update`.
- اعتماد Meta Business Verification وApp Review/Advanced Access قد يلزم لإتاحة الربط للعملاء خارج App Roles. تحقق من الأذونات المذكورة في قائمة Meta المرفقة.
- المسار الحالي يدعم تدفق Cloud API القياسي `FINISH` فقط. لا يدعم بعد Coexistence الذي يُبقي الرقم يعمل داخل تطبيق WhatsApp Business على الجوال؛ قد يحتاج هذا النوع من الأرقام إعدادًا مختلفًا.

## إعدادات الخادم

اضبط الأسرار التالية في مدير أسرار الاستضافة أو `.env` الخادمي فقط، وليس في كود الواجهة أو Git:

```dotenv
WHATSAPP_META_APP_ID=<APP_ID>
WHATSAPP_META_APP_SECRET=<APP_SECRET>
WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=<CONFIGURATION_ID>
WHATSAPP_GRAPH_VERSION=v27.0
WHATSAPP_WEBHOOK_VERIFY_TOKEN=<LONG_RANDOM_TOKEN>
APP_URL=https://<backend-host>
```

شغّل `php artisan config:cache` بعد تغيير البيئة. لا ترسل App Secret أو أي رمز Access Token في الدردشة أو إلى العملاء. يُنشئ النظام Verify Token داخليًا للحساب؛ Meta تستخدم Verify Token العام الواحد للمنصة.

## نشر التغييرات

1. انشر تحديثي `erpbackend` و`erpzin` على بيئة الاختبار/الإنتاج المتوافقة.
2. بعد أخذ نسخة احتياطية والتحقق من قاعدة البيانات، شغّل على Backend: `php artisan migrate --force`.
3. اضبط متغيرات البيئة أعلاه ثم `php artisan config:cache`، وتأكد أن reverse proxy/TLS يوصل GET وPOST إلى Laravel دون CSRF أو صفحة تسجيل دخول.
4. من حساب Meta مضاف إلى App Roles، افتح WhatsApp في ERP، أكمل نافذة Meta، ثم تحقق من ظهور الرقم كمتصل. اختبر رسالة واردة من هاتف خارجي وقالبًا معتمدًا.
5. إذا ظهر `setup_failed`، ستعرض الواجهة سببًا مختصرًا؛ أعد تدفق الربط بعد تصحيح سبب Meta. لا ترفع سجلًا يحتوي رموزًا أو بيانات خاصة.

## المسارات

- `GET /api/integrations/whatsapp/webhook` و`POST /api/integrations/whatsapp/webhook`: Callback التطبيق العام، يتحقق من Verify Token في GET ومن توقيع `X-Hub-Signature-256` في POST باستخدام App Secret الخادمي، ثم يوجه الأحداث بحسب phone number ID إلى حساب المستأجر.
- `GET /api/whatsapp/embedded-signup/config` و`POST /api/whatsapp/embedded-signup/complete`: تهيئة الواجهة وتبادل رمز التسجيل، خلف Sanctum وtenant subscription/module middleware.
- `GET /api/whatsapp/account`, `POST /api/whatsapp/account/test`, القوالب والرسائل والإرسال: endpoints المستأجر الحالية.
- مسارات `/api/integrations/whatsapp/webhook/{webhookKey}` القديمة وواجهة التهيئة اليدوية في Backend باقية للتوافق مع الحسابات القائمة؛ لا تستخدم كمسار إعداد افتراضي للعملاء الجدد.

استقبال الرسائل وتخزينها لا يفعّل الرد الآلي بالذكاء الاصطناعي. يبقى الرد الآلي غير مفعّل حتى يُحدد مسؤول النظام سياسة الردود ويُجهّز لها واجهة تحكم واختبارات؛ لا تتصل هذه المهمة بإرسال تلقائي إلى العملاء.
