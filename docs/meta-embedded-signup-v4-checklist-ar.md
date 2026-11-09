# إعداد Meta WhatsApp Embedded Signup v4 لـ ERP

هذه القائمة تجهز تطبيق المنصة مرة واحدة. بعد الإعداد، العميل يختار «ربط واتساب» من ERP ويسجّل الدخول إلى Meta ويختار نشاطه ورقمه؛ لا ينسخ Access Token يدويًا. ستظل Meta قد تطلب من العميل التحقق من ملكية الرقم أو استكمال بيانات نشاطه.

## 1. إنشاء التطبيق والتحقق من المنصة

1. افتح [Meta for Developers](https://developers.facebook.com/apps/) وأنشئ تطبيقًا مرتبطًا بـ **Business Portfolio** واختر استخدام **WhatsApp**.
2. من لوحة التطبيق افتح **Use cases → Customize → WhatsApp → Tech Provider onboarding**.
3. أكمل **Business Verification** للمنصة. أضف بيانات نشاطك وموقع الويب وسياسة الخصوصية وشروط الاستخدام في إعدادات التطبيق.
4. قدّم التطبيق إلى **App Review** واطلب **Advanced Access** على الأقل لـ `whatsapp_business_management` و`whatsapp_business_messaging`. Meta تطلب فيديوهات تثبت الإرسال وإدارة القوالب. قبل الموافقة، يكون الاختبار محدودًا بأدوار التطبيق (Admins/Developers/Testers) ولا يعني أن العملاء العموميين يستطيعون الربط والإرسال.

## 2. إعداد Facebook Login for Business وv4

1. من **Facebook Login for Business → Settings → Client OAuth settings** فعّل: Client OAuth Login، Web OAuth Login، Enforce HTTPS، Embedded Browser OAuth Login، Strict Mode للـ redirect URIs، وLogin with the JavaScript SDK.
2. أضف **نطاق الواجهة العام عبر HTTPS** إلى Allowed Domains وValid OAuth Redirect URIs. أضف نطاق التطوير عبر HTTPS عند الحاجة. عنوان HTTP محلي أو `http://...:7000` لن يعمل للإنتاج.
3. افتح **Facebook Login for Business → Configurations → Create from template**، واختر قالب **WhatsApp Embedded Signup Configuration With 60 Expiration Token**. تأكد أن Login variation هي **WhatsApp Embedded Signup**، واختر منتج **WhatsApp Cloud API** فقط ما لم تكن تحتاج منتجات أخرى.
4. انسخ **Configuration ID** الناتج. ستحتاج أيضًا **App ID** من أعلى لوحة التطبيق. كلاهما معرّفان عامان نسبيًا ويُستخدمان لتهيئة الواجهة.

## 3. إعداد Webhook للتطبيق

1. عيّن Webhook callback واحدًا ثابتًا للمنصة على عنوان HTTPS الذي يوفره الـ Backend، مثل `https://API-DOMAIN/api/integrations/whatsapp/webhook`.
2. اختر Verify Token عشوائيًا قويًا وضعه كإعداد خادمي `WHATSAPP_WEBHOOK_VERIFY_TOKEN`. أدخله في Meta Dashboard عند اختبار Callback. لا ترسله للعميل ولا تضع App Secret أو Access Token في الواجهة.
3. اشترك على مستوى التطبيق في حقلي Webhook `messages` و`account_update`. حقل `account_update` مطلوب لإتمام تدفق التسجيل، و`messages` لاستقبال الرسائل وتحديث حالات الإرسال.
4. يجب أن يكون `WHATSAPP_META_APP_SECRET` في إعدادات أسرار الـ Backend فقط؛ يستخدمه الخادم للتحقق من توقيع Meta وتبادل رمز التسجيل.

## 4. القيم التي تُضبط على الخادم

أضف عبر مدير أسرار الاستضافة (لا تضعها في Git أو المحادثة):

```dotenv
WHATSAPP_META_APP_ID=<APP_ID>
WHATSAPP_META_APP_SECRET=<APP_SECRET>
WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=<CONFIGURATION_ID>
WHATSAPP_GRAPH_VERSION=v27.0
WHATSAPP_WEBHOOK_VERIFY_TOKEN=<LONG_RANDOM_VERIFY_TOKEN>
```

بعد التعديل شغّل `php artisan config:cache` بالطريقة المعتادة على الاستضافة، ثم انشر الواجهة والـ Backend على HTTPS. App Secret سرّي؛ لا ترسله إلى Manus أو في دردشة أو تذكرة دعم.

## 5. اختبار آمن قبل فتحه للعملاء

1. أضف حساب Meta الذي سيجرب الربط إلى **App Roles** كـ Admin أو Developer أو Tester.
2. افتح شاشة WhatsApp في ERP على النطاق الذي أُدرج في إعداد OAuth، واضغط **ربط واتساب**. اختر حساب النشاط، الرقم، وأكمل خطوة تأكيد الرقم لدى Meta.
3. بعد اكتمال التدفق يتبادل Backend رمز Meta القصير الصلاحية server-to-server، ويتحقق من تطابق WABA والرقم، ويشترك في Webhooks ويكمل تسجيل رقم Cloud API. لا تعرض واجهة ERP الرمز ولا تحفظه في سجلات المتصفح.
4. أرسل رسالة اختبار من مستخدم إلى رقم النشاط، وتأكد من وصول Webhook. اختبر قالبًا معتمدًا قبل الإرسال إلى عملاء فعليين.
5. قبل تمكين العملاء خارج أدوار التطبيق، أكمل موافقات Meta المطلوبة، وسياسة الخصوصية وحذف بيانات المستخدم. إذا لم تكن Solution Partner، قد تطلب Meta من حساب العميل إضافة وسيلة دفع قبل الاستخدام التجاري.

## ملاحظات مهمة عن الأرقام

- تدفق Embedded Signup القياسي يتيح للعميل إنشاء/اختيار حساب WhatsApp والتحقق من الرقم؛ ليس مجرد إدخال رقم بلا موافقة أو تحقق لدى Meta.
- عند تسجيل Cloud API يطلب Meta PIN مكوّنًا من 6 أرقام لتأكيد الخطوتين. ينشئه النظام لكل رقم ويخزنه مشفرًا على Backend؛ لا يحتاج العميل إلى نسخ Access Token أو اختيار هذا PIN في التدفق المعتاد.
- إذا كان الرقم مستخدمًا بالفعل في تطبيق **WhatsApp Business** ويريد العميل الاستمرار باستخدام التطبيق بالتوازي مع Cloud API، فهذا مسار **Coexistence** منفصل ويجب إعداد Embedded Signup له. لا تختَر الرقم القياسي عشوائيًا؛ اتفق على ما إذا كان المطلوب نقل الرقم إلى Cloud API فقط أو إبقاء التطبيق قائمًا.
- توثيق Meta الحالي يقول إن Embedded Signup v2/v3 أُوقفت في 15 أكتوبر 2026، لذا يُستخدم v4.

## المصادر الرسمية

- [Embedded Signup v4](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/version-4)
- [Embedded Signup Implementation](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/implementation)
- [Onboarding customers as a Tech Provider](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-customers-as-a-tech-provider)
- [Become a Tech Provider](https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/get-started-for-tech-providers)
- [WhatsApp Webhooks](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/overview)
- [Register a business phone number](https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/registration)
