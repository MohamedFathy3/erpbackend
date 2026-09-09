# Marketing Website + Trial System + SEO

## نطاق التنفيذ

تمت إضافة موقع تسويقي عام داخل تطبيق React الحالي مع صفحات Home وAbout وServices وPricing وContact وSignup. الموقع منفصل وظيفيًا عن Dashboard الداخلي، بينما يعمل حاليًا داخل نفس تطبيق Vite وبمسارات عامة.

## Free Trial

ينشئ `POST /api/public/trial-signup` Tenant جديدًا، Admin مرتبطًا به، وجميع الموديولات مفعلة. مدة التجربة 15 يومًا. لا توجد بوابة دفع ولا يتم طلب بطاقة ائتمان. يرسل النظام رسالة ترحيب عبر Queue.

حقول الاشتراك هي `trial_starts_at`, `trial_ends_at`, `subscription_status`, و`last_trial_reminder_at`. حالات الاشتراك هي `trial`, `active`, `expired`, و`suspended`. يتم الاحتفاظ ببيانات العميل بعد انتهاء التجربة.

## انتهاء التجربة والتذكيرات

يمنع `EnsureTenantSubscriptionActive` الوصول إلى الطلبات المحمية عند انتهاء التجربة أو تعليق Tenant. ينفذ الأمر `trials:process` يوميًا، ويحوّل التجارب المنتهية إلى `expired`، ويرسل تذكيرًا قبل 3 أيام وقبل يوم واحد عبر Queue. تم تسجيل الأمر في Laravel Scheduler عند الساعة 01:00.

```bash
php artisan schedule:work
php artisan queue:work database --queue=default --tries=3 --sleep=3
```

## Super Admin

تمت إضافة إدارة التجارب إلى Super Admin API وواجهة Super Admin. يستطيع Super Admin رؤية عدد الأيام المتبقية، تمديد التجربة، تحويلها إلى Active، أو تعليقها. كل تعديل يدوي يُسجل باستخدام Spatie Activitylog.

## Marketing Pages

تتضمن الصفحة الرئيسية Hero وCTA ومميزات المنتج وخطوات الاستخدام. تعرض About الرؤية والقيم. تشرح Services الموديولات. تعرض Pricing ثلاث باقات ثابتة للعرض فقط. يرسل Contact الرسائل عبر Queue. ينشئ Signup مساحة العمل التجريبية.

## SEO

كل صفحة عامة تضبط title وdescription وOpen Graph وTwitter Card وCanonical URL وSchema.org عند الحاجة. تمت إضافة `sitemap.xml` و`robots.txt`. يجب استبدال `https://example.com` في sitemap وrobots بالدومين الحقيقي قبل النشر.

الموقع الحالي SPA ويستخدم تحديث Meta Tags في المتصفح. هذا يحسن المشاركة والفهرسة الأساسية، لكنه لا يحقق SSR/Pre-rendering كاملًا. للحصول على أفضل نتائج SEO، يجب نشر صفحات Marketing عبر prerendering أو نقلها لاحقًا إلى Next.js/SSR. لم يتم إضافة بوابة دفع أو Google Analytics لأن الملف طلب عرض الأسعار فقط ولم يحدد مزودًا أو Measurement ID.

## افتراضات قابلة للتعديل

يستخدم الموقع نفس React app حاليًا بدل subdomain مستقل. التسجيل لا يطلب بيانات دفع. البيانات لا تُحذف بعد انتهاء التجربة. الدومين النهائي وبيانات SMTP والبريد الإداري يجب ضبطها في `.env` وملفات SEO قبل النشر.
