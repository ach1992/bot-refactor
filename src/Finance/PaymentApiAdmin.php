<?php
/** AChBot existing-payment API administration; no provider or financial effects. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    http_response_code(404);exit;
}
require_once __DIR__.'/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
if (!function_exists('achbotPaymentEvidenceSettingFields'))
    require_once __DIR__.'/PaymentEvidence.php';

/** Labels and grouping only; validation/storage semantics belong to PaymentEvidence. */
function achbotPaymentApiAdminFieldMap(): array {
    return [
        'smsApiUrl'=>['bank_transfer',false],
        'smsApiToken'=>['bank_transfer',false],
        'smsApiHorizonHours'=>['bank_transfer',false],
        'smsApiProvider'=>['bank_transfer',true],
        'smsApiSender'=>['bank_transfer',true],
        'smsApiMobileSource'=>['bank_transfer',true],
        'smsApiRuleId'=>['bank_transfer',true],
        'smsApiBankKey'=>['bank_transfer',true],
        'smsApiTimezone'=>['bank_transfer',true],
        'smsApiCalendar'=>['bank_transfer',true],
        'giftApiUrl'=>['gift_card',false],
        'giftApiKeyId'=>['gift_card',false],
        'giftApiSecret'=>['gift_card',false],
        'giftApiAmountUnit'=>['gift_card',false],
        'giftApiGameKey'=>['gift_card',true],
        'giftApiValueId'=>['gift_card',true]
    ];
}
function achbotPaymentApiAdminIsField(string $field): bool {
    return isset(achbotPaymentApiAdminFieldMap()[$field]) &&
        in_array($field,achbotPaymentEvidenceSettingFields(),true);
}
function achbotPaymentApiAdminMethod(string $field): ?string {
    return achbotPaymentApiAdminIsField($field)
        ? achbotPaymentApiAdminFieldMap()[$field][0] : null;
}
function achbotPaymentApiAdminSecret(string $field): bool {
    return in_array($field,['smsApiToken','giftApiSecret'],true);
}
function achbotPaymentApiAdminStoredValue(string $field,string $value): string {
    if (!achbotPaymentApiAdminIsField($field))
        throw new RuntimeException('payment_api_setting_unknown');
    return $value!=='' && achbotPaymentApiAdminSecret($field)
        ? achbotPaymentEvidenceProtect('setting:'.$field,['value'=>$value]) : $value;
}
/** Current unique document; optionally called inside the gateway writer transaction. */
function achbotPaymentApiAdminKeys(mysqli $db,bool $locking=false): ?array {
    try {
        $stmt=$db->prepare("SELECT id,value FROM setting WHERE type='PAYMENT_KEYS'
            ORDER BY id LIMIT 2".($locking?' FOR UPDATE':''));
        if (!$stmt || !$stmt->execute()) return null;
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if (count($rows)>1) return null;
        if (!$rows) return [];
        $keys=json_decode((string)$rows[0]['value'],true,512,JSON_THROW_ON_ERROR);
        return is_array($keys)?$keys:null;
    } catch (Throwable $ignored) {return null;}
}
function achbotPaymentApiAdminToggleAllowed(mysqli $db,string $flag): bool {
    $method=match($flag) {
        'smsApiVerifyState'=>'bank_transfer',
        'giftApiVerifyState'=>'gift_card',default=>null
    };
    if ($method===null) return false;
    $keys=achbotPaymentApiAdminKeys($db,true);
    return $keys!==null && achbotPaymentEvidenceSettingsValid($method,$keys);
}
function achbotPaymentApiAdminRows(string $method,array $keys,array $states): array {
    global $buttonValues;
    if (!in_array($method,['bank_transfer','gift_card'],true)) return [];
    $sms=$method==='bank_transfer';
    $flag=$sms?'smsApiVerifyState':'giftApiVerifyState';
    $suffix=$sms?'sms':'gift';
    return [
        [
            ['text'=>($states[$flag]??'off')==='on'
                ? $buttonValues['on'] : $buttonValues['off'],
             'callback_data'=>'changeGateWays'.$flag],
            ['text'=>achbotMessage('payment_settings.api.'.$suffix.'_verify'),
             'callback_data'=>'wizwizch']
        ],
        [['text'=>achbotMessage('payment_settings.api.'.$suffix.'_settings').' - '.
             achbotMessage(achbotPaymentEvidenceSettingsValid($method,$keys)
                 ? 'payment_settings.api.configured' : 'payment_settings.api.incomplete'),
          'callback_data'=>$sms?'changePaymentKeysApiSms':'changePaymentKeysApiGift']]
    ];
}
function achbotPaymentApiAdminFieldValue(array $keys,string $field): string {
    $value=$keys[$field]??'';
    if (!array_key_exists($field,$keys) || $value==='')
        return achbotMessage('payment_settings.api.unset');
    if (is_int($value)) $value=(string)$value;
    if (!is_string($value)) return achbotMessage('payment_settings.api.invalid');
    if (achbotPaymentApiAdminSecret($field)) {
        return achbotPaymentEvidenceSecret($keys,$field)!==null
            ? achbotMessage('payment_settings.api.configured')
            : achbotMessage('payment_settings.api.invalid');
    }
    if (achbotPaymentEvidenceNormalizeSetting($field,$value)===null)
        return achbotMessage('payment_settings.api.invalid');
    // Authentication and URL values never become labels or echoed confirmations.
    if (in_array($field,['smsApiUrl','giftApiUrl','giftApiKeyId'],true))
        return achbotMessage('payment_settings.api.configured');
    return strlen($value)<=45?$value:achbotMessage('payment_settings.api.configured');
}
function achbotPaymentApiAdminPage(string $method,bool $advanced=false): array {
    global $connection;
    if (!in_array($method,['bank_transfer','gift_card'],true))
        throw new RuntimeException('payment_api_page_unknown');
    $keys=achbotPaymentApiAdminKeys($connection);
    $sms=$method==='bank_transfer';$suffix=$sms?'sms':'gift';
    $text=achbotMessage('payment_settings.api.'.$suffix.'_title')."\n".
        achbotMessage('payment_settings.api.'.$suffix.'_guidance')."\n\n".
        achbotMessage('payment_settings.api.no_live_test');
    $rows=[];
    if ($keys===null) {
        $text.="\n".achbotMessage('payment_settings.api.read_unavailable');
    } else {
        $urlField=$sms?'smsApiUrl':'giftApiUrl';
        $url=$keys[$urlField]??'';
        if (is_string($url) && $url!=='' &&
            achbotPaymentEvidenceNormalizeSetting($urlField,$url)===$url)
            $text.="\n\n".achbotMessage('payment_settings.api.field.'.$urlField).
                "\n<code>".htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>";
        $text.="\n".achbotMessage(achbotPaymentEvidenceSettingsValid($method,$keys)
            ? 'payment_settings.api.complete_guidance' : 'payment_settings.api.incomplete_guidance');
        foreach (achbotPaymentApiAdminFieldMap() as $field=>$meta) {
            if ($meta!==[$method,$advanced] || !achbotPaymentApiAdminIsField($field)) continue;
            $rows[]=[
                ['text'=>achbotPaymentApiAdminFieldValue($keys,$field),
                 'callback_data'=>'changePaymentKeys'.$field],
                ['text'=>achbotMessage('payment_settings.api.field.'.$field),
                 'callback_data'=>'changePaymentKeys'.$field]
            ];
        }
        if (!$advanced) $rows[]=[[
            'text'=>achbotMessage('payment_settings.api.advanced'),
            'callback_data'=>$sms?'changePaymentKeysApiSmsAdvanced':'changePaymentKeysApiGiftAdvanced'
        ]];
    }
    $rows[]=[['text'=>achbotMessage('payment_settings.api.back'),
        'callback_data'=>$advanced
            ? ($sms?'changePaymentKeysApiSms':'changePaymentKeysApiGift') : 'gateWays_Channels']];
    return ['text'=>$text,'keyboard'=>json_encode(['inline_keyboard'=>$rows],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
}
function achbotPaymentApiAdminPrompt(string $field): string {
    if (!achbotPaymentApiAdminIsField($field))
        throw new RuntimeException('payment_api_prompt_unknown');
    return achbotMessage('payment_settings.api.field.'.$field)."\n".
        achbotMessage('payment_settings.api.hint.'.$field)."\n".
        achbotMessage('payment_settings.api.clear_hint');
}
function achbotPaymentApiAdminReturn(string $field): void {
    $method=achbotPaymentApiAdminMethod($field);
    if ($method===null) return;
    $page=achbotPaymentApiAdminPage($method,achbotPaymentApiAdminFieldMap()[$field][1]);
    sendMessage($page['text'],$page['keyboard'],'HTML');
}
