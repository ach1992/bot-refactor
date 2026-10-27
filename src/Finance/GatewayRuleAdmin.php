<?php
/** AChBot Telegram administration for validated eligibility rules. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    http_response_code(404);exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

/** UI-state token: detects superseded administrator buttons on current data.
 * 15 hex chars fit Telegram's 64-byte callback limit even for longest route.
 * It is a CAS token, not an authentication capability (webhook/role are separate).
 */
function achbotGatewayRuleControlTag(array $rule): string {
    return substr(hash('sha256',json_encode($rule,JSON_THROW_ON_ERROR)),0,15);
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
        count($p)<4 || count($p)>7 ||
        !array_key_exists($p[2],achbotPayUxAudienceMethods()) ||
        !array_key_exists($p[3],achbotGatewayRuleCategories()))
        return null;
    if (in_array($action,['a','q'],true) && count($p)!==4) return null;
    if ($action==='d' && (count($p)!==5 ||
        preg_match('/\A[1-9][0-9]*\z/D',$p[4])!==1)) return null;
    if ($action==='t' && count($p)!==5) return null;
    if (in_array($action,['v','c'],true) &&
        (count($p)!==6 || !array_key_exists($p[4],achbotGatewayRuleMetrics())))
        return null;
    if ($action==='n' && (count($p)!==7 ||
        !(($p[4]==='age' && $p[5]==='days') ||
          (array_key_exists($p[4],achbotGatewayRuleMetrics()) &&
           in_array($p[5],['count','days'],true))))) return null;
    $tokenIndex=match ($action) {
        't'=>4,'v','c'=>5,'n'=>6,default=>null
    };
    if ($tokenIndex!==null &&
        preg_match('/\A[a-f0-9]{15}\z/D',$p[$tokenIndex])!==1) return null;
    return ['action'=>$action,'method'=>$p[2],'category'=>$p[3],
            'metric'=>$p[4]??null,'field'=>$p[5]??null,
            'expected_tag'=>$tokenIndex!==null?$p[$tokenIndex]:null];
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
        // A newer update_id proves only delivery order, not whether a button
        // came from a stale screen. Compare against the rule the admin saw.
        // This includes BOTH count and days for Clear and all rule fields for
        // Toggle/Direction. Numeric Save retains its per-field CAS afterward.
        if (in_array($act,['t','v','c','n'],true) &&
            !hash_equals(achbotGatewayRuleControlTag($entries[$index]),
                         (string)$parsed['expected_tag']))
            throw new RuntimeException('gateway_rule_stale_operator_button');
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
        'nowpayments'=>['nowPaymentWallet'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.c7cf334c78'),'nowPaymentOther'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.452e2203fa')],
        'zarinpal'=>['zarinpal'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc')],'nextpay'=>['nextpay'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc')],
        'bank_transfer'=>['cartToCartState'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc')],
        'gift_card'=>['giftCardState'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc')],
        'manual_usdt'=>['manualUsdtState'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc')],
        'manual_trx'=>['manualUsdtState'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc')],
        'wallet'=>['walletState'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc')]
    ];
    $labels=[];
    foreach ($flags[$method]??[] as $key=>$label) {
        $labels[]=$label.': '.(($state[$key]??'off')==='on'?\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.be9fd3bd1b'):\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.841b8cb341'));
    }
    return implode(\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.11735aabd3'),$labels);
}
function achbotGatewayAdminPage(string $data,?int $nonce=null): array {
    $state=achbotGatewayAdminCurrent();
    if ($state===null) return [
        'text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.36e4da5935'),
        'keyboard'=>achbotGatewayAdminMarkup([[['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.b233883d0d'),'callback_data'=>'gateWays_Channels']]])
    ];
    $policy=isset($state['gatewayRules'])
        ? achbotGatewayRulesDecode($state['gatewayRules'])['methods'] : [];
    $legacy=$state['gatewayAudience']??[];
    $p=explode('|',$data);
    $back=[[['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.b35a2352f5'),'callback_data'=>'gateWays_Channels']]];
    if ($data==='gr|l' || $data==='gatewayAudienceSettings') {
        $rows=[];$text=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.778e46431a');
        $text.=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.557fcc0258');
        foreach (achbotPayUxAudienceMethods() as $method=>$label) {
            $count=count($policy[$method]??[]);
            $suffix=array_key_exists($method,$policy)?($count.\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.5c034d60ca')):\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.17dc013fc7');
            $rows[]=[['text'=>$label.\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.3b2e8119b6').$suffix,
                       'callback_data'=>'gr|m|'.$method]];
        }
        return ['text'=>$text,'keyboard'=>achbotGatewayAdminMarkup(array_merge(
            $rows,$back))];
    }
    if (count($p)<3 || $p[0]!=='gr' ||
        !array_key_exists($p[2],achbotPayUxAudienceMethods())) return [
            'text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.1ca8c08333'),'keyboard'=>achbotGatewayAdminMarkup($back)];
    $method=$p[2];$methodLabel=achbotPayUxAudienceMethods()[$method];
    $methodBack=[[['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.4b1d33d728'),'callback_data'=>'gr|l']]];
    if ($p[1]==='m' && count($p)===3) {
        $text=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.method_label.b0c241d56f', ['methodLabel' => $methodLabel]).achbotGatewayAdminMethodFlags($method,$state)."\n";
        $text.=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.cffb41a268').(achbotPayUxAudienceModes()[$legacy[$method]??'all']??\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.a3544b7c99'))."\n";
        $text.=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.82e58a9a6a');
        $rows=[];
        foreach (achbotGatewayRuleCategories() as $category=>$label) {
            $rule=null;
            foreach ($policy[$method]??[] as $entry)
                if ($entry['category']===$category) {$rule=$entry;break;}
            $tag=$rule===null?\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.50c8d65df6'):($rule['enabled']?\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc'):\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.4e67bd8e34'));
            $rows[]=[['text'=>$label.\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.3b2e8119b6').$tag,
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
    $ruleBack=[[['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.557627db84'),'callback_data'=>'gr|m|'.$method]]];
    $prefix=$method.'|'.$category;
    if ($p[1]==='q' && count($p)===4 && $r!==null) {
        if (!$nonce || $nonce<=0)
            return ['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.78eea75cd9'),
                'keyboard'=>achbotGatewayAdminMarkup($ruleBack)];
        return ['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.50abbe7e6f', ['categoryLabel' => $categoryLabel, 'methodLabel' => $methodLabel]).
            \achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.63f13c4d9f'),
            'keyboard'=>achbotGatewayAdminMarkup([
                [['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.909c78e8f7'),'callback_data'=>'gr|d|'.$prefix.'|'.$nonce]],
                [['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.fa7af603d4'),'callback_data'=>'gr|x|'.$nonce]]
            ])];
    }
    if ($r===null) {
        return ['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.method_label_category_label.2d180b42ba', ['methodLabel' => $methodLabel, 'categoryLabel' => $categoryLabel]).
            \achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.c050f2e620').
            \achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.9a38991a3b'),
            'keyboard'=>achbotGatewayAdminMarkup([
                [['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.f9e2c2e01e'),'callback_data'=>'gr|a|'.$prefix]],
                ...$ruleBack])];
    }
    if ($p[1]==='f' && count($p)===5 &&
        array_key_exists($p[4],achbotGatewayRuleMetrics())) {
        $metric=$p[4];$v=$r['metrics'][$metric];
        $tag=achbotGatewayRuleControlTag($r);
        $text=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.category_label.5c0303d4dc', ['categoryLabel' => $categoryLabel]).achbotGatewayRuleMetrics()[$metric]."\n";
        $text.=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.b35dd70a37').$v['count'].\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.930aa8fb30').$v['days'].
            \achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.e943a81156').($v['direction']==='older'?\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.12186b2949'):\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.c6bdd72afe'))."\n";
        $text.=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.3153c0f2f5');
        $text.=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.0d5b2ce24e');
        return ['text'=>$text,'keyboard'=>achbotGatewayAdminMarkup([
            [['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.4402a7a7da'),'callback_data'=>'gr|n|'.$prefix.'|'.$metric.'|count|'.$tag]],
            [['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.38748be977'),'callback_data'=>'gr|n|'.$prefix.'|'.$metric.'|days|'.$tag]],
            [['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.74c39e45b8'),'callback_data'=>'gr|v|'.$prefix.'|'.$metric.'|'.$tag]],
            [['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.dd5c4f32ce'),'callback_data'=>'gr|c|'.$prefix.'|'.$metric.'|'.$tag]],
            [['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.2fe0521c21'),'callback_data'=>'gr|r|'.$prefix]]
        ])];
    }
    $text=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.method_label_category_label.f136e472ba', ['methodLabel' => $methodLabel, 'categoryLabel' => $categoryLabel]);
    $text.=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.c5d6fc3ebd').($r['enabled']?\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_method_flags.text.a3fa2a3ffc'):\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.4e67bd8e34')).
        \achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.633cdaf049').$r['registration_age_days'].\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.f91c474748');
    $text.=\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.03d7bf8c63');
    foreach (achbotGatewayRuleMetrics() as $key=>$label) {
        $v=$r['metrics'][$key];
        $value=$v['count'].\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.103d12a4c3').$v['days'].\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.69ff4a27e5');
        $text.=$label.': '.$value.' ('.($v['direction']==='older'?\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.12186b2949'):\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.c6bdd72afe')).")\n";
    }
    $tag=achbotGatewayRuleControlTag($r);
    $rows=[[['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.02bfa8380c'),'callback_data'=>'gr|n|'.$prefix.'|age|days|'.$tag]]];
    foreach (achbotGatewayRuleMetrics() as $metric=>$label)
        $rows[]=[['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.1e53a1442f').$label,'callback_data'=>'gr|f|'.$prefix.'|'.$metric]];
    $rows[]=[['text'=>$r['enabled']?\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.fe79dd4aa2'):\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.9dda803abf'),
               'callback_data'=>'gr|t|'.$prefix.'|'.$tag]];
    $rows[]=[['text'=>\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.button.661846f2c0'),'callback_data'=>'gr|q|'.$prefix]];
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
