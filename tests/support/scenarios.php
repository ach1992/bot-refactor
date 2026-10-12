<?php
// Each branch calls functions extracted byte-for-byte from the current application.
// Assertions pin important semantics; reviewed goldens additionally pin ordered SQL, bindings and messages.
$connection=new mysqli();
[$family,$variant]=explode('.',$scenario,2);
$pay=['id'=>31,'hash_id'=>'fixturehash1','user_id'=>1001,'type'=>'BUY_SUB','price'=>12000,'state'=>'pending',
      'plan_id'=>101,'server_id'=>42,'order_id'=>0,'request_date'=>time()-60];
$success=(object)['success'=>true,'obj'=>(object)['id'=>81]];
$botState += ['sharedExistence'=>'off','individualExistence'=>'off'];
$userInfo += ['freetrial'=>null,'agent_date'=>'1700000000'];
$mainValues=replaceAllPlaceholdersInMainValues($mainValues,$botState,$from_id);

if ($family==='pure') {
    $r=[];
    $r['retry']=[tgRetryAfter(null),tgRetryAfter((object)['ok'=>true]),tgRetryAfter((object)['error_code'=>429]),
                tgRetryAfter((object)['error_code'=>429,'parameters'=>(object)['retry_after'=>17]])];
    achExpect($r['retry']===[null,null,1,17],'Telegram retry contract');
    foreach(['','@fixture_support','fixture_support','https://t.me/fixture_support','https://example.invalid'] as $s) $r['support'][]=getSupportUrl(['supportID'=>$s],'fallback');
    $r['markup']=[normalizeReplyMarkup(null),normalizeReplyMarkup(['inline_keyboard'=>[]]),normalizeReplyMarkup('raw')];
    $r['groups']=[groupFa('all'),groupFa('agents'),groupFa('users'),groupFa('unknown')];
    foreach([0,1,2,3,4,99] as $st) $r['states'][]=stateFa($st);
    foreach([0,1024,1073741824,1073741825,2147483648] as $n) $r['sizes'][]=[sumerize($n),sumerize2($n)];
    $r['placeholders']=replaceAllPlaceholdersInMainValues(['a'=>'SUPPORTID LOCKCHANNEL userid','b'=>['userid',7]],$botState,1001);
    $r['types']=array_map('payTypeToDescription',['BUY_SUB','RENEW_ACCOUNT','RENEW_SCONFIG','INCREASE_WALLET','INCREASE_DAY_1_2','INCREASE_VOLUME_1_2','OTHER']);
    $r['forward']=[isForwardedMessageObj((object)[]),isForwardedMessageObj((object)['forward_origin'=>(object)[]])];
    $r['api']=[apiOk((object)['ok'=>true]),apiOk(null),getApiMessageId((object)['result'=>(object)['message_id'=>501]])];
    $GLOBALS['ach_result']=$r;
} elseif ($family==='price') {
    $info=['discount_percent'=>json_encode(['normal'=>10,'plans'=>[101=>20],'servers'=>[42=>30]])];
    $state=['agencyPlanDiscount'=>$variant==='plan'?'on':'off'];
    if($variant==='malformed') $info['discount_percent']='invalid-json';
    if($variant==='over100_legacy') $info['discount_percent']='{"normal":110}';
    $r=calculateAgentPrice($info,$state,101,42,$variant==='customer'?'normal':'one',10000);
    $expected=['customer'=>10000,'plan'=>8000,'server'=>7000,'malformed'=>10000,'over100_legacy'=>-1000];
    achExpect($r[0]===$expected[$variant] || $r[0]==(float)$expected[$variant],'agent price'); $GLOBALS['ach_result']=$r;
} elseif ($family==='remark') {
    $r=buildRemark(['remark'=>$variant],'fixture','manual-label',1001,$variant==='forced'?'forced-label':null);
    if($variant==='manual') achExpect($r==='manual-label','manual remark');
    if($variant==='forced') achExpect($r==='forced-label','forced remark');
    $GLOBALS['ach_result']=$r;
} elseif ($family==='port') {
    if($variant!=='missing') file_put_contents('port-fixture.txt','10000-8');
    $r=getNextPortAndIncrease('port-fixture.txt',$variant==='manual'?'manual':'auto');
    $GLOBALS['ach_result']=['returned'=>$r,'file'=>is_file('port-fixture.txt')?file_get_contents('port-fixture.txt'):null];
    if($variant==='auto') achExpect($r===[10001,9],'port cursor');
} elseif ($family==='wallet') {
    $states=['already'=>'paid_with_wallet','busy'=>'processing_wallet','approved'=>'approved','declined'=>'declined',
             'expired_sent'=>'expired_have_sent','expired_legacy'=>'expired'];
    $pay['state']=$states[$variant] ?? 'pending';
    if($variant==='foreign_owner_legacy') $pay['user_id']=2002;
    achRead('/^SELECT \* FROM `pays`.*FOR UPDATE$/',$variant==='missing'?[]:[$pay]);
    $early=['missing','already','busy','approved','declined','expired_sent'];
    if(!in_array($variant,$early,true)) achRead('/^SELECT `wallet`, `refered_by` FROM `users`.*FOR UPDATE$/',[
       ['wallet'=>$variant==='low'?100:50000,'refered_by'=>2002]]);
    if($variant==='write_exception') $GLOBALS['ach_write_failure']='/UPDATE `users`/';
    $handler=function($payment,$user) use($variant) {
        achEvent('business.handler',['pay_id'=>$payment['id'],'wallet_before'=>$user['wallet']]);
        if($variant==='handler_exception') throw new Exception('fixture-business-failure');
        return $variant!=='business_false';
    };
    $r=processWalletPayment($connection,'fixturehash1',$handler);
    $expected=['missing'=>'not_found','already'=>'already_paid','busy'=>'in_progress','approved'=>'invalid_state','declined'=>'invalid_state',
       'expired_sent'=>'invalid_state','low'=>'low_wallet','business_false'=>'business_failed','handler_exception'=>'error','write_exception'=>'error'];
    achExpect($r['status']===($expected[$variant]??'ok'),'wallet status');
    $tx=array_values(array_filter(array_column($GLOBALS['ach_trace'],'kind'),fn($v)=>in_array($v,['db.begin','db.commit','db.rollback'],true)));
    $commit=in_array($variant,['low','success','expired_legacy','foreign_owner_legacy'],true);
    achExpect($tx===['db.begin',$commit?'db.commit':'db.rollback'],'wallet transaction boundary');
    $GLOBALS['ach_result']=$r;
} elseif ($family==='capacity') {
    if($variant==='inbound_empty') { ensureServerCapacity($connection,42,9,0,$mainValues); achFail('Expected capacity exit'); }
    achRead('/^SELECT \* FROM `server_info`/',$variant==='server_empty'?[]:[['id'=>42,'ucount'=>'3']]);
    $r=ensureServerCapacity($connection,42,0,0,$mainValues);
    if($variant==='server_empty') achFail('Expected server-capacity exit');
    achExpect($r['ucount']==='3','existing varchar capacity preserved'); $GLOBALS['ach_result']=$r;
} elseif ($family==='provision') {
    $isMarzban=str_starts_with($variant,'marzban');
    $inbound=str_starts_with($variant,'inbound')?9:0;
    $name=$inbound?'addInboundAccount':($isMarzban?'addMarzbanUser':'addUser');
    $duplicate=str_contains($variant,'duplicate')||$variant==='xui_port';
    if($duplicate) $GLOBALS['ach_panel'][]=[$name,(object)['success'=>false,'msg'=>$isMarzban?'User already exists':($variant==='xui_port'?'Port already exists':'Duplicate email')]];
    $GLOBALS['ach_panel'][]=[$name,$success];
    $r=createServerAccount($isMarzban?'marzban':'xui',42,$inbound,101,'fixture-remark',10,31,'fixture-uuid','vless',12345,1802678400000,'ws',false,1);
    achExpect($r[0]->success===true,'provision response');
    achExpect(count(array_filter($GLOBALS['ach_trace'],fn($e)=>$e['kind']==='panel.fake'))===($duplicate?2:1),'provision retry count');
    $GLOBALS['ach_result']=$r;
} elseif ($family==='renew') {
    achRead('/^SELECT \* FROM `server_info`/',[['id'=>42,'title'=>'fixture-server']]);
    achRead('/^SELECT \* FROM `server_config`/',[['id'=>42,'type'=>$variant==='marzban'?'marzban':'xui']]);
    $name=$variant==='marzban'?'editMarzbanConfig':($variant==='client'?'editClientTraffic':'editInboundTraffic');
    $GLOBALS['ach_panel'][]=[$name,$success];
    $GLOBALS['ach_result']=renewConfigOnServer($connection,42,$variant==='client'?9:0,'fixture-uuid','fixture-remark',31,10,false);
} elseif ($family==='account_error') {
    $responses=['null'=>null,'inbound'=>'inbound not Found','response'=>(object)['success'=>false,'msg'=>'fixture-error'],'array_success'=>['success'=>true]];
    try { ensureAccountCreationSucceeded($responses[$variant],['title'=>'fixture-server'],9,9001,true); $r='accepted'; }
    catch(Exception $e) { $r=$e->getMessage(); }
    achExpect(($r==='accepted')===($variant==='array_success'),'account error contract'); $GLOBALS['ach_result']=$r;
} elseif ($family==='order') {
    if($variant==='insert') insertOrder($connection,1001,'fixture-sub-token',101,42,9,'fixture-remark','fixture-uuid','vless',1802678400,'["vless://fixture"]',12000,time(),0,1);
    elseif($variant==='increase') createIncreaseOrder($connection,1001,42,9,'fixture-remark',12000,time(),null,31);
    elseif($variant==='capacity') { decreaseServerCapacity($connection,42,0,101,2); decreaseServerCapacity($connection,42,9,101,3); decreaseServerCapacity($connection,42,0,101,0); }
    elseif($variant==='plan') {
       achRead('/^SELECT \* FROM `server_plans`/',[['id'=>101,'days'=>31.5,'volume'=>10.5,'price'=>12000]]);
       $r=getPlanDetails($connection,101); achExpect($r['days']===31 && $r['volume']===10,'legacy truncation of fractional days/volume'); $GLOBALS['ach_result']=$r;
    }
} elseif ($family==='referral') {
    if($variant==='wallet_skip') { giveReferralReward($connection,1001,2002,9001,$botState,'wallet'); achExpect(count($GLOBALS['ach_trace'])===1,'wallet skips referral query'); }
    else {
        achRead('/INVITE_BANNER_AMOUNT/',[['value'=>'300']]);
        achRead('/^SELECT `wallet` FROM `users`/',[['wallet'=>700]]);
        giveReferralReward($connection,1001,2002,9001,$botState,'external');
    }
} elseif ($family==='discount') {
    achRead('/^SELECT \* FROM `pays`/',$variant==='missing_pay'?[]:[$pay]);
    if($variant!=='missing_pay') {
        $d=['id'=>41,'hash_id'=>'FIXTURE20','amount'=>20,'type'=>'percent','expire_count'=>10,'can_use'=>1,'min_price'=>0,'max_discount'=>null,
            'audience_type'=>'all','audience_user_id'=>null,'target_type'=>'all','target_id'=>null,'used_by'=>null];
        if($variant==='wrong_audience') { $d['audience_type']='user'; $d['audience_user_id']=2002; }
        if($variant==='minprice') $d['min_price']=20000;
        if($variant==='used') $d['used_by']='[1001]';
        if($variant==='fixed_cap') { $d['type']='fixed'; $d['amount']=5000; $d['max_discount']=1500; }
        if($variant==='unlimited') { $d['expire_count']=-1; $d['can_use']=-1; $d['used_by']='[1001]'; }
        if($variant==='wrong_target') { $d['target_type']='server'; $d['target_id']=43; }
        if($variant==='zero_clamp') { $d['type']='fixed'; $d['amount']=20000; }
        achRead('/^SELECT \* FROM `discounts`/',$variant==='missing_code'?[]:[$d]);
        if($variant!=='missing_code') {
            achRead('/^SELECT `is_agent` FROM `users`/',[['is_agent'=>0]]);
            if($variant==='wrong_target') achRead('/^SELECT `server_id`, `catid` FROM `server_plans`/',[['server_id'=>42,'catid'=>1]]);
        }
    }
    $r=applyDiscountToPay($connection,31,'FIXTURE20',1001,'fixture_user','Fixture',9001,$mainValues,$botState);
    $ok=in_array($variant,['percent','fixed_cap','unlimited','zero_clamp'],true);
    achExpect($r['success']===$ok,'discount acceptance');
    if($ok) achExpect($r['price']===($variant==='fixed_cap'?10500:($variant==='zero_clamp'?0:9600)) || $r['price']==(float)($variant==='fixed_cap'?10500:($variant==='zero_clamp'?0:9600)),'discount arithmetic');
    $GLOBALS['ach_result']=$r;
} elseif ($family==='receipt') {
    if($variant==='current') $pay['state']='have_sent';
    else {
        if($variant==='renew') $pay['type']='RENEW_ACCOUNT';
        $age=['recent_buy'=>599,'boundary_buy'=>600,'renew'=>3599,'declined'=>179,'decline_boundary'=>180,'have_sent'=>21599,'none'=>30000][$variant];
        $state=in_array($variant,['declined','decline_boundary'],true)?'declined':($variant==='have_sent'?'have_sent':'approved');
        achRead('/^SELECT `state`, `request_date` FROM `pays`/',[['state'=>$state,'request_date'=>time()-$age]]);
    }
    $r=duplicateReceiptGuard($connection,$pay,time());
    achExpect(($r===null)===in_array($variant,['boundary_buy','decline_boundary','none'],true),'receipt windows'); $GLOBALS['ach_result']=$r;
} elseif ($family==='cart') {
    $price=$variant==='low'?49999:($variant==='usdt'?12000000:50000);
    if(!in_array($variant,['low','usdt'],true)) {
        $joined=in_array($variant,['old_one','old_two'],true)?1700000000:(time()-10*86400);
        if($variant==='new') $joined=time();
        achRead('/^SELECT `date`, `is_agent` FROM `users`/',[['date'=>(string)$joined,'is_agent'=>$variant==='agent'?1:0]]);
        $total=['new'=>0,'agent'=>0,'old_one'=>1,'old_two'=>2,'ten_days_three'=>3,'ten_days_two'=>2][$variant];
        achRead('/COUNT\(\*\) AS approved_total/',[['approved_total'=>$total,'approved_before_cutoff'=>$variant==='old_one'?1:0]]);
    }
    $r=resolveCartToCartMessageType($price,1001);
    $expected=in_array($variant,['low','new','ten_days_two'],true)?'gift':($variant==='usdt'?'usdt':'detail');
    achExpect($r===$expected,'card-transfer tier'); $GLOBALS['ach_result']=$r;
} elseif ($family==='menu') {
    if(in_array($variant,['regular_admin','special_admin'],true)) {
        $from_id=$variant==='special_admin'?9001:9002; $userInfo['isAdmin']=1;
        $r=json_decode(getAdminKeys(),true);
        $flat=json_encode($r); achExpect(str_contains($flat,'botReports')===($variant==='special_admin'),'special admin menu');
    } else {
        if($variant==='agent') { $botState['agencyState']='on'; $userInfo['is_agent']=1; }
        if($variant==='sale_off') $botState['sellState']='off';
        if($variant==='admin_main') { $from_id=9001; $userInfo['isAdmin']=1; }
        achRead('/MAIN_BUTTONS/',$variant==='custom'?[['id'=>88,'type'=>'MAIN_BUTTONSFixture Link','value'=>'fixture']]:[]);
        $r=json_decode(getMainKeys(),true);
        if($variant==='agent') achExpect(str_contains(json_encode($r),'agentConfigsList'),'agent subscriptions');
        if($variant==='sale_off') achExpect(!str_contains(json_encode($r),'buySubscription'),'sale-off menu');
    }
    $GLOBALS['ach_result']=$r;
} elseif ($family==='command') {
    if($variant==='buy_off') $botState['sellState']='off';
    if($variant==='gateways_off') { $botState['cartToCartState']='off'; $botState['walletState']='off'; }
    if($variant==='agent_buy'||$variant==='agent_mysubs') { $botState['agencyState']='on'; $userInfo['is_agent']=1; }
    $cmd=str_contains($variant,'mysubs')?'/mysubs':($variant==='unknown'?'/unknown':'/buy');
    $r=getCallbackFromCommand($cmd);
    if(in_array($variant,['buy_off','gateways_off','agent_buy'],true)) achFail('Expected command exit');
    $GLOBALS['ach_result']=$r;
} elseif ($family==='broadcast') {
    if($variant==='helpers') {
        hbUpdate($connection,'bc_jobs',51,80); markPaused($connection,'bc_jobs',51,80);
        markCancelled($connection,'send_list',51,80); markFinished($connection,'send_list',51,80);
    } else {
        achRead('/^SELECT `state`/',[['state'=>$variant==='cancelled'?4:2]]);
        $r=isCancelled($connection,'bc_jobs',51); achExpect($r===($variant==='cancelled'),'broadcast cancellation'); $GLOBALS['ach_result']=$r;
    }
} elseif ($family==='transport') {
    if($variant==='429') achHttp('sendMessage',['ok'=>false,'error_code'=>429,'parameters'=>['retry_after'=>7]],429);
    elseif($variant==='failure') achHttp('sendMessage','',0,28);
    elseif($variant==='500') achHttp('sendMessage',['ok'=>false],500);
    elseif($variant==='bad_json') achHttp('sendMessage','not-json');
    elseif($variant==='multipart') {
        file_put_contents('fixture.txt','fixture');
        $GLOBALS['ach_result']=sendFileToTelegram('sendDocument',['chat_id'=>1001,'document'=>new CURLFile('fixture.txt'),'reply_markup'=>['inline_keyboard'=>[]]]);
        return;
    }
    $r=bot('sendMessage',['chat_id'=>1001,'text'=>'fixture','reply_markup'=>'{"inline_keyboard":[]}']);
    if($variant==='failure'||$variant==='bad_json') achExpect($r===null,'transport null contract');
    if($variant==='429') achExpect($r->parameters->retry_after===7,'429 without hidden retry');
    $GLOBALS['ach_result']=$r;
} else { achFail('Unknown scenario: '.$scenario); }
