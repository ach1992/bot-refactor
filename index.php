<?php
require_once __DIR__ . '/messages/loader.php';
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    $validCsrf = hash_equals($_SESSION['csrf_token'], $token);

    if (!$validCsrf) {
        $response = [
            'status' => 'error',
            'message' => achbotMessage('web_login.csrf_invalid')
        ];
    } else {
        switch ($action) {
            case 'login':
                $response = [
                    'status' => 'error',
                    'message' => achbotMessage('web_login.login_denied')
                ];
                break;

            case 'register':
                $response = [
                    'status' => 'success',
                    'message' => achbotMessage('web_login.register_received')
                ];
                break;

            case 'forgot':
                $response = [
                    'status' => 'info',
                    'message' => achbotMessage('web_login.forgot_received')
                ];
                break;

            default:
                $response = [
                    'status' => 'error',
                    'message' => achbotMessage('web_login.action_unknown')
                ];
        }
    }

    if (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $flash = $response;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars(achbotMessage('web_login.page_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        @font-face {
            font-family: 'Vazirmatn';
            src: url('fonts/Vazirmatn.woff2') format('woff2');
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Vazirmatn', Tahoma, Arial, sans-serif;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top right, rgba(37, 99, 235, 0.42), transparent 34%),
                radial-gradient(circle at bottom left, rgba(124, 58, 237, 0.38), transparent 36%),
                linear-gradient(135deg, #020617, #0f172a 48%, #111827);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .page {
            width: 100%;
            max-width: 1120px;
            display: grid;
            grid-template-columns: 1fr 430px;
            gap: 44px;
            align-items: center;
        }

        .hero {
            padding: 20px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border: 1px solid rgba(255,255,255,0.16);
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            color: #dbeafe;
            font-size: 13px;
            margin-bottom: 24px;
        }

        .badge span {
            width: 8px;
            height: 8px;
            background: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 18px #22c55e;
        }

        .hero h1 {
            font-size: clamp(25px, 3vw, 30px);
            line-height: 1.55;
            margin-bottom: 18px;
            letter-spacing: -0.4px;
            font-weight: 800;
        }

        .hero p {
            color: #cbd5e1;
            line-height: 2.1;
            font-size: 16px;
            max-width: 570px;
        }

        .features {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 30px;
        }

        .feature {
            padding: 10px 14px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 14px;
            color: #e5e7eb;
            font-size: 13px;
        }

        .card {
            position: relative;
            overflow: hidden;
            padding: 28px;
            border-radius: 30px;
            background: rgba(15, 23, 42, 0.76);
            border: 1px solid rgba(255,255,255,0.16);
            box-shadow: 0 30px 90px rgba(0,0,0,0.48);
            backdrop-filter: blur(22px);
        }

        .card::before {
            content: "";
            position: absolute;
            width: 230px;
            height: 230px;
            background: rgba(59, 130, 246, 0.22);
            border-radius: 50%;
            top: -95px;
            left: -95px;
            filter: blur(5px);
        }

        .logo {
            width: 62px;
            height: 62px;
            border-radius: 20px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: bold;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
            box-shadow: 0 14px 36px rgba(59,130,246,0.42);
        }

        .form-title {
            position: relative;
            z-index: 1;
            margin-bottom: 8px;
            font-size: 24px;
            font-weight: 800;
        }

        .form-desc {
            position: relative;
            z-index: 1;
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.9;
            margin-bottom: 22px;
        }

        .tabs {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            background: rgba(255,255,255,0.06);
            padding: 6px;
            border-radius: 16px;
            margin-bottom: 22px;
        }

        .tab {
            border: none;
            border-radius: 12px;
            padding: 11px 8px;
            background: transparent;
            color: #cbd5e1;
            cursor: pointer;
            transition: 0.25s;
            font-size: 13px;
            font-weight: 500;
        }

        .tab.active {
            color: #fff;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            box-shadow: 0 10px 24px rgba(37,99,235,0.3);
        }

        form {
            position: relative;
            z-index: 1;
            display: none;
            animation: fade 0.25s ease;
        }

        form.active {
            display: block;
        }

        @keyframes fade {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .field {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #e5e7eb;
            font-size: 13px;
            font-weight: 500;
        }

        input {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.08);
            color: #fff;
            border-radius: 15px;
            padding: 14px 15px;
            outline: none;
            font-size: 14px;
            transition: 0.25s;
        }

        input::placeholder {
            color: #64748b;
        }

        input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(96,165,250,0.12);
            background: rgba(255,255,255,0.11);
        }

        .password-wrap {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: rgba(255,255,255,0.08);
            color: #cbd5e1;
            border-radius: 10px;
            padding: 7px 10px;
            cursor: pointer;
            font-size: 12px;
        }

        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 10px 0 18px;
            color: #94a3b8;
            font-size: 13px;
        }

        .row a {
            color: #93c5fd;
            text-decoration: none;
            cursor: pointer;
        }

        .check {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .check input {
            width: auto;
        }

        .submit {
            width: 100%;
            border: none;
            border-radius: 16px;
            padding: 14px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            transition: 0.25s;
            box-shadow: 0 18px 36px rgba(37,99,235,0.28);
        }

        .submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 42px rgba(37,99,235,0.36);
        }

        .submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .message {
            margin-top: 16px;
            padding: 13px 14px;
            border-radius: 14px;
            font-size: 13px;
            line-height: 1.8;
            display: none;
        }

        .message.show {
            display: block;
        }

        .message.success {
            background: rgba(34,197,94,0.14);
            border: 1px solid rgba(34,197,94,0.25);
            color: #bbf7d0;
        }

        .message.error {
            background: rgba(239,68,68,0.14);
            border: 1px solid rgba(239,68,68,0.25);
            color: #fecaca;
        }

        .message.info {
            background: rgba(59,130,246,0.14);
            border: 1px solid rgba(59,130,246,0.25);
            color: #bfdbfe;
        }

        .footer-note {
            margin-top: 18px;
            color: #64748b;
            font-size: 12px;
            text-align: center;
            line-height: 1.8;
        }

        @media (max-width: 900px) {
            .page {
                grid-template-columns: 1fr;
                gap: 24px;
            }

            .hero {
                text-align: center;
                padding: 0;
            }

            .hero p {
                margin: auto;
            }

            .features {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 14px;
            }

            .card {
                padding: 22px;
                border-radius: 24px;
            }

            .tabs {
                grid-template-columns: 1fr;
            }

            .row {
                align-items: flex-start;
                flex-direction: column;
            }

            .hero h1 {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>

<div class="page">
    <section class="hero">
        <div class="badge">
            <span></span>
            <?= htmlspecialchars(achbotMessage('web_login.brand'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" ?>
        </div>

        <h1><?= htmlspecialchars(achbotMessage('web_login.hero_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

        <p>
            <?= htmlspecialchars(achbotMessage('web_login.hero_line_one'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" ?>
            <?= htmlspecialchars(achbotMessage('web_login.hero_line_two'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" ?>
        </p>

        <div class="features">
            <div class="feature"><?= htmlspecialchars(achbotMessage('web_login.feature_login'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="feature"><?= htmlspecialchars(achbotMessage('web_login.feature_approval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="feature"><?= htmlspecialchars(achbotMessage('web_login.feature_accounts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="feature"><?= htmlspecialchars(achbotMessage('web_login.feature_mobile'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
    </section>

    <section class="card">
        <div class="logo"><?= htmlspecialchars(achbotMessage("web_login.logo"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>

        <h2 class="form-title" id="formTitle"><?= htmlspecialchars(achbotMessage('web_login.login_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p class="form-desc" id="formDesc">
            <?= htmlspecialchars(achbotMessage('web_login.login_description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" ?>
        </p>

        <div class="tabs">
            <button class="tab active" type="button" data-target="login"><?= htmlspecialchars(achbotMessage('web_login.tab_login'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            <button class="tab" type="button" data-target="register"><?= htmlspecialchars(achbotMessage('web_login.tab_register'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            <button class="tab" type="button" data-target="forgot"><?= htmlspecialchars(achbotMessage('web_login.tab_forgot'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>

        <form class="active ajax-form" id="login" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="login">

            <div class="field">
                <label><?= htmlspecialchars(achbotMessage('web_login.login_username_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" name="email" placeholder="<?= htmlspecialchars(achbotMessage("web_login.email_example"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" autocomplete="username" required>
            </div>

            <div class="field">
                <label><?= htmlspecialchars(achbotMessage('web_login.login_password_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <div class="password-wrap">
                    <input type="password" placeholder="<?= htmlspecialchars(achbotMessage('web_login.login_password_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="current-password" required>
                    <button class="toggle-password" type="button"><?= htmlspecialchars(achbotMessage('web_login.password_show'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
            </div>

            <div class="row">
                <label class="check">
                    <input type="checkbox">
                    <?= htmlspecialchars(achbotMessage('web_login.remember_me'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" ?>
                </label>

                <a data-switch="forgot"><?= htmlspecialchars(achbotMessage('web_login.forgot_link'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            </div>

            <button class="submit" type="submit"><?= htmlspecialchars(achbotMessage('web_login.login_submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </form>

        <form class="ajax-form" id="register" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="register">

            <div class="field">
                <label><?= htmlspecialchars(achbotMessage('web_login.register_name_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" name="name" placeholder="<?= htmlspecialchars(achbotMessage('web_login.register_name_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="name" required>
            </div>

            <div class="field">
                <label><?= htmlspecialchars(achbotMessage('web_login.email_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="email" name="email" placeholder="<?= htmlspecialchars(achbotMessage("web_login.email_example"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" autocomplete="email" required>
            </div>

            <div class="field">
                <label><?= htmlspecialchars(achbotMessage('web_login.phone_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="tel" name="phone" placeholder="<?= htmlspecialchars(achbotMessage('web_login.phone_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="tel">
            </div>

            <div class="field">
                <label><?= htmlspecialchars(achbotMessage('web_login.register_password_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <div class="password-wrap">
                    <input type="password" placeholder="<?= htmlspecialchars(achbotMessage('web_login.register_password_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="new-password" required>
                    <button class="toggle-password" type="button"><?= htmlspecialchars(achbotMessage('web_login.password_show'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
            </div>

            <button class="submit" type="submit"><?= htmlspecialchars(achbotMessage('web_login.register_submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </form>

        <form class="ajax-form" id="forgot" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="forgot">

            <div class="field">
                <label><?= htmlspecialchars(achbotMessage('web_login.forgot_email_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="email" name="email" placeholder="<?= htmlspecialchars(achbotMessage("web_login.email_example"), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>" autocomplete="email" required>
            </div>

            <button class="submit" type="submit"><?= htmlspecialchars(achbotMessage('web_login.forgot_submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>

            <div class="row">
                <a data-switch="login"><?= htmlspecialchars(achbotMessage('web_login.back_login'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            </div>
        </form>

        <div class="message <?php echo $flash ? 'show ' . htmlspecialchars($flash['status']) : ''; ?>" id="message">
            <?php echo $flash ? htmlspecialchars($flash['message']) : ''; ?>
        </div>

        <div class="footer-note">
            <?= htmlspecialchars(achbotMessage('web_login.footer_note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" ?>
        </div>
    </section>
</div>

<script>
    const tabs = document.querySelectorAll('.tab');
    const forms = document.querySelectorAll('form.ajax-form');
    const messageBox = document.getElementById('message');
    const formTitle = document.getElementById('formTitle');
    const formDesc = document.getElementById('formDesc');

    const texts = {
        login: {
            title: <?= json_encode(achbotMessage('web_login.login_title'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>,
            desc: <?= json_encode(achbotMessage('web_login.login_description'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . "\n" ?>
        },
        register: {
            title: <?= json_encode(achbotMessage('web_login.register_submit'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>,
            desc: <?= json_encode(achbotMessage('web_login.register_description'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . "\n" ?>
        },
        forgot: {
            title: <?= json_encode(achbotMessage('web_login.forgot_submit'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>,
            desc: <?= json_encode(achbotMessage('web_login.forgot_description'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . "\n" ?>
        }
    };

    function switchForm(target) {
        tabs.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.target === target);
        });

        forms.forEach(form => {
            form.classList.toggle('active', form.id === target);
        });

        formTitle.textContent = texts[target].title;
        formDesc.textContent = texts[target].desc;

        hideMessage();
    }

    function showMessage(type, text) {
        messageBox.className = 'message show ' + type;
        messageBox.textContent = text;
    }

    function hideMessage() {
        messageBox.className = 'message';
        messageBox.textContent = '';
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            switchForm(tab.dataset.target);
        });
    });

    document.querySelectorAll('[data-switch]').forEach(link => {
        link.addEventListener('click', () => {
            switchForm(link.dataset.switch);
        });
    });

    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('input');

            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = <?= json_encode(achbotMessage('web_login.password_hide'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
            } else {
                input.type = 'password';
                button.textContent = <?= json_encode(achbotMessage('web_login.password_show'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
            }
        });
    });

    forms.forEach(form => {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            const submitButton = form.querySelector('.submit');
            const originalText = submitButton.textContent;

            submitButton.disabled = true;
            submitButton.textContent = <?= json_encode(achbotMessage('web_login.processing'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;

            const formData = new FormData(form);

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const result = await response.json();

                setTimeout(() => {
                    showMessage(result.status || 'info', result.message || <?= json_encode(achbotMessage('web_login.processed'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>);
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                }, 700);

            } catch (error) {
                showMessage('error', <?= json_encode(achbotMessage('web_login.unavailable'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>);
                submitButton.disabled = false;
                submitButton.textContent = originalText;
            }
        });
    });
</script>

</body>
</html>