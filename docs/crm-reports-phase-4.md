# CRM Reports & Advanced Analytics — Phase 4

## API

```http
GET /api/crm/reports/analytics
GET /api/crm/reports/analytics?from=2026-09-01&to=2026-09-30
```

المسار محمي بـ `auth:sanctum`, `resolve.tenant`, و`module:crm`. لذلك لا تُجمع بيانات أكثر من Tenant في استجابة واحدة.

## المؤشرات

| مجموعة | المؤشرات |
|---|---|
| Summary | Leads، Deals، Pipeline Value، Won Value، Won Deals، Lost Deals، Conversion Rate، Win Rate |
| Operations | عدد الأنشطة، عدد الإيميلات، الإيميلات الناجحة، Email Success Rate |
| Funnel | عدد الصفقات وقيمتها داخل كل Pipeline Stage |
| Lead Sources | عدد Leads والتحويل ومعدل التحويل حسب المصدر |
| Assignee Performance | عدد الصفقات، الصفقات الرابحة، قيمة Pipeline، وقيمة الصفقات الرابحة حسب المسؤول |
| Daily Trend | Leads وDeals وActivities وEmails لكل يوم في الفترة |

## تعريفات المؤشرات

يُحتسب **Conversion Rate** كنسبة الصفقات الرابحة إلى عدد Leads في الفترة. يُحتسب **Win Rate** كنسبة الصفقات الرابحة إلى مجموع الصفقات المغلقة فوزًا أو خسارة. يُحتسب **Email Success Rate** كنسبة سجلات `email_logs` التي وصلت إلى حالة `sent` إلى إجمالي سجلات البريد في الفترة.

## الواجهة

داخل `/crm` يوجد تبويب **التقارير**. عند فتحه، تُستدعى API التقارير وتظهر بطاقات KPI، وFunnel المراحل، ومصادر Leads، والاتجاه اليومي لآخر 14 يومًا من الفترة الافتراضية.

## ملاحظات الدقة

التقارير لا تستخدم بيانات اصطناعية. تعتمد على الجداول الحالية فقط. دقة المؤشرات الزمنية تعتمد على `created_at` للـ Leads والصفقات والإيميلات، وعلى `occurred_at` للأنشطة. لا يتضمن Email Success Rate فتح الرسالة أو النقر على الروابط لأن ذلك يحتاج مزود بريد أو tracking mechanism إضافيًا.
