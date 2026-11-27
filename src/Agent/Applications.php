<?php
/** Current agency request and decision routes. The original application
 * generation, state change and notifications share the existing journal. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../messages/loader.php';
require_once __DIR__.'/../Telegram/Conversation.php';
require_once __DIR__.'/../Telegram/UserEvents.php';
require_once __DIR__.'/../Admin/Access.php';

require_once __DIR__.'/State.php';

if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}
$agencyActor=(int)$from_id;
$agencyCallback=\AchBot\Telegram\Conversation\source($update,$agencyActor,true);
if ($data==='agencySettings' && $agencyCallback && (int)$userInfo['is_agent']===1) {
    editText($message_id,achbotMessage('legacy_messages.agent_setting_message'),getAgentKeys());
    return;
}
if ($data==='requestAgency' && $agencyCallback) {
    $result=achbotAgencyRequest($connection,$agencyActor,(int)($update->update_id??0),$first_name,$username);
    switch($result['state']){
    case 'claimed':
        alert(achbotMessage('legacy_messages.agency_request_sent'));
        achbotOperationalReportDispatch($connection,$result['ref'],8.0);break;
    case 2: alert(achbotMessage('legacy_messages.agency_request_already_sent'));break;
    case -1: alert(achbotCurrentMessage('agency_request_declined'));break;
    case 1: editText($message_id,achbotMessage('labels.select_option'),getMainKeys());break;
    default: alert(achbotMessage('legacy_messages.config_not_found'));
    }
    return;
}
if ($agencyCallback && preg_match('/^agency(Approve|Decline)([1-9][0-9]*)_([1-9][0-9]*)$/D',(string)$data,$m)) {
    $applicant=achbotAgencyPositive($m[2]);$generation=achbotAgencyPositive($m[3]);
    if (!$applicant || !$generation || !achbotAdminCanFor($connection,$agencyActor,(int)$admin,'agents')) {
        alert(achbotMessage('legacy_messages.config_not_found'));return;
    }
    if ($m[1]==='Approve') {
        $u=achbotAgencyApplicant($connection,$applicant);
        if (!$u || !achbotAgencyMatches($connection,$u,$generation)) {
            alert(achbotMessage('legacy_messages.config_not_found'));return;
        }
        if (\AchBot\Telegram\Conversation\transitionEditor($connection,$agencyActor,$userInfo,
            'agencyApprove'.$applicant.'_'.$generation.'_'.$agencyCallback->message_id,update:$update)!==null)
            sendMessage(achbotMessage('legacy_messages.send_agent_discount_percent'),$cancelKey,'HTML');
        return;
    }
    $result=achbotAgencyDecision($connection,$agencyActor,$userInfo,$applicant,$generation,null,
        achbotCurrentMessage('agency_request_declined'));
    if ($result['state']==='claimed') {
        if ($result['editor_closed']??false)
            \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_buttons.declined'),$agencyActor);
        achbotOperationalReportDispatch($connection,$result['ref'],8.0);
        try {editKeys(json_encode(['inline_keyboard'=>[[['text'=>achbotMessage('legacy_buttons.declined'),'callback_data'=>'wizwizch']]]],JSON_THROW_ON_ERROR));}
        catch(Throwable $ignored){}
    } elseif ($result['editor_closed']??false)
        \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.config_not_found'),$agencyActor);
    else alert(achbotMessage('legacy_messages.config_not_found'));
    return;
}
if (preg_match('/^agencyApprove([1-9][0-9]*)_([1-9][0-9]*)_([1-9][0-9]*)$/D',(string)($userInfo['step']??''),$m) &&
    $text!==achbotMessage('legacy_buttons.cancel')) {
    $source=\AchBot\Telegram\Conversation\source($update,$agencyActor,false);
    if (!$source || !is_string($source->text??null) || $source->message_id<=(int)$m[3]) return;
    $applicant=achbotAgencyPositive($m[1]);$generation=achbotAgencyPositive($m[2]);
    if (!$applicant || !$generation) return;
    $result=achbotAgencyDecision($connection,$agencyActor,$userInfo,$applicant,$generation,$source->text,
        achbotMessage('legacy_messages.agency_request_approved'));
    if ($result['state']==='invalid') {sendMessage(achbotMessage('legacy_messages.send_only_number'),null,'HTML');return;}
    if ($result['state']==='claimed') {
        \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.saved_successfuly'),$agencyActor);
        achbotOperationalReportDispatch($connection,$result['ref'],8.0);
        try {editKeys(json_encode(['inline_keyboard'=>[[['text'=>achbotMessage('legacy_buttons.approved'),'callback_data'=>'wizwizch']]]],JSON_THROW_ON_ERROR),(int)$m[3]);}
        catch(Throwable $ignored){}
    } elseif ($result['editor_closed']??false)
        \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.config_not_found'),$agencyActor);
    else sendMessage(achbotMessage('legacy_messages.config_not_found'),null,'HTML');
    return;
}
