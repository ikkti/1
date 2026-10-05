const express = require('express');
const jwt = require('jsonwebtoken');
const axios = require('axios');
const path = require('path');
const cors = require('cors');
require('dotenv').config();

const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(cors());
app.use(express.static(path.join(__dirname, 'public')));

const PORT = process.env.PORT || 3000;
const MSISDN = process.env.MSISDN || "9647887276016";
const SECRET = process.env.CLIENT_SECRET || "UzngAXjlbO5NXEej8RDh28QcYHxbbTJn";
const MERCHANT_ID = process.env.MERCHANT_ID || "d14f70274313451fae3cdf2de370e09a";
const ZAINCASH_API_URL = process.env.ZAINCASH_API_URL || "https://pg-api.zaincash.iq";
const REDIRECT_URL = process.env.REDIRECT_URL || "http://krar.top/payment-callback";

// API Endpoint to initiate transaction
app.post('/api/pay', async (req, res) => {
    try {
        const { amount, serviceType } = req.body;
        const time = Date.now();
        const orderId = "ORDER_" + time;

        const payload = {
            amount: Number(amount) || 1000,
            serviceType: serviceType || "اختبار بوابة الدفع",
            msisdn: MSISDN,
            orderId: orderId,
            redirectUrl: REDIRECT_URL,
            iat: Math.floor(time / 1000),
            exp: Math.floor(time / 1000) + (60 * 60)
        };

        // Sign token with secret
        const token = jwt.sign(payload, SECRET);

        // Send initialization request to ZainCash
        const initRes = await axios.post(`${ZAINCASH_API_URL}/transaction/init`, {
            token: token,
            merchantId: MERCHANT_ID,
            lang: "ar"
        });

        if (initRes.data && initRes.data.id) {
            const transactionId = initRes.data.id;
            const payUrl = `${ZAINCASH_API_URL}/transaction/pay?id=${transactionId}`;
            return res.json({ success: true, payUrl, transactionId, orderId });
        } else {
            return res.status(400).json({ success: false, message: "فشل في إنشاء رابط الدفع", data: initRes.data });
        }
    } catch (error) {
        console.error("ZainCash Init Error:", error.response ? error.response.data : error.message);
        return res.status(500).json({ 
            success: false, 
            message: error.response ? JSON.stringify(error.response.data) : error.message 
        });
    }
});

// Handle Payment Callback Redirect from ZainCash
app.get('/payment-callback', (req, res) => {
    const tokenFromQuery = req.query.token;
    if (!tokenFromQuery) {
        return res.status(400).send("No token provided in callback.");
    }

    try {
        const decoded = jwt.verify(tokenFromQuery, SECRET);
        // Serve HTML page displaying result based on decoded token status
        res.send(`
            <!DOCTYPE html>
            <html lang="ar" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>نتيجة الدفع - $krar.top</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
                <style>
                    body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
                    .card { border: none; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); padding: 30px; text-align: center; max-width: 500px; width: 100%; }
                    .status-icon { font-size: 64px; margin-bottom: 20px; }
                </style>
            </head>
            <body>
                <div class="card">
                    ${decoded.status === 'SUCCESS' ? `
                        <div class="status-icon text-success">✓</div>
                        <h3 class="text-success fw-bold">تم الدفع بنجاح!</h3>
                        <p class="text-muted">شكراً لك، اكتملت عملية الدفع بنجاح عبر زين كاش.</p>
                    ` : `
                        <div class="status-icon text-danger">✕</div>
                        <h3 class="text-danger fw-bold">فشلت عملية الدفع</h3>
                        <p class="text-muted">لم تكتمل عملية الدفع أو تم إلغاؤها.</p>
                    `}
                    <hr>
                    <div class="text-start fs-6">
                        <p><strong>رقم الطلب (Order ID):</strong> ${decoded.orderId || 'N/A'}</p>
                        <p><strong>حالة العملية:</strong> ${decoded.status || 'N/A'}</p>
                        <p><strong>رقم العملية (Transaction ID):</strong> ${decoded.id || 'N/A'}</p>
                    </div>
                    <a href="/" class="btn btn-primary mt-3 w-100">العودة للرئيسية</a>
                </div>
            </body>
            </html>
        `);
    } catch (err) {
        res.status(400).send("توكن غير صالح أو منتهي الصلاحية: " + err.message);
    }
});

// Catch all to serve frontend
app.get('*', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

app.listen(PORT, () => {
    console.log(`Server running on http://localhost:${PORT} for domain $krar.top`);
});
