<?php
/** The only automatic-gateway exception screen and its direct callbacks.
 * No separate review journal or recovery workbench is loaded here.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404); exit;
}
require_once __DIR__.'/../../Admin/Access.php';
require_once __DIR__.'/../../../messages/loader.php';

/** Telegram's 4096-character text limit applies after HTML parsing.
 * Conservatively count UTF-16 units so astral emojis cannot overflow.
 */
$gatewayTextLength=static function(string $html): int {
    $plain=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $characters=preg_match_all('/./us',$plain);
    $astral=preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$plain);
    return $characters===false || $astral===false
        ? PHP_INT_MAX : $characters+$astral;
};

/** Protect payment/provider/Native facts and buttons; fit only display-only
 * text (including a 5000-character legacy description) into the remainder.
 * Raw persisted facts remain unchanged for all subsequent validations.
 */
$gatewayFitCase=static function(string $core,array $optional,string $footer) use ($gatewayTextLength): string {
    $notice="\n".achbotMessage('automatic_gateway_review.truncated');
    // Reserve a notice even when it turns out unnecessary. This keeps the
    // budget exact for both editMessageText and fallback sendMessage.
    $budget=4096-$gatewayTextLength($notice);
    if ($gatewayTextLength($core.$footer)>$budget)
        throw new RuntimeException('gateway_review_essential_text_over_limit');
    $added='';$trimmed=false;
    foreach ($optional as [$key,$param,$value]) {
        $value=(string)$value;
        if ($value==='') continue;
        $line=static fn(string $v): string =>
            "\n".achbotMessageHtml($key,[$param=>$v]);
        $complete=$line($value);
        if ($gatewayTextLength($core.$added.$complete.$footer)<=$budget) {
            $added.=$complete;
            continue;
        }
        $trimmed=true;
        $low=0;$high=mb_strlen($value,'UTF-8');
        while ($low<$high) {
            $mid=intdiv($low+$high+1,2);
            $partial=$line(mb_substr($value,0,$mid,'UTF-8').'…');
            if ($gatewayTextLength($core.$added.$partial.$footer)<=$budget)
                $low=$mid;
            else $high=$mid-1;
        }
        if ($low>0)
            $added.=$line(mb_substr($value,0,$low,'UTF-8').'…');
    }
    $result=$core.$added.($trimmed?$notice:'').$footer;
    if ($gatewayTextLength($result)>4096)
        throw new RuntimeException('gateway_review_message_budget_invalid');
    return $result;
};

// An explicit private, payments-authorized human decision to send exactly
// one original UNKNOWN packet again. The preview NEVER sends or claims.
$deliveryAction=[];
if (preg_match('/^attentionDelivery(Confirm|Send)_([1-9][0-9]{0,17})_([1-9][0-9]{0,17})(?:_([a-f0-9]{20}))?$/D',
    (string)($data??''),$deliveryAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    require_once __DIR__.'/../DeliveryLedger.php';
    $payId=(int)$deliveryAction[2];$ordinal=(int)$deliveryAction[3];
    $caseBack=[['text'=>achbotMessage('automatic_gateway_review.redrive_cancel'),
        'callback_data'=>'attentionCase_'.$payId]];
    $rows=[];$message='';
    try {
        $candidate=achbotDeliveryManualCandidate($connection,$payId,$ordinal);
        if (!$candidate) {
            $message=achbotMessage('automatic_gateway_review.redrive_stale');
        } elseif ($deliveryAction[1]==='Confirm' &&
            (string)($deliveryAction[4]??'')==='') {
            $message=achbotMessageHtml('automatic_gateway_review.redrive_confirm',[
                'user'=>(int)$candidate['user_id'],
                'payment'=>$payId,'ordinal'=>$ordinal]);
            $callback='attentionDeliverySend_'.$payId.'_'.$ordinal.'_'.
                achbotDeliveryManualConfirmationToken($candidate,$payId,(int)$from_id);
            if (strlen($callback)>64)
                throw new RuntimeException('manual_redrive_callback_limit');
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_send'),
                'callback_data'=>$callback]];
        } elseif ($deliveryAction[1]==='Send' &&
            hash_equals(achbotDeliveryManualConfirmationToken(
                $candidate,$payId,(int)$from_id),(string)($deliveryAction[4]??''))) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
            $result=achbotDeliveryManualRedrive(
                $connection,$payId,$ordinal,(int)$from_id,(int)$admin);
            $message=achbotMessage(match($result) {
                'acknowledged'=>'automatic_gateway_review.redrive_ack',
                'unknown'=>'automatic_gateway_review.redrive_unknown',
                'busy'=>'automatic_gateway_review.redrive_busy',
                'denied'=>'automatic_gateway_review.denied',
                'stale'=>'automatic_gateway_review.redrive_stale',
                default=>'automatic_gateway_review.redrive_unavailable',
            });
        } else {
            // Forged/unconfirmed/other-actor/stale callback: no customer send.
            $message=achbotMessage('automatic_gateway_review.redrive_stale');
        }
        $rows[]=$caseBack;
        $keys=json_encode(['inline_keyboard'=>$rows],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $edited=editText($message_id,$message,$keys,'HTML',(int)$from_id);
        if (!is_object($edited)||($edited->ok??false)!==true)
            sendMessage($message,$keys,'HTML',(int)$from_id);
        if ($deliveryAction[1]==='Confirm') answerCallbackQuery($callbackId);
    } catch(Throwable $error) {
        error_log('achbot_delivery_manual_ui_unavailable pay_id='.$payId.
            ' ordinal='.$ordinal.' class='.get_class($error));
        answerCallbackQuery($callbackId,
            achbotMessage('automatic_gateway_review.redrive_unavailable'),true);
    }
    return;
}

// Recover original Wallet CREATEs only with independent authenticated GET.
// Original LOCAL orders can be restored after explicit staff confirmation;
// uncertain Native outcomes cannot trigger another CREATE or debit.
// Marzban additive renewal remains evidence-only and must never infer finality.
$originalAction=[];
if (preg_match('/^attWOriginal([IC])_([1-9][0-9]{0,17})(?:_([a-f0-9]{20}))?$/D',
    (string)($data??''),$originalAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    require_once __DIR__.'/../WalletOriginalRecovery.php';
    $payId=(int)$originalAction[2];$actor=(int)$from_id;$lines=[];
    try {
        $q=$connection->prepare('SELECT type FROM pays WHERE id=?');
        $q->bind_param('i',$payId);$q->execute();
        $p=$q->get_result()->fetch_assoc();$q->close();
        $message='';
        if ($originalAction[1]==='I' && ($originalAction[3]??'')==='') {
            if (($p['type']??null)==='RENEW_SCONFIG') {
                $evidence=achbotWalletOriginalMarzbanInspect(
                    $connection,$payId,$actor,(int)$admin);
                $safeOriginal=static fn($v)=>mb_substr((string)($v??''),0,180,'UTF-8');
                $message=achbotMessageHtml('automatic_gateway_review.wallet_marzban_evidence',[
                    'payment'=>$payId,'order'=>(int)($evidence['order_id']??0),
                    'price'=>number_format((int)($evidence['price']??0)),
                    'original_days'=>(int)($evidence['original_days']??0),
                    'original_volume'=>(int)($evidence['original_volume']??0),
                    'plan'=>(int)($evidence['plan_id']??0),
                    'server'=>(int)($evidence['server_id']??0),
                    'account'=>$safeOriginal($evidence['remark']??''),
                    'endpoint'=>$safeOriginal($evidence['endpoint_sha256']??''),
                    'observed'=>$safeOriginal($evidence['observation']??
                        $evidence['reason']??'unknown'),
                    'days'=>(int)($evidence['current_plan_days']??0),
                    'volume'=>(int)($evidence['current_plan_volume']??0),
                    'native_state'=>$safeOriginal($evidence['current_native']['status']??
                        achbotMessage('automatic_gateway_review.unknown')),
                    'native_expiry'=>$safeOriginal($evidence['current_native']['expire']??
                        achbotMessage('automatic_gateway_review.unknown')),
                    'native_bytes'=>$safeOriginal($evidence['current_native']['bytes']??
                        achbotMessage('automatic_gateway_review.unknown'))]);
            } elseif (($p['type']??null)==='BUY_SUB') {
                $result=achbotWalletOriginalBuyInspect(
                    $connection,$payId,$actor,(int)$admin);
                $safeOriginal=static fn($v)=>mb_substr((string)($v??''),0,160,'UTF-8');
                $lineStatus=static fn($s)=>achbotMessage(match((string)$s) {
                    'matching_original_linked'=>
                        'automatic_gateway_review.wallet_original_status_linked',
                    'matching_original_unlinked_recoverable'=>
                        'automatic_gateway_review.wallet_original_status_unlinked',
                    'native_matches_but_original_order_unlinked'=>
                        'automatic_gateway_review.wallet_original_status_unverified',
                    'native_unverified'=>
                        'automatic_gateway_review.wallet_original_status_unverified',
                    default=>'automatic_gateway_review.wallet_original_status_missing',
                });
                foreach (array_slice($result['lines']??[],0,8) as $line) {
                    $lines[]=achbotMessageHtml('automatic_gateway_review.wallet_original_line',[
                        'ordinal'=>$line['ordinal'],'effect'=>$line['effect_id'],
                        'operation'=>$safeOriginal($line['operation']),
                        'account'=>$safeOriginal($line['username']),
                        'order'=>$line['order_id'],'status'=>$lineStatus($line['status']),
                        'expiry'=>$line['expiry'],'bytes'=>$line['bytes']]);
                }
                $message=($result['status']??null)==='ready'
                    ?achbotMessageHtml('automatic_gateway_review.wallet_original_ready',[
                        'count'=>$result['count'],'payment'=>$payId])
                    :achbotMessageHtml('automatic_gateway_review.wallet_original_hold',[
                        'payment'=>$payId,'reason'=>$safeOriginal(
                            $result['reason']??$result['status']??'unknown')]);
                if ($lines) $message.="\n\n".implode("\n",$lines);
                if (count($result['lines']??[])>8)
                    $message.="\n".achbotMessageHtml(
                        'automatic_gateway_review.wallet_original_lines_truncated',[
                        'visible'=>8,'total'=>count($result['lines'])]);
                if (($result['status']??null)==='ready') {
                    $token=achbotWalletOriginalBuyToken($result,$actor);
                    $lines=[['text'=>achbotMessage('automatic_gateway_review.wallet_original_confirm'),
                        'callback_data'=>'attWOriginalC_'.$payId.'_'.$token]];
                } else $lines=[];
            } else {
                $message=achbotMessage('automatic_gateway_review.wallet_review_stale');
            }
        } elseif ($originalAction[1]==='C' &&
            ($p['type']??null)==='BUY_SUB' &&
            preg_match('/^[a-f0-9]{20}$/D',(string)($originalAction[3]??''))===1) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
            $result=achbotWalletOriginalBuyComplete($connection,$payId,$actor,
                (int)$admin,(string)$originalAction[3]);
            $message=achbotMessage(match($result['status']??'review') {
                'settled_delivery_pending','already_paid'=>'automatic_gateway_review.wallet_original_done',
                default=>'automatic_gateway_review.wallet_review_stale',
            });
        } else $message=achbotMessage('automatic_gateway_review.wallet_review_stale');
        $buttons=[];
        if ($lines && isset($lines[0]['callback_data'])) $buttons[]=[$lines[0]];
        $buttons[]=[['text'=>achbotMessage('automatic_gateway_review.back_list'),
            'callback_data'=>'attentionCase_'.$payId]];
        $keyboard=json_encode(['inline_keyboard'=>$buttons],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $edited=editText($message_id,$message,$keyboard,'HTML',$actor);
        if (!is_object($edited) || ($edited->ok??false)!==true)
            sendMessage($message,$keyboard,'HTML',$actor);
        if ($originalAction[1]==='I') answerCallbackQuery($callbackId);
    } catch(Throwable $error) {
        error_log('achbot_wallet_original_ui_deferred pay_id='.$payId.
            ' actor='.$actor.' reason='.achbotFinanceDiagnosticReason($error));
        answerCallbackQuery($callbackId,
            achbotMessage('automatic_gateway_review.wallet_original_hold',[
                'payment'=>$payId,'reason'=>'operator_unavailable']),true);
    }
    return;
}

// Separate original administrator report lifecycle: no financial/native action.
// A second send requires the explicit human duplicate-warning confirmation,
// never a Cron retry or inferred Telegram acknowledgement.
$reportAction=[];
if (preg_match('/^attWReport([IS])_([1-9][0-9]{0,17})(?:_([a-f0-9]{20}))?$/D',
    (string)($data??''),$reportAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    require_once __DIR__.'/../WalletAddonNotices.php';
    $payId=(int)$reportAction[2];$actor=(int)$from_id;
    $rows=[];$message='';
    try {
        $case=achbotWalletAddonReportCase($connection,$payId);
        if ($reportAction[1]==='I' && ($reportAction[3]??'')==='') {
            if ($case && $case['state']==='unknown_or_partial' &&
                $case['manual_attempts']===0) {
                $message=achbotMessageHtml('automatic_gateway_review.wallet_report_confirm',
                    ['payment'=>$payId]);
                $token=achbotWalletAddonReportToken($case,$actor);
                $rows[]=[['text'=>achbotMessage('automatic_gateway_review.wallet_report_send'),
                    'callback_data'=>'attWReportS_'.$payId.'_'.$token]];
            } elseif ($case && $case['state']==='pending') {
                $message=achbotMessage('automatic_gateway_review.wallet_report_pending');
            } else {
                $message=achbotMessage('automatic_gateway_review.wallet_report_hold');
            }
        } elseif ($reportAction[1]==='S' &&
            preg_match('/^[a-f0-9]{20}$/D',(string)($reportAction[3]??''))===1) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
            $result=achbotWalletAddonReportManualSend($connection,$payId,$actor,
                (int)$admin,(string)$reportAction[3]);
            $message=achbotMessage(match($result) {
                'acknowledged'=>'automatic_gateway_review.wallet_report_ack',
                'unknown_or_partial'=>'automatic_gateway_review.wallet_report_unknown',
                'denied'=>'automatic_gateway_review.denied',
                'busy'=>'automatic_gateway_review.redrive_busy',
                'stale'=>'automatic_gateway_review.wallet_review_stale',
                default=>'automatic_gateway_review.redrive_unavailable',
            });
        } else {
            $message=achbotMessage('automatic_gateway_review.wallet_review_stale');
        }
        $rows[]=[['text'=>achbotMessage('automatic_gateway_review.back_list'),
            'callback_data'=>'attentionCase_'.$payId]];
        $keyboard=json_encode(['inline_keyboard'=>$rows],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $edited=editText($message_id,$message,$keyboard,'HTML',$actor);
        if (!is_object($edited) || ($edited->ok??false)!==true)
            sendMessage($message,$keyboard,'HTML',$actor);
        if ($reportAction[1]==='I') answerCallbackQuery($callbackId);
    } catch(Throwable $error) {
        error_log('achbot_wallet_report_operator_ui_deferred pay_id='.$payId.
            ' actor='.$actor.' reason='.achbotFinanceDiagnosticReason($error));
        answerCallbackQuery($callbackId,
            achbotMessage('automatic_gateway_review.wallet_report_hold'),true);
    }
    return;
}

// Original Wallet additive exceptions are not safe to reapply. Staff can
// inspect their exact Native-applied proof, then finish the original local
// debit/order/audit/notices, never another DAY/VOLUME PUT.
$addonAction=[];
if (preg_match('/^attWAdd([IC])_([1-9][0-9]{0,17})(?:_([a-f0-9]{20}))?$/D',
    (string)($data??''),$addonAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    require_once __DIR__.'/../WalletEditRecovery.php';
    $payId=(int)$addonAction[2];$actor=(int)$from_id;$owner=(int)$admin;
    $rows=[];$message='';
    try {
        if ($addonAction[1]==='I' && ($addonAction[3]??'')==='') {
            $inspection=achbotWalletPgAddonInspect($connection,$payId,$actor,$owner);
            if (($inspection['status']??null)==='applied') {
                $original=achbotWalletPgAddonOriginal($connection,$payId);
                $token=achbotWalletPgAddonConfirmationToken($original,$actor);
                $message=achbotMessageHtml('automatic_gateway_review.wallet_addon_ready',
                    ['payment'=>$payId]);
                $rows[]=[['text'=>achbotMessage(
                    'automatic_gateway_review.wallet_review_confirm'),
                    'callback_data'=>'attWAddC_'.$payId.'_'.$token]];
            } else {
                $message=achbotMessage('automatic_gateway_review.wallet_review_hold');
            }
        } elseif ($addonAction[1]==='C' &&
            preg_match('/^[a-f0-9]{20}$/D',(string)($addonAction[3]??''))===1) {
            $original=achbotWalletPgAddonOriginal($connection,$payId);
            $expected=achbotWalletPgAddonConfirmationToken($original,$actor);
            if (!hash_equals($expected,(string)$addonAction[3])) {
                $message=achbotMessage('automatic_gateway_review.wallet_review_stale');
            } else {
                answerCallbackQuery($callbackId,
                    achbotMessage('automatic_gateway_review.checking'));
                $outcome=achbotWalletPgResumeAddon($connection,$payId,$actor,$owner);
                $message=achbotMessage(
                    ($outcome['status']??null)==='settled_delivery_pending'
                        ?'automatic_gateway_review.wallet_addon_done'
                        :'automatic_gateway_review.wallet_review_hold');
            }
        } else {
            $message=achbotMessage('automatic_gateway_review.wallet_review_stale');
        }
        $rows[]=[['text'=>achbotMessage('automatic_gateway_review.back_list'),
            'callback_data'=>'attentionCase_'.$payId]];
        $keys=json_encode(['inline_keyboard'=>$rows],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $edited=editText($message_id,$message,$keys,'HTML',$actor);
        if (!is_object($edited) || ($edited->ok??false)!==true)
            sendMessage($message,$keys,'HTML',$actor);
        if ($addonAction[1]==='I') answerCallbackQuery($callbackId);
    } catch (Throwable $error) {
        error_log('achbot_wallet_addon_operator_ui_deferred pay_id='.$payId.
            ' actor_id='.$actor.' class='.get_class($error).
            ' reason='.achbotFinanceDiagnosticReason($error));
        answerCallbackQuery($callbackId,
            achbotMessage('automatic_gateway_review.wallet_review_hold'),true);
    }
    return;
}

// The same payment inbox opens original-Wallet RENEW reconciliation. Preview
// performs authenticated GET only; completion rechecks the original Native
// result and commits the same debited invoice, without another RESET/PUT.
$walletAction=[];
if (preg_match('/^attentionWallet(Inspect|Complete|Retry)_([1-9][0-9]{0,17})(?:_([a-f0-9]{20}))?$/D',
    (string)($data??''),$walletAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    require_once __DIR__.'/../WalletRenewalRecovery.php';
    $payId=(int)$walletAction[2];$actor=(int)$from_id;$owner=(int)$admin;
    $back=[['text'=>achbotMessage('automatic_gateway_review.back_list'),
        'callback_data'=>'attentionCase_'.$payId]];
    $rows=[];$resultMessage='';
    try {
        if ($walletAction[1]==='Inspect' && ($walletAction[3]??'')==='') {
            $result=achbotWalletPgRenewInspect($connection,$payId,$actor,$owner);
            if (($result['status']??null)==='applied') {
                $original=achbotWalletPgRenewOriginal($connection,$payId);
                $token=achbotWalletPgRenewConfirmationToken($original,$actor);
                $resultMessage=achbotMessageHtml('automatic_gateway_review.wallet_review_ready',
                    ['payment'=>$payId]);
                $rows[]=[['text'=>achbotMessage(
                    'automatic_gateway_review.wallet_review_confirm'),
                    'callback_data'=>'attentionWalletComplete_'.$payId.'_'.$token]];
            } elseif (($result['status']??null)==='retryable') {
                $original=achbotWalletPgRenewOriginal($connection,$payId);
                $digest=(string)$result['entitlement_digest'];
                $token=achbotWalletPgRenewRetryToken($original,$actor,$digest);
                $drift=($result['disposition']??null)==='drift';
                if ($drift) {
                    $resultMessage=achbotMessageHtml(
                        'automatic_gateway_review.service_override_confirm',[
                        'payment'=>$payId,
                        'currentExpiry'=>$result['native_expire']??
                            achbotMessage('automatic_gateway_review.unknown'),
                        'targetExpiry'=>$result['target_expire'],
                        'currentVolume'=>number_format((float)$result['native_bytes']/1073741824,2),
                        'targetVolume'=>number_format((float)$result['target_bytes']/1073741824,2),
                        'currentStatus'=>$result['native_status'],
                        'currentGroups'=>implode(',',(array)$result['native_groups'])]);
                } else {
                    $resultMessage=achbotMessageHtml(
                        'automatic_gateway_review.service_confirm',
                        ['payment'=>$payId]);
                }
                // The operator confirms the same immutable invoice, not a
                // stale preview digest. Re-observe and finish/retry on click.
                // This avoids a merely changed Native readback becoming a
                // permanent button-level lock on an already-debited renewal.
                $token=achbotWalletPgRenewConfirmationToken($original,$actor);
                $rows[]=[['text'=>achbotMessage($drift
                    ?'automatic_gateway_review.service_override_execute'
                    :'automatic_gateway_review.wallet_retry_action'),
                    'callback_data'=>'attentionWalletComplete_'.$payId.'_'.$token]];
            } elseif (($result['status']??null)==='retry_pending') {
                $original=achbotWalletPgRenewOriginal($connection,$payId);
                $token=achbotWalletPgRenewConfirmationToken($original,$actor);
                $resultMessage=achbotMessage(
                    'automatic_gateway_review.wallet_review_warning');
                $rows[]=[['text'=>achbotMessage(
                    'automatic_gateway_review.wallet_retry_action'),
                    'callback_data'=>'attentionWalletComplete_'.$payId.'_'.$token]];
            } else {
                $resultMessage=achbotMessage('automatic_gateway_review.wallet_review_hold');
            }
        } elseif ($walletAction[1]==='Retry' &&
            preg_match('/^[a-f0-9]{20}$/D',(string)($walletAction[3]??''))===1) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
            $result=achbotWalletPgRetryOriginalRenew($connection,$payId,
                $actor,$owner,(string)$walletAction[3]);
            $resultMessage=achbotMessage(
                ($result['status']??null)==='settled_delivery_pending'
                ?'automatic_gateway_review.wallet_retry_done'
                :'automatic_gateway_review.wallet_review_stale');
        } elseif ($walletAction[1]==='Complete' &&
            preg_match('/^[a-f0-9]{20}$/D',(string)($walletAction[3]??''))===1) {
            // Cron may have finished this ORIGINAL pay after the staff preview.
            // Do not misreport a successful concurrent completion as a stale
            // or blocked operation. The payments permission/private chat
            // boundary already applies to this read-only terminal status.
            $q=$connection->prepare('SELECT type,state FROM pays WHERE id=?');
            $q->bind_param('i',$payId);$q->execute();
            $terminal=$q->get_result()->fetch_assoc();$q->close();
            if ($terminal && $terminal['type']==='RENEW_ACCOUNT' &&
                $terminal['state']==='paid_with_wallet') {
                $resultMessage=achbotMessage('automatic_gateway_review.wallet_review_done');
            } else {
                $original=achbotWalletPgRenewOriginal($connection,$payId);
                $expected=achbotWalletPgRenewConfirmationToken($original,$actor);
                if (!hash_equals($expected,(string)$walletAction[3])) {
                    $resultMessage=achbotMessage('automatic_gateway_review.wallet_review_stale');
                } else {
                    answerCallbackQuery($callbackId,
                        achbotMessage('automatic_gateway_review.checking'));
                    $result=achbotWalletPgResumeRenew($connection,$payId,$actor,$owner);
                    $state=(string)($result['status']??'');
                    $resultMessage=achbotMessage(
                        in_array($state,['settled_delivery_pending',
                                'already_paid_state'],true)
                        ?'automatic_gateway_review.wallet_review_done'
                        :'automatic_gateway_review.wallet_review_hold');
                }
            }
        } else {
            $resultMessage=achbotMessage('automatic_gateway_review.wallet_review_stale');
        }
        $rows[]=$back;
        $keys=json_encode(['inline_keyboard'=>$rows],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $edited=editText($message_id,$resultMessage,$keys,'HTML',$actor);
        if (!is_object($edited) || ($edited->ok??false)!==true)
            sendMessage($resultMessage,$keys,'HTML',$actor);
        if ($walletAction[1]==='Inspect') answerCallbackQuery($callbackId);
    } catch (Throwable $error) {
        error_log('achbot_wallet_renew_operator_ui_deferred pay_id='.$payId.
            ' actor_id='.$actor.' class='.get_class($error).
            ' reason='.achbotFinanceDiagnosticReason($error));
        answerCallbackQuery($callbackId,
            achbotMessage('automatic_gateway_review.wallet_review_hold'),true);
    }
    return;
}

// One operator inbox for genuinely unfinished original hosted/manual/API cases.
if (preg_match('/^attention(?:Inbox(?:_[1-9][0-9]{0,17})?|Case_[1-9][0-9]{0,17})$/D',
    (string)($data??''))===1) {
    require_once __DIR__.'/../PaymentLifecycle.php';
    require_once __DIR__.'/../GatewayReview.php';
    require_once __DIR__.'/../ApiPayments.php';

    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }

    // An old unpaid checkout is not itself an exception. Admission uses
    // original provider evidence or a previously started unresolved effect;
    // elapsed time alone never creates a staff action.
    $scope=achbotGatewayAttentionScope($connection);
    $inbox=[];$case=[];
    $isInbox=preg_match('/^attentionInbox(?:_([1-9][0-9]{0,17}))?$/D',
        (string)$data,$inbox)===1;
    $isCase=preg_match('/^attentionCase_([1-9][0-9]{0,17})$/D',
        (string)$data,$case)===1;
    if (!$isInbox && !$isCase) return;

    $send=static function(string $message,array $rows) use ($message_id,$from_id): void {
        $markup=json_encode(['inline_keyboard'=>$rows],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $result=editText($message_id,$message,$markup,'HTML',(int)$from_id);
        if (!is_object($result) || ($result->ok??false)!==true)
            sendMessage($message,$markup,'HTML',(int)$from_id);
    };
    $labels=[
        'nowpayments'=>achbotMessage('automatic_gateway_review.provider_nowpayments'),
        'zarinpal'=>achbotMessage('automatic_gateway_review.provider_zarinpal'),
        'nextpay'=>achbotMessage('automatic_gateway_review.provider_nextpay'),
        'bank_transfer'=>achbotMessage('automatic_gateway_review.provider_bank_transfer'),
        'gift_card'=>achbotMessage('automatic_gateway_review.provider_gift_card'),
        'manual_usdt'=>achbotManualLabel('manual_usdt'),
        'manual_trx'=>achbotManualLabel('manual_trx'),
        'wallet'=>achbotMessage('automatic_gateway_review.wallet_provider'),
    ];
    $safe=static fn($v) => htmlspecialchars(mb_substr((string)($v??''),0,180,'UTF-8'),
        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    try {
        $rows=[];
        if ($isInbox) {
            $before=(int)($inbox[1]??0);
            $items=[];$more=false;
            if (achbotPayUxTable($connection,'payment_attempts')) {
                $q=$connection->prepare("SELECT p.id,p.user_id,p.price,COALESCE(a.provider,'wallet') AS provider
                    FROM pays p LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
                    WHERE $scope AND (?=0 OR p.id<?)
                    ORDER BY p.id DESC LIMIT 13");
                $q->bind_param('ii',$before,$before);
                $q->execute();$items=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
                $more=count($items)>12;$items=array_slice($items,0,12);
            }
            foreach ($items as $item) {
                $label=number_format((int)$item['price']).' '.
                    achbotMessage('automatic_gateway_review.toman').' | '.
                    (int)$item['user_id'].' | '.
                    ($labels[$item['provider']]??$safe($item['provider']));
                $rows[]=[['text'=>$label,'callback_data'=>'attentionCase_'.(int)$item['id']]];
            }
            if ($more && $items) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.older'),
                'callback_data'=>'attentionInbox_'.(int)end($items)['id']]];
            if ($before>0) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.newest'),
                'callback_data'=>'attentionInbox']];
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.back_admin'),
                'callback_data'=>'managePanel']];
            $body=achbotMessage('automatic_gateway_review.title');
            if (!$items) $body.="\n".achbotMessage('automatic_gateway_review.empty');
        } else {
            $payId=(int)$case[1];
            $q=$connection->prepare("SELECT p.id,p.user_id,p.hash_id,p.type,p.price,p.state,
                p.plan_id,p.order_id,p.server_id,p.day,p.volume,p.description,\n                p.agent_count,p.request_date,p.approved_by,
                a.id AS attempt_id,COALESCE(a.provider,'wallet') AS provider,a.order_hash,a.provider_reference,
                a.payment_id,a.quote_amount,a.quote_currency,a.stage,a.provider_status,
                a.created_at,a.updated_at,a.last_checked_at
                FROM pays p LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
                WHERE p.id=? AND $scope LIMIT 1");
            $q->bind_param('i',$payId);$q->execute();
            $item=$q->get_result()->fetch_assoc();$q->close();
            if (!$item) {
                $send(achbotMessage('automatic_gateway_review.resolved'),
                    [[['text'=>achbotMessage('automatic_gateway_review.back_list'),
                        'callback_data'=>'attentionInbox']]]);
                answerCallbackQuery($callbackId);
                return;
            }
            // Customer-facing display names are context, never authorization.
            $name='';
            $handle='';
            $q=$connection->prepare('SELECT name,username FROM users WHERE userid=? LIMIT 1');
            $uid=(int)$item['user_id'];$q->bind_param('i',$uid);$q->execute();
            $person=$q->get_result()->fetch_assoc();$q->close();
            if ($person) {
                $name=(string)($person['name']??'');
                $handle=(string)($person['username']??'');
            }
            // Recorded merchant observations, not new network verification.
            $latest=[];
            $q=$connection->prepare('SELECT payment_id,observed_status,received_at
                FROM payment_transactions WHERE attempt_id=? AND provider=?
                ORDER BY id DESC LIMIT 2');
            $attempt=(int)$item['attempt_id'];$provider=(string)$item['provider'];
            $q->bind_param('is',$attempt,$provider);$q->execute();
            $latest=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $type=(string)$item['type'];
            $walletCase=(string)$item['provider']==='wallet';
            $subject=match(true) {
                $type==='BUY_SUB' => achbotMessage('automatic_gateway_review.purchase'),
                $type==='RENEW_ACCOUNT'||$type==='RENEW_SCONFIG' =>
                    achbotMessage('automatic_gateway_review.renew'),
                $type==='INCREASE_WALLET' => achbotMessage('automatic_gateway_review.wallet'),
                str_starts_with($type,'INCREASE_DAY_') =>
                    achbotMessage('automatic_gateway_review.days'),
                str_starts_with($type,'INCREASE_VOLUME_') =>
                    achbotMessage('automatic_gateway_review.volume'),
                default => $safe($type),
            };
            $orderId=(int)($item['order_id']??0);
            if ($orderId<=0 && preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/D',$type,$m))
                $orderId=(int)$m[1];
            $orderName='';
            if ($orderId>0) {
                $q=$connection->prepare('SELECT remark FROM orders_list
                    WHERE id=? AND userid=? LIMIT 1');
                $user=(int)$item['user_id'];
                $q->bind_param('ii',$orderId,$user);
                $q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
                $orderName=(string)($r['remark']??'');
            }
            $planTitle='';
            if ((int)$item['plan_id']>0) {
                $q=$connection->prepare('SELECT title FROM server_plans WHERE id=? LIMIT 1');
                $pid=(int)$item['plan_id'];$q->bind_param('i',$pid);$q->execute();
                $r=$q->get_result()->fetch_assoc();$q->close();
                $planTitle=(string)($r['title']??'');
            }
            // The existing immutable 007 ledger explains whether a Native
            // account was reserved/dispatched, including without a local order.
            // This is read-only case evidence, not another recovery screen.
            $native=[];$nativeTotal=0;
            if ($type==='BUY_SUB') {
                $q=$connection->prepare("SELECT COUNT(*) AS total FROM service_effects
                    WHERE pay_row_id=? AND effect_kind IN ('create','replacement')");
                $q->bind_param('i',$payId);$q->execute();
                $nativeTotal=(int)($q->get_result()->fetch_assoc()['total']??0);$q->close();
                if ($nativeTotal>0) {
                    // 007 payment-effect evidence is valid even if optional
                    // 012 provider-storage columns were never installed.
                    // Preserve the existing original record; only its extra
                    // native-state display field is absent in that schema.
                    $column=$connection->query(
                        "SHOW COLUMNS FROM service_effects LIKE 'provider_state'");
                    if (!($column instanceof mysqli_result))
                        throw new RuntimeException('gateway_review_effect_columns_unavailable');
                    $nativeState=$column->num_rows>0
                        ?'provider_state':'NULL AS provider_state';
                    $column->free();
                    $q=$connection->prepare("SELECT id,ordinal,state,effect_kind,operation_id,
                        remote_username,target_server_id,order_id,$nativeState
                        FROM service_effects WHERE pay_row_id=?
                          AND effect_kind IN ('create','replacement')
                        ORDER BY ordinal DESC LIMIT 5");
                    $q->bind_param('i',$payId);$q->execute();
                    $native=array_reverse($q->get_result()->fetch_all(MYSQLI_ASSOC));
                    $q->close();
                }
            }
            $requested=(int)$item['request_date']>0
                ?(new DateTimeImmutable('@'.(int)$item['request_date']))
                    ->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s')
                : (string)$item['created_at'];
            $status=(string)($item['provider_status']??'');
            $optional=[
                ['automatic_gateway_review.plan','plan',$planTitle],
                ['automatic_gateway_review.name','name',$name],
                ['automatic_gateway_review.handle','handle',$handle],
                ['automatic_gateway_review.description','description',
                    (string)($item['description']??'')],
            ];
            $body=achbotMessage('automatic_gateway_review.case_title').
                "\n\n".achbotMessageHtml('automatic_gateway_review.customer',['id'=>$item['user_id']]).
                "\n".achbotMessageHtml('automatic_gateway_review.amount',[
                    'amount'=>number_format((int)$item['price'])]).
                "\n".achbotMessageHtml('automatic_gateway_review.gateway',[
                    'provider'=>$labels[$item['provider']]??$item['provider']]).
                "\n".achbotMessageHtml('automatic_gateway_review.subject',['subject'=>$subject]).
                ($orderName!==''?"\n".achbotMessageHtml('automatic_gateway_review.username',
                    ['username'=>$orderName]):'').
                ($orderId>0?"\n".achbotMessageHtml('automatic_gateway_review.order',
                    ['id'=>$orderId]):'').
                (($type==='BUY_SUB' && (int)$item['agent_count']>1)
                    ?"\n".achbotMessageHtml('automatic_gateway_review.quantity',
                        ['quantity'=>$item['agent_count']]):'').
                (($type==='BUY_SUB' || $type==='RENEW_SCONFIG')
                    && (int)$item['day']>0?"\n".achbotMessageHtml(
                        'automatic_gateway_review.duration',['days'=>$item['day']]):'').
                (($type==='BUY_SUB' || $type==='RENEW_SCONFIG')
                    && (int)$item['volume']>0?"\n".achbotMessageHtml(
                        'automatic_gateway_review.quota',['gb'=>$item['volume']]):'').
                "\n".achbotMessageHtml('automatic_gateway_review.request_time',
                    ['date'=>$requested]).
                ($walletCase?'':(
                    "\n".achbotMessageHtml(
                        in_array($item['provider'],['bank_transfer','gift_card'],true)
                            ?'automatic_gateway_review.api_quote'
                            :'automatic_gateway_review.merchant_quote',[
                        'amount'=>$item['quote_amount'],'currency'=>$item['quote_currency']]).
                    "\n".achbotMessageHtml('automatic_gateway_review.invoice',
                        ['reference'=>$item['provider_reference']]).
                    "\n".achbotMessageHtml('automatic_gateway_review.payment',
                        ['reference'=>$item['payment_id']?:
                            achbotMessage('automatic_gateway_review.unknown')]))).
                "\n".achbotMessageHtml('automatic_gateway_review.reference',
                    ['hash'=>$item['hash_id']]).
                "\n".achbotMessageHtml('automatic_gateway_review.status',[
                    'stage'=>$item['state'].' / '.($item['stage']??
                        achbotMessage('automatic_gateway_review.unknown')),
                    'status'=>$status!==''?$status:
                        achbotMessage('automatic_gateway_review.unknown')]).
                "\n".achbotMessageHtml('automatic_gateway_review.updated',[
                    'date'=>$item['updated_at']??$requested]);
            foreach ($latest as $observed) {
                $body.="\n".achbotMessageHtml('automatic_gateway_review.merchant_observation',[
                    'payment'=>$observed['payment_id'],
                    'status'=>$observed['observed_status'],
                    'date'=>$observed['received_at']]);
            }
            $manualCase=in_array((string)$item['provider'],
                ['bank_transfer','gift_card','manual_usdt','manual_trx'],true);
            $apiAttempt=$manualCase?achbotApiPaymentAttempt($connection,$payId):null;
            $apiCase=$manualCase &&
                in_array((string)$item['provider'],['bank_transfer','gift_card'],true) &&
                !empty($apiAttempt['evidence_profile']);
            if ($apiCase) {
                $item['evidence_state']=$apiAttempt['evidence_state']??null;
                $item['evidence_fulfillment_state']=$apiAttempt['evidence_fulfillment_state']??null;
                $body.="\n".achbotMessageHtml('automatic_gateway_review.api_status',[
                    'evidence'=>$item['evidence_state']?:achbotMessage('automatic_gateway_review.unknown'),
                    'fulfillment'=>$item['evidence_fulfillment_state']?:achbotMessage('automatic_gateway_review.unknown')]);
            }
            if ($type==='BUY_SUB') {
                $body.="\n".achbotMessageHtml('automatic_gateway_review.native_count',[
                    'count'=>$nativeTotal]);
                foreach ($native as $effect) {
                    $body.="\n".achbotMessageHtml('automatic_gateway_review.native_effect',[
                        'id'=>$effect['id'],'ordinal'=>$effect['ordinal'],
                        'username'=>$effect['remote_username']?:achbotMessage('automatic_gateway_review.unknown'),
                        'state'=>$effect['state'],
                        'provider_state'=>$effect['provider_state']?:achbotMessage('automatic_gateway_review.unknown'),
                        'operation'=>$effect['operation_id'],
                        'server'=>$effect['target_server_id'],
                        'order'=>$effect['order_id']?:achbotMessage('automatic_gateway_review.unknown')]);
                }
                if ($nativeTotal>count($native))
                    $body.="\n".achbotMessageHtml('automatic_gateway_review.native_more',[
                        'remaining'=>$nativeTotal-count($native)]);
                if ($nativeTotal>0)
                    $body.="\n".achbotMessage('automatic_gateway_review.native_errors');
            }
            if ($walletCase && $orderId>0) {
                // This journal is optional before native storage migration 012.
                // An older hosted-only runtime must still show wallet cases
                // rather than failing its entire payments inbox on 1054.
                $probe=$connection->query(
                    "SHOW COLUMNS FROM service_effects LIKE 'provider_mutation_key'");
                if ($probe instanceof mysqli_result && $probe->num_rows===1) {
                    $probe->free();
                    $q=$connection->prepare('SELECT e.provider_state,e.provider_mutation_key
                        FROM orders_list o
                        LEFT JOIN service_effects e ON e.provider_reference_id=o.provider_effect_id
                        WHERE o.id=? AND o.userid=? LIMIT 1');
                    $user=(int)$item['user_id'];
                    $q->bind_param('ii',$orderId,$user);$q->execute();
                    $walletNative=$q->get_result()->fetch_assoc();$q->close();
                    if ($walletNative) $body.="\n".achbotMessageHtml(
                        'automatic_gateway_review.wallet_native_evidence',[
                        'operation'=>$walletNative['provider_mutation_key']??
                            achbotMessage('automatic_gateway_review.unknown'),
                        'state'=>$walletNative['provider_state']??
                            achbotMessage('automatic_gateway_review.unknown')]);
                } elseif ($probe instanceof mysqli_result) {
                    $probe->free();
                }
            }
            $footer="\n\n".achbotMessage($walletCase
                ?'automatic_gateway_review.wallet_review_warning'
                :($apiCase?'automatic_gateway_review.api_warning'
                  :($manualCase?'automatic_gateway_review.manual_warning'
                               :'automatic_gateway_review.warning')));
            $attempt=(int)$item['attempt_id'];
            $actions=[];$recover=false;
            if ($walletCase && $item['state']==='processing_wallet'
                && $type==='RENEW_ACCOUNT') {
                // Only the original GET-verified wallet RENEW may be
                // completed from this screen. No gateway fake approve/reject.
                $recover=['text'=>achbotMessage('automatic_gateway_review.wallet_review_action'),
                    'callback_data'=>'attentionWalletInspect_'.$payId];
            } elseif ($walletCase && $item['state']==='processing_wallet' &&
                preg_match('/^INCREASE_(?:DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$/D',
                    $type)===1) {
                $recover=['text'=>achbotMessage(
                    'automatic_gateway_review.wallet_addon_review_action'),
                    'callback_data'=>'attWAddI_'.$payId];
            } elseif (!$manualCase && !$walletCase) {
                // Hosted human Approve/Reject keep their original eligibility
                // and the same one-shot settlement coordinator.
                if (achbotGatewayReviewEligible($connection,$attempt,'approve'))
                    $actions[]=['text'=>achbotMessage('automatic_gateway_review.approve'),
                        'callback_data'=>'gatewayReview_A_'.$attempt];
                if (achbotGatewayReviewEligible($connection,$attempt,'reject'))
                    $actions[]=['text'=>achbotMessage('automatic_gateway_review.reject'),
                        'callback_data'=>'gatewayReview_R_'.$attempt];
                if ($item['provider']==='nowpayments' && $item['stage']==='review' &&
                    $item['provider_status']==='amount_mismatch' &&
                    in_array($item['state'],['pending','send'],true))
                    $recover=['text'=>achbotMessage('automatic_gateway_review.recover'),
                        'callback_data'=>'gatewayReview_V_'.$attempt];
            } elseif ($apiCase) {
                $item['attempt']=$apiAttempt;
                $item['evidence_fulfillment_state']=$apiAttempt['evidence_fulfillment_state']??null;
                $proof=achbotPaymentEvidenceGetProof($connection,$payId);
                $eligible=achbotApiPaymentCanResume($connection,$item);
                $terminalDelivery=achbotApiPaymentTerminal($item) &&
                    ($proof['mode']??null)==='settle';
                if ($eligible || $terminalDelivery)
                    // A final payment/native effect only needs a delivery
                    // status check. Do not promise to rerun an operation that
                    // has already completed or has an UNKNOWN Telegram ACK.
                    $recover=['text'=>achbotMessage($terminalDelivery
                        ?'automatic_gateway_review.open_case'
                        :'automatic_gateway_review.service_action'),
                        'callback_data'=>'apiEvidenceResume_'.$payId];
                // An API-unproven receipt remains a human decision, using
                // the SAME existing Approve/Reject callbacks and reason flow.
                if ($item['state']==='have_sent' && !$proof) {
                    $manual=json_decode(achbotApiPaymentReviewKeyboard($item),true);
                    foreach ($manual['inline_keyboard']??[] as $buttons)
                        foreach ($buttons as $button)
                            if (preg_match('/^(?:approve|accept|accCustom|declineAction_)/D',
                                (string)($button['callback_data']??''))===1)
                                $actions[]=$button;
                }
            }
            if ($walletCase && $item['state']==='processing_wallet' &&
                in_array($type,['BUY_SUB','RENEW_SCONFIG'],true)) {
                $recover=['text'=>achbotMessage(
                    'automatic_gateway_review.wallet_original_action'),
                    'callback_data'=>'attWOriginalI_'.$payId];
            }
            if ($walletCase && $item['state']==='paid_with_wallet') {
                require_once __DIR__.'/../WalletAddonNotices.php';
                $report=achbotWalletAddonReportCase($connection,$payId);
                if ($report && $report['state']!=='acknowledged') {
                    $reportState=achbotMessage(match($report['state']) {
                        'pending'=>'automatic_gateway_review.wallet_report_state_pending',
                        'dispatching'=>'automatic_gateway_review.wallet_report_state_dispatching',
                        'manual_dispatching'=>
                            'automatic_gateway_review.wallet_report_state_manual_dispatching',
                        default=>'automatic_gateway_review.wallet_report_state_unknown',
                    });
                    $body.="\n".achbotMessageHtml(
                        'automatic_gateway_review.wallet_report_status',[
                        'payment'=>$payId,'state'=>$reportState,
                        'attempts'=>$report['attempts']]);
                    $recover=['text'=>achbotMessage(
                        'automatic_gateway_review.wallet_report_action'),
                        'callback_data'=>'attWReportI_'.$payId];
                }
            }
            if ($actions) $rows[]=$actions;
            if ($recover) $rows[]=[$recover];
            // Only unresolved original Telegram ACKs, never an unproven
            // payment or a second financial/Native continuation.
            require_once __DIR__.'/../DeliveryLedger.php';
            $manualDelivery=achbotDeliveryManualCandidate($connection,$payId);
            if ($manualDelivery)
                $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_action'),
                    'callback_data'=>'attentionDeliveryConfirm_'.$payId.'_'.
                        (int)$manualDelivery['ordinal']]];
            if (!$actions && !$recover)
                $footer.="\n".achbotMessage('automatic_gateway_review.manual_hold');
            if (!$walletCase) {
                $rows[]=[['text'=>achbotMessage('automatic_gateway_review.archive'),
                    'callback_data'=>'gatewayReview_H_'.$attempt]];
                $footer.="\n".achbotMessage('automatic_gateway_review.archive_notice');
            }
            $body=$gatewayFitCase($body,$optional,$footer);
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.back_list'),
                'callback_data'=>'attentionInbox']];
        }
        answerCallbackQuery($callbackId);
        $send($body,$rows);
    } catch (Throwable $error) {
        error_log('achbot_automatic_gateway_inbox_unavailable class='.get_class($error).
            ' code='.(int)$error->getCode());
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.unavailable'),true);
    }
    return;
}


// Same original financial effect, with one-shot provider-attempt claim.
$gatewayAction=[];
if (preg_match('/^gatewayReview_([ARHV])_([1-9][0-9]{0,17})$/D',
    (string)($data??''),$gatewayAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    $archive=$gatewayAction[1]==='H';
    $recovery=$gatewayAction[1]==='V';
    require_once __DIR__.'/../PaymentLifecycle.php';
    if (!$archive) {
        if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
        require_once __DIR__.'/../GatewayReturn.php';
    }
    require_once __DIR__.'/../GatewayReview.php';
    $mode=$gatewayAction[1]==='A'?'approve':'reject';
    $attempt=(int)$gatewayAction[2];
    $keys=json_encode(['inline_keyboard'=>[[[
        'text'=>achbotMessage('automatic_gateway_review.back_list'),
        'callback_data'=>'attentionInbox',
    ]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    try {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
        $result=$archive
            ?['status'=>achbotGatewayReviewArchive(
                $connection,(int)$from_id,$attempt,(int)$admin)]
            :($recovery
                ?achbotGatewayReviewRecover($connection,(int)$from_id,$attempt,
                    (int)$admin,(array)($paymentKeys??[]))
                :achbotGatewayReviewCommit(
                    $connection,(int)$from_id,$attempt,$mode,(int)$admin));
        $status=(string)($result['status']??'unavailable');
        if ($archive && $status==='archived') {
            // The old API receipt remains a stored admin message. Update its
            // keyboard in place so it no longer offers a stale review action.
            try {
                $q=$connection->prepare('SELECT pay_row_id,provider FROM payment_attempts WHERE id=?');
                $q->bind_param('i',$attempt);$q->execute();
                $archivedSource=$q->get_result()->fetch_assoc();$q->close();
                if ($archivedSource && in_array($archivedSource['provider'],
                    ['bank_transfer','gift_card'],true)) {
                    $original=achbotApiPaymentRow($connection,(int)$archivedSource['pay_row_id']);
                    if ($original) achbotApiPaymentNotice($connection,$original,'archived',false);
                }
            } catch (Throwable $error) {
                error_log('achbot_archive_receipt_projection_deferred attempt='.$attempt.
                    ' class='.get_class($error));
            }
        }
        if ($status==='rejected') {
            // Closing the business order does not refund the merchant.
            $pay=$result['pay'];
            $params=['id'=>$attempt,'user'=>(int)$pay['user_id'],
                'amount'=>number_format((int)$pay['price']),
                'hash'=>(string)$pay['hash_id']];
            try {
                sendMessage(achbotMessageHtml('automatic_gateway_review.rejected_customer',[
                    'hash'=>$params['hash'],'amount'=>$params['amount']]),
                    null,'HTML',(int)$pay['user_id']);
            } catch(Throwable $error) {
                error_log('achbot_gateway_reject_customer_notice_unknown pay_id='.
                    (int)$pay['id']);
            }
            try {
                notifyAllAdminsSimple(achbotMessageHtml('automatic_gateway_review.rejected_report',
                    $params),(int)$admin,'html',null,$botState['rewardChannel']??null);
            } catch(Throwable $error) {
                error_log('achbot_gateway_reject_report_unknown pay_id='.(int)$pay['id']);
            }
        }
        $text=match($status) {
            'completed'=>achbotMessage('automatic_gateway_review.status_completed'),
            'rejected'=>achbotMessage('automatic_gateway_review.status_rejected'),
            'archived'=>achbotMessage('automatic_gateway_review.status_archived'),
            'busy'=>achbotMessage('automatic_gateway_review.status_busy'),
            'unknown'=>achbotMessage('automatic_gateway_review.status_unknown'),
            'stale'=>achbotMessage('automatic_gateway_review.status_stale'),
            'denied'=>achbotMessage('automatic_gateway_review.denied'),
            'unsupported'=>achbotMessage('automatic_gateway_review.status_unsupported'),
            default=>achbotMessage('automatic_gateway_review.status_unavailable'),
        };
        $body=achbotMessageHtml('automatic_gateway_review.result',['result'=>$text]);
        $sent=editText($message_id,$body,$keys,'HTML',(int)$from_id);
        if (!is_object($sent)||($sent->ok??false)!==true)
            sendMessage($body,$keys,'HTML',(int)$from_id);
    } catch(Throwable $error) {
        error_log('achbot_gateway_review_callback_error class='.get_class($error));
        $body=achbotMessage('automatic_gateway_review.unavailable');
        try {
            $sent=editText($message_id,$body,$keys,'HTML',(int)$from_id);
            if (!is_object($sent)||($sent->ok??false)!==true)
                sendMessage($body,$keys,'HTML',(int)$from_id);
        } catch(Throwable $ignored) {}
    }
    return;
}
