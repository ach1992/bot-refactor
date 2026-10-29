<?php
/** AChBot administrator levels stored in existing setting rows; users.isAdmin remains boolean. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function achbotAdminLevel(?int $actor=null,?mysqli $db=null,?int $primary=null,bool $lock=false): string {
    $actor=$actor??(int)($GLOBALS['from_id']??0);
    $primary=$primary??(int)($GLOBALS['admin']??0);
    if ($actor<=0 || $primary<=0) return 'none';
    if ($actor===$primary) return 'owner';
    $db=$db??($GLOBALS['connection']??null);
    if (!($db instanceof mysqli)) return 'none';
    try {
        $s=$db->prepare('SELECT id,isAdmin,step FROM users WHERE userid=? LIMIT 2'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$actor);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($rows)!==1 || (int)$rows[0]['isAdmin']!==1 || $rows[0]['step']==='banned') return 'none';
        $key='ADMIN_ROLE_'.$actor;
        $s=$db->prepare('SELECT value FROM setting WHERE type=? LIMIT 2'.($lock?' FOR UPDATE':''));
        $s->bind_param('s',$key);$s->execute();$roles=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($roles)>1) return 'none';
        if ($roles) return in_array($roles[0]['value'],['manager','operator','support'],true)?$roles[0]['value']:'none';
        // Legacy staff have Operator scope until Owner assigns a persisted level.
        return 'operator';
    } catch (Throwable $ignored) {return 'none';}
}
function achbotAdminLevelCan(string $level,string $capability): bool {
    if (!in_array($capability,['admin','admins','stats','servers','plans','bot_settings','gateways',
        'customers','moderation','orders','agents','operations','review'],true)) return false;
    if ($level==='owner') return true;
    if ($capability==='admins') return false;
    if ($level==='manager') return true;
    if ($level==='operator') return in_array($capability,
        ['admin','customers','moderation','orders','agents','operations','review'],true);
    return $level==='support' && in_array($capability,['admin','customers','moderation','orders'],true);
}
function achbotAdminLevelLabel(string $level): string {
    return match($level) {
        'owner'=>achbotMessage('admin.roles.level.owner'),
        'manager'=>achbotMessage('admin.roles.level.manager'),
        'operator'=>achbotMessage('admin.roles.level.operator'),
        'support'=>achbotMessage('admin.roles.level.support'),
        default=>achbotMessage('admin.roles.level.invalid'),
    };
}
function achbotAdminCanFor(mysqli $db,int $actor,int $primary,string $capability): bool {
    return achbotAdminLevelCan(achbotAdminLevel($actor,$db,$primary),$capability);
}
function achbotAdminCan(string $capability,?int $actor=null): bool {
    return achbotAdminLevelCan(achbotAdminLevel($actor),$capability);
}
/** Called after the existing workflow has locked its actor row. Current reads
 * avoid authorizing from an older repeatable-read role snapshot. */
function achbotAdminCanLocked(mysqli $db,int $actor,int $primary,string $capability): bool {
    return achbotAdminLevelCan(achbotAdminLevel($actor,$db,$primary,true),$capability);
}
/** Same persisted membership as the administrator list. The current read also
 * sees newly added staff when a caller already has an older RR snapshot.
 * No transaction is opened or committed around outbound delivery. */
function achbotAdminRecipientCandidates(mysqli $db,int $primary): array {
    if ($primary<=0) return [];
    try {
        $q=$db->prepare('SELECT userid FROM users WHERE isAdmin=1 AND userid<>? ORDER BY id FOR UPDATE');
        $q->bind_param('i',$primary);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        return array_values(array_unique(array_column($rows,'userid'),SORT_STRING));
    } catch (Throwable $ignored) {return [];}
}
/** Privileged outbound copies follow the recipient's current authority, never
 * a cached staff list or stored message reference. */
function achbotAdminRecipientAllowed(mysqli $db,$recipient,int $primary): bool {
    if (is_int($recipient)) $actor=$recipient;
    elseif (is_string($recipient) && preg_match('/^[1-9][0-9]*$/D',$recipient))
        $actor=filter_var($recipient,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    else return false;
    return is_int($actor) && $actor>0 &&
        achbotAdminCanLocked($db,$actor,$primary,'operations');
}
function achbotAdminButtonCapability(string $key): string {
    return match($key) {
        'bot_reports'=>'stats','server_settings'=>'servers',
        'categories_settings','plan_settings'=>'plans',
        'gateways_settings'=>'gateways','bot_settings'=>'bot_settings',
        'admins_list'=>'admins','user_reports'=>'customers',
        'ban_user','unban_user'=>'moderation','manage_order_database'=>'orders',
        'agent_list'=>'agents', default=>'operations',
    };
}
/** Existing callback/state families, grouped by the same visible admin tasks.
 * A null result is a customer action and keeps its own current authorization. */
function achbotAdminRequestCapability(string $value): ?string {
    if ($value==='' || in_array($value,['none','mainMenu','evidenceHome','wizwizch'],true)) return null;
    $groups=[
        'admin'=>'~^managePanel$~D',
        'admins'=>'~^(?:adminsList|addNewAdmin|delAdmin|adminRole\\||adminAssign\\|)~D',
        'review'=>'~^attention(?:Inbox|Case)~D',
        'owner_recovery'=>'~^(?:npReview|bankReview)~D',
        'gateways'=>'~^(?:gateWays_Channels|changeGateWays|changePaymentKeys|achbotPaymentKey\\||gatewayAudienceSettings|cycleGatewayAudience_|paymentPolicy|editPaymentPolicy_|gr\\||editcartToCartAutoAcceptTime)~D',
        'bot_settings'=>'~^(?:botSettings|changeBot|changeUpdateConfigLinkState|changeConfigRemarkType|editSupportID|editRewardChannel|editLockChannel|inviteSetting|inviteBanner|editInvite|editRewaredTime|addExemptUser|removeExempt|achbotExempt:|ic\\||achbotInstance:)~D',
        'stats'=>'~^(?:botReports|show_stats_menu|stats_|top_50_|agent_users_list|plan_sales|server_sales|custom_sales_date|awaitingDateRange)~D',
        'servers'=>'~^(?:serversSetting|nextServerPage|showServerSettings|toggleServerState|addNewServer|addManagedServer|addNewMarzbanPanel|pgConnections|pgAddConnection|pgNewServer|manageMarzbanPanelGroups|addMarzbanPanelGroup|newMarzbanPanelGroup|deleteMarzbanPanelGroup_|editMarzbanGroup|setServerPanelGroup_|changesServer|editServer|editsServer|changeServerType|chhangeServerType|changePortType|changeRealityState|wizwizdeleteserver|yesDeleteServer|marzbanHostSettings|selectHost|saveServerHost|editSubLinks|subLinksInput|serverDisplay|achbotDisplay\\||achbotManage\\||achbotField\\||am\\||addserverName|addServer|pg:|pgRotate|pgServerGroups)~D',
        'plans'=>'~^(?:backplan|planDetails|plansList|addNewPlan|addNewRahgozarPlan|editDestName|editSpiderX|editFlow|newPlanForServer|addManagedPlan|addNewMarzbanPlan|planDays|planVolume|pgPlanGroups|categoriesSetting|nextCategoryPage|addNewCategory|wizwizcategory|wizwizplan|editCustom|editPFlow|volumePlanSettings|dayPlanSettings|addNewDayPlan|addNewVolumePlan|deleteDayPlan|deleteVolumePlan|changeDayPlan|changeVolumePlan)~D',
        'agents'=>'~^(?:agentsList|nextAgentList|agentDetails|removeAgent|toggleAgent_|agentPercentDetails|editAgentDiscount|nextAgentDiscount|addDiscountServerAgent|addDiscountPlanAgent|removePercentOfAgent|agencyApprove|agencyDecline|rejectedAgentList|releaseRejectedAgent)~D',
        'customers'=>'~^(?:userReports|userInfoDetails|quickUserDetails)~D',
        'moderation'=>'~^(?:banUser|unbanUser|toggleBan_)~D',
        'orders'=>'~^(?:manageorderdatabase|showUserSubscriptions|selectOrderByRemark_userid_|returnToPanel_|deleteOrder_|confirmDeleteOrder_|toggleNotif_|orderWarningStatus_|changeExpireDate_|changeToken_|changeUUID_|changeServerId_|selectServerId_|changeFileId_|selectFileId_|quickUserOrderDetails_|userOrderDetails|searchUsersConfig|adminToggleAutoRenew|awaitingOrderRemarkOnly|awaitingUserIdSelection_|manageOrderById_|awaitingExpireChange_|awaitingToken_|awaitingUUID_)~D',
        'operations'=>'~^(?:managePanel|messageToSpeceficUser|sendMessageToUser|increaseUserWallet|decreaseUserWallet|achbotWalletAdmin|increaseWalletUser|decreaseWalletUser|debtWalletUser|achbotWalletReason:|achbotWalletDebt:|createUserWalletDebt|walletDebt|addSubscriptionToCustomer|createMultipleAccounts|createAcc|awaiting_userid_input|giftVolumeAndDay|giftTo|giftServer|achbotDirectGift|mainMenuButtons|delMainButton|addNewMainButton|setMainButtonAnswer|discount_codes|addDiscount|editDiscount|delDiscount|editD|setDiscount|removeDiscountUser|toggleDiscount|copyHash|message2All|broadcast|editBroadcastAsk_|chooseSendGroup_|cancelBroadcast|noDontSend2all|yesSend2All|pinManageAll|pinManageSelect_|jobCancel_|jobCreate_|jobsStatus|s2a_|bc_edit_|ticketsList|ticketsCategory|addTicketCategory|delTicketCat_|allTickets|newTickets|openTickets|reply_|answer_|accept[A-Za-z0-9_-]|accCustom|approveRenewAcc|approveIncreaseDay|approveIncreaseVolume|approvePayment|declineAction|changeCustomPort|changeCustomSni|changeCustomPath|pgResetAccount)~D',
    ];
    foreach ($groups as $capability=>$pattern) if (preg_match($pattern,$value)) return $capability;
    return null;
}
function achbotAdminRequestAllowed(?string $callback,?string $text,string $step): bool {
    // Home/cancel always allows leaving a stale staff editor after demotion.
    // Callback message text is not fresh typed input and cannot provide escape.
    if (in_array($callback,['mainMenu','evidenceHome'],true) ||
        (($callback??'')==='' && $text!==null &&
         (preg_match('~^/[Ss]tart(?:\\s|$)~D',$text) ||
          $text===($GLOBALS['buttonValues']['cancel']??null)))) return true;
    foreach (array_unique([(string)$callback,$step]) as $value) {
        $capability=achbotAdminRequestCapability($value);
        if ($capability===null) continue;
        if ($capability==='owner_recovery') {
            if (achbotAdminLevel()!=='owner') return false;
        } elseif (!achbotAdminCan($capability)) return false;
    }
    return true;
}
/** Shared ticket buttons are customer routes; only their own ticket or a
 * current operations-capable staff actor may inspect/manage another ticket. */
function achbotAdminTicketAllowed(mysqli $db,int $actor,int $primary,int $ticket,bool $staff=true): bool {
    if ($actor<=0 || $ticket<=0) return false;
    if ($staff && achbotAdminCanFor($db,$actor,$primary,'operations')) return true;
    try {
        $q=$db->prepare('SELECT user_id FROM chats WHERE id=?');
        $q->bind_param('i',$ticket);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        return count($rows)===1 && (int)$rows[0]['user_id']===$actor;
    } catch (Throwable $ignored) {return false;}
}
function achbotAdminDeny(): void {
    $message=achbotMessage('admin.roles.denied');
    if (!empty($GLOBALS['callbackId'])) answerCallbackQuery($GLOBALS['callbackId'],$message,true);
    else sendMessage($message,null,'HTML');
}
/** Owner-only idempotent staff assignment; no new tables or private config edits. */
function achbotAdminAssignLevel(mysqli $db,int $owner,int $primary,int $target,?string $level): bool {
    if ($owner<=0 || $owner!==$primary || $target<=0 || $target===$primary ||
        ($level!==null && !in_array($level,['manager','operator','support'],true))) return false;
    $begun=false;
    try {
        if (!$db->begin_transaction()) return false;$begun=true;
        $s=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
        $s->bind_param('i',$target);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($rows)!==1) throw new RuntimeException('admin_target_unavailable');
        $key='ADMIN_ROLE_'.$target;
        $s=$db->prepare('SELECT id FROM setting WHERE type=? LIMIT 2 FOR UPDATE');
        $s->bind_param('s',$key);$s->execute();$roles=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($roles)>1) throw new RuntimeException('admin_role_ambiguous');
        $enabled=$level===null?0:1;$pk=(int)$rows[0]['id'];
        $s=$db->prepare('UPDATE users SET isAdmin=? WHERE id=?');$s->bind_param('ii',$enabled,$pk);$s->execute();$s->close();
        if ($level===null) {
            $s=$db->prepare('DELETE FROM setting WHERE type=?');$s->bind_param('s',$key);
        } elseif ($roles) {
            $s=$db->prepare('UPDATE setting SET value=? WHERE id=?');$roleId=(int)$roles[0]['id'];$s->bind_param('si',$level,$roleId);
        } else {
            $s=$db->prepare('INSERT INTO setting(type,value) VALUES(?,?)');$s->bind_param('ss',$key,$level);
        }
        $s->execute();$s->close();
        if (!$db->commit()) throw new RuntimeException('admin_commit_unconfirmed');
        return true;
    } catch (Throwable $ignored) {
        if ($begun) try {$db->rollback();} catch (Throwable $ignoredRollback) {}
        return false;
    }
}
