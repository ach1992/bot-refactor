<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Access.php';
require_once __DIR__.'/../Telegram/Conversation.php';
require_once __DIR__.'/../Telegram/Presentation.php';
require_once __DIR__.'/../Finance/ReferralReward.php';
require_once __DIR__.'/../Panel/ProviderStorage.php';

function achbotReferralSettingAuthorize(mysqli $db,int $actor): void {
    if (!achbotAdminCanLocked($db,$actor,(int)$GLOBALS['admin'],'bot_settings'))
        throw new DomainException('referral_setting_role_revoked');
}

/** Both invitation singleton writers use the existing named-writer-lock /
 * actor -> exact setting PK order and shared atomic editor transition.
 * No full-table singleton lookup locks or duplicate insertion, no dependence on BOT_STATES
 * or successful Telegram cleanup. A pre-existing ambiguous setting is not
 * silently overwritten or deduplicated. */
function achbotReferralSettingSave(mysqli $db,int $actor,array $cached,string $value,$update): bool {
    $editor=\AchBot\Telegram\Conversation\referralSettingEditor((string)($cached['step']??''));
    if (!$editor) return false;
    $type=$editor['action']==='editInviteAmount'?'INVITE_BANNER_AMOUNT':'INVITE_BANNER_TEXT';
    if ($type==='INVITE_BANNER_AMOUNT' && achbotPurchaseReferralAmount($value)===null) return false;
    $lock=null;$held=false;
    try {
        $lock=achbotDatabaseLockName($db,'referral-setting:'.$type);
        $held=(int)(achbotProviderRow($db,'SELECT GET_LOCK(?,10) AS acquired','s',[$lock])['acquired']??0)===1;
        if (!$held) return false;
        return \AchBot\Telegram\Conversation\transitionEditor($db,$actor,$cached,'none',null,
            static function(mysqli $db) use($actor,$type,$value): void {
                achbotReferralSettingAuthorize($db,$actor);
                $s=achbotProviderStatement($db,'SELECT id FROM setting WHERE type=? ORDER BY id LIMIT 2','s',[$type]);
                $ids=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
                if (count($ids)>1) throw new RuntimeException('referral_setting_ambiguous');
                if ($ids) {
                    $id=(int)$ids[0]['id'];
                    $row=achbotProviderRow($db,'SELECT id FROM setting WHERE id=? AND type=? FOR UPDATE','is',[$id,$type]);
                    if (!$row) throw new RuntimeException('referral_setting_changed');
                    $s=achbotProviderStatement($db,'UPDATE setting SET value=? WHERE id=?','si',[$value,$id]);
                    $s->close();
                } else {
                    $s=achbotProviderStatement($db,'INSERT INTO setting(type,value) VALUES(?,?)','ss',[$type,$value]);
                    $id=$s->insert_id;$s->close();
                }
                $stored=achbotProviderRow($db,'SELECT value FROM setting WHERE id=? AND type=?','is',[$id,$type]);
                if (!$stored || $stored['value']!==$value) throw new RuntimeException('referral_setting_write_unconfirmed');
            },$update)!==null;
    } catch(Throwable $e) {
        error_log('achbot_referral_setting_deferred actor='.$actor.' class='.get_class($e));
        return false;
    } finally {
        if ($held) try {achbotProviderRow($db,'SELECT RELEASE_LOCK(?) AS released','s',[$lock]);}
        catch(Throwable $ignored) {}
    }
}
function achbotReferralSettingKeys(string $amount): string {
    return json_encode(['inline_keyboard'=>[
        [['text'=>achbotMessage('bot.bot.invite_setting.button.e5798de28e'),'callback_data'=>'inviteBanner']],
        [['text'=>$amount,'callback_data'=>'editInviteAmount'],
         ['text'=>achbotMessage('bot.bot.invite_setting.button.0854688c71'),'callback_data'=>'wizwizch']],
        [['text'=>achbotMessage('legacy_buttons.back_button'),'callback_data'=>'botSettings']]
    ]],JSON_THROW_ON_ERROR);
}
function achbotReferralBannerKeys(): string {
    return json_encode(['inline_keyboard'=>[
        [['text'=>achbotMessage('labels.edit'),'callback_data'=>'editInviteBannerText']],
        [['text'=>achbotMessage('legacy_buttons.back_button'),'callback_data'=>'inviteSetting']]
    ]],JSON_THROW_ON_ERROR);
}
function achbotReferralAmountLabel(int $amount): string {
    return number_format($amount).achbotMessage('labels.currency_toman_suffix');
}
function achbotReferralSettingMenuAmount(mysqli $db): string {
    try {return achbotReferralAmountLabel(achbotPurchaseReferralPolicyAmount($db));}
    catch(Throwable $e) {return achbotMessage('bot.bot.edit_invite_amount.message.736a04e908');}
}

function achbotReferralSettingsRoute(mysqli $db,int $actor,$update,array $cached,string $cancelKey): bool {
    $data=$update->callback_query->data??null;
    $isCallback=in_array($data,['inviteSetting','inviteBanner','editInviteAmount','editInviteBannerText'],true);
    $editor=\AchBot\Telegram\Conversation\referralSettingEditor((string)($cached['step']??''));
    $text=$update->message->text??null;
    if (!$isCallback && (!$editor || isset($update->callback_query) ||
        $text===achbotMessage('legacy_buttons.cancel') ||
        \AchBot\Telegram\Conversation\homeRequested($update,$actor))) return false;
    $source=\AchBot\Telegram\Conversation\source($update,$actor,$isCallback);
    if (!$source || ($isCallback && ($source->from->is_bot??false)!==true) ||
        !achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'bot_settings')) {
        achbotAdminDeny();return true;
    }
    try {
        $generation=$editor?\AchBot\Telegram\Conversation\editorOpeningGeneration($cached):null;
        $updateId=$update->update_id??null;
        if ($isCallback) {
            $edit=$data==='editInviteAmount' || $data==='editInviteBannerText';
            $openedAt=time();
            // A replayed older invitation control cannot replace the newer
            // active editor. Fresh navigation may recover malformed state.
            if ($generation && !\AchBot\Telegram\Conversation\updateAfterOpening($generation,$updateId,$openedAt)) return true;
            if ($edit && (!is_int($updateId) || $updateId<=0)) return true;
            $row=\AchBot\Telegram\Conversation\transitionEditor($db,$actor,$cached,
                $edit?$data.'_'.$source->message_id:'none',$edit?$updateId.':'.$openedAt:null,
                static fn(mysqli $db)=>achbotReferralSettingAuthorize($db,$actor),$update);
            if (!$row) return true;
            if ($edit) {
                // Telegram prompt retirement is optional, never a save gate.
                try {delMessage($source->message_id,$actor);} catch(Throwable $ignored){}
                sendMessage($data==='editInviteAmount'?achbotMessage('bot.bot.edit_invite_amount.message.0372e8b9cd'):
                    achbotMessage('bot.bot.edit_invite_banner_text.message.28f265f083'),$cancelKey,'HTML',$actor);
                return true;
            }
            if (\AchBot\Telegram\Conversation\referralSettingEditor($row['step']))
                \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.operation_canceled'),$actor);
            if ($data==='inviteSetting') {
                \AchBot\Telegram\Conversation\replaceTransient($actor,$source->message_id,
                    achbotMessage('bot.bot.invite_setting.message.554f354e70'),achbotReferralSettingKeys(achbotReferralSettingMenuAmount($db)));
            } else {
                $row=achbotProviderRow($db,"SELECT value FROM setting WHERE type='INVITE_BANNER_TEXT' ORDER BY id LIMIT 1");
                $banner=json_decode((string)($row['value']??''),true);
                $keys=achbotReferralBannerKeys();
                if (($banner['type']??null)==='photo') {
                    try {delMessage($source->message_id,$actor);} catch(Throwable $ignored){}
                    $res=sendPhoto($banner['file_id'],$banner['caption'],$keys,'HTML',$actor);
                    if (($res->ok??null)===false)
                        sendMessage(achbotMessage('bot.bot.invite_banner.message.e5b34cbee4'),$keys,'HTML',$actor);
                } else {
                    \AchBot\Telegram\Conversation\replaceTransient($actor,$source->message_id,
                        achbotMessage('bot.bot.invite_banner.message.379af3c42e').($banner['text']??''),$keys);
                }
            }
            return true;
        }
        if ($source->message_id<=$editor['source'] || !$generation ||
            !\AchBot\Telegram\Conversation\updateAfterOpening($generation,$updateId,$source->date??null)) return true;
        if ($editor['action']==='editInviteAmount') {
            $raw=is_string($source->text??null)?trim($source->text):'';
            // Normalize ordinary digit input, then use exactly the reader's
            // canonical representation and existing amount domain.
            $digits=ltrim($raw,'0');
            $amount=$raw===''?null:achbotPurchaseReferralAmount($digits===''?'0':$digits);
            if ($amount===null) {sendMessage(achbotMessage('legacy_messages.send_only_number'),null,'HTML',$actor);return true;}
            if (!achbotReferralSettingSave($db,$actor,$cached,(string)$amount,$update)) return true;
            \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.saved_successfuly'),$actor);
            sendMessage(achbotMessage('bot.bot.invite_setting.message.554f354e70'),achbotReferralSettingKeys(achbotReferralAmountLabel($amount)),'HTML',$actor);
        } else {
            if (is_array($source->photo??null) && $source->photo) {
                $photo=end($source->photo)->file_id??null;
                if (!is_string($photo) || $photo==='') return true;
                $caption=(string)($source->caption??'');
                $banner=['type'=>'photo','caption'=>!empty($source->caption_entities)?
                    \AchBot\Telegram\Presentation\incomingHtml($caption,(array)$source->caption_entities):$caption,'file_id'=>$photo];
            } elseif (is_string($source->text??null)) {
                $banner=['type'=>'text','text'=>!empty($source->entities)?
                    \AchBot\Telegram\Presentation\incomingHtml($source->text,(array)$source->entities):$source->text];
            } else {
                sendMessage(achbotMessage('bot.bot.edit_invite_banner_text.message.155c179a84'),null,'HTML',$actor);
                return true;
            }
            if (!achbotReferralSettingSave($db,$actor,$cached,json_encode($banner,JSON_THROW_ON_ERROR),$update)) return true;
            \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.saved_successfuly'),$actor);
            $keys=achbotReferralBannerKeys();
            if ($banner['type']==='text')
                sendMessage(achbotMessage('bot.bot.invite_banner.message.379af3c42e').$banner['text'],$keys,'HTML',$actor);
            else sendPhoto($banner['file_id'],$banner['caption'],$keys,'HTML',$actor);
        }
    } catch(Throwable $e) {
        error_log('achbot_referral_settings_route_deferred actor='.$actor.' class='.get_class($e));
    }
    return true;
}
