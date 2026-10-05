# مشروع اختبار بوابة دفع زين كاش (ZainCash Payment Integration)

هذا المشورع عبارة عن نظام كامل وجاهز للاختبار مخصص للنطاق **krar.top**.

## البيانات المدمجة:
- **MSISDN:** `9647887276016`
- **Merchant ID:** `d14f70274313451fae3cdf2de370e09a`
- **Redirect URL:** `http://krar.top/payment-callback`
- **Environment:** Production/Live API (`https://pg-api.zaincash.iq`)

## طريقة التشغيل المحلي / السيرفر:

1. قم بفك الضغط عن الملف:
   ```bash
   unzip zaincash-krar-top.zip
   cd zaincash-krar-top
   ```

2. قم بتثبيت الحزم المطلوبة (Node.js يجب أن يكون مثبتاً):
   ```bash
   npm install
   ```

3. قم بتشغيل السيرفر:
   ```bash
   npm start
   ```

4. افتح المتصفح وتوجه إلى:
   `http://localhost:3000` أو ارقعه على سيرفرك المربوط بالنطاق `http://krar.top`.

## المكونات الأساسية:
- `server.js`: الخادم التنسيقي الذي يقوم بتشفير الـ JWT وإرساله لـ ZainCash وتلقي الـ Redirect Callback.
- `public/index.html`: واجهة واضحة وسريعة لتجربة الدفع بالدينار العراقي.
- `.env`: يحتوي على جميع مفاتيح الاعتماد الخاصة بك.
