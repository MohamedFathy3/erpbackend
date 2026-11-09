# ربط WhatsApp Business Platform بالـ ERP

## الوضع الحالي في الكود

يحتوي هذا التغيير على إعداد حساب WhatsApp لكل tenant، مع حفظ Access Token وApp Secret وWebhook Verify Token مشفرة في قاعدة البيانات، وعنوان Webhook منفصل لكل حساب. بعد تشغيل الترحيلات، يستطيع مسؤول الشركة حفظ بيانات الرقم واختبار اتصال Graph API من صفحة WhatsApp في ERP. يستقبل الـ Webhook رسائل `messages` وتحديثات الحالة، ويتحقق من توقيع Meta، ويسجل أحداثًا ورسائل واردة مع منع التكرار.

استقبال الرسائل وتخزينها لا يفعّل رد الذكاء الاصطناعي. يجب بناء Agent orchestration وأدوات ERP والصلاحيات وتحويل المحادثة قبل فتح الرد الآلي.

## إعداد التطبيق على Meta

1. سجّل حسابك كمطوّر في [Meta for Developers](https://developers.facebook.com/async/registration/) ثم أنشئ تطبيقًا جديدًا باختيار حالة استخدام WhatsApp، واربطه بمحفظة Meta Business المناسبة. استخدم صفحة **WhatsApp > API Setup** لإضافة أو اختيار حساب مراسلة ورقم تجريبي أولًا.
2. اختبر الإرسال إلى رقم مستلم مسموح به. خذ من API Setup قيمة **Phone Number ID** وقيمة **WhatsApp Business Account / Messaging Account ID**. أسماء الحسابات ومعرّفاتها تتطور لدى Meta، لذلك نعتمد على الـ ID الذي تعرضه لوحة التطبيق.
3. لا تستخدم Access Token المؤقت للاختبار في الإنتاج. أنشئ System User من Business Settings، وأسند إليه التطبيق وحساب WhatsApp، ثم أنشئ رمزًا بصلاحيات `whatsapp_business_messaging` و`whatsapp_business_management`، وأضف `business_management` حيث تتطلبه طريقة إعداد Meta. خزّن الرمز في صفحة إعداد WhatsApp لهذا الـ tenant فقط.
4. احفظ **App Secret** الخاص بتطبيق Meta في إعداد الشركة عند الحاجة إلى توقيع Webhook. إذا كان كل العملاء يستخدمون تطبيق Meta واحدًا، يمكن ضبط `WHATSAPP_APP_SECRET` على الخادم كقيمة افتراضية مشتركة؛ أما Access Token فيبقى خاصًا بكل شركة ولا يوضع في `.env` المشترك.
5. أنشئ Verify Token قويًا عشوائيًا لكل شركة (16 حرفًا على الأقل)، وأدخله في صفحة إعداد WhatsApp. استخدم القيمة نفسها في لوحة Meta عند التحقق من Callback URL؛ لا يُعاد عرض هذا الرمز بعد حفظه.

للاستخدام التجاري متعدد العملاء على نطاق واسع، استخدم **Embedded Signup v4** بدل طلب نسخ Access Token يدويًا من كل عميل. يتطلب هذا تهيئة تطبيق Meta وحالة الاستخدام/الصلاحيات الملائمة، ثم خادمًا لإتمام تبادل رمز التسجيل، وربط الرقم، والاشتراك في Webhooks بعد نجاح تدفق العميل. هذه الطبقة غير منفذة في هذا التغيير.

## إعداد الـ Webhook

1. انشر الـ Backend خلف HTTPS بشهادة TLS موثوقة، وشغّل migration.
2. افتح ERP بحساب الشركة، ثم **WhatsApp > إعداد الربط**. أدخل Phone Number ID وWABA/Messaging Account ID ورقم العرض واسم النشاط والرمز الدائم وApp Secret وVerify Token، ثم احفظ.
3. انسخ عنوان Callback الفريد الذي يعرضه ERP إلى لوحة Meta، في قسم **WhatsApp > Configuration** أو **Use cases > Customize > Configuration** بحسب واجهة التطبيق. ضع Verify Token المطابق، ثم نفّذ Verify/Save.
4. اشترك في الحقل `messages` على WhatsApp Business Account حتى تصل الرسائل الواردة وتحديثات الرسائل الصادرة. تتضمن رسائل Meta رمز توقيع `X-Hub-Signature-256`؛ يرفض الـ Backend الطلب عند غياب السر أو عدم صحة التوقيع.
5. اضغط **اختبار اتصال** من ERP. أرسل رسالة من هاتف خارجي إلى رقم النشاط، ثم تحقق من سجل الأحداث والمحادثة في قاعدة البيانات/السجلات التشغيلية. يجب إضافة واجهة صندوق المحادثات قبل أن تصبح المحادثات الواردة قابلة للمعالجة من لوحة ERP.

المسارات المسجلة:

- `GET /api/integrations/whatsapp/webhook/{webhookKey}`: تحقق Meta من `hub.mode` و`hub.verify_token`، ثم يعيد `hub.challenge`.
- `POST /api/integrations/whatsapp/webhook/{webhookKey}`: يتحقق من HMAC-SHA256، يطابق `phone_number_id` مع الحساب، يزيل التكرار، ويحفظ رسالة الوارد وحالة الرسائل.
- `GET/PUT /api/whatsapp/account` و`POST /api/whatsapp/account/test`: إعداد الحساب واختبار الاتصال للمستخدم المصادق عليه داخل tenant الحالي.
- `GET /api/whatsapp/templates` و`GET /api/whatsapp/messages` و`POST /api/whatsapp/customers/{customer}/send`: القوالب والرسائل والإرسال من بيانات الاتصال الخاصة بالشركة.

## تشغيل التغيير ونشره

- راجع `APP_KEY` قبل التشغيل؛ تستخدمه Laravel لتشفير أسرار كل اتصال. لا تغيّره بعد حفظ الرموز المشفرة إلا بخطة تدوير مفاتيح.
- شغّل `php artisan migrate --force` بعد نسخة احتياطية وفحص بيئة الإنتاج.
- أضف `WHATSAPP_APP_SECRET` إلى أسرار الخادم فقط إذا كنت تريد App Secret افتراضيًا مشتركًا، ثم أعد تحميل إعدادات Laravel (`php artisan config:cache`).
- انشر الـ Backend والـ Frontend على النطاقات الفعلية، وتأكد أن Callback URL يشير إلى **Backend** وليس استضافة الواجهة، وأن GET وPOST يصلان إلى Laravel دون صفحة تسجيل دخول أو حماية CSRF.
- لم يتم دفع أي تغيير إلى GitHub أو نشره على بيئة الإنتاج ضمن هذه المهمة.
