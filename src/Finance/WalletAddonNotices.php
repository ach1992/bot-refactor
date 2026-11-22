<?php
/**
 * Durable first-send customer notice and ordinary admin report for a
 * recovered ORIGINAL Wallet RENEW/DAY/VOLUME/BUY_SUB effect. No Native writes
 * live in these dispatchers, and Telegram is never called inside SQL.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/DeliveryLedger.php';
require_once __DIR__.'/GatewayClient.php';
require_once __DIR__.'/../../messages/loader.php';

/**
 * Exact original Wallet service-edit notification dependency preflight.
 * Run BEFORE debit/Native, not after the irreversible external effect.
 * 018 alone is insufficient: 014 adds the evidence payload columns used
 * by the original notification-only attempt. Check the actual writer
 * dependencies rather than requiring unrelated API-provider features.
 */
function achbotWalletAddonNoticesReady(mysqli $db): bool {
    try {
        if (!achbotDeliverySchemaReady($db)) return false;
        foreach (['payment_attempts','setting'] as $table) {
            $status=$db->query("SHOW TABLE STATUS WHERE Name='".$table."'");
            if (!$status || strtolower((string)($status->fetch_assoc()['Engine']??''))!=='innodb')
                return false;
        }
        // Structural SELECT catches a missing 014 column before Wallet debit.
        $probe=$db->query('SELECT id,pay_row_id,provider,order_hash,quote_amount,'.
            'quote_currency,local_amount_toman,stage,provider_status,'.
            'evidence_fulfillment_state,evidence_fulfillment_payload '.
            'FROM payment_attempts LIMIT 0');
        if (!$probe) return false;
        $probe->free();
        $probe=$db->query('SELECT id,type,value FROM setting LIMIT 0');
        if (!$probe) return false;
        $probe->free();
        $columns=[];
        $q=$db->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,
                NUMERIC_PRECISION,NUMERIC_SCALE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_attempts'");
        while ($q && ($c=$q->fetch_assoc()))
            $columns[$c['COLUMN_NAME']]=$c;
        foreach ([
            'id'=>['bigint',0],'pay_row_id'=>['int',0],
            'provider'=>['varchar',6],'order_hash'=>['varchar',40],
            'quote_amount'=>['decimal',0],'quote_currency'=>['varchar',5],
            'local_amount_toman'=>['int',0],'stage'=>['varchar',9],
            'provider_status'=>['varchar',24],
            'evidence_fulfillment_state'=>['varchar',8],
            'evidence_fulfillment_payload'=>['longtext',0]
        ] as $name=>$expect) {
            $col=$columns[$name]??null;
            if (!$col || strtolower((string)$col['DATA_TYPE'])!==$expect[0] ||
                ($expect[1]>0 &&
                    (int)($col['CHARACTER_MAXIMUM_LENGTH']??0)<$expect[1]))
                return false;
        }
        if ((int)($columns['quote_amount']['NUMERIC_SCALE']??-1)<8 ||
            (int)($columns['quote_amount']['NUMERIC_PRECISION']??0)<24)
            return false;
        $unique=[];
        $q=$db->query('SHOW INDEX FROM payment_attempts');
        while ($q && ($idx=$q->fetch_assoc())) {
            if ((int)$idx['Non_unique']===0)
                $unique[$idx['Key_name']][(int)$idx['Seq_in_index']]=$idx['Column_name'];
        }
        foreach ([
            'uq_payment_attempt_pay_row'=>['pay_row_id'],
            'uq_payment_attempt_order_hash'=>['order_hash']
        ] as $name=>$fields) {
            if (!isset($unique[$name])) return false;
            ksort($unique[$name]);
            if (array_values($unique[$name])!==$fields) return false;
        }
        $columns=[];
        $q=$db->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='setting'");
        while ($q && ($c=$q->fetch_assoc()))
            $columns[$c['COLUMN_NAME']]=$c;
        if (!in_array(strtolower((string)($columns['id']['DATA_TYPE']??'')),
                ['int','bigint'],true) ||
            strtolower((string)($columns['type']['DATA_TYPE']??''))!=='varchar' ||
            (int)($columns['type']['CHARACTER_MAXIMUM_LENGTH']??0)<38 ||
            !in_array(strtolower((string)($columns['value']['DATA_TYPE']??'')),
                ['text','mediumtext','longtext'],true))
            return false;
        return true;
    } catch (Throwable $error) { return false; }
}

/** Caller holds the original pays row and the local terminal COMMIT. */
function achbotWalletAddonMarkRecoveryNoticesLocked(mysqli $db,array $claim): void {
    $pay=$claim['pay'];$order=$claim['order'];
    $id=(int)$pay['id'];$uid=(int)$pay['user_id'];
    $oid=(int)$claim['order_id'];$sid=(int)$pay['server_id'];
    $kind=(string)$claim['kind'];$units=(int)$claim['units'];
    $amount=(int)$pay['price'];$hash=(string)$pay['hash_id'];
    // A verified automatic renewal can have a legitimate 100% discount.
    // It still needs the same durable first-send customer/admin obligations.
    $zeroCostAutoRenew=$amount===0 && $kind==='renew' &&
        is_array($claim['automatic_attempt']??null) &&
        (int)$claim['automatic_attempt']['price_toman']===0;
    if ($id<=0 || $uid<=0 || $oid<=0 || $sid<=0 ||
        ($amount<=0 && !$zeroCostAutoRenew) ||
        !in_array($kind,['renew','day','volume'],true) || $units<=0 ||
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
        'version'=>1,'purpose'=>$kind==='renew'?'original_applied_wallet_renew_notice':
                   'original_applied_wallet_addon_notice',
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
    $userText=$kind==='renew'
        ?achbotMessage('renewal.renewal_checkout.message.cca1075329',
            ['remark'=>htmlspecialchars((string)$order['remark'],
                ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')])
        :achbotMessage($kind==='day'
            ?'renewal.extensions.message.cce260f97e'
            :'renewal.extensions.message.2d27ba4eea',['volume'=>$units]);
    achbotDeliveryInsertTextLocked($db,$pay,$a,$userText,'HTML',
        $oid,$sid,hash('sha256',$sealed));

    $q=$db->prepare('SELECT name,username FROM users WHERE userid=?');
    $q->bind_param('i',$uid);$q->execute();
    $users=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($users)!==1)
        throw new RuntimeException('wallet_recovery_notice_customer_unavailable');
    if ($kind==='renew') {
        $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $report=strtr(achbotMessage('legacy_messages.renew_account_request_message'),[
            'TYPE'=>achbotMessage('renewal.renewal_checkout.text.6295c35212'),
            'USER-ID'=>(string)$uid,
            'USERNAME'=>$escape($users[0]['username']??''),
            'NAME'=>$escape($users[0]['name']??''),
            'PRICE'=>(string)$amount,
            'REMARK'=>$escape($order['remark']),
            'VOLUME'=>(string)$claim['volume'],
            'DAYS'=>(string)$claim['days']]);
        $button='renewal.renewal_checkout.button.7deb81ef97';
    } else {
        $report=achbotMessageHtml($kind==='day'
            ?'renewal.extensions.msg.bfc1b46322'
            :'renewal.extensions.msg.0e12e9ce3d',[
            'from_id'=>$uid,'first_name'=>(string)($users[0]['name']??''),
            'username'=>(string)($users[0]['username']??''),
            'remark'=>(string)$order['remark'],'volume'=>$units,'price'=>$amount]);
        $button=$kind==='day'
            ?'renewal.extensions.button.aba3054570'
            :'renewal.extensions.button.9a3cb8176f';
    }
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

/**
 * Persist the SAME original successful-purchase report used by Checkout,
 * sourced only from original payment/effect/order proof. Calling transaction
 * inserts both original customer intents and this staff report atomically.
 */
function achbotWalletOriginalBuyMarkAdminReportLocked(mysqli $db,array $claim): void {
    $pay=$claim['pay'];$payId=(int)$pay['id'];$count=(int)$claim['count'];
    if ($payId<=0 || $count<=0 || $pay['type']!=='BUY_SUB' ||
        $pay['state']!=='processing_wallet' ||
        count($claim['lines'])!==$count || count($claim['orders'])!==$count)
        throw new RuntimeException('wallet_buy_report_original_claim_incomplete');
    $tx=$db->query('SELECT @@in_transaction AS active');
    if (!$tx || (int)($tx->fetch_assoc()['active']??0)!==1)
        throw new RuntimeException('wallet_buy_report_transaction_required');

    $uid=(int)$pay['user_id'];$sid=(int)$pay['server_id'];
    $q=$db->prepare('SELECT name,username FROM users WHERE userid=?');
    $q->bind_param('i',$uid);$q->execute();$users=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($users)!==1)
        throw new RuntimeException('wallet_buy_report_subject_missing');
    $days=null;$volume=null;$names=[];
    foreach ($claim['lines'] as $ordinal=>$e) {
        $order=$claim['orders'][$ordinal]??null;
        $original=achbotWalletOriginalBuyEntitlement($e);
        if (!$order || $e['state']!=='local_recorded' ||
            (int)$e['order_id']!==(int)$order['id'] || !$original ||
            (int)$order['userid']!==$uid || (int)$order['server_id']!==$sid ||
            (int)$order['fileid']!==(int)$pay['plan_id'] ||
            (int)$order['amount']!==achbotAllocateOrderAmount(
                (int)$pay['price'],$count,$ordinal) ||
            (int)$order['expire_date']-(int)$order['date']!==$original['days']*86400 ||
            abs((int)$order['expire_date']-$original['expiry'])>60)
            throw new RuntimeException('wallet_buy_report_immutable_quote_unproven');
        if ($days!==null && ($days!==$original['days'] ||
            $volume!==$original['volume']))
            throw new RuntimeException('wallet_buy_report_mixed_original_entitlement');
        $days=$original['days'];$volume=$original['volume'];
        $names[]=['ordinal'=>$ordinal,'name'=>(string)$e['remote_username'],
            'order_id'=>(int)$order['id']];
    }
    $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $hash=(string)$pay['hash_id'];$amount=(int)$pay['price'];
    $name=$count>1?achbotMessage('bulk_reporting.bulk_subject'):$names[0]['name'];
    $report=strtr(achbotMessage('legacy_messages.buy_new_account_request'),[
        // Only original server ID is immutable. Its current display title
        // can change later and would misrepresent an old paid purchase.
        'SERVERNAME'=>'#'.$sid,
        'TYPE'=>achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.6295c35212'),
        'USER-ID'=>(string)$uid,
        'USERNAME'=>$escape($users[0]['username']??''),
        'NAME'=>$escape($users[0]['name']??''),
        'PRICE'=>(string)$amount,'REMARK'=>$escape($name),
        'VOLUME'=>(string)$volume,'DAYS'=>(string)$days]);
    if ($count>1) {
        require_once __DIR__.'/../Purchase/BulkReport.php';
        $report=achbotBulkReportAppend($report,[
            'requested'=>$count,'confirmed'=>$count,'complete'=>true,
            'accounts'=>$names,'reference'=>'#'.$payId],true);
    }
    $buttons=json_encode(['inline_keyboard'=>[
        [['text'=>achbotMessage('payment.checkout.button.e401f3d5a2'),
            'callback_data'=>'wizwizch']],
        [['text'=>achbotMessage('payment.checkout.hash_id.286c7d3faf',
            ['hashId'=>$hash]),'callback_data'=>'noop_'.$hash]]
    ]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $sealed=achbotPaymentEvidenceProtect('wallet-addon-admin-report:'.$payId,[
        'version'=>1,'pay_id'=>$payId,'order_id'=>(int)$pay['order_id'],
        'pay_sha256'=>achbotPaymentEvidencePayFingerprint($pay),
        'kind'=>'buy','count'=>$count,
        'source_proof_sha256'=>$claim['proof'],
        'original_orders'=>array_map(static fn($row)=>[
            'ordinal'=>$row['ordinal'],'order_id'=>$row['order_id'],
            'name'=>$row['name']],$names),
        'report_text'=>$report,'report_buttons'=>$buttons]);
    $type='WALLET_ADDON_REPORT:'.$payId;
    $q=$db->prepare('SELECT id FROM setting WHERE type=? FOR UPDATE');
    $q->bind_param('s',$type);$q->execute();
    $prior=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if ($prior!==[]) throw new RuntimeException('wallet_buy_report_duplicate');
    $value=json_encode(['version'=>1,'pay_id'=>$payId,'state'=>'pending',
        'sealed'=>$sealed,'attempts'=>0],JSON_THROW_ON_ERROR);
    $q=$db->prepare('INSERT INTO setting(type,value) VALUES (?,?)');
    $q->bind_param('ss',$type,$value);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('wallet_buy_report_insert_unconfirmed');
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
            achbotEffectAssertNotArchivedLocked($db,$payId);
            $q=$db->prepare('SELECT id,value FROM setting WHERE type=? FOR UPDATE');
            $q->bind_param('s',$type);$q->execute();
            $markers=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            if (count($markers)!==1 || !$pay || $pay['state']!=='paid_with_wallet' ||
                !in_array(true,[
                    $pay['type']==='RENEW_ACCOUNT',
                    $pay['type']==='BUY_SUB',
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
                !in_array($packet['kind']??null,['renew','day','volume','buy'],true) ||
                (($pay['type']==='BUY_SUB')!==(
                    ($packet['kind']??null)==='buy')) ||
                (($packet['kind']??null)==='buy' &&
                    ($packet['count']??null)!==max(1,(int)$pay['agent_count'])) ||
                !is_string($packet['report_text']??null) ||
                !is_string($packet['report_buttons']??null))
                throw new RuntimeException('wallet_report_packet_invalid');
            $q=$db->prepare('SELECT ordinal,state FROM payment_delivery_intents
                WHERE pay_row_id=? ORDER BY ordinal FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();
            $delivery=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $expected=$pay['type']==='BUY_SUB'?max(1,(int)$pay['agent_count']):1;
            if (count($delivery)!==$expected)
                throw new RuntimeException('wallet_report_customer_intent_missing');
            foreach ($delivery as $i=>$notice) {
                if ((int)$notice['ordinal']!==$i+1 ||
                    !in_array($notice['state'],[
                        'pending','dispatching','unknown','acknowledged'],true))
                    throw new RuntimeException('wallet_report_customer_intent_missing');
            }
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

/** Inspect ONLY a preserved original Wallet administrator report, not a
 * generic Telegram message or payment. This read does not dispatch anything.
 */
function achbotWalletAddonReportCase(mysqli $db,int $payId): ?array {
    if ($payId<=0) return null;
    $type='WALLET_ADDON_REPORT:'.$payId;
    $q=$db->prepare("SELECT p.*,s.id AS marker_id,s.value AS marker_value
        FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id
        JOIN setting s ON s.type=?
        WHERE p.id=? AND p.state='paid_with_wallet' AND a.provider='wallet'
          AND a.review_archived_at IS NULL
        ORDER BY s.id LIMIT 2");
    if (!$q) throw new RuntimeException('wallet_report_case_prepare');
    $q->bind_param('si',$type,$payId);$q->execute();
    $matches=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($matches)!==1) return null;
    $record=$matches[0];
    if (!$record || !in_array(true,[
        $record['type']==='RENEW_ACCOUNT',
        $record['type']==='BUY_SUB',
        preg_match('/^INCREASE_DAY_[1-9][0-9]*_[1-9][0-9]*$/D',(string)$record['type'])===1,
        preg_match('/^INCREASE_VOLUME_[1-9][0-9]*_[1-9][0-9]*$/D',(string)$record['type'])===1],true))
        return null;
    try {
        $meta=json_decode((string)$record['marker_value'],true,64,JSON_THROW_ON_ERROR);
        if (!is_array($meta) || ($meta['version']??null)!==1 ||
            ($meta['pay_id']??null)!==$payId ||
            !in_array(($meta['state']??null),[
                'pending','dispatching','unknown_or_partial','manual_dispatching','acknowledged'],true) ||
            !is_string($meta['sealed']??null) || $meta['sealed']==='' ||
            !is_int($meta['attempts']??null) || $meta['attempts']<0 ||
            !is_int($meta['manual_attempts']??0) || ($meta['manual_attempts']??0)<0)
            return null;
        $packet=achbotPaymentEvidenceUnprotect(
            'wallet-addon-admin-report:'.$payId,$meta['sealed']);
        if (!is_array($packet) || ($packet['version']??null)!==1 ||
            ($packet['pay_id']??null)!==$payId ||
            ($packet['order_id']??null)!==(int)$record['order_id'] ||
            ($packet['pay_sha256']??null)!==achbotPaymentEvidencePayFingerprint($record) ||
            !in_array(($packet['kind']??null),['renew','day','volume','buy'],true) ||
            (($record['type']==='BUY_SUB')!==(
                ($packet['kind']??null)==='buy')) ||
            (($packet['kind']??null)==='buy' &&
                ($packet['count']??null)!==max(1,(int)$record['agent_count'])) ||
            !is_string($packet['report_text']??null) || $packet['report_text']==='' ||
            !is_string($packet['report_buttons']??null))
            return null;
    } catch(Throwable $error) {
        error_log('achbot_wallet_report_case_invalid pay_id='.$payId.
            ' class='.get_class($error));
        return null;
    }
    return ['pay_id'=>$payId,'marker_id'=>(int)$record['marker_id'],
        'state'=>(string)$meta['state'],'attempts'=>$meta['attempts'],
        'manual_attempts'=>$meta['manual_attempts']??0,
        'sealed_digest'=>hash('sha256',$meta['sealed']),
        'value'=>(string)$record['marker_value'],'kind'=>$packet['kind'],
        'order_id'=>(int)$record['order_id']];
}

/** Bound to one actor, original marker and exact persisted report version. */
function achbotWalletAddonReportToken(array $case,int $actor): string {
    if ($actor<=0 || (int)($case['pay_id']??0)<=0 ||
        (int)($case['marker_id']??0)<=0 ||
        !preg_match('/^[a-f0-9]{64}$/D',(string)($case['sealed_digest']??'')))
        throw new RuntimeException('wallet_report_confirmation_invalid');
    return substr(hash_hmac('sha256',implode('|',[
        'wallet-admin-report-confirm-v1',$actor,$case['pay_id'],$case['marker_id'],
        $case['state'],$case['attempts'],$case['manual_attempts'],
        $case['sealed_digest']]),achbotPaymentEvidenceKey()),0,20);
}

/** An explicit staff decision can make at most ONE additional send attempt
 * of the SAME encrypted report after UNKNOWN first fanout. This never claims
 * an old Telegram ACK, reopens finance, or retries Native/Wallet mutations.
 * Retain a crash-mid-send marker for human review, never auto-repeat it.
 */
function achbotWalletAddonReportManualSend(mysqli $db,int $payId,
    int $actor,int $primary,string $token): string {
    if ($payId<=0 || $actor<=0 || $primary<=0 ||
        preg_match('/^[a-f0-9]{20}$/D',$token)!==1) return 'stale';
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    if ($lease!=='owned') return $lease==='busy'||$lease==='borrowed'?'busy':'unavailable';
    $type='WALLET_ADDON_REPORT:'.$payId;$started=false;
    try {
        try {
            $before=achbotWalletAddonReportCase($db,$payId);
            if (!$before || $before['state']!=='unknown_or_partial' ||
                $before['manual_attempts']!==0 ||
                !hash_equals(achbotWalletAddonReportToken($before,$actor),$token))
                return 'stale';
            if (!$db->begin_transaction()) throw new RuntimeException('wallet_report_manual_begin');
            $started=true;
            $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();
            $pay=$q->get_result()->fetch_assoc();$q->close();
            achbotEffectAssertNotArchivedLocked($db,$payId);
            require_once __DIR__.'/../Admin/Access.php';
            if (!achbotAdminCanLocked($db,$actor,$primary,'payments')) {
                $db->rollback();$started=false;return 'denied';
            }
            $q=$db->prepare('SELECT id,value FROM setting WHERE type=? FOR UPDATE');
            $q->bind_param('s',$type);$q->execute();
            $row=$q->get_result()->fetch_assoc();$q->close();
            if (!$pay || $pay['state']!=='paid_with_wallet' || !$row ||
                (int)$row['id']!==$before['marker_id'] ||
                $row['value']!==$before['value']) {
                $db->rollback();$started=false;return 'stale';
            }
            $meta=json_decode($row['value'],true,64,JSON_THROW_ON_ERROR);
            $packet=achbotPaymentEvidenceUnprotect(
                'wallet-addon-admin-report:'.$payId,$meta['sealed']);
            if (!is_array($packet) ||
                ($packet['pay_sha256']??null)!==achbotPaymentEvidencePayFingerprint($pay))
                throw new RuntimeException('wallet_report_manual_original_changed');
            $meta['state']='manual_dispatching';
            $meta['attempts']++;
            $meta['manual_attempts']=1;
            $meta['manual_actor']=$actor;
            $meta['updated_at']=gmdate('c');
            $claimed=json_encode($meta,JSON_THROW_ON_ERROR);
            $q=$db->prepare('UPDATE setting SET value=? WHERE id=? AND value=?');
            $id=$before['marker_id'];$prior=$row['value'];
            $q->bind_param('sis',$claimed,$id,$prior);
            if (!$q->execute() || $q->affected_rows!==1)
                throw new RuntimeException('wallet_report_manual_claim_changed');
            $q->close();
            if (!$db->commit()) throw new RuntimeException('wallet_report_manual_commit_unknown');
            $started=false;
        } catch (Throwable $error) {
            if ($started) try {$db->rollback();} catch(Throwable $ignored) {}
            error_log('achbot_wallet_report_manual_deferred pay_id='.$payId.
                ' actor='.$actor.' reason='.achbotFinanceDiagnosticReason($error));
            return 'unavailable';
        }
        $ack=false;
        try {
            if (function_exists('achbotNotifyAdmins') &&
                function_exists('achbotAdminFanoutAcknowledged')) {
                $result=achbotNotifyAdmins('sendMessage',[
                    'text'=>$packet['report_text'],
                    'reply_markup'=>$packet['report_buttons'],'parse_mode'=>'HTML']);
                $ack=achbotAdminFanoutAcknowledged($result);
            }
        } catch(Throwable $error) {
            error_log('achbot_wallet_report_manual_telegram_unknown pay_id='.$payId.
                ' actor='.$actor.' class='.get_class($error));
        }
        $meta['state']=$ack?'acknowledged':'unknown_or_partial';
        $meta['updated_at']=gmdate('c');
        $final=json_encode($meta,JSON_THROW_ON_ERROR);
        $q=$db->prepare('UPDATE setting SET value=? WHERE id=? AND type=? AND value=?');
        $id=$before['marker_id'];
        $q->bind_param('siss',$final,$id,$type,$claimed);
        $ok=$q->execute() && $q->affected_rows===1;$q->close();
        achbotDiagnosticEvent('achbot.finance.wallet_report_manual_result',[
            'pay_id'=>$payId,'actor_id'=>$actor,'state'=>$ack?'acknowledged':'unknown_or_partial',
            'saved'=>$ok],'info');
        return $ok?$meta['state']:'unavailable';
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
            LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
            WHERE p.state='processing_wallet' AND p.request_date<=?
              AND a.review_archived_at IS NULL
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
        // Original administrator report is not delivered after terminal Archive.
        if (achbotGatewayOriginalArchived($db,(int)$m[1])) continue;
        $stats['pending']++;
        $result=achbotWalletAddonDispatchReport($db,(int)$m[1]);
        if ($result==='acknowledged') $stats['acknowledged']++;
        elseif ($result==='unknown_or_partial') $stats['unknown']++;
    }
    return $stats;
}
