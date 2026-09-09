# Email Integration — Phase 3

تمت إضافة إرسال البريد من داخل CRM باستخدام **database queue**.

## المكونات

- `email_templates`: قوالب قابلة للتعديل لكل Tenant.
- `email_logs`: سجل كل رسالة وحالتها `queued`, `sent`, أو `failed`.
- `CrmEmailMailable`: رسالة HTML مع دعم BCC.
- `SendCrmEmail`: Job يعمل في الخلفية، يعيد المحاولة 3 مرات، ويكتب الخطأ عند الفشل.
- `EmailController`: إدارة القوالب والسجلات وإرسال البريد للعميل.

## Endpoints

- `GET /api/crm/email/templates`
- `POST /api/crm/email/templates`
- `PUT /api/crm/email/templates/{id}`
- `DELETE /api/crm/email/templates/{id}`
- `GET /api/crm/email/logs`
- `POST /api/crm/customers/{id}/send-email`

طلب الإرسال يقبل `subject`, `body`, و`template_id` اختياريًا. المتغيرات المدعومة داخل القالب هي `{{customer_name}}`, `{{name}}`, `{{email}}`, و`{{phone}}`.

## إعداد SMTP

اضبط القيم التالية في `.env` الحقيقية، ولا تضع كلمات المرور في Git:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-user
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="ERP"
QUEUE_CONNECTION=database
```

بعد تشغيل الـ migrations، شغّل Worker دائمًا على الخادم:

```bash
php artisan migrate --force
php artisan queue:work database --queue=default --tries=3 --sleep=3
```

لبيئة الإنتاج استخدم Supervisor أو systemd لإعادة تشغيل Worker تلقائيًا عند التوقف. بدون Worker ستظل الرسائل في حالة `queued` ولن تُرسل.

## التحقق

تم فحص syntax لجميع ملفات PHP الجديدة والمعدلة، وتم بناء Frontend بنجاح. لم يتم إرسال رسالة حقيقية من بيئة التطوير لأن بيانات SMTP غير موجودة.
