<?php
/** AChBot V5: broadcast administration and job lifecycle; legacy caller scope and side effects are intentional. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


// BEGIN send to all
if($data == 'message2All' && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $stmt = $connection->prepare("SELECT * FROM `send_list` WHERE `state` IN (1,2) ORDER BY `id` ASC LIMIT 1");
    $stmt->execute();
    $info = $stmt->get_result();
    $stmt->close();

    if($info->num_rows > 0){
        $sendInfo = $info->fetch_assoc();

        $jobId  = (int)$sendInfo['id'];
        $group  = $sendInfo['target_group'] ?? 'all';
        $type   = $sendInfo['type'] ?? 'text';
        $title  = $sendInfo['title'] ?? '';
        $offset = (int)($sendInfo['offset'] ?? 0);
			
		$usersCount = getUsersCountByGroup($connection, $group);
		$kind = ($type === 'forwardall') ? \achbotMessage('broadcast.routes.message2_all.text.e92414445c') : \achbotMessage('broadcast.routes.message2_all.text.a8b7952c88');
        $titleLine = $title ? \achbotMessage('broadcast.routes.message2_all.text.b00aae4551', ['title' => $title]) : "";

		$sentCount = 0;
		$stc = $connection->prepare("
			SELECT COUNT(*) AS c
			FROM `bc_messages`
			WHERE `broadcast_id`=? AND `deleted`=0
		");
		$stc->bind_param("i", $jobId);
		$stc->execute();
		$rr = $stc->get_result();
		if($rr && $rr->num_rows > 0){
			$sentCount = (int)$rr->fetch_assoc()['c'];
		}
		$stc->close();

		$left = max(0, $usersCount - $sentCount);

		$lastUserIdProcessed = $offset;

		$statusText =
			\achbotMessage('broadcast.routes.message2_all.status_text.0f1b386506', ['kind' => $kind]).
			$titleLine.
			\achbotMessage('broadcast.routes.message2_all.text.a22c91864d').groupFa($group)."\n".
			\achbotMessage('broadcast.routes.message2_all.text.d9e95941c1', ['usersCount' => $usersCount]).
			\achbotMessage('broadcast.routes.message2_all.text.e082dd3b03', ['sentCount' => $sentCount]).
			\achbotMessage('broadcast.routes.message2_all.text.6a89c76a24', ['left' => $left]).
			\achbotMessage('broadcast.routes.message2_all.text.c57348613a', ['lastUserIdProcessed' => $lastUserIdProcessed]);
	

        sendMessage($statusText, json_encode([
            'inline_keyboard' => [
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.bf542d764e'), 'callback_data'=>"cancelBroadcast".$jobId]],
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.0bb9320093'), 'callback_data'=>"broadcastHistory_0"]],
                [['text'=>$buttonValues['start_bot'], 'callback_data'=>"mainMenu"]],
            ]
        ]));
    } else {
        sendMessage(\achbotMessage('broadcast.routes.message2_all.message.3c4afc11a6'), json_encode([
            'inline_keyboard' => [
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.7dba3a00f7'), 'callback_data'=>"broadcastStart"]],
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.0bb9320093'), 'callback_data'=>"broadcastHistory_0"]],
				[['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"mainMenu"]],
            ]
        ]));
    }
}

if($data == 'broadcastStart' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.76bb0d02d0'), json_encode([
        'inline_keyboard' => [
            [['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.fac6686c54'),'callback_data'=>"chooseSendGroup_all"]],
            [['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.ebc2cb2366'),'callback_data'=>"chooseSendGroup_has"]],
            [['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.49b4568f0c'),'callback_data'=>"chooseSendGroup_none"]],
            [['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.706e4a7807'),'callback_data'=>"chooseSendGroup_agents"]],
        ]
    ]));
}

if(preg_match('/^cancelBroadcast(\d+)/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $id = (int)$m[1];

    $stmt = $connection->prepare("UPDATE `send_list` SET `state` = 4 WHERE `id` = ? AND `state` IN (1,2)");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if($affected > 0){
        editText($message_id, \achbotMessage('broadcast.routes.broadcast_start.message.39fe6c5d7e'), getMainKeys());
    } else {
        editText($message_id, \achbotMessage('broadcast.routes.broadcast_start.message.78511c235c'), getMainKeys());
    }
}

if(preg_match('/^chooseSendGroup_(all|has|none|agents)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $group = $match[1];
    setUser("s2a_title_$group");
    sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.f7c7bcfe5e'), $cancelKey);
}

if (preg_match('/^s2a_title_(all|has|none|agents)$/', $userInfo['step'], $match)
    && $text != $buttonValues['cancel']
    && ($from_id == $admin || $userInfo['isAdmin'] == true)) {

    $group = $match[1];
    $title = trim(preg_replace('/\s+/u', ' ', (string)$text));

    if (mb_strlen($title, 'UTF-8') > 255) {
        sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.adb061a104'), $cancelKey);
        setUser("s2a_title_$group");
        return;
    }

    if (!preg_match('/^[\p{L}\p{N}\p{P}\p{S} \-_\+\(\)=\/\\\\!]+$/u', $title)) {
        sendMessage(
            \achbotMessage('broadcast.routes.broadcast_start.message.9df7171b87').
            \achbotMessage('broadcast.routes.broadcast_start.message.b6d57f844e'),
            $cancelKey
        );
        setUser("s2a_title_$group");
        return;
    }

    $emptyText = '';
    $state0 = 0;

    $stmt = $connection->prepare(
        "INSERT INTO send_list (type, title, text, target_group, state)
         VALUES ('draft', ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssi", $title, $emptyText, $group, $state0);
    $stmt->execute();

    $draftId = (int)$stmt->insert_id;
    $stmt->close();

    if ($draftId <= 0) {
        sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.0c01c8169a'), $cancelKey);
        setUser("s2a_title_$group");
        return;
    }

    setUser("s2a_msg_$draftId");
    sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.45cbfc9bc3'), $cancelKey);
}

if(preg_match('/^s2a_msg_(\d+)$/', $userInfo['step'], $match)
   && $text != $buttonValues['cancel']
   && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $sendId = (int)$match[1];
    setUser();

	if(isset($update->message) && isForwardedMessageObj($update->message)){

		$preview = '';
		if(isset($update->message->text)) {
			$preview = (string)$update->message->text;
		} elseif(isset($update->message->caption)) {
			$preview = (string)$update->message->caption;
		}

		$preview = trim($preview);
		if(mb_strlen($preview, 'UTF-8') > 300){
			$preview = mb_substr($preview, 0, 300, 'UTF-8') . \achbotMessage('broadcast.routes.broadcast_start.text.b4935894c8');
		}

		$stmt = $connection->prepare("
			UPDATE `send_list`
			SET `type`='forwardall', `message_id`=?, `chat_id`=?, `text`=?
			WHERE `id`=? AND `state`=0
		");
		$stmt->bind_param("sssi", $message_id, $chat_id, $preview, $sendId);
		$stmt->execute();
		$stmt->close();

		sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.e3d28dd838'), $removeKeyboard);
		sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.a3919d081e'), json_encode(['inline_keyboard'=>[
			[['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.8157c7d122'),'callback_data'=>"yesSend2All".$sendId],['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.5734fc6a07'),'callback_data'=>"noDontSend2all".$sendId]]
		]]));
		return;
	}

    if(isset($fileid) && $fileid !== null) {
        $stmt = $connection->prepare("
            UPDATE `send_list`
            SET `type`=?, `text`=?, `file_id`=?
            WHERE `id`=? AND `state`=0
        ");
        $stmt->bind_param("sssi", $filetype, $caption, $fileid, $sendId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $connection->prepare("
            UPDATE `send_list`
            SET `type`='text', `text`=?
            WHERE `id`=? AND `state`=0
        ");
        $stmt->bind_param("si", $text, $sendId);
        $stmt->execute();
        $stmt->close();
    }

    sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.e3d28dd838'), $removeKeyboard);
    sendMessage(\achbotMessage('broadcast.routes.broadcast_start.message.f041aa9600'), json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.8157c7d122'),'callback_data'=>"yesSend2All".$sendId],['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.5734fc6a07'),'callback_data'=>"noDontSend2all".$sendId]]
    ]]));
}

if(preg_match('/^noDontSend2all(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $id = (int)$match[1];

    $stmt = $connection->prepare("UPDATE `send_list` SET `state`=4 WHERE `id`=? AND `state` IN (0,1,2)");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    editText($message_id, \achbotMessage('broadcast.routes.broadcast_start.message.372ee64c90'), getMainKeys());
}

if(preg_match('/^yesSend2All(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $id = (int)$match[1];

    $stmt = $connection->prepare("UPDATE `send_list` SET `state`=1 WHERE `id`=? AND `state`=0");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    editText($message_id, \achbotMessage('broadcast.routes.broadcast_start.message.b56da4fdde'), getMainKeys());
}

if(preg_match('/^broadcastHistory_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $page = (int)$m[1];
    if($page < 0) $page = 0;

    $limit = 10;
    $off = $page * $limit;
	
	$stmt = $connection->prepare("
		SELECT 
			s.`id`, s.`title`, s.`type`, s.`target_group`, s.`state`, s.`offset`, s.`created_at`,
			IFNULL(m.sent, 0) AS sent_count
		FROM `send_list` s
		LEFT JOIN (
			SELECT `broadcast_id`, COUNT(*) AS sent
			FROM `bc_messages`
			WHERE `deleted`=0
			GROUP BY `broadcast_id`
		) m ON m.`broadcast_id` = s.`id`
		WHERE s.`state` IN (3,4)
		ORDER BY s.`id` DESC
		LIMIT ? OFFSET ?
	");

    $stmt->bind_param("ii", $limit, $off);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $items = [];
    while($row = $res->fetch_assoc()){
        $id    = (int)$row['id'];
        $title = $row['title'] ?: \achbotMessage('broadcast.routes.broadcast_start.text.bddb5c40d4');
        $type  = ($row['type'] === 'forwardall') ? \achbotMessage('broadcast.routes.broadcast_start.text.0dc38e658c') : \achbotMessage('broadcast.routes.broadcast_start.text.f910f3eee6');
        $group = $row['target_group'] ?? 'all';
        $st    = stateFa((int)$row['state']);
        $sent = (int)($row['sent_count'] ?? 0);
		$lastId = (int)($row['offset'] ?? 0);
        $dt    = $row['created_at'] ?? '';

        $items[] =
            \achbotMessage('broadcast.routes.broadcast_start.id_type.b95c2c743a', ['id' => $id, 'type' => $type]).groupFa($group).\achbotMessage('broadcast.routes.broadcast_start.text.2af0046efa', ['st' => $st, 'sent' => $sent, 'lastId' => $lastId]).
            \achbotMessage('broadcast.routes.broadcast_start.title.eaf516bb5a', ['title' => $title]).
            \achbotMessage('broadcast.routes.broadcast_start.dt.ba0c133dbc', ['dt' => $dt]);
    }

    $out = \achbotMessage('broadcast.routes.broadcast_start.out.661347ab91').($page+1).")\n\n";
    $out .= $items ? implode(\achbotMessage('broadcast.routes.broadcast_start.text.8d78e8b033'), $items) : \achbotMessage('broadcast.routes.broadcast_start.text.dd189f637d');

    $kb = [];
    if($page > 0){
        $kb[] = [['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.4210cf7432'), 'callback_data'=>"broadcastHistory_".($page-1)]];
    }
    $kb[] = [['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.876ba507e6'), 'callback_data'=>"broadcastHistory_".($page+1)]];
    $kb[] = [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'), 'callback_data'=>"message2All"]];

    sendMessage($out, json_encode(['inline_keyboard'=>$kb]));
}
// END send to all

// BEGIN Broadcast Pin Management Panel
if($data == 'pinManageAll' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $data = "pinManageAllPage_0";
}

if(preg_match('/^pinManageAllPage_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
 
    $page = max(0, (int)$m[1]); 
    $limit = 20; 
    $off = $page * $limit; 
 
    $c = $connection->prepare("SELECT COUNT(*) AS cnt FROM `send_list` WHERE `state`=3"); 
    $c->execute(); 
    $totalRows = (int)$c->get_result()->fetch_assoc()['cnt']; 
    $c->close(); 
 
    $stmt = $connection->prepare(" 
        SELECT 
            s.`id`, s.`type`, s.`target_group`, 
            COALESCE(x.total_cnt,0) AS total_cnt, 
            COALESCE(x.pinned_cnt,0) AS pinned_cnt, 
            COALESCE(x.deleted_cnt,0) AS deleted_cnt 
        FROM `send_list` s 
        LEFT JOIN ( 
            SELECT 
                `broadcast_id`, 
                COUNT(*) AS total_cnt, 
                SUM(CASE WHEN `pinned`=1 THEN 1 ELSE 0 END) AS pinned_cnt, 
                SUM(CASE WHEN `deleted`=1 THEN 1 ELSE 0 END) AS deleted_cnt 
            FROM `bc_messages` 
            GROUP BY `broadcast_id` 
        ) x ON x.broadcast_id = s.id 
        WHERE s.`state` = 3 
        ORDER BY s.`id` DESC 
        LIMIT ? OFFSET ? 
    "); 
    $stmt->bind_param("ii", $limit, $off); 
    $stmt->execute(); 
    $res = $stmt->get_result(); 
    $stmt->close(); 
 
    if(!$res || $res->num_rows == 0){ 
        sendMessage(\achbotMessage('broadcast.routes.broadcast_start.text.dd189f637d'), json_encode([
            'inline_keyboard'=>[ 
                [['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.adbdd32809'),'callback_data'=>"jobsStatus_0"]],
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"mainMenu"]]
            ] 
        ])); 
        return; 
    } 
 
    $kb = []; 
 
    $kb[] = [ 
        ['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.7e419b9f57'), 'callback_data'=>"pmNoop"],
        ['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.b4b49ad6b9'), 'callback_data'=>"pmNoop"],
        ['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.0a8c3c1d0f'), 'callback_data'=>"pmNoop"],
        ['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.dfe7a8cc72'), 'callback_data'=>"pmNoop"],
    ]; 
 
    while($row = $res->fetch_assoc()){ 
        $bid = (int)$row['id']; 
        $group = groupFa($row['target_group'] ?? 'all'); 
 
        $total  = (int)$row['total_cnt']; 
        $pinned = (int)$row['pinned_cnt']; 
 
        if($total > 0 && $pinned == $total){ 
            $pinTxt = \achbotMessage('broadcast.routes.pin_manage_all.pin_txt.00c693eabb');
        } elseif($pinned > 0){ 
            $pinTxt = \achbotMessage('broadcast.routes.pin_manage_all.pin_txt.878906729e');
        } else { 
            $pinTxt = \achbotMessage('broadcast.routes.pin_manage_all.pin_txt.a17e88e7f0');
        } 
 
        $kb[] = [ 
            ['text'=>"#".$bid, 'callback_data'=>"pinManageSelect_$bid"], 
            ['text'=>$group, 'callback_data'=>"pmNoop"], 
            ['text'=>$pinTxt, 'callback_data'=>"pmNoop"], 
            ['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.1780efa62b'), 'callback_data'=>"pinManageSelect_$bid"],
        ]; 
    } 
 
    $nav = []; 
    if($page > 0){ 
        $nav[] = ['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.4210cf7432'), 'callback_data'=>"pinManageAllPage_".($page-1)];
    } 
 
    $maxPage = (int)floor(max(0, $totalRows - 1) / $limit); 
    if($page < $maxPage){ 
        $nav[] = ['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.876ba507e6'), 'callback_data'=>"pinManageAllPage_".($page+1)];
    } 
    if(!empty($nav)){ 
        $kb[] = $nav; 
    } 
 
    $kb[] = [['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.adbdd32809'),'callback_data'=>"jobsStatus_0"]];
    $kb[] = [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'), 'callback_data'=>"mainMenu"]];
 
    $text = \achbotMessage('broadcast.routes.pin_manage_all.text.4fd6482802')
          . \achbotMessage('broadcast.routes.pin_manage_all.text.3586bc29c6').($page+1).\achbotMessage('broadcast.routes.pin_manage_all.text.a624a1e41f').($maxPage+1)."\n"
          . \achbotMessage('broadcast.routes.pin_manage_all.text.58592602aa');
 
    sendMessage($text, json_encode(['inline_keyboard'=>$kb])); 
}

if($data == 'pmNoop' && isset($update->callback_query)){
    bot('answerCallbackQuery', [
        'callback_query_id' => $update->callback_query->id,
        'text' => ''
    ]);
}

if(preg_match('/^pinManageSelect_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    $bid = (int)$m[1]; 
 
    $stmt = $connection->prepare(" 
        SELECT `id`,`title`,`type`,`target_group`,`created_at`,`sample_userid`,`sample_message_id`, `text`
        FROM `send_list` 
        WHERE `id`=? LIMIT 1 
    "); 
    $stmt->bind_param("i",$bid); 
    $stmt->execute(); 
    $r = $stmt->get_result(); 
    $stmt->close(); 
 
    if(!$r || $r->num_rows==0){ 
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.dd6f985b6d'), json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageAll"]]
        ]])); 
        return; 
    } 
 
    $info = $r->fetch_assoc(); 
 
    $title = $info['title'] ?: \achbotMessage('broadcast.routes.broadcast_start.text.bddb5c40d4');
    $tp = ($info['type']==='forwardall') ? \achbotMessage('broadcast.routes.broadcast_start.text.0dc38e658c') : \achbotMessage('broadcast.routes.broadcast_start.text.f910f3eee6');
    $g  = groupFa($info['target_group'] ?? 'all'); 
    $dt = $info['created_at'] ?? ''; 
	 
	$previewText = trim((string)($info['text'] ?? '')); 
	if($previewText === ''){ 
		$previewText = \achbotMessage('broadcast.routes.pm_noop.preview_text.6c87d45576');
	} 
 
    $sampleU = (int)($info['sample_userid'] ?? 0); 
    $sampleM = (int)($info['sample_message_id'] ?? 0); 
 
    $st = $connection->prepare(" 
        SELECT 
          COUNT(*) AS total_cnt, 
          SUM(CASE WHEN pinned=1 THEN 1 ELSE 0 END) AS pinned_cnt, 
          SUM(CASE WHEN deleted=1 THEN 1 ELSE 0 END) AS deleted_cnt 
        FROM `bc_messages` 
        WHERE `broadcast_id`=? 
    "); 
    $st->bind_param("i",$bid); 
    $st->execute(); 
    $sr = $st->get_result()->fetch_assoc(); 
    $st->close(); 
 
    $total = (int)($sr['total_cnt'] ?? 0); 
    $pinned = (int)($sr['pinned_cnt'] ?? 0); 
    $deleted = (int)($sr['deleted_cnt'] ?? 0); 
 
    $out = 
        \achbotMessage('broadcast.routes.pm_noop.out.94647cb3a5', ['bid' => $bid]).
        \achbotMessage('broadcast.routes.message2_all.text.b00aae4551', ['title' => $title]).
        \achbotMessage('broadcast.routes.pm_noop.text.b59e6ba611', ['tp' => $tp]).
        \achbotMessage('broadcast.routes.pm_noop.text.daf77714f9', ['g' => $g]).
        \achbotMessage('broadcast.routes.pm_noop.text.24129349bd', ['dt' => $dt]).
        \achbotMessage('broadcast.routes.pm_noop.text.bca58ca580', ['previewText' => $previewText]).
        \achbotMessage('broadcast.routes.pm_noop.text.d1e67bbdc2', ['total' => $total]).
        \achbotMessage('broadcast.routes.pm_noop.text.dfcfc0a515', ['pinned' => $pinned]).
        \achbotMessage('broadcast.routes.pm_noop.text.6a21ff47a8', ['deleted' => $deleted]).
        \achbotMessage('broadcast.routes.pm_noop.text.4926bd2ec3');
 
    $kb = [];

    if(!($total > 0 && $deleted >= $total)){
        $kb[] = [['text'=>\achbotMessage('broadcast.routes.pm_noop.button.83af8b08ef'), 'callback_data'=>"jobCreate_pin_$bid"]];
        $kb[] = [['text'=>\achbotMessage('broadcast.routes.pm_noop.button.e0acd784b7'), 'callback_data'=>"jobCreate_unpin_$bid"]];
		if(($info['type'] ?? '') !== 'forwardall'){
			$kb[] = [['text'=>\achbotMessage('broadcast.routes.pm_noop.button.c79e563595'), 'callback_data'=>"editBroadcastAsk_$bid"]];
		}
    }

    $kb[] = [['text'=>\achbotMessage('broadcast.routes.pm_noop.button.4c694cc9a7'), 'callback_data'=>"jobCreate_delete_$bid"]];
    $kb[] = [['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.adbdd32809'), 'callback_data'=>"jobsStatusBid_{$bid}_0"]];
    $kb[] = [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'), 'callback_data'=>"pinManageAll"]];

    sendMessage($out, json_encode(['inline_keyboard'=>$kb]));
}

if(preg_match('/^jobsStatusBid_(\d+)_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
 
    $bid  = (int)$m[1]; 
    $page = max(0, (int)$m[2]); 
 
    $limit = 10; 
    $off = $page * $limit; 
 
    $c = $connection->prepare("SELECT COUNT(*) AS cnt FROM `bc_jobs` WHERE `broadcast_id`=?"); 
    $c->bind_param("i",$bid);
    $c->execute(); 
    $totalJobs = (int)$c->get_result()->fetch_assoc()['cnt']; 
    $c->close(); 
 
    $maxPage = ($totalJobs > 0) ? (int)floor(($totalJobs - 1) / $limit) : 0; 
 
    $stmt = $connection->prepare(" 
		SELECT j.`id`, j.`broadcast_id`, j.`action`, j.`offset`, j.`ok_cnt`, j.`fail_cnt`, j.`state`, j.`created_at`,
               s.`title`, s.`target_group` 
        FROM `bc_jobs` j 
        LEFT JOIN `send_list` s ON s.`id` = j.`broadcast_id` 
        WHERE j.`broadcast_id`=? 
        ORDER BY j.`id` DESC 
        LIMIT ? OFFSET ? 
    "); 
    $stmt->bind_param("iii",$bid,$limit,$off); 
    $stmt->execute(); 
    $res = $stmt->get_result(); 
    $stmt->close(); 
 
    if(!$res || $res->num_rows == 0){ 
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.e3e2d204c0'), json_encode([
            'inline_keyboard'=>[ 
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageSelect_$bid"]],
            ] 
        ])); 
        return; 
    } 
 
    $txt = \achbotMessage('broadcast.routes.pm_noop.txt.b846cf96a2', ['bid' => $bid]).($page+1).\achbotMessage('broadcast.routes.pin_manage_all.text.a624a1e41f').($maxPage+1)."\n\n";
    $kb = []; 
 
    while($row = $res->fetch_assoc()){ 
        $jid = (int)$row['id']; 
 
        $act = $row['action']; 
		$actFa = ($act=='pin'?\achbotMessage('broadcast.routes.pm_noop.text.f9b5a56317'):($act=='unpin'?\achbotMessage('broadcast.routes.pm_noop.text.55e164ad1c'):($act=='edit'?\achbotMessage('broadcast.routes.pin_manage_all.button.1780efa62b'):\achbotMessage('broadcast.routes.pm_noop.text.bc26818ef0'))));
 
        $st  = (int)$row['state']; 
        $stFa = ($st==1?\achbotMessage('broadcast.routes.pm_noop.text.1ebfb10a5f'):($st==2?\achbotMessage('broadcast.routes.pm_noop.text.ba31fd7d85'):($st==3?\achbotMessage('broadcast.routes.pm_noop.text.98a2718667'):\achbotMessage('broadcast.routes.pm_noop.text.1704c602b4'))));
 
        $offx = (int)$row['offset'];
		$okc   = (int)($row['ok_cnt'] ?? 0);
		$failc = (int)($row['fail_cnt'] ?? 0);
        $title = $row['title'] ?: \achbotMessage('broadcast.routes.broadcast_start.text.bddb5c40d4');
        $group = groupFa($row['target_group'] ?? 'all'); 
 
		$txt .= \achbotMessage('broadcast.routes.pm_noop.txt.2fbdb8a0e8', ['jid' => $jid, 'actFa' => $actFa, 'stFa' => $stFa, 'offx' => $offx, 'okc' => $okc, 'failc' => $failc]);
        $txt .= \achbotMessage('broadcast.routes.pm_noop.title_group.1f33f97a14', ['title' => $title, 'group' => $group]);
        $txt .= \achbotMessage('broadcast.routes.pm_noop.txt.668fbd01f4');
 
        if($st==1 || $st==2){ 
            $kb[] = [[ 'text'=>\achbotMessage('broadcast.routes.pm_noop.button.446d9430a8', ['jid' => $jid]), 'callback_data'=>"jobCancel_$jid" ]];
        } 
    } 
 
    $nav = []; 
    if($page > 0) $nav[] = ['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.4210cf7432'), 'callback_data'=>"jobsStatusBid_{$bid}_".($page-1)];
    if($page < $maxPage) $nav[] = ['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.876ba507e6'), 'callback_data'=>"jobsStatusBid_{$bid}_".($page+1)];
    if(!empty($nav)) $kb[] = $nav; 
 
    $kb[] = [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageSelect_$bid"]];
 
    sendMessage(trim($txt), json_encode(['inline_keyboard'=>$kb])); 
}

if(preg_match('/^editBroadcastAsk_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $bid = (int)$m[1];

    $st = $connection->prepare("SELECT `type`,`title` FROM `send_list` WHERE `id`=? AND `state`=3 LIMIT 1");
    $st->bind_param("i",$bid);
    $st->execute();
    $rr = $st->get_result();
    $st->close();

    if(!$rr || $rr->num_rows==0){
        editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.4a15268e86'), getMainKeys());
        return;
    }

    $row = $rr->fetch_assoc();
    if(($row['type'] ?? '') === 'forwardall'){
        editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.48a8e21213'), json_encode([
            'inline_keyboard'=>[
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageSelect_$bid"]],
            ]
        ]));
        return;
    }

    setUser("bc_edit_$bid");
    sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.656de1721c'), $cancelKey, null);
    return;
}

// Create Manage Job (pin/unpin/delete)
if(preg_match('/^jobCreate_(pin|unpin|delete)_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
 
    $action = $m[1]; 
    $bid = (int)$m[2]; 
 
    $chkSend = $connection->prepare("SELECT `id` FROM `send_list` WHERE `id`=? AND `state`=3 LIMIT 1"); 
    $chkSend->bind_param("i",$bid); 
    $chkSend->execute(); 
    $sr = $chkSend->get_result(); 
    $chkSend->close(); 
 
    if(!$sr || $sr->num_rows == 0){ 
        editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.d433f90c40'), json_encode([
            'inline_keyboard'=>[ 
                [['text'=>\achbotMessage('broadcast.routes.pm_noop.button.b35a2352f5'),'callback_data'=>"pinManageAll"]],
            ] 
        ])); 
        return; 
    } 

    if($action === 'pin' || $action === 'unpin'){
        $st2 = $connection->prepare("
            SELECT 
              COUNT(*) AS total_cnt,
              SUM(CASE WHEN deleted=1 THEN 1 ELSE 0 END) AS deleted_cnt
            FROM `bc_messages`
            WHERE `broadcast_id`=?
        ");
        $st2->bind_param("i",$bid);
        $st2->execute();
        $rr = $st2->get_result()->fetch_assoc();
        $st2->close();

        $totalCnt = (int)($rr['total_cnt'] ?? 0);
        $deletedCnt = (int)($rr['deleted_cnt'] ?? 0);

        if($totalCnt > 0 && $deletedCnt >= $totalCnt){
            editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.eeefb6dde3'), json_encode([
                'inline_keyboard'=>[
                    [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageSelect_$bid"]],
                ]
            ]));
            return;
        }
    }
 
    $chk = $connection->prepare(" 
        SELECT `id` FROM `bc_jobs` 
        WHERE `broadcast_id`=? AND `action`=? AND `state` IN (1,2) 
        LIMIT 1 
    "); 
    $chk->bind_param("is", $bid, $action); 
    $chk->execute(); 
    $cr = $chk->get_result(); 
    $chk->close(); 
 
    if($cr && $cr->num_rows > 0){ 
        editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.30bfbbe059'), json_encode([
            'inline_keyboard'=>[ 
                [['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.adbdd32809'),'callback_data'=>"jobsStatusBid_{$bid}_0"]],
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageSelect_$bid"]],
            ] 
        ])); 
        return; 
    } 
 
    $stmt = $connection->prepare(" 
        INSERT INTO `bc_jobs` (`broadcast_id`,`action`,`offset`,`state`) 
        VALUES (?,?,0,1) 
    "); 
    $stmt->bind_param("is", $bid, $action); 
    $ok = $stmt->execute(); 
    $jobId = (int)$stmt->insert_id; 
    $stmt->close(); 
 
    if(!$ok || $jobId <= 0){ 
        editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.55c6d689fd'), json_encode([
            'inline_keyboard'=>[ 
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageSelect_$bid"]],
            ] 
        ])); 
        return; 
    } 
 
    $actFa = ($action=='pin'?\achbotMessage('broadcast.routes.pm_noop.text.f9b5a56317'):($action=='unpin'?\achbotMessage('broadcast.routes.pm_noop.button.e0acd784b7'):\achbotMessage('broadcast.routes.pm_noop.text.569e7df050')));
 
    editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.97dfcd54fa', ['jobId' => $jobId, 'actFa' => $actFa, 'bid' => $bid]), json_encode([
        'inline_keyboard'=>[ 
            [['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.adbdd32809'),'callback_data'=>"jobsStatusBid_{$bid}_0"]],
            [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageSelect_$bid"]],
        ] 
    ])); 
}

if(preg_match('/^bc_edit_(\d+)$/', $userInfo['step'], $m)
   && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $bid = (int)$m[1];
    $input = trim((string)($text ?? $caption ?? ''));

    if($input === $buttonValues['cancel']){
        setUser();
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.3d8a2c45c2'), $removeKeyboard, null);
        return;
    }

    if($input === ''){
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.272345cd68'), $cancelKey, null);
        setUser("bc_edit_$bid");
        return;
    }

    if(mb_strlen($input,'UTF-8') > 4096){
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.72fe3f7c5d'), $cancelKey, null);
        setUser("bc_edit_$bid");
        return;
    }

    $chk = $connection->prepare("SELECT `id`,`type` FROM `send_list` WHERE `id`=? AND `state`=3 LIMIT 1");
    $chk->bind_param("i",$bid);
    $chk->execute();
    $rr = $chk->get_result();
    $chk->close();

    if(!$rr || $rr->num_rows==0){
        setUser();
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.cc3858d5b2'), $removeKeyboard, null);
        return;
    }

    $info = $rr->fetch_assoc();
    if(($info['type'] ?? '') === 'forwardall'){
        setUser();
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.48a8e21213'), $removeKeyboard, null);
        return;
    }

    $chk2 = $connection->prepare("
        SELECT `id`
        FROM `bc_jobs`
        WHERE `broadcast_id`=? AND `action`='edit' AND `state` IN (1,2)
        LIMIT 1
    ");
    $chk2->bind_param("i",$bid);
    $chk2->execute();
    $r2 = $chk2->get_result();
    $chk2->close();

    if($r2 && $r2->num_rows>0){
        setUser();
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.ddd2c4a6c0'), $removeKeyboard, null);
        return;
    }

    $action = 'edit';
    $parseMode = null;

    $ins = $connection->prepare("
        INSERT INTO `bc_jobs`
        (`broadcast_id`,`action`,`new_text`,`parse_mode`,`offset`,`state`,`created_at`,`updated_at`)
        VALUES (?,?,?,?,0,1,NOW(),NOW())
    ");
    $ins->bind_param("isss", $bid, $action, $input, $parseMode);
    $ok = $ins->execute();
    $jid = (int)$ins->insert_id;
    $ins->close();

    setUser();

    if(!$ok || $jid <= 0){
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.42f2d7e7a7'), $removeKeyboard, null);
        return;
    }

    $u = $connection->prepare("UPDATE `send_list` SET `text`=? WHERE `id`=? LIMIT 1");
    if($u){
        $u->bind_param("si", $input, $bid);
        $u->execute();
        $u->close();
    }

    sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.b1578cca6d'), $removeKeyboard, null);

    sendMessage(
        \achbotMessage('broadcast.routes.pm_noop.message.e85dadc103', ['jid' => $jid]),
        json_encode([
            'inline_keyboard'=>[
                [['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.adbdd32809'),'callback_data'=>"jobsStatusBid_{$bid}_0"]],
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageSelect_$bid"]],
            ]
        ]),
        null
    );

    return;
}

if(preg_match('/^jobsStatus_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $page = max(0, (int)$m[1]);
    $limit = 10;
    $off = $page * $limit;

    // شمارش کل job ها
    $c = $connection->prepare("SELECT COUNT(*) AS cnt FROM `bc_jobs`");
    $c->execute();
    $totalJobs = (int)$c->get_result()->fetch_assoc()['cnt'];
    $c->close();

    $maxPage = ($totalJobs > 0) ? (int)floor(($totalJobs - 1) / $limit) : 0;

    $stmt = $connection->prepare("
		SELECT j.`id`, j.`broadcast_id`, j.`action`, j.`offset`, j.`ok_cnt`, j.`fail_cnt`, j.`state`, j.`created_at`,
               s.`title`, s.`target_group`
        FROM `bc_jobs` j
        LEFT JOIN `send_list` s ON s.`id` = j.`broadcast_id`
        ORDER BY j.`id` DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("ii",$limit,$off);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if(!$res || $res->num_rows == 0){
        sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.9bdefaee4b'), json_encode([
            'inline_keyboard'=>[
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageAll"]],
            ]
        ]));
        return;
    }

    $txt = \achbotMessage('broadcast.routes.pm_noop.txt.c79ae91fbd').($page+1).\achbotMessage('broadcast.routes.pin_manage_all.text.a624a1e41f').($maxPage+1)."\n\n";
    $kb = [];

    while($row = $res->fetch_assoc()){
        $jid = (int)$row['id'];
        $bid = (int)$row['broadcast_id'];

        $act = $row['action'];
		$actFa = ($act=='pin'?\achbotMessage('broadcast.routes.pm_noop.text.f9b5a56317'):($act=='unpin'?\achbotMessage('broadcast.routes.pm_noop.text.55e164ad1c'):($act=='edit'?\achbotMessage('broadcast.routes.pin_manage_all.button.1780efa62b'):\achbotMessage('broadcast.routes.pm_noop.text.bc26818ef0'))));

        $st  = (int)$row['state'];
        $stFa = ($st==1?\achbotMessage('broadcast.routes.pm_noop.text.1ebfb10a5f'):($st==2?\achbotMessage('broadcast.routes.pm_noop.text.ba31fd7d85'):($st==3?\achbotMessage('broadcast.routes.pm_noop.text.98a2718667'):\achbotMessage('broadcast.routes.pm_noop.text.1704c602b4'))));

        $offx = (int)$row['offset'];
		$okc   = (int)($row['ok_cnt'] ?? 0);
		$failc = (int)($row['fail_cnt'] ?? 0);
        $title = $row['title'] ?: \achbotMessage('broadcast.routes.broadcast_start.text.bddb5c40d4');
        $group = groupFa($row['target_group'] ?? 'all');

		$txt .= \achbotMessage('broadcast.routes.pm_noop.txt.2fbdb8a0e8', ['jid' => $jid, 'actFa' => $actFa, 'stFa' => $stFa, 'offx' => $offx, 'okc' => $okc, 'failc' => $failc]);
        $txt .= \achbotMessage('broadcast.routes.pm_noop.title_group.1f33f97a14', ['title' => $title, 'group' => $group]);
        $txt .= \achbotMessage('broadcast.routes.pm_noop.txt.668fbd01f4');

        if($st==1 || $st==2){
            $kb[] = [[ 'text'=>\achbotMessage('broadcast.routes.pm_noop.button.446d9430a8', ['jid' => $jid]), 'callback_data'=>"jobCancel_$jid" ]];
        }
    }

    $nav = [];
    if($page > 0) $nav[] = ['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.4210cf7432'), 'callback_data'=>"jobsStatus_".($page-1)];
    if($page < $maxPage) $nav[] = ['text'=>\achbotMessage('broadcast.routes.broadcast_start.button.876ba507e6'), 'callback_data'=>"jobsStatus_".($page+1)];
    if(!empty($nav)) $kb[] = $nav;

    $kb[] = [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageAll"]];

    sendMessage(trim($txt), json_encode(['inline_keyboard'=>$kb]));
}

if(preg_match('/^jobCancel_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $jid = (int)$m[1];

    $stmt = $connection->prepare("
        UPDATE `bc_jobs`
        SET `state`=4, `updated_at`=NOW()
        WHERE `id`=? AND `state` IN (1,2)
    ");
    $stmt->bind_param("i",$jid);
    $stmt->execute();
    $aff = $stmt->affected_rows;
    $stmt->close();

    if($aff > 0){
        editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.85bd844d25', ['jid' => $jid]), json_encode([
            'inline_keyboard'=>[
                [['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.adbdd32809'),'callback_data'=>"jobsStatus_0"]],
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageAll"]],
            ]
        ]));
    } else {
        editText($message_id, \achbotMessage('broadcast.routes.pm_noop.message.ad14040149'), json_encode([
            'inline_keyboard'=>[
                [['text'=>\achbotMessage('broadcast.routes.pin_manage_all.button.adbdd32809'),'callback_data'=>"jobsStatus_0"]],
                [['text'=>\achbotMessage('broadcast.routes.message2_all.button.b233883d0d'),'callback_data'=>"pinManageAll"]],
            ]
        ]));
    }
}
// END pin Manage
