<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/GatewayClient.php';

/** Provider-specific money quote, request and response contracts. None of
 * these functions knows a service operation, writes a Wallet or handles UI. */
/** Provider-specific local quote inputs; one configuration path for
 * discovery and first issuance. Never contact merchants for eligibility. */
function achbotHostedTomanCheckoutInput(int $price,array $keys,array $configuration): array {
    return ['local_price'=>$price];
}
function achbotHostedNowpaymentsCheckoutInput(int $price,array $keys,array $configuration): array {
    $secret=$configuration['nowpaymentsIpnSecret']??null;
    if (!is_string($secret) || trim($secret)==='')
        throw new DomainException('checkout_ipn_secret_unavailable');
    $rate=achbotUsdtRate($keys);
    if ($rate===null) throw new DomainException('checkout_exchange_rate_unavailable');
    $quote=['local_price'=>$price,'usd'=>number_format(round($price/$rate,2),2,'.','')];
    if (array_key_exists('nowpaymentsSettlementPolicy',$configuration))
        $quote['settlement_policy']=$configuration['nowpaymentsSettlementPolicy'];
    return $quote;
}
function achbotHostedCheckoutInputs(array $def,int $price,array $keys,array $configuration): array {
    $credential=$keys[$def['credential_setting']]??null;
    if (!is_string($credential) || trim($credential)==='')
        throw new DomainException('hosted_method_unavailable');
    return ['credential'=>$credential,'quote'=>($def['checkout_input'])($price,$keys,$configuration)];
}

function achbotHostedTomanQuote(array $pay,array $quote): array {
    return ['amount'=>(string)$pay['price'],'currency'=>'toman','settlement_currency'=>null,'minimum'=>'0'];
}
function achbotHostedUsdQuote(array $pay,array $quote): array {
    $amount=$quote['usd']??null;
    if (!is_string($amount) || !preg_match('/\A[0-9]{1,12}\.[0-9]{2}\z/D',$amount) ||
        !achbotNpDecimalAtLeast($amount,'0.01'))
        throw new DomainException('hosted_usd_quote_invalid');
    $currency=null;$minimum='0';
    if (array_key_exists('settlement_policy',$quote)) {
        $policy=$quote['settlement_policy'];
        $asset=is_array($policy)?($policy['outcome_currency']??null):null;
        $rate=is_array($policy)?($policy['minimum_outcome_per_usd']??null):null;
        $currency=is_scalar($asset)?strtolower((string)$asset):'';
        $minimum=is_scalar($rate) && preg_match('/\A[a-z0-9]{2,32}\z/D',$currency)?
            achbotNpMinimumPayout($amount,(string)$rate):null;
        if ($minimum===null) throw new DomainException('hosted_settlement_policy_invalid');
    }
    // Preserve the current exact issued USD quote and its existing policy
    // metadata. Merchant FINISHED is the current finality contract; do not
    // invent an additional fee/deposit/payout threshold.
    return ['amount'=>$amount,'currency'=>'usd','settlement_currency'=>$currency,'minimum'=>$minimum];
}
function achbotHostedReferenceSyntax(string $reference): bool {
    return preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$reference)===1;
}
function achbotHostedExactReference(array $attempt,string $reference): bool {
    return achbotHostedReferenceSyntax($reference) && $reference===(string)$attempt['provider_reference'];
}
function achbotHostedChildReference(array $attempt,string $reference): bool {
    return achbotHostedReferenceSyntax($reference);
}
function achbotHostedNextpayUrl(string $reference,string $url): bool {
    return $url==='https://nextpay.org/nx/gateway/payment/'.rawurlencode($reference);
}
function achbotHostedZarinpalUrl(string $reference,string $url): bool {
    return $url==='https://www.zarinpal.com/pg/StartPay/'.rawurlencode($reference).'/ZarinGate';
}
function achbotHostedNowpaymentsUrl(string $reference,string $url): bool {
    return achbotNpValidInvoiceUrl($url);
}

/** Transport uses the provider's actual HTTPS endpoint and original request
 * shape. Logs keep provider/HTTP/transport context, not credentials/body. */
function achbotHostedPostJson(string $provider,string $url,string $body,array $headers,int $timeout): ?array {
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$timeout,
    ]);
    $raw=curl_exec($ch);$errno=(int)curl_errno($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    unset($ch);
    if ($raw===false || $errno!==0 || $http<200 || $http>=300) {
        achbotDiagnosticEvent('achbot.finance.hosted_request_http',[
            'provider'=>$provider,'http_status'=>$http,'curl_errno'=>$errno,
            'reason'=>$raw===false || $errno!==0?'transport_unavailable':'http_unexpected'],'warning');
        return null;
    }
    $data=json_decode($raw,true,64,JSON_BIGINT_AS_STRING);
    return is_array($data)?$data:null;
}
function achbotHostedZarinpalSoap(string $action,array $args): ?array {
    $old=ini_get('default_socket_timeout');
    ini_set('default_socket_timeout','15');
    try {
        $client=new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl',[
            'encoding'=>'UTF-8','connection_timeout'=>5,
            'stream_context'=>stream_context_create(['http'=>['timeout'=>15]])]);
        $result=$action==='issue'?$client->PaymentRequest($args):$client->PaymentVerification($args);
        return is_object($result)?get_object_vars($result):null;
    } catch (Throwable $e) {
        achbotDiagnosticEvent('achbot.finance.hosted_request_http',[
            'provider'=>'zarinpal','reason'=>'soap_result_unconfirmed'],'warning');
        return null;
    } finally {if ($old!==false) ini_set('default_socket_timeout',$old);}
}
function achbotHostedCallbackBase(string $botUrl): string {
    $u=parse_url($botUrl);
    if (!is_array($u) || !in_array($u['scheme']??'',['https','http'],true) ||
        empty($u['host']) || isset($u['user']) || isset($u['pass']) ||
        isset($u['query']) || isset($u['fragment']) || preg_match('/[\x00-\x20\x7f]/',$botUrl))
        throw new DomainException('hosted_callback_base_invalid');
    return rtrim($botUrl,'/').'/pay/back.php';
}
function achbotHostedNextpayIssue(array $pay,array $a,string $credential,string $botUrl,string $description): ?array {
    $r=achbotHostedPostJson('nextpay','https://nextpay.org/nx/gateway/token',
        http_build_query(['api_key'=>$credential,'amount'=>(int)$pay['price'],'order_id'=>(int)$pay['id'],
            'currency'=>'IRT','callback_uri'=>achbotHostedCallbackBase($botUrl).'?nextpay&hash_id='.rawurlencode($pay['hash_id'])]),
        ['Content-Type: application/x-www-form-urlencoded'],20);
    $reference=(string)($r['trans_id']??'');
    if (!in_array($r['code']??null,[-1,'-1'],true) || !achbotHostedReferenceSyntax($reference)) return null;
    return ['reference'=>$reference,'url'=>'https://nextpay.org/nx/gateway/payment/'.rawurlencode($reference)];
}
function achbotHostedZarinpalIssue(array $pay,array $a,string $credential,string $botUrl,string $description): ?array {
    $r=achbotHostedZarinpalSoap('issue',['MerchantID'=>$credential,'Amount'=>(int)$pay['price'],
        'Description'=>$description,'Email'=>'','Mobile'=>'',
        'CallbackURL'=>achbotHostedCallbackBase($botUrl).'?zarinpal&hash_id='.rawurlencode($pay['hash_id'])]);
    $reference=(string)($r['Authority']??'');
    if (!in_array($r['Status']??null,[100,'100',0,'0'],true) || !achbotHostedReferenceSyntax($reference)) return null;
    return ['reference'=>$reference,'url'=>'https://www.zarinpal.com/pg/StartPay/'.rawurlencode($reference).'/ZarinGate'];
}
function achbotHostedNowpaymentsIssue(array $pay,array $a,string $credential,string $botUrl,string $description): ?array {
    $base=achbotHostedCallbackBase($botUrl);
    $r=achbotHostedPostJson('nowpayments','https://api.nowpayments.io/v1/invoice',
        json_encode(['price_amount'=>(float)$a['quote_amount'],'price_currency'=>'usd',
            'order_id'=>$pay['hash_id'],'order_description'=>$description,
            'ipn_callback_url'=>$base.'?nowpayment_ipn=1',
            'success_url'=>$base.'?nowpayment&hash_id='.rawurlencode($pay['hash_id']),
            'is_fee_paid_by_user'=>false],JSON_THROW_ON_ERROR),
        ['X-API-KEY: '.$credential,'Content-Type: application/json'],30);
    $reference=(string)($r['id']??'');$url=(string)($r['invoice_url']??'');
    if (!achbotHostedReferenceSyntax($reference) || !achbotNpValidInvoiceUrl($url)) return null;
    return ['reference'=>$reference,'url'=>$url];
}
function achbotHostedNextpayVerify(array $pay,array $a,string $credential,string $reference): array {
    $r=achbotHostedPostJson('nextpay','https://nextpay.org/nx/gateway/verify',
        http_build_query(['api_key'=>$credential,'amount'=>(int)$pay['price'],'currency'=>'IRT','trans_id'=>$reference]),
        ['Content-Type: application/x-www-form-urlencoded'],30);
    if ($r===null) return ['status'=>'unavailable'];
    $status=$r['code']??null;
    $outcome=achbotVerifiedGatewayOutcome('nextpay',$status);
    if ($outcome!=='verified') return ['status'=>$outcome,'reason'=>'nextpay_not_verified'];
    return ['status'=>'verified','proof'=>['reference'=>$reference,'status'=>$status,'amount'=>(int)$pay['price']]];
}
function achbotHostedZarinpalVerify(array $pay,array $a,string $credential,string $reference): array {
    $r=achbotHostedZarinpalSoap('verify',['MerchantID'=>$credential,'Authority'=>$reference,'Amount'=>(int)$pay['price']]);
    if ($r===null) return ['status'=>'unavailable'];
    $status=$r['Status']??null;
    // Legacy WebGate SOAP 101 is already verified (not an arbitrary numeric
    // status). Only this new pinned-merchant invoice path may recover it.
    // The old unscoped callback behavior is not changed.
    if (!in_array($status,[100,'100',101,'101'],true))
        return ['status'=>'review','reason'=>'zarinpal_not_verified'];
    return ['status'=>'verified','proof'=>['reference'=>$reference,'status'=>$status,'amount'=>(int)$pay['price']]];
}
function achbotHostedNowpaymentsVerify(array $pay,array $a,string $credential,string $reference): array {
    $r=achbotNpGetVerifiedPayment($reference,$credential);
    if ($r===null) return ['status'=>'unavailable'];
    if ((string)($r['payment_id']??'')!==$reference ||
        (string)($r['invoice_id']??'')!==(string)$a['provider_reference'] ||
        (string)($r['order_id']??'')!==(string)$a['order_hash'])
        return ['status'=>'review','reason'=>'nowpayments_identity_mismatch'];
    $outcome=achbotVerifiedGatewayOutcome('nowpayment',$r['payment_status']??null);
    if ($outcome!=='verified') return ['status'=>$outcome,'reason'=>'nowpayments_not_verified','observed_status'=>$r['payment_status']??null];
    if (!achbotNpValidFinalAmount($a,$r)) return ['status'=>'review','reason'=>'nowpayments_quote_mismatch','observed_status'=>'amount_mismatch'];
    return ['status'=>'verified','proof'=>$r];
}
