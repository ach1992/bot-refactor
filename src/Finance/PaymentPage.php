<?php
/** AChBot: shared escaped Persian/RTL payment status page. */
function achbotRenderPaymentPage($msg, $type = "", $state = false) {
    global $botUsername;
    $heading = trim((string)$type) ?: 'وضعیت پرداخت';
    $message = (string)$msg;
    $isWarning = preg_match('/⚠️|خطا|نامشخص|تایید نشد|تأیید نشد|نیازمند بررسی|نیاز به بررسی/u', $message) === 1;
    $variant = $isWarning ? 'caution' : ($state === true ? 'success' : 'neutral');
    $title = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $content = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    $rawUser = isset($botUsername) ? ltrim(trim((string)$botUsername), '@') : '';
    $botLink = preg_match('/^[A-Za-z0-9_]{5,32}$/D', $rawUser)
        ? 'https://t.me/' . rawurlencode($rawUser) : null;
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#17152c">
    <title><?= $title ?> — وضعیت سفارش</title>
    <style>
        *,*::before,*::after{box-sizing:border-box}
        :root{color-scheme:dark;--fg:#f7f7fd;--muted:#bac0d6;--accent:#a69bff;--border:rgba(206,211,246,.17)}
        html{min-height:100%;background:#17152c}
        body{min-height:100vh;margin:0;padding:24px 16px;display:flex;align-items:center;justify-content:center;
             font-family:Vazirmatn,Tahoma,Arial,sans-serif;line-height:1.9;color:var(--fg);
             background:radial-gradient(ellipse at 70% 0%,#353065 0%,transparent 50%),#17152c}
        .panel{width:100%;max-width:530px;overflow-wrap:anywhere;border:1px solid var(--border);
               background:rgba(33,30,57,.94);padding:clamp(22px,5vw,42px);border-radius:24px;
               box-shadow:0 20px 65px rgba(0,0,0,.26)}
        .eyebrow{font-size:.82rem;color:var(--accent);font-weight:700;letter-spacing:.025em}
        h1{font-size:clamp(1.3rem,4vw,1.75rem);margin:10px 0 14px;line-height:1.6}
        .status{display:inline-flex;align-items:center;gap:9px;border:1px solid var(--border);
                background:#282541;border-radius:99px;padding:5px 14px;font-size:.8rem;color:#dddaf9}
        .dot{width:9px;height:9px;border-radius:50%;background:#a69bff;flex-shrink:0}
        .success .dot{background:#51d1a2}.caution .dot{background:#f5bf6b}
        .message{margin:22px 0 24px;font-size:1.02rem;white-space:normal;color:var(--fg)}
        .hint{margin:0 0 22px;padding:14px 16px;border-inline-start:3px solid #8980d3;
              background:rgba(166,155,255,.08);border-radius:8px;font-size:.88rem;color:var(--muted)}
        .button{display:block;text-align:center;background:#c4bcff;color:#211b44;text-decoration:none;
                border-radius:12px;font-weight:800;padding:11px 18px;min-height:48px}
        .button:focus-visible{outline:3px solid white;outline-offset:3px}
        footer{font-size:.78rem;color:#a6adca;margin-top:22px;text-align:center}
        @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
    </style>
</head>
<body>
    <main class="panel <?= $variant ?>">
        <div class="eyebrow">اطلاع‌رسانی سفارش</div>
        <h1><?= $title ?></h1>
        <div class="status"><span class="dot" aria-hidden="true"></span>
            <?= $variant === 'success' ? 'نتیجه ثبت‌شده' : 'پیگیری وضعیت سفارش' ?>
        </div>
        <div class="message" role="status"><?= $content ?></div>
        <p class="hint">این صفحه به‌تنهایی مدرک تسویه یا تحویل سرویس نیست. نتیجه نهایی را در ربات پیگیری کنید. بررسی بعضی پرداخت‌ها بیش از پنج دقیقه زمان می‌برد.</p>
        <?php if ($botLink !== null): ?>
            <a class="button" href="<?= htmlspecialchars($botLink, ENT_QUOTES, 'UTF-8') ?>"
               rel="noopener noreferrer">بازگشت به ربات و پیگیری سفارش</a>
        <?php endif; ?>
        <footer>در صورت مغایرت، شناسه سفارش را برای پشتیبانی ارسال کنید.</footer>
    </main>
</body>
</html>
    <?php
}
