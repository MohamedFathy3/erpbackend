# تكامل البصمة والرواتب

يدعم HR أجهزة ZKTeco التي تتصل عبر TCP/UDP على المنفذ `4370`، مثل الجهاز الظاهر في إعدادات الشبكة (`192.168.1.201`). كل جهاز مرتبط بمستأجر واحد ويمكن ربطه بفرع.

## إعداد الجهاز

## تجهيز السيرفر

بعد سحب نسخة جديدة من Git يجب تثبيت مكتبات PHP وإعادة بناء autoload، وإلا سيظهر الخطأ `Class Mithun\\PhpZkteco\\Libs\\ZKTeco not found`:

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan route:clear
php artisan route:list --path=biometric/agents
```

يجب أن تظهر مسارات `biometric/agents` في الأمر الأخير. إذا استمر `404` بعد تحديث الخادم، فتأكد أن الـrelease نشر `routes/api.php` وشغّل `php artisan route:clear` على نسخة الإنتاج.

تأكد أن الأمر التالي يطبع `1`:

```bash
php -r 'require "vendor/autoload.php"; var_dump(class_exists("Mithun\\PhpZkteco\\Libs\\ZKTeco"));'
```

1. ثبّت IP الجهاز داخل الشبكة، مثل `192.168.1.201`.
2. اترك `TCP COMM.Port` على `4370`، واختر `TCP/IP` داخل ERP (ويمكن تجربة UDP عند الحاجة).
3. من **HR → البصمة والرواتب → الأجهزة** أضف اسم الجهاز وIP والمنفذ.
4. اضغط **اختبار** ثم **سحب البصمات**.
5. من **ربط الموظفين** أدخل `User ID` الموجود في الجهاز لكل موظف. يفضّل أن يكون User ID مختلفًا لكل موظف.

## Local Connector Agent

عند استضافة ERP SaaS خارج شبكة الشركة، لا يستطيع خادم Laravel عادةً الوصول إلى عنوان خاص مثل `192.168.x.x`. الـAgent المحلي يتصل بالجهاز من داخل شبكة العميل ويرسل السجلات إلى API الخاص بالمستأجر عبر HTTPS؛ لا يحتاج فتح منفذ جهاز البصمة للإنترنت.

1. بعد نشر migrations ومسارات الـAPI، افتح **HR → البصمة والرواتب → Local Agent** وأنشئ رمز اقتران لمرة واحدة. الرمز صالح 10 دقائق ويُحفظ على الخادم كـhash فقط.
2. على كمبيوتر دائم التشغيل داخل شبكة الجهاز، انسخ مجلد `agent/` من مستودع الواجهة وثبّته:

   ```bash
   python -m pip install ./agent
   erp-biometric-agent pair --api-url https://acsa.professionalacademyedu.com/api --code ONE_TIME_CODE
   erp-biometric-agent sync-once
   erp-biometric-agent run
   ```

3. اضبط عناوين الأجهزة في ERP واربط `User ID` بموظفي ERP. سيكتشف الـAgent الأجهزة النشطة عبر API ويزامن السجلات دوريًا.

مسارات الاقتران والتشغيل هي `POST /biometric/agents/pairing-codes`, `POST /biometric/agents/pair`, `GET /biometric/agents/devices`, `POST /biometric/agents/events`, و`POST /biometric/agents/heartbeat`. إنشاء رمز الاقتران وإلغاءه يتطلبان مستخدمًا مسجلاً لديه صلاحية `hr.view`؛ بقية مسارات الـAgent تستخدم bearer token عشوائيًا خاصًا به، يُخزّن كـSHA-256 hash، ومقيّدًا بالمستأجر وقابلًا للإلغاء من شاشة Local Agent.

## المزامنة

- المزامنة اليدوية متاحة من شاشة الأجهزة.
- الأمر المجدول يسحب السجلات من كل الأجهزة النشطة كل 10 دقائق:

```bash
php artisan biometric:sync
php artisan biometric:sync --device=DEVICE_ID
```

يجب تشغيل Laravel scheduler في بيئة الإنتاج (`php artisan schedule:work` أو Cron يستدعي `php artisan schedule:run` كل دقيقة). لا يتم حذف سجلات الجهاز تلقائيًا؛ السجلات الخام محفوظة في `biometric_logs` لمنع التكرار والمراجعة.

## الحسابات

من **المعادلات** يمكن ضبط:

- بداية ونهاية الدوام.
- دقائق السماح قبل اعتبار الموظف متأخرًا.
- خصم التأخير: بدون خصم، مبلغ ثابت، مبلغ لكل ساعة تأخير، أو نسبة من الراتب.
- خصم الغياب: بدون خصم، مبلغ ثابت، مبلغ لكل يوم، أو نسبة من الراتب.

تُحوّل أول بصمة في اليوم إلى دخول وآخر بصمة إلى انصراف، وتُحدّث سجل `attendance` مع مصدر `biometric` ودقائق التأخير ومدة العمل. معاينة الراتب تعرض الخصومات المتوقعة ولا تصرف الراتب تلقائيًا؛ الصرف يظل من دورة الرواتب المحاسبية الحالية.

## ملاحظات الشبكة

- عند المزامنة المباشرة، يجب أن يستطيع خادم Laravel الوصول إلى IP الجهاز؛ وعند SaaS استخدم الـAgent المحلي داخل شبكة العميل.
- لا تفتح المنفذ 4370 للعامة دون جدار ناري وقائمة IP مسموحة.
- في حالة وجود أكثر من فرع/وحدة، أضف جهازًا مستقلًا لكل IP واربطه بالفرع المناسب.
