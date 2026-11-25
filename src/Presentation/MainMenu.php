<?php
/** Existing main menu with an explicit recipient context. No Telegram I/O. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Text.php';
require_once __DIR__.'/../Purchase/AgentPricing.php';
require_once __DIR__.'/../../messages/loader.php';

function achbotMainButtonNamespace(): string {
    return "BINARY LEFT(type,12)=BINARY 'MAIN_BUTTONS' AND CHAR_LENGTH(type)>12";
}

function achbotMainMenuForContext($connection,$userInfo,$from_id,$admin,$botState){
    $mainKeys = array();
    $temp = array();
    $isAdminMenu = ($from_id == $admin || $userInfo['isAdmin'] == true);
    $canBuy=achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin);
    $cooperationUrl = \AchBot\Presentation\Text\configuredDestination($botState,'cooperationUrl',true);
    $helpChannelUrl = \AchBot\Presentation\Text\configuredDestination($botState,'helpChannel');

    if(($botState['agencyState'] ?? 'off') == "on" && $userInfo['is_agent'] == 1){
		$mainKeys = array_merge($mainKeys, [
			[['text'=>\achbotMessage('legacy_buttons.agency_setting'),'callback_data'=>"agencySettings"]],
			[['text'=>\achbotMessage('legacy_buttons.sharj'),'callback_data'=>"increaseMyWallet"]],
		]);

		if ($canBuy) {
			$mainKeys[] = [
				['text'=>\achbotMessage('legacy_buttons.agent_one_buy'),'callback_data'=>"agentOneBuy"],
				['text'=>\achbotMessage('legacy_buttons.agent_much_buy'),'callback_data'=>"agentMuchBuy"]
			];
		}
	}else{
        $mainKeys = array_merge($mainKeys,[
            ((($botState['agencyState'] ?? 'off') == "on" && $userInfo['is_agent'] == 0)?[
                ['text'=>\achbotMessage('legacy_buttons.request_agency'),'callback_data'=>"requestAgency"]
            ]: []),
            ($canBuy?
                [['text'=>\achbotMessage('legacy_buttons.sharj'),'callback_data'=>"increaseMyWallet"],['text'=>\achbotMessage('legacy_buttons.buy_subscriptions'),'callback_data'=>"buySubscription"]]
                :
                [['text'=>\achbotMessage('legacy_buttons.sharj'),'callback_data'=>"increaseMyWallet"]]
            )
        ]);
    }

    $mainKeys = array_merge($mainKeys, [
        // Owner-approved conditional row: free trial precedes account/view
        // whenever the unchanged eligibility gate makes it visible.
        ((($botState['testAccount'] ?? 'off') == "on" && ($from_id == $admin || $userInfo['freetrial'] != 'used')) ?
            [['text'=>\achbotMessage('legacy_buttons.test_account'),'callback_data'=>"getTestAccount"]] : []),
        [
            ['text'=>\achbotMessage('legacy_buttons.my_info'),'callback_data'=>"myInfo"],
            ['text'=>\achbotMessage('legacy_buttons.my_subscriptions'),'callback_data'=>
                $userInfo['is_agent']==1?'agentConfigsList':'mySubscriptions']
        ],
        (!$isAdminMenu ? [
            ['text'=>\achbotMessage('legacy_buttons.invite_friends'),'callback_data'=>"inviteFriends"],
            ['text'=>\achbotMessage('legacy_buttons.application_links'),'callback_data'=>"reciveApplications"]
        ] : []),
        ((($botState['individualExistence'] ?? 'off') == "on")?
            [['text'=>\achbotMessage('legacy_buttons.individual_existence'),'callback_data'=>"availableServers2"]] : []
        ),
        // Keep the optional customer import action above the configured
        // cooperation/support URL; the staff search pair stays on one row.
        ((($botState['searchState'] ?? 'off')=="on" || $isAdminMenu)?
            array_merge(
                [['text'=>\achbotMessage('legacy_buttons.search_config'),'callback_data'=>"showUUIDLeft"]],
                ($isAdminMenu ? [[
                    'text'=>\achbotMessage('admin_settings.config.get_main_keys.button.11b0188fcb'),
                    'callback_data'=>"searchUsersConfig"
                ]] : [])
            ) : []
        ),
		(($from_id != $admin && $userInfo['isAdmin'] != true && $cooperationUrl!=='') ?
			[['text' => \achbotMessage('admin_settings.config.get_main_keys.button.b7d6aa7173'), 'url' => $cooperationUrl]] : []
		),
    ]);

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE ".achbotMainButtonNamespace());
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();
    if($buttons->num_rows > 0){
        while($row = $buttons->fetch_assoc()){
            $rowId = $row['id'];
            $title = substr($row['type'],12);

            $temp[] = ['text'=>$title,'callback_data'=>"showMainButtonAns" . $rowId];
            if(count($temp) >= 2){
                array_push($mainKeys, $temp);
                $temp = array();
            }
        }
    }
    array_push($mainKeys, $temp);

    if ($isAdminMenu) {
        array_push($mainKeys, [['text' => \achbotMessage('admin_settings.config.get_main_keys.button.d9c51cced4'), 'callback_data' => "managePanel"]]);
    }

    if ($helpChannelUrl!=='') $mainKeys[]=[['text'=>achbotMessage('instance_content.help_button'),'url'=>$helpChannelUrl]];

    return json_encode(['inline_keyboard' => $mainKeys]);
}

/** For a deferred operator notice use the actual original approver, never
 * webhook/Cron globals. Missing actor profile can omit a menu, not the notice. */
function achbotMainMenuForActor(mysqli $db,int $actor,int $owner,array $state): ?string {
    if ($actor<=0 || $owner<=0) throw new InvalidArgumentException('service_report_actor_invalid');
    $q=$db->prepare('SELECT isAdmin,is_agent,freetrial FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
    if (!$q || !$q->bind_param('i',$actor) || !$q->execute())
        throw new RuntimeException('service_report_actor_menu_unavailable');
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($rows)!==1) return null;
    return achbotMainMenuForContext($db,$rows[0],$actor,$owner,$state);
}
