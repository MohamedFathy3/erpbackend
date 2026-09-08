# Multi-Tenancy — Phase 1

تمت إضافة عزل Shared-Database/Shared-Schema مع `tenant_id`، وجدولي `tenants` و`tenant_modules`.

## API

- `GET /api/super-admin/tenants`
- `POST /api/super-admin/tenants` with `{name, slug?, status?, plan?}`
- `GET /api/super-admin/tenants/{tenant}/modules`
- `PATCH /api/super-admin/tenants/{tenant}/modules/{moduleKey}` with `{is_enabled: boolean}`
- `GET /api/me/enabled-modules`

طلبات الإدارة محمية بـ `super_admin === true`. المستخدم العادي يحصل على `403` عند محاولة الوصول إليها. الموديولات الجديدة تُنشأ مفعّلة افتراضيًا لكل Tenant.

## التشغيل

1. شغّل `php artisan migrate` على نسخة احتياطية من قاعدة البيانات.
2. راجع أن الحسابات القديمة مرتبطة بـ `Default Tenant`، ثم انقلها إلى Tenants الصحيحة.
3. أنشئ Tenant تجريبيًا A وB، وأنشئ مستخدمًا لكل منهما.
4. نفّذ طلب قراءة/تعديل لرقم سجل موجود في Tenant الآخر؛ يجب أن يرجع `404` أو لا يظهر في الاستعلام بسبب الـ Global Scope.
5. عطّل `crm` من Super Admin، ثم اختبر أن endpoints الموديول التي تستخدم `module:crm` ترجع `403`. يجب إضافة middleware إلى كل مجموعة routes خاصة بكل موديول عند إنشائها.

## ملاحظة نشر

لم يُنفَّذ `php artisan migrate` في بيئة التطوير الحالية لأن PHP غير مثبت في الـ sandbox. تم تشغيل بناء Frontend بنجاح، بينما lint الحالي يحتوي أخطاء سابقة كثيرة في ملفات المشروع غير المتأثرة بهذه المرحلة.
