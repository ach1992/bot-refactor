<?php
/** AChBot Telegram administration for validated eligibility rules. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    http_response_code(404);exit;
}
function achbotGatewayRuleCallback(string $data): ?array {
    if (strlen($data)>64) return null;
    $p=explode('|',$data);
    if (count($p)<2 || $p[0]!=='gr') return null;
    $action=$p[1];
    if (in_array($action,['s','x'],true)) {
        return count($p)===3 && preg_match('/\A[1-9][0-9]*\z/D',$p[2])
            ? ['action'=>$action,'nonce'=>$p[2]] : null;
    }
    if (!in_array($action,['a','d','q','t','n','v','c'],true) ||
        count($p)<4 || count($p)>6 ||
        !array_key_exists($p[2],achbotPayUxAudienceMethods()) ||
        !array_key_exists($p[3],achbotGatewayRuleCategories()))
        return null;
    if (in_array($action,['a','q','t'],true) && count($p)!==4) return null;
    if ($action==='d' && (count($p)!==5 ||
        preg_match('/\A[1-9][0-9]*\z/D',$p[4])!==1)) return null;
    if (in_array($action,['v','c'],true) &&
        (count($p)!==5 || !array_key_exists($p[4],achbotGatewayRuleMetrics())))
        return null;
    if ($action==='n' && (count($p)!==6 ||
        !(($p[4]==='age' && $p[5]==='days') ||
          (array_key_exists($p[4],achbotGatewayRuleMetrics()) &&
           in_array($p[5],['count','days'],true))))) return null;
    return ['action'=>$action,'method'=>$p[2],'category'=>$p[3],
            'metric'=>$p[4]??null,'field'=>$p[5]??null];
}
function achbotGatewayRuleStep(string $source): ?array {
    $p=explode('|',$source);
    if (count($p)<5 || $p[0]!=='gr' ||
        !in_array($p[1],['edit','confirm','delete'],true) ||
        !array_key_exists($p[2],achbotPayUxAudienceMethods()) ||
        !array_key_exists($p[3],achbotGatewayRuleCategories()) ||
        !($p[1]==='delete' ||
          ($p[4]==='age' && ($p[5]??null)==='days') ||
          (array_key_exists($p[4],achbotGatewayRuleMetrics()) &&
           in_array($p[5]??null,['count','days'],true)) )) return null;
    if ($p[1]==='edit' && (count($p)!==7 ||
        preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$p[6])!==1)) return null;
    if ($p[1]==='delete' && (count($p)!==6 ||
        preg_match('/\A[1-9][0-9]*\z/D',$p[4])!==1 ||
        preg_match('/\A[a-f0-9]{64}\z/D',$p[5])!==1)) return null;
    if ($p[1]==='confirm' && (count($p)!==9 ||
        preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$p[6])!==1 ||
        preg_match('/\A[1-9][0-9]*\z/D',$p[7])!==1 ||
        preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$p[8])!==1)) return null;
    return ['phase'=>$p[1], 'method'=>$p[2], 'category'=>$p[3],
            'metric'=>$p[4], 'field'=>$p[5]??null,
            'value'=>$p[6]??null,'nonce'=>$p[1]==='delete'?$p[4]:($p[7]??null),
            'baseline'=>$p[1]==='confirm'?($p[8]??null):($p[1]==='edit'?($p[6]??null):null),
            'fingerprint'=>$p[1]==='delete'?$p[5]:null];
}
function achbotGatewayRuleTextInteger(string $text,bool $days): ?int {
    if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$text)!==1) return null;
    $n=filter_var($text,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    return $n!==false && achbotGatewayRuleInteger($n,$days)?$n:null;
}
/**
 * Called only inside achbotMutateBotStates, after the current actor is locked.
 * Return a targeted BOT_STATES delta and optionally an owned users.step delta.
 * All policy edits are fresh-path-specific; other fields and admins survive.
 */
function achbotGatewayRuleMutate(array $state,string $kind,string $key,
                                ?string $value,string $step,int $sourceId,
                                ?string &$newStep): array {
    $raw=array_key_exists('gatewayRules',$state)
        ? achbotGatewayRulesDecode($state['gatewayRules'])
        : ['version'=>1,'methods'=>[]];
    if ($raw===null) throw new RuntimeException('gateway_rule_document_invalid');
    if ($kind==='number') {
        $s=achbotGatewayRuleStep($step);
        if (!$s || $s['phase']!=='edit' || $step!==$key || $value===null)
            throw new RuntimeException('gateway_rule_input_not_owned');
        if ($value===($GLOBALS['buttonValues']['cancel']??null)) {
            $newStep='none';
            return $state;
        }
        $n=achbotGatewayRuleTextInteger($value,$s['field']==='days');
        if ($n===null) throw new RuntimeException('gateway_rule_integer_invalid');
        $method=$s['method'];$category=$s['category'];
        $metric=$s['metric'];$field=$s['field'];
        $newStep='gr|confirm|'.$method.'|'.$category.'|'.$metric.'|'.$field.'|'.$n.'|'.$sourceId.'|'.$s['baseline'];
        return $state; // No policy change before explicit confirmation.
    }
    $parsed=achbotGatewayRuleCallback($key);
    if ($parsed===null) throw new RuntimeException('gateway_rule_action_invalid');
    $act=$parsed['action'];
    if ($act==='s' || $act==='x') {
        $s=achbotGatewayRuleStep($step);
        if ($s===null || !in_array($s['phase'],['confirm','delete'],true) ||
            $s['nonce']!==$parsed['nonce'])
            throw new RuntimeException('gateway_rule_confirmation_stale');
        $newStep='none';
        if ($act==='x') return $state;
        if ($s['phase']==='delete')
            throw new RuntimeException('gateway_rule_delete_requires_bound_action');
        $method=$s['method'];$category=$s['category'];
        $metric=$s['metric'];$field=$s['field'];
        $number=achbotGatewayRuleTextInteger($s['value'],$field==='days');
        if ($number===null) throw new RuntimeException('gateway_rule_value_invalid');
    } else {
        $method=$parsed['method'];$category=$parsed['category'];
        $metric=$parsed['metric'];$field=$parsed['field'];
    }
    if ($act==='d') {
        $s=achbotGatewayRuleStep($step);
        if (!$s || $s['phase']!=='delete' ||
            $s['method']!==$method || $s['category']!==$category ||
            $s['nonce']!==$parsed['metric'])
            throw new RuntimeException('gateway_rule_delete_unconfirmed');
    }
    $entries=$raw['methods'][$method]??[];
    $index=null;
    foreach ($entries as $i=>$entry) {
        if ($entry['category']===$category) {$index=$i;break;}
    }
    if ($act==='a') {
        if ($index!==null) throw new RuntimeException('gateway_rule_already_exists');
        $entries[]=achbotGatewayRuleBlank($category);
    } else {
        if ($index===null) throw new RuntimeException('gateway_rule_missing');
        if ($act==='d' && !hash_equals($s['fingerprint'],
            hash('sha256',json_encode($entries[$index],JSON_THROW_ON_ERROR))))
            throw new RuntimeException('gateway_rule_delete_changed_since_preview');
        if ($act==='q') {
            $fingerprint=hash('sha256',json_encode($entries[$index],JSON_THROW_ON_ERROR));
            $newStep='gr|delete|'.$method.'|'.$category.'|'.$sourceId.'|'.$fingerprint;
            return $state;
        } elseif ($act==='d') {
            $newStep='none';
            array_splice($entries,$index,1);
        }
        elseif ($act==='t') $entries[$index]['enabled']=!$entries[$index]['enabled'];
        elseif ($act==='n') {
            $baseline=$metric==='age'
                ? $entries[$index]['registration_age_days']
                : $entries[$index]['metrics'][$metric][$field];
            $newStep='gr|edit|'.$method.'|'.$category.'|'.$metric.'|'.$field.'|'.$baseline;
            return $state;
        } elseif ($act==='v') {
            $current=$entries[$index]['metrics'][$metric]['direction'];
            $entries[$index]['metrics'][$metric]['direction']=
                $current==='older'?'recent':'older';
        } elseif ($act==='c') {
            $entries[$index]['metrics'][$metric]['count']=0;
            $entries[$index]['metrics'][$metric]['days']=0;
        } elseif ($act==='s') {
            $current=$metric==='age'
                ? $entries[$index]['registration_age_days']
                : $entries[$index]['metrics'][$metric][$field];
            if ((string)$current!==$s['baseline'])
                throw new RuntimeException('gateway_rule_field_changed_since_editor');
            if ($metric==='age') {
                $entries[$index]['registration_age_days']=$number;
            } else $entries[$index]['metrics'][$metric][$field]=$number;
        } else throw new RuntimeException('gateway_rule_operation_unknown');
    }
    $raw['methods'][$method]=array_values($entries);
    if (achbotGatewayRulesDecode($raw)===null)
        throw new RuntimeException('gateway_rule_after_write_invalid');
    $state['gatewayRules']=$raw;
    return $state;
}
/** Current persisted operator view, never request-global cached policy. */
function achbotGatewayAdminCurrent(): ?array {
    global $connection;
    try {
        $stmt=$connection->prepare("SELECT id,value FROM setting WHERE type='BOT_STATES' ORDER BY id LIMIT 2");
        if (!$stmt || !$stmt->execute()) return null;
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if (count($rows)!==1) return null;
        $state=json_decode((string)$rows[0]['value'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($state) || (
            array_key_exists('gatewayRules',$state) &&
            achbotGatewayRulesDecode($state['gatewayRules'])===null)) return null;
        return $state;
    } catch (Throwable $ignored) {return null;}
}
function achbotGatewayAdminMarkup(array $rows): string {
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function achbotGatewayAdminMethodFlags(string $method,array $state): string {
    $flags=[
        'nowpayments'=>['nowPaymentWallet'=>'شارژ','nowPaymentOther'=>'سایر'],
        'zarinpal'=>['zarinpal'=>'فعال'],'nextpay'=>['nextpay'=>'فعال'],
        'bank_transfer'=>['cartToCartState'=>'فعال'],
        'gift_card'=>['giftCardState'=>'فعال'],
        'manual_usdt'=>['manualUsdtState'=>'فعال'],
        'manual_trx'=>['manualUsdtState'=>'فعال'],
        'wallet'=>['walletState'=>'فعال']
    ];
    $labels=[];
    foreach ($flags[$method]??[] as $key=>$label) {
        $labels[]=$label.': '.(($state[$key]??'off')==='on'?'روشن':'خاموش');
    }
    return implode('، ',$labels);
}
function achbotGatewayAdminPage(string $data,?int $nonce=null): array {
    $state=achbotGatewayAdminCurrent();
    if ($state===null) return [
        'text'=>'⛔️ تنظیمات پرداخت خواندنی نیست؛ هیچ قانون نامعتبر خودکار فعال نمی‌شود.',
        'keyboard'=>achbotGatewayAdminMarkup([[['text'=>'بازگشت','callback_data'=>'gateWays_Channels']]])
    ];
    $policy=isset($state['gatewayRules'])
        ? achbotGatewayRulesDecode($state['gatewayRules'])['methods'] : [];
    $legacy=$state['gatewayAudience']??[];
    $p=explode('|',$data);
    $back=[[['text'=>'🔙 بازگشت','callback_data'=>'gateWays_Channels']]];
    if ($data==='gr|l' || $data==='gatewayAudienceSettings') {
        $rows=[];$text="🔐 مدیریت دسترسی به روش‌های پرداخت\n";
        $text.="قوانین هر روش و هر دسته مستقل‌اند. خاموش‌بودن درگاه همیشه مانع پرداخت است.\n";
        foreach (achbotPayUxAudienceMethods() as $method=>$label) {
            $count=count($policy[$method]??[]);
            $suffix=array_key_exists($method,$policy)?($count.' قانون'):'سیاست قبلی';
            $rows[]=[['text'=>$label.' — '.$suffix,
                       'callback_data'=>'gr|m|'.$method]];
        }
        return ['text'=>$text,'keyboard'=>achbotGatewayAdminMarkup(array_merge(
            $rows,$back))];
    }
    if (count($p)<3 || $p[0]!=='gr' ||
        !array_key_exists($p[2],achbotPayUxAudienceMethods())) return [
            'text'=>'⛔️ انتخاب ناشناخته است.','keyboard'=>achbotGatewayAdminMarkup($back)];
    $method=$p[2];$methodLabel=achbotPayUxAudienceMethods()[$method];
    $methodBack=[[['text'=>'🔙 روش‌های پرداخت','callback_data'=>'gr|l']]];
    if ($p[1]==='m' && count($p)===3) {
        $text="💳 $methodLabel\n".achbotGatewayAdminMethodFlags($method,$state)."\n";
        $text.="حالت پیشین: ".(achbotPayUxAudienceModes()[$legacy[$method]??'all']??'نامعتبر')."\n";
        $text.="شرط‌های هر قانون با AND و قوانین فعال با OR ترکیب می‌شوند.\n";
        $rows=[];
        foreach (achbotGatewayRuleCategories() as $category=>$label) {
            $rule=null;
            foreach ($policy[$method]??[] as $entry)
                if ($entry['category']===$category) {$rule=$entry;break;}
            $tag=$rule===null?'افزودن':($rule['enabled']?'فعال':'غیرفعال');
            $rows[]=[['text'=>$label.' — '.$tag,
                'callback_data'=>'gr|r|'.$method.'|'.$category]];
        }
        return ['text'=>$text,'keyboard'=>achbotGatewayAdminMarkup(
            array_merge($rows,$methodBack))];
    }
    if (count($p)<4 || !array_key_exists($p[3],achbotGatewayRuleCategories()))
        return achbotGatewayAdminPage('gr|m|'.$method);
    $category=$p[3];$categoryLabel=achbotGatewayRuleCategories()[$category];
    $r=null;
    foreach ($policy[$method]??[] as $entry)
        if ($entry['category']===$category) {$r=$entry;break;}
    $ruleBack=[[['text'=>'🔙 دسته‌ها','callback_data'=>'gr|m|'.$method]]];
    $prefix=$method.'|'.$category;
    if ($p[1]==='q' && count($p)===4 && $r!==null) {
        if (!$nonce || $nonce<=0)
            return ['text'=>'⛔️ تأیید حذف منقضی شده است.',
                'keyboard'=>achbotGatewayAdminMarkup($ruleBack)];
        return ['text'=>"⚠️ قانون $categoryLabel از $methodLabel حذف شود؟\n".
            "اگر آخرین قانون باشد، روش تا تعریف قانون جدید مسدود می‌ماند.",
            'keyboard'=>achbotGatewayAdminMarkup([
                [['text'=>'🗑 تأیید حذف','callback_data'=>'gr|d|'.$prefix.'|'.$nonce]],
                [['text'=>'انصراف','callback_data'=>'gr|x|'.$nonce]]
            ])];
    }
    if ($r===null) {
        return ['text'=>"💳 $methodLabel / $categoryLabel\n".
            "هنوز قانونی ثبت نشده است. با ذخیره، این دسته بدون شرط عددی مجاز می‌شود. ".
            "می‌توانید بعداً سن و سابقهٔ خرید را محدود کنید.",
            'keyboard'=>achbotGatewayAdminMarkup([
                [['text'=>'💾 ذخیره و فعال‌سازی','callback_data'=>'gr|a|'.$prefix]],
                ...$ruleBack])];
    }
    if ($p[1]==='f' && count($p)===5 &&
        array_key_exists($p[4],achbotGatewayRuleMetrics())) {
        $metric=$p[4];$v=$r['metrics'][$metric];
        $text="📊 $categoryLabel / ".achbotGatewayRuleMetrics()[$metric]."\n";
        $text.="حداقل تعداد: ".$v['count']."\nروز: ".$v['days'].
            "\nجهت زمان: ".($v['direction']==='older'?'قدیمی‌تر':'اخیر')."\n";
        $text.="۰/۰ = غیرفعال، تعداد ۰ و روز مثبت = حداقل یک تراکنش در بازه.\n";
        $text.="تاریخ، زمان ثبت درخواست پرداخت است، نه زمان قطعی تسویه.";
        return ['text'=>$text,'keyboard'=>achbotGatewayAdminMarkup([
            [['text'=>'✏️ ویرایش تعداد','callback_data'=>'gr|n|'.$prefix.'|'.$metric.'|count']],
            [['text'=>'✏️ ویرایش روز','callback_data'=>'gr|n|'.$prefix.'|'.$metric.'|days']],
            [['text'=>'🔁 تغییر جهت (قدیمی‌تر / اخیر)','callback_data'=>'gr|v|'.$prefix.'|'.$metric]],
            [['text'=>'⛔️ غیرفعال‌سازی شرط (۰/۰)','callback_data'=>'gr|c|'.$prefix.'|'.$metric]],
            [['text'=>'🔙 قانون','callback_data'=>'gr|r|'.$prefix]]
        ])];
    }
    $text="📋 $methodLabel / $categoryLabel\n";
    $text.="وضعیت قانون: ".($r['enabled']?'فعال':'غیرفعال').
        "\nحداقل سابقهٔ عضویت: ".$r['registration_age_days']." روز\n";
    $text.="۰ روز = بدون محدودیت سن عضویت\n";
    foreach (achbotGatewayRuleMetrics() as $key=>$label) {
        $v=$r['metrics'][$key];
        $value=$v['count'].' تراکنش، '.$v['days'].' روز';
        $text.=$label.': '.$value.' ('.($v['direction']==='older'?'قدیمی‌تر':'اخیر').")\n";
    }
    $rows=[[['text'=>'✏️ سن عضویت (روز)','callback_data'=>'gr|n|'.$prefix.'|age|days']]];
    foreach (achbotGatewayRuleMetrics() as $metric=>$label)
        $rows[]=[['text'=>'📊 '.$label,'callback_data'=>'gr|f|'.$prefix.'|'.$metric]];
    $rows[]=[['text'=>$r['enabled']?'⏸ غیرفعال‌کردن قانون':'▶️ فعال‌کردن قانون',
               'callback_data'=>'gr|t|'.$prefix]];
    $rows[]=[['text'=>'🗑 حذف قانون','callback_data'=>'gr|q|'.$prefix]];
    return ['text'=>$text,'keyboard'=>achbotGatewayAdminMarkup(
        array_merge($rows,$ruleBack))];
}

function achbotGatewayAdminCanView($update,int $actor,int $chat,int $message,
                                   int $primary,string $data): bool {
    global $connection;
    try {
        if ($actor<=0 || $primary<=0 || $actor!==$chat || $message<=0 ||
            !function_exists('achbotWebhookIngressAuthenticated') ||
            !achbotWebhookIngressAuthenticated()) return false;
        if (!function_exists('achbotDirectGiftWebhook'))
            require_once __DIR__.'/../Subscription/DirectGift.php';
        achbotDirectGiftWebhook($update,$actor,$chat,$message,$data,'callback');
        $stmt=$connection->prepare('SELECT id,isAdmin,step FROM users WHERE userid=? LIMIT 2');
        if (!$stmt) return false;
        $stmt->bind_param('i',$actor);
        if (!$stmt->execute()) return false;
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        return count($rows)===1 && $rows[0]['step']!=='banned' &&
            ($actor===$primary || (int)$rows[0]['isAdmin']===1);
    } catch (Throwable $ignored) { return false;}
}
