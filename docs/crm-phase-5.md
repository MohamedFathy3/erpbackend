# CRM — Phase 5: Notifications and Advanced Permissions

## Notifications

تمت إضافة جدول Laravel القياسي `notifications` مع مركز إشعارات داخل الـ Header. يعرض المركز الإشعارات غير المقروءة، يسمح بتحديد إشعار كمقروء، ويسمح بتحديد الكل كمقروء. يتم تحديث القائمة كل 15 ثانية، وتستخدم إشعارات CRM قناة `database` و`broadcast` مع Queue. عند إنشاء صفقة أو نقلها إلى مرحلة أخرى، يتلقى المسؤول عن الصفقة إشعارًا يتضمن رابط CRM.

## Permissions

تمت إضافة جداول `permissions` و`permission_role`. الصلاحيات مصنفة حسب الموديول، وتشمل CRM والتقارير والبريد وإدارة المستخدمين والأدوار والإشعارات والإعدادات. يدعم Admin وUser فحص الصلاحية من خلال `hasPermission`. يحصل Super Admin على جميع الصلاحيات. تم تجهيز صلاحيات افتراضية للأدوار الموجودة أثناء Migration مع مبدأ أقل صلاحية لازمة.

## API

```http
GET  /api/me/permissions
GET  /api/access-control/permissions
GET  /api/access-control/roles
PUT  /api/access-control/roles/{id}
GET  /api/notifications
GET  /api/notifications/unread-count
POST /api/notifications/{id}/read
POST /api/notifications/read-all
```

تتطلب مسارات إدارة الأدوار صلاحية `roles.manage`. تتطلب تقارير CRM صلاحية `crm.view_reports`. يتطلب إرسال بريد CRM صلاحية `crm.send_email`.

## التشغيل

بعد تشغيل Migration، يجب تشغيل Worker حتى تُنفذ إشعارات CRM في الخلفية:

```bash
php artisan migrate --force
php artisan queue:work database --queue=default --tries=3 --sleep=3
```

قناة `broadcast` الحالية تستخدم إعداد Laravel الموجود. لإيصال الإشعار عبر WebSocket فورًا بدل polling، يجب ضبط مزود Broadcast مثل Reverb أو Pusher وإضافة عميل Echo في Frontend. بدون ذلك يظل مركز الإشعارات محدثًا تلقائيًا كل 15 ثانية عبر API.
