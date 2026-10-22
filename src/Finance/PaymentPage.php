<?php
/**
 * Issue #51: single, read-only Persian/RTL browser view for supported payments.
 *
 * This boundary never reads or advances payment/provider/customer state.
 * Legacy callers pass a presentational boolean, NOT verified proof of a
 * merchant payment or a delivered service. Conflicting message text takes
 * precedence over any old true flag to avoid falsely displaying success.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function achbotPaymentPageViewState(string $message, string $heading, $legacyState): string {
    $visible = $heading . "\n" . $message;

    // Warning terms are grounded in the currently supported result messages
    // from all three hosted gateways, including paid-but-not-fulfilled cases.
    // Never infer financial success from a positive phrase if a second clause
    // describes a failed or uncertain fulfillment/credit.
    if (preg_match(
        '/⚠️|❌|خطا|نامشخص|نامعتبر|یافت نشد|پایان یافته|منقضی|متوقف|' .
        'تأیید نشد|تایید نشد|تأیید نبود|تایید نبود|قابل تأیید نبود|' .
        'قابل تایید نبود|انجام نشد|تکمیل نشد|تکمیل نشده|قطعی نیست|' .
        'نیازمند بررسی|نیاز به بررسی|نیازمند پیگیری|مغایرت|مشکل|' .
        'شناسایی نشد|در دسترس نیست|لغو شد|مجاز نیست|نمی‌شود|' .
        'بازگشت اعتبار|(?<!\\p{L})(?:ولی|اما)(?!\\p{L})|' .
        'پرداخت مجدد انجام ندهید/u',
        $visible
    ) === 1) {
        return 'caution';
    }

    // Showing an invoice / getting a browser return / recording an early
    // provider event never means that subscription delivery has completed.
    if (preg_match(
        '/در حال |منتظر |ثبت شده|دریافت شد|ثبت شد|صادر شد|' .
        'پیگیری می‌شود|بررسی می‌شود|در انتظار |تا اعلام نتیجه/u',
        $message
    ) === 1) {
        return 'pending';
    }

    // The old boolean has visual meaning only. Positive copy without an
    // unambiguous completion statement stays neutral, even if true.
    if ($legacyState === true &&
        preg_match('/با موفقیت|تکمیل شد|تمدید شد|اضافه شد|افزوده شد/u', $message) === 1) {
        return 'success';
    }
    return 'neutral';
}

function achbotRenderPaymentPage($msg, $type = "", $state = false) {
    global $botUsername;
    $heading = trim((string)$type) ?: 'وضعیت پرداخت';
    $message = (string)$msg;
    $variant = achbotPaymentPageViewState($message, $heading, $state);
    $status = [
        'success' => 'نتیجه ثبت شد',
        'pending' => 'در انتظار نتیجه',
        'caution' => 'نیازمند پیگیری',
        'neutral' => 'وضعیت سفارش',
    ][$variant];
    $title = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $content = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $rawUser = isset($botUsername) ? ltrim(trim((string)$botUsername), '@') : '';
    $botLink = preg_match('/^[A-Za-z0-9_]{5,32}$/D', $rawUser)
        ? 'https://t.me/' . rawurlencode($rawUser) : null;

    // Result pages can contain a payment/customer reference. Do not index,
    // cache, leak a URL in Referrer, or embed the page in another origin.
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#17152c">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= $title ?> — وضعیت سفارش</title>
    <style>
        *,*::before,*::after{box-sizing:border-box}
        :root{color-scheme:dark;--fg:#f7f7fd;--muted:#c5cbe0;--accent:#c4bcff;--border:rgba(206,211,246,.24)}
        html{min-height:100%;background:#17152c}
        body{min-height:100vh;margin:0;padding:24px 16px;display:flex;align-items:center;justify-content:center;
             font-family:Vazirmatn,Tahoma,Arial,sans-serif;line-height:1.9;color:var(--fg);
             background:radial-gradient(ellipse at 70% 0%,#353065 0%,transparent 50%),#17152c}
        .panel{width:100%;max-width:530px;overflow-wrap:anywhere;border:1px solid var(--border);
               background:rgba(33,30,57,.96);padding:clamp(22px,5vw,42px);border-radius:24px;
               box-shadow:0 20px 65px rgba(0,0,0,.26)}
        .eyebrow{font-size:.83rem;color:var(--accent);font-weight:700}
        h1{font-size:clamp(1.3rem,4vw,1.75rem);margin:10px 0 14px;line-height:1.6}
        .status{display:inline-flex;align-items:center;gap:9px;border:1px solid var(--border);
                background:#282541;border-radius:99px;padding:5px 14px;font-size:.83rem;color:#f0edff}
        .dot{width:9px;height:9px;border-radius:50%;background:#b7adff;flex-shrink:0}
        .success .dot{background:#51d1a2}.pending .dot{background:#8bb8ff}
        .caution .dot{background:#f5bf6b}
        .message{margin:22px 0 24px;font-size:1.02rem;white-space:pre-wrap;overflow-wrap:anywhere;
                 unicode-bidi:plaintext;color:var(--fg)}
        .hint{margin:0 0 22px;padding:14px 16px;border-inline-start:3px solid #8980d3;
              background:rgba(166,155,255,.1);border-radius:8px;font-size:.9rem;color:var(--muted)}
        .button{display:block;text-align:center;background:#c4bcff;color:#211b44;text-decoration:none;
                border-radius:12px;font-weight:800;padding:12px 18px;min-height:48px}
        .button:hover{background:#e0daff}
        .button:focus-visible{outline:3px solid #fff;outline-offset:3px}
        .manual{font-size:.88rem;color:var(--muted);margin:0}
        footer{font-size:.8rem;color:var(--muted);margin-top:22px;text-align:center}
        @media (max-width:420px){
            body{padding:16px 12px;padding-block:calc(16px + env(safe-area-inset-top)) calc(16px + env(safe-area-inset-bottom))}
            .panel{border-radius:18px;padding:22px 18px}
            .message{font-size:1rem}
        }
        @media (forced-colors:active){
            .panel,.status,.hint{border:1px solid CanvasText}
            .button{border:2px solid LinkText}
            .dot{border:1px solid CanvasText}
        }
        @media (prefers-reduced-motion:reduce){
            *,*::before,*::after{animation:none!important;transition:none!important}
        }
    </style>
</head>
<body>
    <main class="panel <?= $variant ?>">
        <div class="eyebrow">اطلاع‌رسانی سفارش</div>
        <h1><?= $title ?></h1>
        <div class="status" role="status"><span class="dot" aria-hidden="true"></span>
            <?= $status ?>
        </div>
        <div class="message" dir="rtl"><?= $content ?></div>
        <p class="hint">این صفحه به‌تنهایی مدرک تسویه یا تحویل سرویس نیست. نتیجه نهایی را در ربات پیگیری کنید. بررسی بعضی پرداخت‌ها بیش از پنج دقیقه زمان می‌برد.</p>
        <?php if ($botLink !== null): ?>
            <a class="button" href="<?= htmlspecialchars($botLink, ENT_QUOTES, 'UTF-8') ?>"
               rel="noopener noreferrer">بازگشت به ربات و پیگیری سفارش</a>
        <?php else: ?>
            <p class="manual">برای پیگیری، از داخل ربات وارد بخش سفارش‌های خود شوید.</p>
        <?php endif; ?>
        <footer>در صورت مغایرت، شناسه سفارش را برای پشتیبانی ارسال کنید.</footer>
    </main>
</body>
</html>
    <?php
}
