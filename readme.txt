=== WooKapso – WhatsApp Notifications ===
Contributors: ahmed
Tags: woocommerce, whatsapp, kapso, notifications, sms
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.1.0
Requires PHP: 8.0
WC requires at least: 6.0
License: GPLv2

إرسال إشعارات WhatsApp تلقائية للعملاء عبر Kapso عند تغيير حالة الطلب، مع لوحة تحكم عربية وإدارة قوالب واختبار وسجلات.

== Description ==
WooKapso يربط WooCommerce بـ Kapso API (WhatsApp Cloud عبر Meta) لإرسال رسائل قوالب معتمدة تلقائياً حسب حالة الطلب.

= المميزات =

* إشعارات حسب حالة الطلب مع تفعيل/تعطيل مستقل لكل حدث:
  * أوردر جديد (Pending / On-hold) — يدعم قالب بأزرار Quick Reply (تأكيد / إلغاء)
  * جاري التجهيز (Processing)
  * اكتمال الطلب / الشحن (Completed) — مع دعم رقم التتبع من حقول الطلب أو إضافات التتبع الشائعة
  * إلغاء الطلب (Cancelled)
* لوحة إعدادات تحت WooCommerce: مفاتيح API و Phone Number ID و **WhatsApp Business Account ID (WABA)** لمسار Kapso v24.0، اختبار الاتصال (قائمة أرقام الحساب)، وربط كل حدث بقالب WhatsApp من القائمة
* إدارة القوالب من ووردبريس: عرض القوالب، إنشاء قالب جديد وإرساله لمراجعة Meta، وحذف قالب (حسب توفر الـ API)
* تبويب اختبار: إرسال رسالة نصية أو قالب تجريبي لأي رقم
* Webhook REST (`wookapso/v1/inbound`) لاستقبال ردود الأزرار والنصوص؛ اختياري: حماية بسر مشترك عبر الترويسة `X-WooKapso-Secret`
* معالجة رد العميل: أزرار القالب أو كلمات مثل تأكيد/إلغاء لتحديث حالة الطلب وإرسال رد نصي داخل نافذة المحادثة
* تنسيق أرقام الهواتف (مثل الأرقام المصرية ذات الصفر البادئ) نحو صيغة مناسبة للإرسال
* سجل رسائل في قاعدة البيانات مع إحصائيات نجاح/فشل ومسح السجلات، وعمود تفاصيل الخطأ (سبب مقترح وحل) عند فشل الإرسال
* تكامل Bosta (اختياري): شحنة عند تأكيد الطلب من واتساب، لوحة إعدادات ورسالة تتبع

= المتطلبات =

* WordPress 5.8+
* PHP 8.0+
* WooCommerce 6.0+ مفعّل
* حساب Kapso وقوالب WhatsApp معتمدة من Meta

== Installation ==
1. ارفع مجلد woo-kapso إلى /wp-content/plugins/
2. فعّل البلجن من Plugins
3. من WooCommerce ← «WhatsApp Kapso» أدخل **API Key** و **Phone Number ID** و **WhatsApp Business Account ID (WABA)** من Meta/Kapso واحفظ (WABA مطلوب لاختبار الاتصال الحديث ولتبويب القوالب على مسار v24.0)
4. انسخ Webhook URL من الإعدادات أو تبويب الاختبار وأضفه في Kapso؛ ويفضّل تعبئة «سر Webhook» إن كانت المنصة تدعم إرسال ترويسة مخصصة
5. أنشئ أو اربط القوالب من تبويب التيمبلتات أو من app.kapso.ai وفق سياسة Meta

= تحديث الإضافة بدون رفع المجلد يدوياً كل مرة =

* **ملف ZIP واحد:** من PowerShell داخل مجلد الإضافة على جهازك:
  `powershell -ExecutionPolicy Bypass -File .\scripts\build-zip.ps1`
  يُنشئ `dist/woo-kapso-VERSION.zip` داخل مجلد الإضافة (الإصدار من `WOOKAPSO_VERSION` في `woo-kapso.php`). ارفع الـ ZIP من: إضافات → أضف جديد → رفع إضافة.
* **على المدى:** اربط الاستضافة بمستودع Git وحدّث بـ `git pull` داخل `wp-content/plugins/woo-kapso` بدل نسخ المجلد بالكامل.

== Changelog ==
= 1.1.0 =
* اختبار الاتصال عبر Kapso Meta Proxy v24.0 (قائمة أرقام الحساب) بدل المسار القديم الذي يعيد 404
* إرسال الرسائل مع ترويسة X-API-Key وتسجيل أخطاء واضحة (سبب/حل) في السجلات واختبار الإرسال
* عمود تفاصيل الخطأ في سجل الرسائل؛ فلتر `wookapso_template_language_code` لمطابقة لغة القالب (مثل ar_EG)

= 1.0.0 =
* الإصدار الأول
