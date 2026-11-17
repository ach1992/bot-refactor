<?php
/**
 * Durable first-send customer notice and ordinary admin report for a
 * recovered ORIGINAL Wallet DAY/VOLUME effect. No financial/Native writes
 * live in these dispatchers, and Telegram is never called inside SQL.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/DeliveryLedger.php';
require_once __DIR__.'/GatewayClient.php';
require_once __DIR__.'/../../messages/loader.php';

/** Caller holds the original pays row and the local terminal COMMIT. */
function achbotWalletAddonMarkRecoveryNoticesLocked(mysqli $db,array $claim): void {
    $pay=$claim['pay'];$order=$claim['order'];
    $id=(int)$pay['id'];$uid=(int)$pay['user_id'];
    $oid=(int)$claim['order_id'];$sid=(int)$pay['server_id'];
    $kind=(string)$claim['kind'];$units=(int)$claim['units'];
    $amount=(int)$pay['price'];$hash=(string)$pay['hash_id'];
    if ($id<=0 || $uid<=0 || $oid<=0 || $sid<=0 || $amount<=0 ||
        !in_array($kind,['day','volume'],true) || $units<=0 ||
        (int)$order['userid']!==$uid || (int)$order['server_id']!==$sid ||
        !achbotDeliverySchemaReady($db))
        throw new RuntimeException('wallet_recovery_notice_preflight_invalid');
    $tx=$db->query('SELECT @@in_transaction AS active');
    if (!$tx || (int)($tx->fetch_assoc()['active']??0)!==1)
        throw new RuntimeException('wallet_recovery_notice_transaction_missing');
    $q=$db->prepare('SELECT id FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
    $q->bind_param('i',$id);$q->execute();
    $previous=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if ($previous!==[])
        throw new RuntimeException('wallet_recovery_notice_attempt_exists');

    $proof=hash('sha256',$claim['fingerprint'].'|'.
        (string)$claim['account']['id'].'|'.(string)$claim['absolute']['expire'].'|'.
        (string)$claim['absolute']['data_limit']);
    $sealed=achbotPaymentEvidenceProtect('wallet-addon-notification:'.$id,[
        'version'=>1,'purpose'=>'original_applied_wallet_addon_notice',
        'pay_id'=>$id,'order_id'=>$oid,'pay_sha256'=>achbotPaymentEvidencePayFingerprint($pay),
        'native_proof_digest'=>$proof,'historical_debit_independently_audited'=>false,
        'units'=>$units,'kind'=>$kind,'recorded_at'=>gmdate('c')]);
    $provider='wallet';$quote=sprintf('%d.00000000',$amount);
    $currency='toman';$stage='completed';$status='wallet_reconciled_notice';
    $verified='verified';
    $q=$db->prepare('INSERT INTO payment_attempts
       (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,
        stage,provider_status,evidence_fulfillment_state,evidence_fulfillment_payload)
       VALUES (?,?,?,?,?,?,?,?,?,?)');
    $q->bind_param('issssissss',$id,$provider,$hash,$quote,$currency,
        $amount,$stage,$status,$verified,$sealed);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('wallet_recovery_notice_attempt_insert');
    $aid=(int)$db->insert_id;$q->close();
    $q=$db->prepare('SELECT * FROM payment_attempts WHERE id=? FOR UPDATE');
    $q->bind_param('i',$aid);$q->execute();
    $a=$q->get_result()->fetch_assoc();$q->close();
    if (!$a || (int)$a['pay_row_id']!==$id || $a['provider']!=='wallet')
        throw new RuntimeException('wallet_recovery_notice_attempt_readback');
    $userText=achbotMessage($kind==='day'
        ?'renewal.extensions.message.cce260f97e'
        :'renewal.extensions.message.2d27ba4eea',['volume'=>$units]);
    achbotDeliveryInsertTextLocked($db,$pay,$a,$userText,'HTML',
        $oid,$sid,hash('sha256',$sealed));

    $q=$db->prepare('SELECT name,username FROM users WHERE userid=?');
    $q->bind_param('i',$uid);$q->execute();
    $users=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($users)!==1)
        throw new RuntimeException('wallet_recovery_notice_customer_unavailable');
    $report=achbotMessageHtml($kind==='day'
        ?'renewal.extensions.msg.bfc1b46322'
        :'renewal.extensions.msg.0e12e9ce3d',[
        'from_id'=>$uid,'first_name'=>(string)($users[0]['name']??''),
        'username'=>(string)($users[0]['username']??''),
        'remark'=>(string)$order['remark'],'volume'=>$units,'price'=>$amount]);
    $button=$kind==='day'
        ?'renewal.extensions.button.aba3054570'
        :'renewal.extensions.button.9a3cb8176f';
    $keys=json_encode(['inline_keyboard'=>[
        [['text'=>achbotMessage($button),'callback_data'=>'wizwizch']],
        [['text'=>achbotMessage('renewal.extensions.hash_id.2899228604',
            ['hash_id'=>$hash]),'callback_data'=>'noop_'.$hash]]
    ]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $notice=achbotPaymentEvidenceProtect('wallet-addon-admin-report:'.$id,[
        'version'=>1,'pay_id'=>$id,'order_id'=>$oid,
        'pay_sha256'=>achbotPaymentEvidencePayFingerprint($pay),
        'kind'=>$kind,'report_text'=>$report,'report_buttons'=>$keys]);
    $type='WALLET_ADDON_REPORT:'.$id;
    $q=$db->prepare('SELECT id FROM setting WHERE type=? FOR UPDATE');
    $q->bind_param('s',$type);$q->execute();
    $prior=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if ($prior!==[]) throw new RuntimeException('wallet_recovery_notice_report_exists');
    $value=json_encode(['version'=>1,'pay_id'=>$id,'state'=>'pending',
        'sealed'=>$notice,'attempts'=>0],JSON_THROW_ON_ERROR);
    $q=$db->prepare('INSERT INTO setting(type,value) VALUES (?,?)');
    $q->bind_param('ss',$type,$value);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('wallet_recovery_notice_report_insert');
    $q->close();
}

/** Never create a second admin report; claim once before any Telegram HTTP. */
function achbotWalletAddonDispatchReport(mysqli $db,int $payId): string {
    if ($payId<=0) return 'unavailable';
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    if ($lease!=='owned') return $lease;
    $begun=false;$type='WALLET_ADDON_REPORT:'.$payId;$marker=0;$claimed='';
    try {
        try {
            if (!$db->begin_transaction()) throw new RuntimeException('wallet_report_begin');
            $begun=true;
            $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
            $q=$db->prepare('SELECT id,value FROM setting WHERE type=? FOR UPDATE');
            $q->bind_param('s',$type);$q->execute();
            $markers=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            if (count($markers)!==1 || !$pay || $pay['state']!=='paid_with_wallet' ||
                !in_array(true,[
                    preg_match('/^INCREASE_DAY_[1-9][0-9]*_[1-9][0-9]*$/D',$pay['type'])===1,
                    preg_match('/^INCREASE_VOLUME_[1-9][0-9]*_[1-9][0-9]*$/D',$pay['type'])===1],true))
                throw new RuntimeException('wallet_report_identity_missing');
            $marker=(int)$markers[0]['id'];
            $meta=json_decode($markers[0]['value'],true,64,JSON_THROW_ON_ERROR);
            if (($meta['version']??null)!==1 ||
                ($meta['pay_id']??null)!==$payId ||
                ($meta['state']??null)!=='pending' ||
                ($meta['attempts']??null)!==0 ||
                !is_string($meta['sealed']??null))
                throw new RuntimeException('wallet_report_not_pending');
            $packet=achbotPaymentEvidenceUnprotect(
                'wallet-addon-admin-report:'.$payId,$meta['sealed']);
            if (!is_array($packet) || ($packet['version']??null)!==1 ||
                ($packet['pay_id']??null)!==$payId ||
                ($packet['order_id']??null)!==(int)$pay['order_id'] ||
                ($packet['pay_sha256']??null)!==achbotPaymentEvidencePayFingerprint($pay) ||
                !in_array($packet['kind']??null,['day','volume'],true) ||
                !is_string($packet['report_text']??null) ||
                !is_string($packet['report_buttons']??null))
                throw new RuntimeException('wallet_report_packet_invalid');
            $q=$db->prepare('SELECT state FROM payment_delivery_intents
                WHERE pay_row_id=? AND ordinal=1 FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();
            $delivery=$q->get_result()->fetch_assoc();$q->close();
            if (!$delivery || !in_array($delivery['state'],
                ['pending','dispatching','unknown','acknowledged'],true))
                throw new RuntimeException('wallet_report_customer_intent_missing');
            $meta['state']='dispatching';$meta['attempts']=1;
            $claimed=json_encode($meta,JSON_THROW_ON_ERROR);
            $q=$db->prepare('UPDATE setting SET value=? WHERE id=? AND value=?');
            $prior=$markers[0]['value'];
            $q->bind_param('sis',$claimed,$marker,$prior);
            if (!$q->execute() || $q->affected_rows!==1)
                throw new RuntimeException('wallet_report_claim_failed');
            $q->close();
            if (!$db->commit()) throw new RuntimeException('wallet_report_claim_commit_unknown');
            $begun=false;
        } catch (Throwable $error) {
            if ($begun) try {$db->rollback();} catch (Throwable $ignored) {}
            $reason=(string)$error->getMessage();
            if (preg_match('/^[a-z][a-z0-9_]{3,95}$/D',$reason)!==1)
                $reason='internal_error';
            achbotDiagnosticEvent('achbot.finance.wallet_report_deferred',
                ['pay_id'=>$payId,'reason'=>$reason],'warning');
            return 'review';
        }
        // No transaction spans Telegram. If the network reply is lost, do not
        // automatically resend a possibly delivered administrator report.
        $ack=false;
        try {
            if (function_exists('achbotNotifyAdmins') &&
                function_exists('achbotAdminFanoutAcknowledged')) {
                $sent=achbotNotifyAdmins('sendMessage',[
                    'text'=>$packet['report_text'],
                    'reply_markup'=>$packet['report_buttons'],
                    'parse_mode'=>'HTML']);
                $ack=achbotAdminFanoutAcknowledged($sent);
            }
        } catch (Throwable $error) {
            error_log('achbot_wallet_report_telegram_uncertain pay_id='.$payId.
                ' class='.get_class($error));
        }
        $meta['state']=$ack?'acknowledged':'unknown_or_partial';
        $meta['updated_at']=gmdate('c');
        $final=json_encode($meta,JSON_THROW_ON_ERROR);
        $q=$db->prepare('UPDATE setting SET value=? WHERE id=? AND type=? AND value=?');
        $q->bind_param('siss',$final,$marker,$type,$claimed);
        $q->execute();$saved=$q->affected_rows===1;$q->close();
        return $saved?$meta['state']:'unknown';
    } finally {
        achbotOriginalDispatchLeaseEnd($db,$payId,$lease);
    }
}

/**
 * Two independent Cron duties: source-proven applied-only original settlement
 * and separately persisted first-send admin fanout. No hidden total retries:
 * deferrals rotate a soft per-invoice scheduling timestamp.
 */
function achbotWalletAddonMaintain(mysqli $db,int $batch=4): array {
    $stats=['considered'=>0,'settled'=>0,'review'=>0,'reports_ack'=>0];
    $batch=max(1,min($batch,16));$cutoff=time()-180;
    try {
        $q=$db->prepare("SELECT DISTINCT p.id FROM pays p
            JOIN service_effects e ON e.provider_mutation_key=CONCAT('pay:',p.id)
            WHERE p.state='processing_wallet' AND p.request_date<=?
              AND (p.updated_at IS NULL OR p.updated_at<=DATE_SUB(NOW(),INTERVAL 2 MINUTE))
              AND e.provider_state='mutating'
              AND (p.type LIKE 'INCREASE_DAY_%' OR p.type LIKE 'INCREASE_VOLUME_%')
            ORDER BY p.id LIMIT ?");
        $q->bind_param('ii',$cutoff,$batch);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    } catch (Throwable $error) {
        achbotDiagnosticEvent('achbot.finance.wallet_recovery_scan_deferred',
            ['class'=>get_class($error)],'warning');
        return $stats;
    }
    foreach($rows as $row) {
        $id=(int)$row['id'];$stats['considered']++;
        $lease=achbotOriginalDispatchLeaseBegin($db,$id);
        if ($lease!=='owned') { $stats['review']++;continue; }
        try {
            $result=achbotWalletPgResumeAppliedSystem($db,$id);
            if (($result['status']??null)==='settled_delivery_pending') {
                $stats['settled']++;
            } elseif (($result['status']??null)!=='already_paid_state') {
                $stats['review']++;
                $q=$db->prepare("UPDATE pays SET updated_at=NOW()
                    WHERE id=? AND state='processing_wallet'");
                $q->bind_param('i',$id);$q->execute();$q->close();
            }
        } finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease); }
    }
    return $stats;
}
function achbotWalletAddonMaintainReports(mysqli $db,int $batch=4): array {
    $stats=['pending'=>0,'acknowledged'=>0,'unknown'=>0];
    $batch=max(1,min($batch,16));
    try {
        $q=$db->prepare("SELECT id,type FROM setting
            WHERE type LIKE 'WALLET_ADDON_REPORT:%' AND JSON_VALID(value)
            AND JSON_UNQUOTE(JSON_EXTRACT(value,'$.state'))='pending'
            ORDER BY id LIMIT ?");
        $q->bind_param('i',$batch);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    } catch (Throwable $error) {
        achbotDiagnosticEvent('achbot.finance.wallet_report_scan_deferred',
            ['class'=>get_class($error)],'warning');
        return $stats;
    }
    foreach($rows as $row) {
        if (preg_match('/^WALLET_ADDON_REPORT:([1-9][0-9]*)$/D',
            (string)$row['type'],$m)!==1) continue;
        $stats['pending']++;
        $result=achbotWalletAddonDispatchReport($db,(int)$m[1]);
        if ($result==='acknowledged') $stats['acknowledged']++;
        elseif ($result==='unknown_or_partial') $stats['unknown']++;
    }
    return $stats;
}
