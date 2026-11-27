<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


if(in_array($data,['agentOneBuy','buySubscription','agentMuchBuy'],true)){
    $shortcut=($customerShortcut??null)==='buy';
    $buyType=match($data){'agentOneBuy'=>'one','agentMuchBuy'=>'much',default=>'none'};
    $agentShortcut=$shortcut && achbotAgencyActive($userInfo,$botState);
    if (!achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin)){
        if ($shortcut) sendMessage(\achbotMessage('legacy_messages.selling_is_off'),null,'HTML');
        else alert(\achbotMessage('legacy_messages.selling_is_off'));
        return;
    }
    if ($agentShortcut) {
        $keyboard=[[
            ['text'=>\achbotMessage('legacy_buttons.agent_one_buy'),'callback_data'=>'agentOneBuy'],
            ['text'=>\achbotMessage('legacy_buttons.agent_much_buy'),'callback_data'=>'agentMuchBuy']
        ],[['text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>'navHome']]];
        sendMessage(\achbotMessage('admin_settings.config.get_callback_from_command.message.ce7759512b'),json_encode(['inline_keyboard'=>$keyboard]),'HTML');
        return;
    }
    if (!achbotPurchaseModeAllowed($userInfo,$botState,$buyType)) return;
    $plans=achbotCustomerPurchasePlans($connection,$userInfo,$botState,$buyType,(int)$from_id,(int)$admin);
    $servers=[];
    foreach ($plans as $p) $servers[(int)$p['server_id']]=$p;
    ksort($servers);
    if(!$servers){
        if ($shortcut) sendMessage(\achbotMessage('legacy_messages.no_server_available'),null,'HTML');
        else alert(\achbotMessage('legacy_messages.no_server_available'));
        return;
    }
    $keyboard=[];
    foreach($servers as $id=>$p){
        $keyboard[] = [['text'=>$p['server_flag'].' '.$p['server_title'],'callback_data'=>"selectServer{$id}_{$buyType}"]];
    }
    $keyboard[] = [['text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>"navHome"]];
    $markup=json_encode(['inline_keyboard'=>$keyboard]);
    if ($shortcut) sendMessage(\achbotMessage('legacy_messages.buy_sub_select_location'),$markup,'HTML');
    else editText($message_id, \achbotMessage('legacy_messages.buy_sub_select_location'), $markup);
    return;
}

if($data=='createMultipleAccounts' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $servers=[];
    foreach (achbotAvailablePlans($connection,null,null,'admin') as $plan) $servers[(int)$plan['server_id']]=$plan;
    if (!$servers) {
        sendMessage(achbotMessage('legacy_messages.no_server_available'),null,'HTML');
        return;
    }
    ksort($servers);$keyboard=[];
    foreach ($servers as $id=>$plan)
        $keyboard[]=['text'=>$plan['server_flag'].' '.$plan['server_title'],'callback_data'=>'createAccServer'.$id];
    $keyboard[] = ['text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>"managePanel"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, \achbotMessage('legacy_messages.buy_sub_select_location'), json_encode(['inline_keyboard'=>$keyboard]));
    

}
if(preg_match('/\AcreateAccServer([1-9][0-9]*)\z/D',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) ) {
    $sid = $match[1];
        
    $categories=[];
    foreach (achbotAvailablePlans($connection,(int)$sid,null,'admin') as $plan)
        $categories[(int)$plan['catid']]=$plan['category_title'];
    if (!$categories) {
        alert(achbotMessage('legacy_messages.category_not_avilable'));
        return;
    } else {
        ksort($categories);$keyboard=[];
        foreach ($categories as $id=>$name)
            $keyboard[]=['text'=>$name,'callback_data'=>"createAccCategory{$id}_{$sid}"];
        alert(\achbotMessage('legacy_messages.receive_categories'));
        $keyboard[] = ['text' => \achbotMessage('legacy_buttons.back_to_main'), 'callback_data' => "createMultipleAccounts"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, \achbotMessage('legacy_messages.buy_sub_select_category'), json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/\AcreateAccCategory([1-9][0-9]*)_([1-9][0-9]*)\z/D',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $respd=achbotAvailablePlans($connection,(int)$sid,(int)$call_id,'admin');
    if (!$respd) {
        alert(achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.5223e5a747'));
    } else {
        alert(\achbotMessage('legacy_messages.receive_plans'));
        $keyboard = [];
        foreach($respd as $file){
            $id = $file['id'];
            $name = $file['title'];
            $keyboard[] = ['text' => "$name", 'callback_data' => "createAccPlan{$id}"];
        }
        $keyboard[] = ['text' => \achbotMessage('legacy_buttons.back_to_main'), 'callback_data' => "createAccServer$sid"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, \achbotMessage('legacy_messages.buy_sub_select_plan'), json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/\AcreateAccPlan([1-9][0-9]*)\z/D',$data,$match) && $text != \achbotMessage('legacy_buttons.cancel') && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!in_array((int)$match[1],array_map('intval',array_column(achbotAvailablePlans($connection,null,null,'admin'),'id')),true)) {
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.d3fb64c4d9'), true);
        return;
    }
    if (!setUser('createAccDate' . $match[1])) return;
    try {delMessage();} catch(Throwable $ignored){}
    sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.c754e347bf'),$cancelKey, 'HTML');
    return;
}
if(isset($update->message->text) && !isset($update->callback_query) && preg_match('/^createAccDate(\d+)$/D',$userInfo['step'],$match) && $text != \achbotMessage('legacy_buttons.cancel') && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $dayUnits=achbotWholeServiceUnits($text,achbotServiceDaysMax(),true);
    if($dayUnits!==null){
        if($dayUnits>0){
            if (!setUser('createAccVolume' . $match[1] . "_" . $dayUnits)) return;
            sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.f018216ab3'), null, 'HTML');
            return;
        }else{
            sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.9cc4de866d'), null, 'HTML');
        }
    }else{
        sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.141dd787b4'), null, 'HTML');
    }
}
if(isset($update->message->text) && !isset($update->callback_query) && preg_match('/^createAccVolume(\d+)_(\d+)$/D',$userInfo['step'],$match) && $text != \achbotMessage('legacy_buttons.cancel') && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $volumeUnits=achbotWholeServiceUnits($text,achbotServiceVolumeMax(),true);
    if($volumeUnits===null){
        sendMessage(\achbotMessage('legacy_messages.send_only_number'), null, 'HTML');
        exit();
    }elseif($volumeUnits===0){
        sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.f30d6ad265'), null, 'HTML');
        exit();
    }
    if (!setUser("createAccAmount" . $match[1] . "_" . $match[2] . "_" . $volumeUnits)) return;
    sendMessage(\achbotMessage('legacy_messages.enter_account_amount'), null, 'HTML');
    return;
}

if (
    preg_match('/^createAccAmount(\d+)_(\d+)_(\d+)$/D', $userInfo['step'], $match) &&
    $text != \achbotMessage('legacy_buttons.cancel') &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)
) {

    require_once __DIR__.'/../Finance/ServiceEffects.php';
    try {
        $bulkSource=achbotNonPaymentSourceRef(
            $update,(int)$from_id,'admin_bulk',(string)$text,
            (int)$from_id===(int)$admin);
    } catch (Throwable $error) {
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.dd52ddf02f'),true);
        return;
    }

    if (!is_string($text) || preg_match('/^[1-9][0-9]*$/D',$text)!==1 ||
        filter_var($text,FILTER_VALIDATE_INT)===false) {
        sendMessage(\achbotMessage('legacy_messages.send_only_number'), null, 'HTML');
        exit();
    }
    $count = (int)$text;
    if ($count <= 0) {
        sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.f30d6ad265'), null, 'HTML');
        exit();
    }

    $uid = (int)$from_id;
    $fidFromStep    = (int)$match[1];
    $daysFromStep   = (int)$match[2];
    $volumeFromStep = (int)$match[3];

    if (!achbotSupportsPlan($connection, $fidFromStep)) {
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.d3fb64c4d9'), true);
        return;
    }
    $planDetails = getPlanDetails($connection, $fidFromStep);
    $plan        = $planDetails['plan'];
    if (!achbotServerCommerceAvailable($connection,(int)$plan['server_id'],true)) {
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.d3fb64c4d9'),true);
        return;
    }

    $days   = $daysFromStep;
    $volume = $volumeFromStep;

    $date             = time();

    $price      = isset($plan['price']) ? (int)$plan['price'] : 0;
    $server_id  = (int)$plan['server_id'];


    ensureServerCapacity($connection, $server_id);


    [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);

    $srv_remark = $serverInfo['remark'] ?? '';
    $serverType = $serverConfig['type'] ?? '';

    require_once __DIR__.'/../Finance/OperationalReports.php';
    $batchLease=achbotOperationalReportLock($connection,$bulkSource);
    $leaseRow=achbotProviderRow($connection,'SELECT GET_LOCK(?,0) AS acquired','s',[$batchLease]);
    if ((int)($leaseRow['acquired']??0)!==1) return;
    try {
    // A replay cannot rebuild or replace the reports of a prior batch.
    if (achbotProviderRow($connection,"SELECT id FROM service_effects
        WHERE source_kind='admin_bulk' AND source_ref=? LIMIT 1",'s',[$bulkSource])) return;
    require_once __DIR__.'/../Telegram/Conversation.php';
    if (!\AchBot\Telegram\Conversation\transitionEditor($connection,$uid,$userInfo,'none',null,
        static function(mysqli $db) use($uid,$admin): void {
            if (!achbotAdminCanLocked($db,$uid,(int)$admin,'operations'))
                throw new DomainException('bulk_editor_role_changed');
        })) return;
    \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.sending_config_to_user'));
    $pendingDeliveries=[];
    $reportBulk=static function(array $deliveryReview=[]) use (
        $connection,$count,$uid,$bulkSource,$serverInfo,$server_id,
        $plan,$fidFromStep,$days,$volume): array {
        require_once __DIR__.'/BulkReport.php';
        $proof=achbotBulkRecordedAccounts($connection,$count,$uid,$bulkSource);
        $base=achbotMessageHtml('bulk_reporting.admin_created',[
            'actor'=>$uid,'source'=>$bulkSource,
            'server'=>(string)($serverInfo['title']??$server_id),
            'plan'=>(string)($plan['title']??$fidFromStep),
            'days'=>$days,'volume'=>$volume]);
        if ($deliveryReview)
            $base.="\n".achbotMessage('bulk_reporting.delivery_needs_review',
                ['count'=>count($deliveryReview)]);
        $report=achbotBulkReportBuild($base,$proof,true);
        return ['proof'=>$proof,'text'=>$report['summary'],'pages'=>$report['pages']];
    };
    try {
    for ($i = 1; $i <= $count; $i++) {

        $uniqid = generateUID();


        $remark = buildRemark(
            $botState,
            $srv_remark,
            $plan['descr'] ?? '',
            $uid,
            null
        );

        // Keep the configured single-account style, with a stable per-line
        // numeric suffix for independent administrator bulk requests.
        $remarkMode=(string)($botState['remark']??'');
        if ($remarkMode==='manual' && (string)$remark==='') {
            $remarkMode='';
            $remark=buildRemark(['remark'=>''],$srv_remark,'',$uid);
        }
        $remark=achbotAdminBulkAccountRemark(
            (string)$remark,$bulkSource,$i,$count,(string)$serverType,
            $remarkMode);
        [$response, $remark, , $effectId] = achbotCreateNonPaymentAccount(
            $connection,'admin_bulk',$bulkSource,$uid,$i,$count,[
                'user_id'=>$uid,'server_type'=>$serverType,
                'server_id'=>$server_id,
                'plan_id'=>$fidFromStep,'remark'=>$remark,'naming_mode'=>$remarkMode,'volume'=>$volume,
                'days'=>$days
            ]
        );

        ensureAccountCreationSucceeded($response, $serverInfo, $admin);
        $expire_date=(int)$response->expire;

        require_once __DIR__.'/../Subscription/Delivery.php';
        $delivery=achbotNonpaidPurchasePacket($connection,$serverType,$server_id,$serverConfig,
            $botState,(string)($botUsername??''),(string)$botUrl,$remark,$response,$volume,$days,$uid);

        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('admin_bulk_order_begin');
        try {
            $orderId=insertOrder(
                $connection,$uid,'',$fidFromStep,$server_id,
                $remark,$uniqid,$expire_date,$delivery['link'],$price,
                $date,0,true);
            achbotLinkNonPaymentAccountEffect($connection,$effectId,$orderId);
            $deliveryId=achbotNonpaidDeliveryInsertLocked($connection,$effectId,$orderId,$delivery['packet']);
            $line=achbotMessageHtml('bulk_reporting.continuation',['page'=>$i,'reference'=>$bulkSource]).
                "\n".$i.'. <code>'.htmlspecialchars($remark,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code> (#'.$orderId.')';
            achbotOperationalReportStageLocked($connection,$bulkSource,$uid,$uid,'operations',
                $line,null,[],'operational_report_p'.$i);
            if ($connection->commit()!==true)
                throw new RuntimeException('admin_bulk_order_commit_unknown');
        } catch (Throwable $error) {
            try {$connection->rollback();} catch (Throwable $ignored) {}
            throw $error;
        }
        $pendingDeliveries[]=$deliveryId;
    }

    // Capacity was atomically held with first journal dispatch.
    } catch (Throwable $error) {
        $safe=achbotEffectSafeFailure($error);
        error_log('achbot_admin_bulk_effect_needs_review source='.
            preg_replace('/[^A-Za-z0-9_:]/','',$bulkSource).
            ' ordinal='.(int)($i??0).' reason='.$safe['reason'].
            ' class='.get_class($error));
        try {
            $summary=$reportBulk();
            achbotOperationalReportCompactBatch($connection,$bulkSource,$uid,$summary['text'],$summary['pages']);
        } catch (Throwable $notificationError) {
            error_log('achbot_admin_bulk_partial_notice_failed source='.
                preg_replace('/[^A-Za-z0-9_:]/','',$bulkSource).
                ' class='.get_class($notificationError));
        }
        sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.c7ce33a4eb'),null,'HTML');
        return;
    }
    $deliveryReview=[];
    foreach ($pendingDeliveries as $index=>$deliveryId) {
        try {
            if (achbotServiceDeliveryDispatch($connection,$deliveryId)!=='acknowledged')
                throw new RuntimeException('bulk_telegram_unconfirmed');
        } catch (Throwable $error) {
            $deliveryReview[]=$index+1;
            error_log('achbot_admin_bulk_delivery_review source='.
                preg_replace('/[^A-Za-z0-9_:]/','',$bulkSource).
                ' ordinal='.($index+1).' class='.get_class($error));
        }
    }
    try {
        $summary=$reportBulk($deliveryReview);
        achbotOperationalReportCompactBatch($connection,$bulkSource,$uid,$summary['text'],$summary['pages']);
        if (!$summary['proof']['complete'])
            error_log('achbot_admin_bulk_report_incomplete source='.
                preg_replace('/[^A-Za-z0-9_:]/','',$bulkSource));
    } catch (Throwable $error) {
        error_log('achbot_admin_bulk_report_unavailable source='.
            preg_replace('/[^A-Za-z0-9_:]/','',$bulkSource).
            ' class='.get_class($error));
    }
    sendMessage($deliveryReview
        ? achbotMessage('bulk_reporting.delivery_needs_review',
            ['count'=>count($deliveryReview)])
        : achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.62d6e89b94'),
        getMainKeys(), 'HTML');
    } finally {
        try {$s=achbotProviderStatement($connection,'SELECT RELEASE_LOCK(?)','s',[$batchLease]);$s->close();}
        catch (Throwable $ignored) {}
        achbotOperationalReportDispatch($connection,$bulkSource);
    }
}
