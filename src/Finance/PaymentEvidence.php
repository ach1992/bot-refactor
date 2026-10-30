<?php
/** AChBot existing authenticated payment APIs and durable evidence boundary.
 * No settlement, UI, provider fulfilment, or production bootstrap belongs here.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    http_response_code(404); exit;
}

function achbotPaymentEvidenceSettingFields(): array {
    return ['smsApiUrl','smsApiToken','smsApiHorizonHours','smsApiProvider',
        'smsApiSender','smsApiMobileSource','smsApiRuleId','smsApiBankKey',
        'smsApiTimezone','smsApiCalendar','giftApiUrl','giftApiKeyId',
        'giftApiSecret','giftApiAmountUnit',
        'giftApiGameKey','giftApiValueId'];
}
function achbotPaymentEvidenceInteger($value,bool $zero=false): ?int {
    if (!is_int($value) && !is_string($value)) return null;
    $text=(string)$value;
    if (!preg_match($zero?'/\A(?:0|[1-9][0-9]*)\z/D':'/\A[1-9][0-9]*\z/D',$text))
        return null;
    $n=filter_var($text,FILTER_VALIDATE_INT);
    return $n===false?null:$n;
}
function achbotPaymentEvidenceBaseUrl(string $value,string $method): ?string {
    $u=parse_url($value);
    if (!is_array($u) || isset($u['user']) || isset($u['pass']) ||
        isset($u['query']) || isset($u['fragment']) ||
        !isset($u['scheme'],$u['host']) || preg_match('/[\x00-\x20\x7f]/',$value))
        return null;
    $test=defined('ACHBOT_PAYMENT_EVIDENCE_TEST_TRANSPORT') &&
        ACHBOT_PAYMENT_EVIDENCE_TEST_TRANSPORT===true &&
        strtolower($u['host'])==='127.0.0.1' && $u['scheme']==='http';
    if (!$test && (strtolower($u['scheme'])!=='https' ||
        filter_var($value,FILTER_VALIDATE_URL)===false)) return null;
    if (!$test && isset($u['port']) && (int)$u['port']!==443) return null;
    $path=rtrim((string)($u['path']??''),'/');
    $suffix=$method==='bank_transfer'?'/wp-json/ach-pay-manager/v1':'/wp-json/ach-gcw/v2';
    if (!str_ends_with($path,$suffix) || str_contains($path,'..') ||
        str_contains($path,'\\') || str_contains($path,'%')) return null;
    return strtolower($u['scheme']).'://'.strtolower($u['host']).
        (isset($u['port'])?':'.(int)$u['port']:'').$path;
}
function achbotPaymentEvidenceNormalizeSetting(string $field,string $input): ?string {
    if (!in_array($field,achbotPaymentEvidenceSettingFields(),true)) return null;
    $value=trim($input);
    if ($value==='/clear' || $value==='') return '';
    if (strlen($value)>2048 || preg_match('/[\x00-\x1f\x7f]/',$value)) return null;
    if ($field==='smsApiUrl' || $field==='giftApiUrl')
        return achbotPaymentEvidenceBaseUrl($value,$field==='smsApiUrl'?'bank_transfer':'gift_card');
    if (in_array($field,['smsApiHorizonHours','smsApiRuleId','giftApiValueId'],true)) {
        $n=achbotPaymentEvidenceInteger($value);
        if ($n===null || ($field==='smsApiHorizonHours' && $n>intdiv(PHP_INT_MAX,3600)))
            return null;
        return (string)$n;
    }
    if ($field==='smsApiTimezone') {
        return in_array($value,DateTimeZone::listIdentifiers(),true) || $value==='UTC'
            ? $value:null;
    }
    $enums=['smsApiCalendar'=>['gregorian','jalali'],
        'giftApiAmountUnit'=>['toman','rial']];
    if (isset($enums[$field])) return in_array($value,$enums[$field],true)?$value:null;
    if (in_array($field,['smsApiBankKey','giftApiGameKey','giftApiKeyId'],true) &&
        !preg_match('/\A[A-Za-z0-9_.:-]{1,120}\z/D',$value)) return null;
    return strlen($value)<=512?$value:null;
}
function achbotPaymentEvidenceKey(): string {
    $secret=$GLOBALS['telegramWebhookSecret']??null;
    if (!is_string($secret) || strlen($secret)<16 || strlen($secret)>256 ||
        !preg_match('/\A[A-Za-z0-9_-]+\z/D',$secret))
        throw new RuntimeException('payment_evidence_key_unavailable');
    return hash_hmac('sha256','AChBot/payment-evidence/aead/v1',$secret,true);
}
function achbotPaymentEvidenceProtect(string $purpose,array $payload): string {
    $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (strlen($json)>262144 || !function_exists('openssl_encrypt'))
        throw new RuntimeException('payment_evidence_protection_unavailable');
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($json,'aes-256-gcm',achbotPaymentEvidenceKey(),
        OPENSSL_RAW_DATA,$iv,$tag,'AChBot/payment-evidence/v1/'.$purpose,16);
    if (!is_string($cipher) || strlen($tag)!==16)
        throw new RuntimeException('payment_evidence_protection_failed');
    return 'achpe1:'.base64_encode($iv.$tag.$cipher);
}
function achbotPaymentEvidenceUnprotect(string $purpose,string $saved): ?array {
    try {
        if (!str_starts_with($saved,'achpe1:') || strlen($saved)>400000) return null;
        $raw=base64_decode(substr($saved,7),true);
        if (!is_string($raw) || strlen($raw)<29 || !function_exists('openssl_decrypt')) return null;
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',achbotPaymentEvidenceKey(),
            OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),
            'AChBot/payment-evidence/v1/'.$purpose);
        if (!is_string($plain)) return null;
        $decoded=json_decode($plain,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        return is_array($decoded)?$decoded:null;
    } catch (Throwable $error) { return null; }
}
function achbotPaymentEvidenceSecret(array $keys,string $field): ?string {
    $saved=$keys[$field]??null;
    if (!is_string($saved)) return null;
    $data=achbotPaymentEvidenceUnprotect('setting:'.$field,$saved);
    $value=$data['value']??null;
    return is_string($value) && $value!=='' &&
        achbotPaymentEvidenceNormalizeSetting($field,$value)===$value?$value:null;
}
function achbotPaymentEvidenceSettingsValid(string $method,array $keys): bool {
    return achbotPaymentEvidenceProfile($method,$keys,
        ['smsApiVerifyState'=>'on','giftApiVerifyState'=>'on'])!==null;
}
function achbotPaymentEvidenceProfile(string $method,?array $keys=null,?array $states=null): ?array {
    $keys=$keys??($GLOBALS['paymentKeys']??[]);
    $states=$states??($GLOBALS['botState']??[]);
    if (!in_array($method,['bank_transfer','gift_card'],true)) return null;
    $prefix=$method==='bank_transfer'?'smsApi':'giftApi';
    if (($states[$prefix.'VerifyState']??'off')!=='on') return null;
    $url=$keys[$prefix.'Url']??'';
    if (!is_string($url) || ($url=achbotPaymentEvidenceBaseUrl($url,$method))===null) return null;
    $profile=['v'=>1,'method'=>$method,'base_url'=>$url,
        'source_key'=>hash('sha256','AChBot/payment-evidence/source-slot/v1/'.$method),
        'endpoint_sha256'=>hash('sha256',$url),'filters'=>[]];
    if ($method==='bank_transfer') {
        $token=achbotPaymentEvidenceSecret($keys,'smsApiToken');
        $hours=achbotPaymentEvidenceInteger($keys['smsApiHorizonHours']??null);
        if ($token===null || $hours===null || $hours>intdiv(PHP_INT_MAX,3600)) return null;
        $profile+=['token'=>$token,'horizon_seconds'=>$hours*3600,'timezone'=>null,'calendar'=>null];
        foreach (['Provider'=>'provider','Sender'=>'sender','MobileSource'=>'mobile_source',
                  'RuleId'=>'rule_id','BankKey'=>'bank_key'] as $setting=>$field) {
            $raw=$keys['smsApi'.$setting]??'';
            if (!is_string($raw) && !is_int($raw)) return null;
            if ((string)$raw==='') continue;
            $normal=achbotPaymentEvidenceNormalizeSetting('smsApi'.$setting,(string)$raw);
            if ($normal===null || $normal!==((string)$raw)) return null;
            $profile['filters'][$field]=$normal;
        }
        foreach (['Timezone'=>'timezone','Calendar'=>'calendar'] as $setting=>$field) {
            $raw=$keys['smsApi'.$setting]??'';
            if (!is_string($raw)) return null;
            if ($raw==='') continue;
            if (achbotPaymentEvidenceNormalizeSetting('smsApi'.$setting,$raw)!==$raw) return null;
            $profile[$field]=$raw;
        }
    } else {
        $secret=achbotPaymentEvidenceSecret($keys,'giftApiSecret');
        $keyId=$keys['giftApiKeyId']??'';
        $field='redeem_amount';
        if (isset($keys['giftApiAmountField']) && !in_array($keys['giftApiAmountField'],['','redeem_amount'],true)) return null;
        $unit=$keys['giftApiAmountUnit']??'';
        if ($secret===null || !is_string($keyId) || $keyId==='' ||
            achbotPaymentEvidenceNormalizeSetting('giftApiKeyId',$keyId)!==$keyId ||
            $field!=='redeem_amount' ||
            !in_array($unit,['rial','toman'],true)) return null;
        $profile+=['key_id'=>$keyId,'secret'=>$secret,'amount_field'=>$field,'amount_unit'=>$unit];
        foreach (['GameKey'=>'game_key','ValueId'=>'redeem_value_id'] as $setting=>$field) {
            $raw=$keys['giftApi'.$setting]??'';
            if (!is_string($raw) && !is_int($raw)) return null;
            if ((string)$raw==='') continue;
            if (achbotPaymentEvidenceNormalizeSetting('giftApi'.$setting,(string)$raw)!==(string)$raw)
                return null;
            $profile['filters'][$field]=(string)$raw;
        }
    }
    return $profile;
}
function achbotPaymentEvidencePayFingerprint(array $pay): string {
    $out=[];
    foreach (['id','hash_id','user_id','type','plan_id','server_id','order_id',
        'price','day','volume','agent_count','description','agent_bought'] as $field)
        $out[$field]=(string)($pay[$field]??'');
    return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function achbotPaymentEvidenceQuoteFingerprint(array $attempt): string {
    $out=[];
    foreach (['id','pay_row_id','provider','order_hash','quote_amount','quote_currency',
        'local_amount_toman','provider_reference','payment_id','settlement_currency'] as $field)
        $out[$field]=(string)($attempt[$field]??'');
    // receipt_file_id is the one existing permitted mutation of manual intent.
    $intent=json_decode((string)($attempt['checkout_url']??''),true);
    if (is_array($intent)) { unset($intent['receipt_file_id']); ksort($intent); }
    $out['intent']=is_array($intent)?$intent:(string)($attempt['checkout_url']??'');
    return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function achbotPaymentEvidenceSchemaReady(mysqli $db): bool {
    try {
        $required=[
            'payment_attempts'=>['evidence_profile','evidence_state','evidence_cursor_id','evidence_claim_token',
                'evidence_claimed_at','evidence_next_check_at','evidence_invoice_chat_id',
                'evidence_invoice_message_id','evidence_invoice_digest','evidence_code',
                'evidence_code_sha256','evidence_review_claim_token',
                'evidence_review_claimed_at','evidence_review_sent_at','evidence_projection_checked_at',
                'evidence_report_digest','evidence_staff_digest','evidence_fulfillment_state',
                'evidence_fulfillment_payload'],
            'payment_transactions'=>['evidence_state','evidence_operation','evidence_payload',
                'evidence_dispatched_at','evidence_proven_at']];
        foreach ($required as $table=>$columns) {
            $probe=$db->query('SELECT '.implode(',',$columns).' FROM '.$table.' LIMIT 0');
            if (!$probe) return false;
            $probe->free();
            $engine=$db->query("SHOW TABLE STATUS WHERE Name='".$table."'");
            $row=$engine?$engine->fetch_assoc():null;
            if (!$row || strtolower((string)$row['Engine'])!=='innodb') return false;
        }
        $indexes=['payment_attempts'=>[
            'uq_payment_attempt_pay_row'=>['pay_row_id'],
            'uq_payment_attempt_order_hash'=>['order_hash'],
            'uq_payment_attempt_ref'=>['provider','provider_reference'],
            'uq_payment_attempt_payment'=>['provider','payment_id'],
            'uq_payment_attempt_provider'=>['id','provider']],
            'payment_transactions'=>[
                'uq_payment_transaction_reference'=>['provider','payment_id'],
                'uq_payment_evidence_operation'=>['evidence_operation']]];
        foreach ($indexes as $table=>$requiredIndexes) foreach ($requiredIndexes as $key=>$fields) {
            $rows=$db->query("SHOW INDEX FROM ".$table." WHERE Key_name='".$key."'");
            $actual=[];
            while ($rows && ($r=$rows->fetch_assoc())) {
                if ((int)$r['Non_unique']!==0) return false;
                $actual[(int)$r['Seq_in_index']]=$r['Column_name'];
            }
            ksort($actual);
            if (array_values($actual)!==$fields) return false;
        }
        return true;
    } catch (Throwable $error) { return false; }
}
function achbotPaymentEvidenceRequireAutocommit(mysqli $db): void {
    $r=$db->query('SELECT @@in_transaction AS active');
    if (!$r || (int)($r->fetch_assoc()['active']??1)!==0)
        throw new RuntimeException('payment_evidence_transaction_boundary');
}
function achbotPaymentEvidencePinLocked(mysqli $db,array $pay,array $attempt,?array $profile=null): bool {
    if (!achbotPaymentEvidenceSchemaReady($db)) return false;
    $profile=$profile??achbotPaymentEvidenceProfile((string)($attempt['provider']??''));
    if ($profile===null || (($profile['method']??'')==='gift_card' &&
        ($profile['amount_field']??'')!=='redeem_amount')) return false;
    $active=$db->query('SELECT @@in_transaction AS active');
    if (!$active || (int)($active->fetch_assoc()['active']??0)!==1) return false;
    $id=(int)($attempt['id']??0); $payId=(int)($pay['id']??0);
    if ($id<=0 || $payId<=0 || (int)($attempt['pay_row_id']??0)!==$payId ||
        ($attempt['provider']??'')!==($profile['method']??'') ||
        ($attempt['order_hash']??'')!==($pay['hash_id']??'') ||
        (int)($attempt['local_amount_toman']??0)!==(int)($pay['price']??0)) return false;
    $stmt=$db->prepare('SELECT *,UNIX_TIMESTAMP(created_at) AS evidence_issued_unix
        FROM payment_attempts WHERE id=? AND pay_row_id=? FOR UPDATE');
    $stmt->bind_param('ii',$id,$payId); $stmt->execute();
    $current=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$current || $current['evidence_profile']!==null) return false;
    $issued=(int)$current['evidence_issued_unix'];
    if ($issued<=0 || (($profile['horizon_seconds']??0)>PHP_INT_MAX-$issued)) return false;
    $profile['issued_at']=$issued;
    $profile['pay_sha256']=achbotPaymentEvidencePayFingerprint($pay);
    $profile['quote_sha256']=achbotPaymentEvidenceQuoteFingerprint($current);
    $sealed=achbotPaymentEvidenceProtect('profile:'.$id.':'.$payId,$profile);
    $stmt=$db->prepare("UPDATE payment_attempts SET evidence_profile=?,
        evidence_state='monitoring',evidence_next_check_at=NOW()
        WHERE id=? AND pay_row_id=? AND evidence_profile IS NULL");
    $stmt->bind_param('sii',$sealed,$id,$payId); $stmt->execute();
    $ok=$stmt->affected_rows===1; $stmt->close(); return $ok;
}
function achbotPaymentEvidenceSnapshot(array $attempt): ?array {
    $id=(int)($attempt['id']??0); $pay=(int)($attempt['pay_row_id']??0);
    $saved=$attempt['evidence_profile']??null;
    if ($id<=0 || $pay<=0 || !is_string($saved)) return null;
    $p=achbotPaymentEvidenceUnprotect('profile:'.$id.':'.$pay,$saved);
    if (!$p || (($p['method']??'')==='gift_card' && ($p['amount_field']??'')!=='redeem_amount') ||
        ($p['method']??'')!==($attempt['provider']??'') ||
        !hash_equals((string)($p['quote_sha256']??''),achbotPaymentEvidenceQuoteFingerprint($attempt)))
        return null;
    $p['profile_sha256']=hash('sha256',$saved);
    return $p;
}

/** Bounded transport. Only owned loopback fixtures may opt into HTTP. */
function achbotPaymentEvidenceHttp(array $profile,string $method,string $path,?array $body=null,array $extra=[]): array {
    $bad=['ok'=>false,'http'=>0,'status'=>'unavailable','json'=>null];
    try {
        if (!function_exists('curl_init') || !in_array($method,['GET','POST'],true)) return $bad;
        $kind=(string)($profile['method']??'');
        $base=achbotPaymentEvidenceBaseUrl((string)($profile['base_url']??''),$kind);
        if ($base===null || !preg_match('~\A/(?:messages(?:/[1-9][0-9]*(?:/actions)?)?(?:\?status=ready&after_id=[0-9]+&per_page=100)?|actions\?after_id=[0-9]+&per_page=100|verify|redeem)\z~D',$path))
            return $bad;
        $raw=$body===null?'':json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (strlen($raw)>8192) return $bad;
        $headers=['Accept: application/json','Content-Type: application/json'];
        if ($kind==='bank_transfer') {
            $token=$profile['token']??null;
            if (!is_string($token) || $token==='' || preg_match('/[\r\n]/',$token)) return $bad;
            $headers[]='Authorization: Bearer '.$token;
        } elseif ($kind==='gift_card') {
            $ts=(string)time(); $nonce=bin2hex(random_bytes(20));
            $key=$profile['key_id']??''; $secret=$profile['secret']??'';
            if (!is_string($key) || !is_string($secret) || $key==='' || $secret==='' ||
                preg_match('/[\r\n]/',$key.$secret)) return $bad;
            $route='/ach-gcw/v2'.$path;
            $sig=rtrim(strtr(base64_encode(hash_hmac('sha256',
                implode("\n",[$ts,$nonce,$method,$route,$raw]),$secret,true)),'+/','-_'),'=');
            $headers[]='X-ACH-Key-Id: '.$key;
            $headers[]='X-ACH-Timestamp: '.$ts;
            $headers[]='X-ACH-Nonce: '.$nonce;
            $headers[]='X-ACH-Signature: '.$sig;
        } else return $bad;
        foreach ($extra as $name=>$value) {
            if ($name!=='Idempotency-Key' || !is_string($value) ||
                !preg_match('/\A[a-f0-9]{64}\z/D',$value)) return $bad;
            $headers[]=$name.': '.$value;
        }
        $buffer=''; $overflow=false;
        $ch=curl_init($base.$path);
        if ($ch===false) return $bad;
        $loopback=str_starts_with($base,'http://127.0.0.1:');
        curl_setopt_array($ch,[
            CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,
            CURLOPT_PROTOCOLS=>$loopback?CURLPROTO_HTTP:CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_PROXY=>'',CURLOPT_HEADER=>false,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$buffer,&$overflow): int {
                if (strlen($buffer)+strlen($chunk)>131072) { $overflow=true; return 0; }
                $buffer.=$chunk; return strlen($chunk);
            }]);
        if ($method==='POST') curl_setopt($ch,CURLOPT_POSTFIELDS,$raw);
        $done=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($done===false || $overflow || $http<200 || $http>=600) return $bad;
        $json=json_decode($buffer,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if (!is_array($json)) return $bad;
        return ['ok'=>$http>=200 && $http<300,'http'=>$http,
            'status'=>$http>=200 && $http<300?'ok':'unavailable','json'=>$json];
    } catch (Throwable $error) { return $bad; }
}
function achbotPaymentEvidenceSmsList(array $profile,int $after=0): array {
    $bad=['ok'=>false,'status'=>'unavailable','data'=>[],'next_after_id'=>$after,'has_more'=>false];
    if (($profile['method']??'')!=='bank_transfer' || $after<0) return $bad;
    $r=achbotPaymentEvidenceHttp($profile,'GET','/messages?status=ready&after_id='.$after.'&per_page=100');
    $j=$r['json'];
    if (!$r['ok'] || !is_array($j['data']??null) || !array_is_list($j['data']) ||
        count($j['data'])>100 || !is_bool($j['pagination']['has_more']??null)) return $bad;
    $next=achbotPaymentEvidenceInteger($j['pagination']['next_after_id']??null,true);
    if ($next===null || ($j['pagination']['has_more'] && $next<=$after)) return $bad;
    return ['ok'=>true,'status'=>'ok','data'=>$j['data'],
        'next_after_id'=>$next,'has_more'=>$j['pagination']['has_more']];
}
function achbotPaymentEvidenceAmountToman($amount,string $unit): ?string {
    $n=achbotPaymentEvidenceInteger($amount);
    if ($n===null || !in_array($unit,['toman','rial'],true) ||
        ($unit==='rial' && $n%10!==0)) return null;
    return (string)($unit==='rial'?intdiv($n,10):$n);
}
function achbotPaymentEvidenceIntegralQuote($value): ?string {
    if (!is_string($value) && !is_int($value)) return null;
    if (!preg_match('/\A([0-9]+)(?:\.0{1,8})?\z/D',(string)$value,$m)) return null;
    $n=achbotPaymentEvidenceInteger(ltrim($m[1],'0'));
    return $n===null?null:(string)$n;
}
function achbotPaymentEvidenceJalaliGregorian(int $y,int $m,int $d): ?array {
    if ($y<1200 || $y>1600 || $m<1 || $m>12 || $d<1 ||
        $d>($m<7?31:30)) return null;
    $convert=static function(int $jy,int $jm,int $jd): array {
        $jy+=1595;
        $days=-355668+365*$jy+intdiv($jy,33)*8+intdiv($jy%33+3,4)+$jd+
            ($jm<7?($jm-1)*31:($jm-7)*30+186);
        $gy=400*intdiv($days,146097); $days%=146097;
        if ($days>36524) {
            $gy+=100*intdiv(--$days,36524); $days%=36524;
            if ($days>=365) $days++;
        }
        $gy+=4*intdiv($days,1461); $days%=1461;
        if ($days>365) { $gy+=intdiv($days-1,365); $days=($days-1)%365; }
        $gd=$days+1;
        $months=[0,31,($gy%4===0 && $gy%100!==0)||$gy%400===0?29:28,31,30,31,30,31,31,30,31,30,31];
        for ($gm=1;$gm<=12 && $gd>$months[$gm];$gm++) $gd-=$months[$gm];
        return [$gy,$gm,$gd];
    };
    $date=$convert($y,$m,$d);
    $next=$m===12?$convert($y+1,1,1):$convert($y,$m+1,1);
    $a=sprintf('%04d-%02d-%02d',...$date);
    $b=sprintf('%04d-%02d-%02d',...$next);
    return $a<$b && checkdate($date[1],$date[2],$date[0])?$date:null;
}
function achbotPaymentEvidenceSmsTime(array $row,array $profile): ?int {
    $date=$row['sms_date']??null; $time=$row['sms_time']??null;
    if (!is_string($date) || !is_string($time)) return null;
    // An explicit offset is independently parseable without a configured zone.
    if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D',$date)) {
        $v=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',str_replace('Z','+00:00',$date));
        $err=DateTimeImmutable::getLastErrors();
        return $v && ($err===false || !$err['warning_count'] && !$err['error_count'])?$v->getTimestamp():null;
    }
    if (!is_string($profile['timezone']??null) ||
        !preg_match('/\A([0-9]{4})[\/-]([0-9]{1,2})[\/-]([0-9]{1,2})\z/D',$date,$m) ||
        !preg_match('/\A([0-9]{1,2}):([0-9]{2})(?::([0-9]{2}))?\z/D',$time,$t)) return null;
    $y=(int)$m[1]; $month=(int)$m[2]; $day=(int)$m[3];
    $calendar=$profile['calendar']??($y<1700?'jalali':'gregorian');
    if ($calendar==='jalali') {
        $converted=achbotPaymentEvidenceJalaliGregorian($y,$month,$day);
        if ($converted===null) return null;
        [$y,$month,$day]=$converted;
    }
    if ($y<1900 || $y>2200 || !checkdate($month,$day,$y) ||
        (int)$t[1]>23 || (int)$t[2]>59 || (int)($t[3]??0)>59) return null;
    $text=sprintf('%04d-%02d-%02d %02d:%02d:%02d',$y,$month,$day,$t[1],$t[2],$t[3]??0);
    try {
        $v=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$text,new DateTimeZone($profile['timezone']));
        return $v && $v->format('Y-m-d H:i:s')===$text?$v->getTimestamp():null;
    } catch (Throwable $e) { return null; }
}
function achbotPaymentEvidenceSmsNormalize(array $row,array $profile,bool $allowUsed=false): ?array {
    if (($profile['method']??'')!=='bank_transfer' ||
        !in_array($row['status']??null,$allowUsed?['ready','used']:['ready'],true)) return null;
    $id=achbotPaymentEvidenceInteger($row['id']??null);
    $uid=$row['public_uid']??null;
    if ($id===null || !is_string($uid) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$uid)) return null;
    foreach ($profile['filters']??[] as $key=>$expected) {
        if ((!is_string($row[$key]??null) && !is_int($row[$key]??null)) ||
            (string)$row[$key]!==$expected) return null;
    }
    $unit=$row['display_unit']??null;
    if (!is_string($unit) || ($amount=achbotPaymentEvidenceAmountToman($row['amount']??null,$unit))===null ||
        (int)$amount>intdiv(PHP_INT_MAX,10)) return null;
    $when=achbotPaymentEvidenceSmsTime($row,$profile);
    if ($when===null) return null;
    $snapshot=[];
    foreach (['provider','provider_sms_id','sender','mobile_source','bank_key','rule_id',
        'sms_date','sms_time'] as $field) {
        $v=$row[$field]??'';
        if (!is_string($v) && !is_int($v)) return null;
        if (strlen((string)$v)>512) return null;
        $snapshot[$field]=(string)$v;
    }
    // Explicit deposit field is validated when present; scoped parser identity
    // remains the current API's authority when its presentation omits direction.
    if (isset($row['direction']) && $row['direction']!=='deposit') return null;
    $source=(string)($profile['source_key']??'');
    $semantic=[$source,(string)$id,$uid,$amount,$when,$snapshot];
    return ['kind'=>'sms','identity'=>hash('sha256',$source."\0sms\0".$uid),
        'remote_id'=>(string)$id,'public_uid'=>$uid,'status'=>$row['status'],
        'amount_toman'=>$amount,'amount_rial'=>(string)((int)$amount*10),
        'transfer_at'=>$when,'source_key'=>$source,
        'fingerprint'=>hash('sha256',json_encode($semantic,JSON_THROW_ON_ERROR))];
}
function achbotPaymentEvidenceSmsMatches(array $evidence,array $attempt,array $profile,?int $issuedUnix=null): bool {
    $issued=$issuedUnix??($profile['issued_at']??null);
    $horizon=$profile['horizon_seconds']??null;
    $quote=achbotPaymentEvidenceIntegralQuote($attempt['quote_amount']??null);
    return ($evidence['kind']??'')==='sms' && ($evidence['status']??'')==='ready' &&
        ($attempt['provider']??'')==='bank_transfer' && ($attempt['quote_currency']??'')==='toman' &&
        $quote!==null && ($evidence['amount_toman']??null)===$quote &&
        ($evidence['source_key']??null)===($profile['source_key']??'') &&
        is_int($issued) && is_int($horizon) && $horizon>0 && $issued>0 &&
        $horizon<=PHP_INT_MAX-$issued && is_int($evidence['transfer_at']??null) &&
        $evidence['transfer_at']>=$issued && $evidence['transfer_at']<=$issued+$horizon;
}
function achbotPaymentEvidenceGiftVerify(array $profile,string $code): array {
    $bad=['ok'=>false,'status'=>'unavailable','data'=>null];
    if (($profile['method']??'')!=='gift_card' || trim($code)==='' ||
        strlen($code)>4096 || preg_match('/[\x00-\x1f\x7f]/',$code)) return $bad;
    $r=achbotPaymentEvidenceHttp($profile,'POST','/verify',['code'=>$code]);
    $j=$r['json'];
    if (($j['api_version']??null)!=='2' || !is_bool($j['ok']??null)) return $bad;
    if (!$r['ok'] || $j['ok']!==true || ($j['code']??'')!=='card_verified') {
        return ['ok'=>false,'status'=>in_array($j['code']??'',
            ['card_not_found','invalid_code','invalid_card_code','payload_mismatch'],true)?'unknown':'unavailable','data'=>null];
    }
    $data=$j['data']??null;
    if (!is_array($data)) return $bad;
    $status=match($data['status']??'') {
        'active'=>'active','redeemed','redeemed_offline'=>'used',
        'revoked'=>'revoked','expired'=>'expired',default=>'unavailable'};
    return ['ok'=>$status!=='unavailable','status'=>$status,'data'=>$data];
}
function achbotPaymentEvidenceGiftNormalize(array $data,array $profile,array $attempt,bool $allowUsed=false): ?array {
    if (($profile['method']??'')!=='gift_card' || ($attempt['provider']??'')!=='gift_card' ||
        ($attempt['quote_currency']??'')!=='toman' ||
        !in_array($data['status']??'',$allowUsed?['active','redeemed','redeemed_offline']:['active'],true)) return null;
    $uuid=$data['card_uuid']??null;
    if (!is_string($uuid) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$uuid)) return null;
    $field=$profile['amount_field']??''; $unit=$profile['amount_unit']??'';
    if ($field!=='redeem_amount' ||
        !in_array($unit,['toman','rial'],true)) return null;
    $amount=achbotPaymentEvidenceAmountToman($data[$field]??null,$unit);
    $quote=achbotPaymentEvidenceIntegralQuote($attempt['quote_amount']??null);
    if ($amount===null || $quote===null || $amount!==$quote) return null;
    foreach ($profile['filters']??[] as $key=>$value)
        if ((string)($data[$key]??'')!==$value) return null;
    $game=$data['game_key']??null;
    $valueId=achbotPaymentEvidenceInteger($data['redeem_value_id']??null);
    if (!is_string($game) || strlen($game)>120 || $valueId===null) return null;
    $source=(string)$profile['source_key'];
    $semantic=[$source,$uuid,$game,(string)$valueId,$amount,$field,$unit];
    return ['kind'=>'gift','identity'=>hash('sha256',$source."\0gift\0".$uuid),
        'card_uuid'=>$uuid,'status'=>$data['status'],'amount_toman'=>$amount,
        'source_key'=>$source,'game_key'=>$game,'redeem_value_id'=>(string)$valueId,
        'amount_field'=>$field,'amount_unit'=>$unit,
        'fingerprint'=>hash('sha256',json_encode($semantic,JSON_THROW_ON_ERROR))];
}

function achbotPaymentEvidenceClaim(mysqli $db,int $payId): ?array {
    if ($payId<=0 || !achbotPaymentEvidenceSchemaReady($db)) return null;
    try {
        achbotPaymentEvidenceRequireAutocommit($db); $db->begin_transaction();
        $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId); $q->execute(); $pay=$q->get_result()->fetch_assoc(); $q->close();
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId); $q->execute(); $a=$q->get_result()->fetch_assoc(); $q->close();
        $p=$a?achbotPaymentEvidenceSnapshot($a):null;
        if (!$pay || !$p || !hash_equals($p['pay_sha256'],achbotPaymentEvidencePayFingerprint($pay))) {
            $db->rollback(); return null;
        }
        $token=bin2hex(random_bytes(16)); $id=(int)$a['id'];
        $q=$db->prepare("UPDATE payment_attempts SET evidence_claim_token=?,evidence_claimed_at=NOW()
            WHERE id=? AND evidence_profile IS NOT NULL
            AND (evidence_next_check_at IS NULL OR evidence_next_check_at<=NOW())
            AND (evidence_claim_token IS NULL OR evidence_claimed_at IS NULL
                 OR evidence_claimed_at<NOW()-INTERVAL 2 MINUTE)");
        $q->bind_param('si',$token,$id); $q->execute(); $ok=$q->affected_rows===1; $q->close();
        if (!$ok || !$db->commit()) { $db->rollback(); return null; }
        $a['evidence_claim_token']=$token;
        return ['token'=>$token,'pay'=>$pay,'attempt'=>$a,'profile'=>$p];
    } catch (Throwable $e) { try{$db->rollback();}catch(Throwable $x){} return null; }
}
function achbotPaymentEvidenceDefer(mysqli $db,int $payId,string $token,string $status='monitoring',int $seconds=60): bool {
    if (!preg_match('/\A[a-f0-9]{32}\z/D',$token) ||
        !in_array($status,['monitoring','review','invalid_code','proven','reserved','dispatched','uncertain'],true))
        return false;
    $seconds=max(1,min(3600,$seconds));
    try {
        $q=$db->prepare("UPDATE payment_attempts SET
            evidence_state=CASE WHEN evidence_state IN ('proven','invalid_code') THEN evidence_state ELSE ? END,
            evidence_next_check_at=DATE_ADD(NOW(),INTERVAL ? SECOND),
            evidence_claim_token=NULL,evidence_claimed_at=NULL
            WHERE pay_row_id=? AND evidence_claim_token=?");
        $q->bind_param('siis',$status,$seconds,$payId,$token); $q->execute();
        $ok=$q->affected_rows===1; $q->close(); return $ok;
    } catch (Throwable $e) { return false; }
}
function achbotPaymentEvidenceCodeDigest(string $code): string {
    return hash_hmac('sha256',"AChBot/payment-evidence/code/v1\0".$code,achbotPaymentEvidenceKey());
}
function achbotPaymentEvidenceSaveGiftCode(mysqli $db,int $payId,int $actor,string $code): array {
    $bad=['status'=>'invalid'];
    $code=trim($code);
    if ($payId<=0 || $actor<=0 || $code==='' || strlen($code)>4096 ||
        preg_match('/[\x00-\x1f\x7f]/',$code) || !achbotPaymentEvidenceSchemaReady($db)) return $bad;
    try {
        achbotPaymentEvidenceRequireAutocommit($db); $db->begin_transaction();
        $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
        $profile=$a?achbotPaymentEvidenceSnapshot($a):null;
        if (!$pay || !$profile || $profile['method']!=='gift_card' ||
            (int)$pay['user_id']!==$actor ||
            !in_array($pay['state'],['pending','have_sent'],true) ||
            !hash_equals($profile['pay_sha256'],achbotPaymentEvidencePayFingerprint($pay))) {
            $db->rollback();return $bad;
        }
        $digest=achbotPaymentEvidenceCodeDigest($code);
        if (is_string($a['evidence_code_sha256']) &&
            hash_equals($a['evidence_code_sha256'],$digest)) {
            $read=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$payId,(string)$a['evidence_code']);
            $db->rollback();
            return ['status'=>($read['code']??null)===$code?'same':'locked'];
        }
        if ($a['evidence_code']!==null && $a['evidence_state']!=='invalid_code') {
            $db->rollback();return ['status'=>'locked'];
        }
        $aid=(int)$a['id'];
        $q=$db->prepare('SELECT id FROM payment_transactions WHERE attempt_id=? AND evidence_operation IS NOT NULL FOR UPDATE');
        $q->bind_param('i',$aid);$q->execute();$existing=$q->get_result()->num_rows;$q->close();
        if ($existing) { $db->rollback();return ['status'=>'locked']; }
        $sealed=achbotPaymentEvidenceProtect('code:'.$aid.':'.$payId,['code'=>$code,'actor'=>$actor]);
        $q=$db->prepare("UPDATE payment_attempts SET evidence_code=?,evidence_code_sha256=?,
            evidence_state='monitoring',evidence_review_sent_at=NULL,
            evidence_review_claim_token=NULL,evidence_review_claimed_at=NULL
            WHERE id=? AND evidence_profile IS NOT NULL");
        $q->bind_param('ssi',$sealed,$digest,$aid);$q->execute();$ok=$q->affected_rows===1;$q->close();
        if (!$ok || !$db->commit()) throw new RuntimeException('payment_evidence_code_commit_unknown');
        return ['status'=>'saved'];
    } catch (Throwable $e) {try{$db->rollback();}catch(Throwable $x){}return $bad;}
}
function achbotPaymentEvidenceReadGiftCode(mysqli $db,int $payId): ?string {
    try {
        if (!achbotPaymentEvidenceSchemaReady($db)) return null;
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=?');
        $q->bind_param('i',$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
        if (!$a || !achbotPaymentEvidenceSnapshot($a)) return null;
        $d=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$payId,(string)($a['evidence_code']??''));
        return is_string($d['code']??null) &&
            hash_equals((string)$a['evidence_code_sha256'],achbotPaymentEvidenceCodeDigest($d['code']))
            ?$d['code']:null;
    } catch (Throwable $e) {return null;}
}
function achbotPaymentEvidenceRejectGiftCode(mysqli $db,int $payId,string $code,string $status): bool {
    if (!in_array($status,['used','revoked','expired','mismatch'],true)) return false;
    try {
        achbotPaymentEvidenceRequireAutocommit($db);$db->begin_transaction();
        $q=$db->prepare('SELECT id FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$q->get_result();$q->close();
        $q=$db->prepare('SELECT id,evidence_code_sha256 FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
        if (!$a || !hash_equals((string)$a['evidence_code_sha256'],achbotPaymentEvidenceCodeDigest(trim($code)))) {
            $db->rollback();return false;
        }
        $aid=(int)$a['id'];
        $q=$db->prepare('SELECT id FROM payment_transactions WHERE attempt_id=? AND evidence_operation IS NOT NULL FOR UPDATE');
        $q->bind_param('i',$aid);$q->execute();$seen=$q->get_result()->num_rows;$q->close();
        if ($seen) {$db->rollback();return false;}
        $q=$db->prepare("UPDATE payment_attempts SET evidence_state='invalid_code' WHERE id=?");
        $q->bind_param('i',$aid);$q->execute();$q->close();
        if (!$db->commit()) throw new RuntimeException('payment_evidence_code_reject_unknown');
        return true;
    } catch (Throwable $e) {try{$db->rollback();}catch(Throwable $x){}return false;}
}
function achbotPaymentEvidenceHasTypedReviewLocked(mysqli $db,array $pay): bool {
    if ((int)($pay['message_id']??0)<=0 || (int)($pay['chat_id']??0)<=0 ||
        !achbotPaymentEvidenceSchemaReady($db)) return false;
    try {
        $payId=(int)$pay['id'];
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
        $p=$a?achbotPaymentEvidenceSnapshot($a):null;
        if (!$p || $p['method']!=='gift_card' || $a['evidence_review_sent_at']===null ||
            !hash_equals($p['pay_sha256'],achbotPaymentEvidencePayFingerprint($pay))) return false;
        $d=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$payId,(string)($a['evidence_code']??''));
        return is_string($d['code']??null) && (int)($d['actor']??0)===(int)$pay['user_id'] &&
            hash_equals((string)$a['evidence_code_sha256'],achbotPaymentEvidenceCodeDigest($d['code']));
    } catch (Throwable $e) {return false;}
}
/** Reserve identity and original intent BEFORE any outbound mutation.
 * Optional guard is a SQL-only, current collision recheck owned by coordinator.
 */
function achbotPaymentEvidenceReserve(mysqli $db,int $payId,array $evidence,string $mode='settle',?string $code=null,?callable $guard=null): ?array {
    if (!achbotPaymentEvidenceSchemaReady($db) || $payId<=0 ||
        !in_array($mode,['settle','consume_only','evidence_only'],true) ||
        !preg_match('/\A[a-f0-9]{64}\z/D',(string)($evidence['identity']??''))) return null;
    try {
        achbotPaymentEvidenceRequireAutocommit($db);$db->begin_transaction();
        $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
        $p=$a?achbotPaymentEvidenceSnapshot($a):null;
        if (!$pay || !$p || !hash_equals($p['pay_sha256'],achbotPaymentEvidencePayFingerprint($pay)) ||
            !in_array($pay['state'],['pending','have_sent','approved','auto_cart_approved','paid','paid_with_wallet'],true)) {
            $db->rollback();return null;
        }
        $settled=in_array($pay['state'],['approved','auto_cart_approved','paid','paid_with_wallet'],true);
        if ($settled && $mode==='settle') {$db->rollback();return null;}
        $mode=$mode==='consume_only'?'evidence_only':$mode;
        if (($p['method']==='bank_transfer' && !achbotPaymentEvidenceSmsMatches($evidence,$a,$p)) ||
            ($p['method']==='gift_card' && (($evidence['kind']??'')!=='gift' ||
             ($evidence['status']??'')!=='active' ||
             ($evidence['amount_toman']??null)!==achbotPaymentEvidenceIntegralQuote($a['quote_amount']) ||
             ($evidence['source_key']??null)!==$p['source_key']))) {
            $db->rollback();return null;
        }
        if ($p['method']==='gift_card') {
            $d=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$payId,(string)($a['evidence_code']??''));
            if (!is_string($code) || ($d['code']??null)!==$code ||
                !hash_equals((string)$a['evidence_code_sha256'],achbotPaymentEvidenceCodeDigest($code))) {
                $db->rollback();return null;
            }
        }
        if ($guard!==null && $guard($db)!==true) {$db->rollback();return null;}
        $aid=(int)$a['id'];$provider=(string)$a['provider'];$identity='api1:'.$evidence['identity'];
        $q=$db->prepare('SELECT * FROM payment_transactions WHERE provider=? AND payment_id=? FOR UPDATE');
        $q->bind_param('ss',$provider,$identity);$q->execute();$old=$q->get_result()->fetch_assoc();$q->close();
        if ($old && ((int)$old['attempt_id']!==$aid || $old['evidence_operation']===null)) {
            $db->rollback();return null;
        }
        if (!$old) {
            $q=$db->prepare('SELECT id FROM payment_transactions WHERE attempt_id=? AND evidence_operation IS NOT NULL FOR UPDATE');
            $q->bind_param('i',$aid);$q->execute();$seen=$q->get_result()->num_rows;$q->close();
            if ($seen) {$db->rollback();return null;}
            $op=bin2hex(random_bytes(32));
            $payload=['v'=>1,'expected'=>$evidence,'mode'=>$mode,'operation'=>$op,
                'code'=>$code,'pay_sha256'=>$p['pay_sha256'],'quote_sha256'=>$p['quote_sha256'],
                'profile_sha256'=>$p['profile_sha256'],'actor'=>(int)$pay['user_id']];
            $sealed=achbotPaymentEvidenceProtect('operation:'.$op,$payload);
            $q=$db->prepare("INSERT INTO payment_transactions
                (attempt_id,provider,payment_id,observed_status,evidence_state,evidence_operation,evidence_payload)
                VALUES (?,?,?,'reserved','reserved',?,?)");
            $q->bind_param('issss',$aid,$provider,$identity,$op,$sealed);
            if (!$q->execute() || $q->affected_rows!==1) throw new RuntimeException('payment_evidence_reserve_failed');
            $tid=(int)$db->insert_id;$q->close();
        } else { $op=(string)$old['evidence_operation'];$tid=(int)$old['id']; }
        $token=is_string($a['evidence_claim_token'])?$a['evidence_claim_token']:bin2hex(random_bytes(16));
        $q=$db->prepare("UPDATE payment_attempts SET evidence_state=CASE WHEN evidence_state='proven' THEN 'proven' ELSE 'reserved' END,
            evidence_claim_token=?,evidence_claimed_at=NOW() WHERE id=?");
        $q->bind_param('si',$token,$aid);$q->execute();$q->close();
        if (!$db->commit()) throw new RuntimeException('payment_evidence_reserve_commit_unknown');
        return ['transaction_id'=>$tid,'pay_id'=>$payId,'operation'=>$op,'token'=>$token];
    } catch (Throwable $e) {try{$db->rollback();}catch(Throwable $x){}return null;}
}
function achbotPaymentEvidencePending(mysqli $db,int $payId): ?array {
    if (!achbotPaymentEvidenceSchemaReady($db)) return null;
    try {
        $q=$db->prepare('SELECT t.id,t.evidence_operation,a.evidence_claim_token
            FROM payment_transactions t JOIN payment_attempts a ON a.id=t.attempt_id
            WHERE a.pay_row_id=? AND t.evidence_operation IS NOT NULL ORDER BY t.id LIMIT 2');
        $q->bind_param('i',$payId);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (count($rows)!==1) return null;
        return ['transaction_id'=>(int)$rows[0]['id'],'pay_id'=>$payId,
            'operation'=>$rows[0]['evidence_operation'],'token'=>$rows[0]['evidence_claim_token']];
    } catch (Throwable $e) {return null;}
}

function achbotPaymentEvidenceGiftNormalizationStatus(array $data,array $profile,array $attempt): string {
    if (achbotPaymentEvidenceGiftNormalize($data,$profile,$attempt)!==null) return 'exact';
    $field=$profile['amount_field']??'';$unit=$profile['amount_unit']??'';
    if (($data['status']??'')!=='active' || !is_string($data['card_uuid']??null) ||
        !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$data['card_uuid']) ||
        $field!=='redeem_amount' ||
        !in_array($unit,['toman','rial'],true)) return 'review';
    $amount=achbotPaymentEvidenceAmountToman($data[$field]??null,$unit);
    $quote=achbotPaymentEvidenceIntegralQuote($attempt['quote_amount']??null);
    return $amount!==null && $quote!==null && $amount!==$quote?'mismatch':'review';
}
function achbotPaymentEvidenceSmsActions(array $profile,int $after=0): array {
    if ($after<0) return ['ok'=>false,'data'=>[]];
    $r=achbotPaymentEvidenceHttp($profile,'GET','/actions?after_id='.$after.'&per_page=100');
    $j=$r['json'];
    return $r['ok'] && is_array($j['data']??null) && array_is_list($j['data'])
        ?['ok'=>true,'data'=>$j['data'],'pagination'=>$j['pagination']??[]]
        :['ok'=>false,'data'=>[]];
}
function achbotPaymentEvidenceStoreOutcome(mysqli $db,array $handle,string $state,array $changes=[]): bool {
    if (!in_array($state,['reserved','uncertain','proven','review'],true)) return false;
    try {
        achbotPaymentEvidenceRequireAutocommit($db);$db->begin_transaction();
        $payId=(int)$handle['pay_id'];$tid=(int)$handle['transaction_id'];$op=(string)$handle['operation'];
        $q=$db->prepare('SELECT id FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$q->get_result();$q->close();
        $q=$db->prepare('SELECT id FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
        if (!$a) {$db->rollback();return false;}
        $q=$db->prepare('SELECT * FROM payment_transactions WHERE id=? AND attempt_id=? AND evidence_operation=? FOR UPDATE');
        $q->bind_param('iis',$tid,$a['id'],$op);$q->execute();$t=$q->get_result()->fetch_assoc();$q->close();
        if (!$t) {$db->rollback();return false;}
        if ($t['evidence_state']==='proven') {$db->rollback();return $state==='proven';}
        $payload=achbotPaymentEvidenceUnprotect('operation:'.$op,(string)$t['evidence_payload']);
        if (!$payload) {$db->rollback();return false;}
        foreach ($changes as $k=>$v) {
            if (!in_array($k,['proof','reason','last_http'],true)) continue;
            $payload[$k]=$v;
        }
        $saved=achbotPaymentEvidenceProtect('operation:'.$op,$payload);
        $q=$db->prepare("UPDATE payment_transactions SET evidence_state=?,observed_status=?,
            evidence_payload=?,evidence_proven_at=CASE WHEN ?='proven' THEN NOW() ELSE NULL END
            WHERE id=? AND evidence_operation=? AND evidence_state<>'proven'");
        $q->bind_param('ssssis',$state,$state,$saved,$state,$tid,$op);
        if (!$q->execute() || $q->affected_rows!==1) throw new RuntimeException('payment_evidence_outcome_unconfirmed');
        $q->close();
        $aid=(int)$a['id'];
        $q=$db->prepare("UPDATE payment_attempts SET evidence_state=? WHERE id=? AND evidence_state<>'proven'");
        $q->bind_param('si',$state,$aid);$q->execute();$q->close();
        if (!$db->commit()) throw new RuntimeException('payment_evidence_outcome_commit_unknown');
        return true;
    } catch (Throwable $e) {try{$db->rollback();}catch(Throwable $x){}return false;}
}
function achbotPaymentEvidenceConsume(mysqli $db,array $handle): array {
    $bad=['status'=>'review','proof'=>null];
    if (!achbotPaymentEvidenceSchemaReady($db)) return ['status'=>'unavailable','proof'=>null];
    try {
        achbotPaymentEvidenceRequireAutocommit($db);$db->begin_transaction();
        $payId=(int)($handle['pay_id']??0);$tid=(int)($handle['transaction_id']??0);
        $op=(string)($handle['operation']??'');
        if ($payId<=0 || $tid<=0 || !preg_match('/\A[a-f0-9]{64}\z/D',$op)) {$db->rollback();return $bad;}
        $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
        if (!$a || !$pay) {$db->rollback();return $bad;}
        $q=$db->prepare('SELECT *,TIMESTAMPDIFF(SECOND,evidence_dispatched_at,NOW()) AS dispatch_age
            FROM payment_transactions WHERE id=? AND attempt_id=? AND evidence_operation=? FOR UPDATE');
        $q->bind_param('iis',$tid,$a['id'],$op);$q->execute();$t=$q->get_result()->fetch_assoc();$q->close();
        $p=$a?achbotPaymentEvidenceSnapshot($a):null;
        $payload=$t?achbotPaymentEvidenceUnprotect('operation:'.$op,(string)$t['evidence_payload']):null;
        if (!$pay || !$p || !$payload || $payload['profile_sha256']!==$p['profile_sha256'] ||
            $payload['pay_sha256']!==achbotPaymentEvidencePayFingerprint($pay) ||
            $payload['quote_sha256']!==$p['quote_sha256']) {$db->rollback();return $bad;}
        if ($t['evidence_state']==='proven') {
            $db->rollback();return ['status'=>'proven','proof'=>achbotPaymentEvidenceGetProof($db,$payId)];
        }
        if ($t['evidence_state']==='review') {$db->rollback();return $bad;}
        if (!in_array($t['evidence_state'],['reserved','dispatched','uncertain'],true)) {$db->rollback();return $bad;}
        $retry=$t['evidence_state']!=='reserved';
        if ($retry && $p['method']==='bank_transfer' &&
            ($t['dispatch_age']===null || (int)$t['dispatch_age']<0 || (int)$t['dispatch_age']>=23*3600)) {
            $db->rollback();achbotPaymentEvidenceStoreOutcome($db,$handle,'review',['reason'=>'sms_replay_window_unproven']);
            return $bad;
        }
        if (!$retry) {
            $q=$db->prepare("UPDATE payment_transactions SET evidence_state='dispatched',
                observed_status='dispatched',evidence_dispatched_at=NOW()
                WHERE id=? AND evidence_state='reserved' AND evidence_operation=?");
            $q->bind_param('is',$tid,$op);$q->execute();
            if ($q->affected_rows!==1) throw new RuntimeException('payment_evidence_dispatch_unconfirmed');
            $q->close();
            $aid=(int)$a['id'];
            $q=$db->prepare("UPDATE payment_attempts SET evidence_state='dispatched' WHERE id=?");
            $q->bind_param('i',$aid);$q->execute();$q->close();
        }
        if (!$db->commit()) throw new RuntimeException('payment_evidence_dispatch_commit_unknown');
        // Every request below occurs after durable dispatch intent commits.
        $expected=$payload['expected'];
        if ($p['method']==='bank_transfer') {
            $before=achbotPaymentEvidenceHttp($p,'GET','/messages/'.$expected['remote_id']);
            $current=$before['ok'] && is_array($before['json']['data']??null)
                ?achbotPaymentEvidenceSmsNormalize($before['json']['data'],$p,true):null;
            if ($current===null || $current['fingerprint']!==$expected['fingerprint']) {
                $state=$before['ok']?'review':'uncertain';
                achbotPaymentEvidenceStoreOutcome($db,$handle,$state,['reason'=>'sms_readback_unproven']);
                return ['status'=>$state==='uncertain'?'pending':'review','proof'=>null];
            }
            if (!$retry && $current['status']!=='ready') {
                achbotPaymentEvidenceStoreOutcome($db,$handle,'review',['reason'=>'sms_already_used']);
                return $bad;
            }
            $response=achbotPaymentEvidenceHttp($p,'POST','/messages/'.$expected['remote_id'].'/actions',
                ['action'=>'redeem'],['Idempotency-Key'=>$op]);
            $j=$response['json'];
            $normalized=$response['ok'] && is_array($j['data']??null)
                ?achbotPaymentEvidenceSmsNormalize($j['data'],$p,true):null;
            $valid=$normalized!==null && $normalized['status']==='used' &&
                $normalized['fingerprint']===$expected['fingerprint'];
            $request=$j['request_id']??null;
        } else {
            $verified=achbotPaymentEvidenceGiftVerify($p,(string)$payload['code']);
            $current=is_array($verified['data'])?
                achbotPaymentEvidenceGiftNormalize($verified['data'],$p,$a,true):null;
            if ($current===null || $current['fingerprint']!==$expected['fingerprint'] ||
                (!$retry && $current['status']!=='active') ||
                ($retry && !in_array($current['status'],['redeemed','redeemed_offline'],true))) {
                $state=$verified['status']==='unavailable'?'uncertain':'review';
                achbotPaymentEvidenceStoreOutcome($db,$handle,$state,['reason'=>'gift_readback_unproven']);
                return ['status'=>$state==='uncertain'?'pending':'review','proof'=>null];
            }
            $reference='achbot-'.$op;
            $response=achbotPaymentEvidenceHttp($p,'POST','/redeem',[
                'code'=>$payload['code'],'external_user_ref'=>'achbot-user-'.$payload['actor'],
                'reference'=>$reference,'telegram_user_id'=>(string)$payload['actor']]);
            $j=$response['json'];
            $normalized=$response['ok'] && is_array($j['data']??null)
                ?achbotPaymentEvidenceGiftNormalize($j['data'],$p,$a,true):null;
            $valid=($j['api_version']??null)==='2' && ($j['ok']??null)===true &&
                ($j['reference']??null)===$reference &&
                in_array($j['code']??'',['card_redeemed','card_redeem_idempotent'],true) &&
                $normalized!==null && $normalized['status']==='redeemed' &&
                $normalized['fingerprint']===$expected['fingerprint'] &&
                is_bool($j['data']['idempotent']??null) &&
                (($j['code']==='card_redeem_idempotent')===$j['data']['idempotent']);
            $request=$j['request_id']??null;
        }
        if (!$valid || !is_string($request) || !preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$request)) {
            $uncertain=$response['http']===0 || $response['http']>=500 || $response['http']===429;
            // Auth/state errors after an earlier uncertain dispatch cannot disprove a commit.
            $state=$uncertain?'uncertain':'review';
            achbotPaymentEvidenceStoreOutcome($db,$handle,$state,
                ['reason'=>'consumption_unproven','last_http'=>$response['http']]);
            return ['status'=>$state==='uncertain'?'pending':'review','proof'=>null];
        }
        $proof=['request_id'=>$request,'remote'=>$normalized,'operation'=>$op,
            'reference'=>$p['method']==='gift_card'?'achbot-'.$op:null];
        if (!achbotPaymentEvidenceStoreOutcome($db,$handle,'proven',['proof'=>$proof,'reason'=>null]))
            return ['status'=>'pending','proof'=>null];
        return ['status'=>'proven','proof'=>achbotPaymentEvidenceGetProof($db,$payId)];
    } catch (Throwable $e) {
        try{$db->rollback();}catch(Throwable $x){}
        return ['status'=>'pending','proof'=>null];
    }
}
function achbotPaymentEvidenceGetProof(mysqli $db,int $payId): ?array {
    if ($payId<=0 || !achbotPaymentEvidenceSchemaReady($db)) return null;
    try {
        $q=$db->prepare("SELECT t.*,a.pay_row_id,a.evidence_profile
            FROM payment_transactions t JOIN payment_attempts a ON a.id=t.attempt_id
            WHERE a.pay_row_id=? AND t.evidence_state='proven' ORDER BY t.id LIMIT 2");
        $q->bind_param('i',$payId);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (count($rows)!==1) return null;
        $t=$rows[0];$op=(string)$t['evidence_operation'];
        $payload=achbotPaymentEvidenceUnprotect('operation:'.$op,(string)$t['evidence_payload']);
        if (!$payload || !is_array($payload['proof']??null) ||
            $payload['profile_sha256']!==hash('sha256',(string)$t['evidence_profile'])) return null;
        return ['transaction_id'=>(int)$t['id'],'pay_id'=>$payId,'attempt_id'=>(int)$t['attempt_id'],
            'operation'=>$op,'mode'=>$payload['mode'],'pay_sha256'=>$payload['pay_sha256'],
            'quote_sha256'=>$payload['quote_sha256'],'profile_sha256'=>$payload['profile_sha256'],
            'proof_sha256'=>hash('sha256',(string)$t['evidence_payload']),
            'evidence'=>$payload['expected'],'consumption'=>$payload['proof']];
    } catch (Throwable $e) {return null;}
}
function achbotPaymentEvidenceValidateProofLocked(mysqli $db,array $pay,array $proof): bool {
    if (!achbotPaymentEvidenceSchemaReady($db) || (int)($pay['id']??0)!==(int)($proof['pay_id']??0))
        return false;
    try {
        $active=$db->query('SELECT @@in_transaction AS active');
        if (!$active || (int)($active->fetch_assoc()['active']??0)!==1) return false;
        $payId=(int)$pay['id'];$tid=(int)($proof['transaction_id']??0);
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
        $p=$a?achbotPaymentEvidenceSnapshot($a):null;
        if (!$p || $p['pay_sha256']!==achbotPaymentEvidencePayFingerprint($pay)) return false;
        $q=$db->prepare('SELECT * FROM payment_transactions WHERE id=? AND attempt_id=? FOR UPDATE');
        $q->bind_param('ii',$tid,$a['id']);$q->execute();$t=$q->get_result()->fetch_assoc();$q->close();
        if (!$t || $t['evidence_state']!=='proven' || $t['evidence_proven_at']===null ||
            ($proof['operation']??null)!==$t['evidence_operation'] ||
            ($proof['proof_sha256']??null)!==hash('sha256',(string)$t['evidence_payload'])) return false;
        $payload=achbotPaymentEvidenceUnprotect('operation:'.$t['evidence_operation'],(string)$t['evidence_payload']);
        return $payload && is_array($payload['proof']??null) &&
            $payload['profile_sha256']===$p['profile_sha256'] &&
            $payload['pay_sha256']===$p['pay_sha256'] && $payload['quote_sha256']===$p['quote_sha256'] &&
            $payload['mode']==='settle';
    } catch (Throwable $e) {return false;}
}
