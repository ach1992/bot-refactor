<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

//====================//  Get  //==============================
$hash_id = (string)($_GET['hash_id'] ?? '');
if(!isset($_GET['zarinpal']) && !isset($_GET['nowpayment']) && !isset($_GET['nextpay'])){
    showForm("درگاه پرداخت شناسایی نشد!");
    exit();
}

$stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND `state` = 'pending'");
$stmt->bind_param("s", $hash_id);
$stmt->execute();
$payInfo = $stmt->get_result();
$stmt->close();

if(mysqli_num_rows($payInfo)==0){
    if (isset($_GET['nowpayment']) && $hash_id !== '') {
        $stmt = $connection->prepare("SELECT state FROM pays WHERE hash_id=? LIMIT 1");
        $stmt->bind_param('s',$hash_id);
        $stmt->execute();
        $existing=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($existing['state'] ?? '')==='paid') {
            showForm("این سفارش قبلاً پرداخت شده؛ لینک یا تاییدیه در ربات ارسال می‌شود.");
        } elseif ($existing) {
            showForm("این سفارش در حال پردازش یا نیازمند پیگیری است؛ فاکتور جدید صادر نشد.");
        } else {
            showForm("کد پرداخت یافت نشد.");
        }
    } else {
        showForm("کد پرداخت یافت نشد.");
    }
}else{
    $payParam = $payInfo->fetch_assoc();
    if (!achbotSupportsPaymentSubject($connection, $payParam)) {
        showForm("این سرویس در پنل مرزبان قابل پرداخت نیست.");
        exit();
    }

    
    // Only purchases depend on the plan's capacity and inbound fields.
    // In particular, an independent INCREASE_WALLET has no plan_id and
    // must not dereference a nonexistent plan to initiate payment.
    $fid = (int) ($payParam['plan_id'] ?? 0);
    $server_id = 0;
    $acount = 0;
    $inbound_id = 0;
    if ($payParam['type'] === 'BUY_SUB') {
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $file_detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$file_detail) {
            showForm("پلن مورد نظر یافت نشد.");
            exit;
        }
        $server_id = $file_detail['server_id'];
        $acount = $file_detail['acount'];
        $inbound_id = $file_detail['inbound_id'];
    }
    
    
    $orderId= $payParam['id'];
    $amount = $payParam['price'];
    $payType = $payParam['type'];
    //========================== // config // ==============================
    
    
    if($acount == 0 and $inbound_id != 0 && $payType == "BUY_SUB"){
        showForm('ظرفیت این کانکشن پر شده است.');
        exit;
    }

    if($inbound_id == 0 && $payType == "BUY_SUB") {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] == 0) {
            showForm('ظرفیت این سرور پر شده است.');
            exit;
        }
    }elseif($payType == "BUY_SUB"){
        if($acount != 0 && $acount < $text){
            showForm("روی این پلن فقط $acount اشتراک میشه ساخت.");
            exit();
        }
    }
    
    if($payType == "BUY_SUB") $type = "خرید اشتراک";
    elseif($payType == "RENEW_ACCOUNT"){
        $type = "تمدید اشتراک";
        $oid = $payParam['order_id'];
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();

        if($order->num_rows == 0){
            showForm($mainValues['config_not_found']);
            exit();
        }

    }
    elseif($payType == "RENEW_SCONFIG") $type = "تمدید اشتراک";
    elseif($payType == "INCREASE_WALLET") $type ="شارژ کیف پول";
    elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType)) $type = "افزایش زمان اشتراک";
    elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType)) $type = "افزایش حجم اشتراک";
    
    
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();
	
    if(isset($_GET['nowpayment'])){
        // The additive attempt table is required for safe invoice continuity.
        // Old payments with an invoice ID but no attempt are not reissued
        // automatically: creating a second provider invoice may double charge.
        $exists = $connection->query("SHOW TABLES LIKE 'achbot_nowpayments_attempts'");
        if (!$exists || $exists->num_rows !== 1) {
            showForm("پیکربندی پیگیری امن پرداخت هنوز تکمیل نشده است.");
            exit;
        }
        $stmt = $connection->prepare("SELECT * FROM achbot_nowpayments_attempts WHERE pay_row_id=? LIMIT 2");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $stored = $stmt->get_result();
        $stmt->close();
        if ($stored->num_rows === 1) {
            $attempt = $stored->fetch_assoc();
            if ($attempt['order_hash'] === $hash_id &&
                $attempt['invoice_id'] === (string)$payParam['payid'] &&
                achbotNpValidInvoiceUrl($attempt['invoice_url'])) {
                header('Location: '.$attempt['invoice_url'], true, 302);
                exit;
            }
            showForm("لینک فاکتور نیاز به بررسی پشتیبانی دارد.");
            exit;
        }
        if ($stored->num_rows > 1 || !empty($payParam['payid'])) {
            showForm("برای این سفارش قبلاً فاکتور صادر شده است؛ از ایجاد فاکتور تکراری جلوگیری شد.");
            exit;
        }
        if (empty($nowpaymentsIpnSecret)) {
            showForm("کلید امن اعلان NOWPayments هنوز توسط مدیر ثبت نشده است.");
            exit;
        }
        $userId = (int)($payParam['user_id'] ?? 0);
        $isAgent = 0;

        if($userId > 0){
            $stmt = $connection->prepare("SELECT `is_agent` FROM `users` WHERE `userid` = ? LIMIT 1");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $isAgent = (int)($userRow['is_agent'] ?? 0);
        }

        if($isAgent === 1 && isset($USDTPriceagent) && trim($USDTPriceagent) !== '' && is_numeric($USDTPriceagent)){
            $dollarPrice = (float)$USDTPriceagent;
        } elseif(isset($USDTPrice) && trim($USDTPrice) !== '' && is_numeric($USDTPrice)){
            $dollarPrice = (float)$USDTPrice;
        } else {
            $tlResponse = @file_get_contents('https://api.tetherland.com/currencies');
            $tlData = json_decode($tlResponse, true);

            $dollarPrice = isset($tlData['data']['currencies']['USDT']['price'])
                ? (float)$tlData['data']['currencies']['USDT']['price']
                : 0;
        }

        if($dollarPrice <= 0){
            showForm("قیمت تتر نامعتبر است.");
            exit();
        }

        if(empty($paymentKeys['nowpayment'])){
            showForm("کلید درگاه NowPayments تنظیم نشده است.");
            exit();
        }

        $base_url = 'https://api.nowpayments.io/v1/invoice';

        $quoteUsd = number_format(round($amount / $dollarPrice, 2), 2, '.', '');
        if ((float)$quoteUsd <= 0) {
            showForm("مبلغ دلاری فاکتور معتبر نیست.");
            exit;
        }
        $payload = [
            'price_amount' => (float)$quoteUsd,
            'price_currency' => 'usd',
            'order_id' => $hash_id,
            'order_description' => $type,
            'ipn_callback_url' => $botUrl . 'pay/back.php?nowpayment_ipn=1',
            'success_url' => $botUrl . 'pay/back.php?nowpayment&hash_id=' . urlencode($hash_id),
            'is_fee_paid_by_user' => false
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $base_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $paymentKeys['nowpayment'],
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($responseBody === false || !empty($curlError)){
            showForm("خطا در ارتباط با NowPayments: " . $curlError);
            exit();
        }

        $res = json_decode($responseBody, true);

        if($httpCode < 200 || $httpCode >= 300){
            $apiError = isset($res['message']) ? $res['message'] : $responseBody;
            showForm("درخواست به NowPayments ناموفق بود. کد: $httpCode | " . $apiError);
            exit();
        }

        if(empty($res['id']) || empty($res['invoice_url'])){
            showForm("لینک پرداخت از سمت NowPayments دریافت نشد.");
            exit();
        }

        $payid = (string)$res['id'];
        $invoiceUrl = (string)$res['invoice_url'];
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $payid) ||
            !achbotNpValidInvoiceUrl($invoiceUrl)) {
            showForm("لینک فاکتور ارسالی درگاه معتبر نیست.");
            exit;
        }
        try {
            $connection->begin_transaction();
            // One local purchase can have one active invoice. A concurrent
            // browser click cannot replace another session's persisted link.
            $stmt = $connection->prepare("SELECT payid,state FROM pays WHERE id=? FOR UPDATE");
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $locked = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$locked || $locked['state'] !== 'pending' || !empty($locked['payid'])) {
                $connection->rollback();
                showForm("فاکتور این سفارش تغییر کرده است؛ دوباره از ربات بررسی کنید.");
                exit;
            }
            $stmt = $connection->prepare("INSERT INTO achbot_nowpayments_attempts
                (pay_row_id,order_hash,invoice_id,invoice_url,quote_usd)
                VALUES (?,?,?,?,?)");
            $stmt->bind_param('issss', $orderId, $hash_id, $payid, $invoiceUrl, $quoteUsd);
            $stmt->execute();
            $stmt->close();
            $stmt = $connection->prepare("UPDATE pays SET payid=? WHERE id=? AND state='pending' AND payid IS NULL");
            $stmt->bind_param('si', $payid, $orderId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed !== 1) {
                throw new RuntimeException('concurrent_checkout_state_change');
            }
            $connection->commit();
        } catch (Throwable $error) {
            $connection->rollback();
            showForm("فاکتور صادر شد اما ذخیره ایمن آن انجام نشد؛ با پشتیبانی تماس بگیرید.");
            exit;
        }

        // The merchant can recover this exact provider invoice on every
        // subsequent click; also preserve a direct button in Telegram.
        if ($userId > 0) {
            $keyboard = json_encode(['inline_keyboard'=>[[
                ['text'=>'🔗 ادامه همین پرداخت', 'url'=>$invoiceUrl]
            ]]], JSON_UNESCAPED_UNICODE);
            try {
                sendMessage("💳 لینک پرداخت سفارش $hash_id ثبت شد.\nپرداخت پس از تایید درگاه به صورت خودکار در ربات بررسی می‌شود؛ نیازی به زدن دکمه بازگشت نیست.",
                    $keyboard, null, $userId);
            } catch (Throwable $ignored) {
                // Durable invoice persists even if a Telegram send fails.
            }
        }
        header('Location: ' . $invoiceUrl, true, 302);
        exit();
    }
    
    elseif(isset($_GET['zarinpal'])){
        $CallbackURL = $botUrl . "pay/back.php?zarinpal&hash_id=$hash_id";
        $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
        $result = $client->PaymentRequest([
            'MerchantID' => $paymentKeys['zarinpal'],
            'Amount' => $amount,
            'Description' => "خرید بک لینک ایرانی",
            'Email' => $Email,
            'Mobile' => $Mobile,
            'CallbackURL' => $CallbackURL,
        ]);
        //==============================================================
        Header('Location: https://www.zarinpal.com/pg/StartPay/'.$result->Authority.'/ZarinGate');
    }

    elseif(isset($_GET['nextpay'])){        
        $Description = "خرید بک لینک ایرانی";
        $CallbackURL = $botUrl . "pay/back.php?nextpay&hash_id=$hash_id";
    
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://nextpay.org/nx/gateway/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'api_key='.$paymentKeys['nextpay'] .'&amount='.$amount.'&order_id='.$orderId.'&currency=IRT&callback_uri='.$CallbackURL,
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);

        if ($response->code == '-1'){
            $startGateWayUrl = "https://nextpay.org/nx/gateway/payment/".$response->trans_id;
            $transId = $response->trans_id;            
            $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ? WHERE `hash_id` = ?");
            $stmt->bind_param("ss", $transId, $hash_id);
            $stmt->execute();
            $stmt->close();
            header('location: '.$startGateWayUrl);
        } else {
            showForm("تراکنش با خطا مواجه شده است.");
        }
    }
}


function showForm($msg){
    ?>
    <html dir="rtl">
        <head>
            <script>
          (function(w,d,s,l,i){w[l]=w[l]||[];
            w[l].push({'gtm.start': new Date().getTime(),event:'gtm.js', });
            var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
            j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl+'&gtm_auth=&gtm_preview=&gtm_cookies_win=x';
            f.parentNode.insertBefore(j,f);
          })(window,document,'script','dataLayer','GTM-MSN6P6G');</script>
          <meta charset="utf-8"><meta name="viewport" content="width=device-width">
            <title><?php echo $msg;?></title>
            <meta name="next-head-count" content="4">
            <link rel="stylesheet" href="../assets/20bb620751bbea45.css">
            <noscript data-n-css=""></noscript>
    
        </head>
        <body>
            <div id="__next">
                <section class="ant-layout ant-layout-rtl PayPing-layout background--primary justify-center" style="min-height:100vh">
                    <header class="ant-layout-header PayPing-header-logo justify-center align-center"></header>
                    <main class="ant-layout-content justify-center align-center flex-column">
                        <div class="ant-row ant-row-center ant-row-rtl PayPing-row w-100">
                            <div class="ant-col PayPing-col PayPing-error-card ant-col-xs-23 ant-col-rtl ant-col-sm-20 ant-col-md-16 ant-col-lg-12 ant-col-xl-8 ant-col-xxl-6">
                                <div class="py-2 align-center color--danger flex-column">
                                    <svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" class="PayPing-icon" stroke-width="1" width="100">
                                        <circle cx="12" cy="12" r="11"></circle>
                                        <path d="M15.3 8.7l-6.6 6.6M8.7 8.7l6.6 6.6"></path>
                                    </svg>
                                    <div class="py-2"><?php echo $msg; ?></div>
                                </div>
                            </div>
                        </div>
                    </main>
                </section>
            </div>
        </body>
    </html>
<?php
}
?>