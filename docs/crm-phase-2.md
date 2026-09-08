# CRM — Phase 2

أضيفت كيانات CRM التالية: `leads`, `pipeline_stages`, `deals`, و`crm_activities`. جميعها تحتوي على `tenant_id` وتستخدم Global Scope المضاف في Phase 1.

## Endpoints

| Method | Endpoint | الاستخدام |
|---|---|---|
| GET | `/api/crm/dashboard` | إحصائيات الـ Pipeline ومعدل التحويل |
| GET/POST | `/api/crm/pipeline-stages` | عرض/إنشاء مراحل Pipeline |
| PUT/DELETE | `/api/crm/pipeline-stages/{id}` | تعديل/حذف مرحلة |
| GET/POST | `/api/crm/leads` | عرض/إنشاء العملاء المحتملين |
| GET/PUT/DELETE | `/api/crm/leads/{id}` | إدارة Lead محدد |
| GET/POST | `/api/crm/deals` | عرض/إنشاء الصفقات |
| GET/PUT/DELETE | `/api/crm/deals/{id}` | إدارة صفقة محددة |
| POST | `/api/crm/deals/{id}/move-stage` | نقل الصفقة بين المراحل |
| GET/POST | `/api/crm/activities` | عرض/إنشاء التفاعلات |
| GET/PUT/DELETE | `/api/crm/activities/{id}` | إدارة تفاعل محدد |

كل مسارات CRM محمية بـ `auth:sanctum`, `resolve.tenant`, و`module:crm`. أي معرف لمرحلة أو Lead أو Customer من Tenant آخر لا يمر عبر الـ Global Scope ويرجع `404`.

## Frontend

تم الحفاظ على تبويب العملاء والولاء الحالي، وإضافة تبويب `CRM Pipeline` داخل `/crm` ويحتوي على Kanban قابل للسحب والإفلات، وتبويب Leads، وTimeline للأنشطة، ونموذج إنشاء Lead.

## التحقق المنفذ

تم تشغيل `php -l` على جميع ملفات PHP الجديدة والمعدلة، ونجحت جميع الفحوص. تم تشغيل `npm run build` ونجح البناء. لم يتم تشغيل `php artisan migrate` أو اختبارات integration لعدم توفر `vendor` وملف `.env` في نسخة الريبو المحلية؛ يجب تشغيلها في بيئة المشروع بعد إعداد قاعدة البيانات.

## اختبار قبول مقترح

أنشئ Tenant A وTenant B، ثم أنشئ Lead وDeal في كل منهما. سجّل دخول مستخدم Tenant A، وتأكد أن `/api/crm/leads` و`/api/crm/deals` لا تعرض سجلات B، وأن `POST /api/crm/deals/{dealB}/move-stage` يرجع `404`. عطّل `crm` من Super Admin، وتأكد أن جميع مسارات `/api/crm/*` ترجع `403` وأن تبويب CRM لا يظهر بعد تحديث قائمة الموديولات.
