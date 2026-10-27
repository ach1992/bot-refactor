<?php
/** AChBot V5: broadcast administration and job lifecycle; legacy caller scope and side effects are intentional. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

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
		$kind = ($type === 'forwardall') ? "فوروارد همگانی" : "ارسال پیام همگانی";
        $titleLine = $title ? "🏷 عنوان: $title\n" : "";

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
			"❗️یک $kind در صف/حال ارسال است...\n\n".
			$titleLine.
			"👥 گروه: ".groupFa($group)."\n".
			"🔰 تعداد کاربران: $usersCount\n".
			"✅ ارسال موفق: $sentCount\n".
			"📣 باقیمانده: $left\n".
			"🧩 آخرین شناسه پردازش‌شده: $lastUserIdProcessed";
	

        sendMessage($statusText, json_encode([
            'inline_keyboard' => [
                [['text'=>"⛔️ لغو ادامه ارسال", 'callback_data'=>"cancelBroadcast".$jobId]],
                [['text'=>"📚 تاریخچه ارسال‌ها", 'callback_data'=>"broadcastHistory_0"]],
                [['text'=>$buttonValues['start_bot'], 'callback_data'=>"mainMenu"]],
            ]
        ]));
    } else {
        sendMessage("📍لطفا انتخاب کنید:", json_encode([
            'inline_keyboard' => [
                [['text'=>"📨 ارسال پیام جدید", 'callback_data'=>"broadcastStart"]],
                [['text'=>"📚 تاریخچه ارسال‌ها", 'callback_data'=>"broadcastHistory_0"]],
				[['text'=>"بازگشت",'callback_data'=>"mainMenu"]],
            ]
        ]));
    }
}

if($data == 'broadcastStart' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("پیام را به کدام گروه می‌خواهید ارسال کنید؟", json_encode([
        'inline_keyboard' => [
            [['text'=>"👥 همه کاربران",'callback_data'=>"chooseSendGroup_all"]],
            [['text'=>"✅ کاربران دارای اشتراک",'callback_data'=>"chooseSendGroup_has"]],
            [['text'=>"❌ کاربران بدون اشتراک",'callback_data'=>"chooseSendGroup_none"]],
            [['text'=>"🧑‍💼 همکاران",'callback_data'=>"chooseSendGroup_agents"]],
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
        editText($message_id, "⛔️ لغو شد. ارسال ادامه پیدا نمی‌کند.", getMainKeys());
    } else {
        editText($message_id, "این ارسال قابل لغو نیست یا قبلاً تمام/لغو شده است.", getMainKeys());
    }
}

if(preg_match('/^chooseSendGroup_(all|has|none|agents)$/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $group = $match[1];
    setUser("s2a_title_$group");
    sendMessage("🏷 لطفاً یک عنوان برای این ارسال وارد کنید.\nمثال: اطلاعیه تعطیلی / آپدیت جدید", $cancelKey);
}

if (preg_match('/^s2a_title_(all|has|none|agents)$/', $userInfo['step'], $match)
    && $text != $buttonValues['cancel']
    && ($from_id == $admin || $userInfo['isAdmin'] == true)) {

    $group = $match[1];
    $title = trim(preg_replace('/\s+/u', ' ', (string)$text));

    if (mb_strlen($title, 'UTF-8') > 255) {
        sendMessage("⚠️ عنوان خیلی طولانی است.", $cancelKey);
        setUser("s2a_title_$group");
        return;
    }

    if (!preg_match('/^[\p{L}\p{N}\p{P}\p{S} \-_\+\(\)=\/\\\\!]+$/u', $title)) {
        sendMessage(
            "⚠️ عنوان شامل کاراکتر غیرمجاز است.\n".
            "مجاز: حروف، اعداد، علائم نگارشی، اموجی و - _ + ( ) = / \\ !",
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
        sendMessage("⚠️ خطایی در ساخت پیش‌نویس ارسال رخ داد.", $cancelKey);
        setUser("s2a_title_$group");
        return;
    }

    setUser("s2a_msg_$draftId");
    sendMessage("📜 حالا پیام را ارسال کنید یا پیام موردنظر را فوروارد کنید.", $cancelKey);
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
			$preview = mb_substr($preview, 0, 300, 'UTF-8') . "…";
		}

		$stmt = $connection->prepare("
			UPDATE `send_list`
			SET `type`='forwardall', `message_id`=?, `chat_id`=?, `text`=?
			WHERE `id`=? AND `state`=0
		");
		$stmt->bind_param("sssi", $message_id, $chat_id, $preview, $sendId);
		$stmt->execute();
		$stmt->close();

		sendMessage("⏳ مرسی از پیامت ...", $removeKeyboard);
		sendMessage("برای گروه انتخاب‌شده فوروارد کنم؟", json_encode(['inline_keyboard'=>[
			[['text'=>"بفرست",'callback_data'=>"yesSend2All".$sendId],['text'=>"نفرست",'callback_data'=>"noDontSend2all".$sendId]]
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

    sendMessage("⏳ مرسی از پیامت ...", $removeKeyboard);
    sendMessage("برای گروه انتخاب‌شده ارسال کنم؟", json_encode(['inline_keyboard'=>[
        [['text'=>"بفرست",'callback_data'=>"yesSend2All".$sendId],['text'=>"نفرست",'callback_data'=>"noDontSend2all".$sendId]]
    ]]));
}

if(preg_match('/^noDontSend2all(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $id = (int)$match[1];

    $stmt = $connection->prepare("UPDATE `send_list` SET `state`=4 WHERE `id`=? AND `state` IN (0,1,2)");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "ارسال پیام همگانی لغو شد.", getMainKeys());
}

if(preg_match('/^yesSend2All(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $id = (int)$match[1];

    $stmt = $connection->prepare("UPDATE `send_list` SET `state`=1 WHERE `id`=? AND `state`=0");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "⏳ کم کم برا همه ارسال میشه ...", getMainKeys());
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
        $title = $row['title'] ?: 'بدون عنوان';
        $type  = ($row['type'] === 'forwardall') ? 'فوروارد' : 'ارسال';
        $group = $row['target_group'] ?? 'all';
        $st    = stateFa((int)$row['state']);
        $sent = (int)($row['sent_count'] ?? 0);
		$lastId = (int)($row['offset'] ?? 0);
        $dt    = $row['created_at'] ?? '';

        $items[] =
            "🧾 #$id | $type | ".groupFa($group)." | $st | ارسال‌شده:$sent | آخرین پردازش:$lastId\n".
            "🏷 $title\n".
            "🕒 $dt";
    }

    $out = "📚 تاریخچه ارسال‌ها (صفحه ".($page+1).")\n\n";
    $out .= $items ? implode("\n\n────────────\n\n", $items) : "⚠️ چیزی برای نمایش نیست.";

    $kb = [];
    if($page > 0){
        $kb[] = [['text'=>"⬅️ قبلی", 'callback_data'=>"broadcastHistory_".($page-1)]];
    }
    $kb[] = [['text'=>"➡️ بعدی", 'callback_data'=>"broadcastHistory_".($page+1)]];
    $kb[] = [['text'=>"بازگشت", 'callback_data'=>"message2All"]];

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
        sendMessage("⚠️ چیزی برای نمایش نیست.", json_encode([ 
            'inline_keyboard'=>[ 
                [['text'=>"🧾 وضعیت عملیات‌ها",'callback_data'=>"jobsStatus_0"]],
                [['text'=>"بازگشت",'callback_data'=>"mainMenu"]] 
            ] 
        ])); 
        return; 
    } 
 
    $kb = []; 
 
    $kb[] = [ 
        ['text'=>"🆔", 'callback_data'=>"pmNoop"], 
        ['text'=>"🗃", 'callback_data'=>"pmNoop"], 
        ['text'=>"📌", 'callback_data'=>"pmNoop"], 
        ['text'=>"🛠️", 'callback_data'=>"pmNoop"], 
    ]; 
 
    while($row = $res->fetch_assoc()){ 
        $bid = (int)$row['id']; 
        $group = groupFa($row['target_group'] ?? 'all'); 
 
        $total  = (int)$row['total_cnt']; 
        $pinned = (int)$row['pinned_cnt']; 
 
        if($total > 0 && $pinned == $total){ 
            $pinTxt = "📌 همه"; 
        } elseif($pinned > 0){ 
            $pinTxt = "📍 بعضی"; 
        } else { 
            $pinTxt = "— ندارد"; 
        } 
 
        $kb[] = [ 
            ['text'=>"#".$bid, 'callback_data'=>"pinManageSelect_$bid"], 
            ['text'=>$group, 'callback_data'=>"pmNoop"], 
            ['text'=>$pinTxt, 'callback_data'=>"pmNoop"], 
            ['text'=>"✏️ ویرایش", 'callback_data'=>"pinManageSelect_$bid"], 
        ]; 
    } 
 
    $nav = []; 
    if($page > 0){ 
        $nav[] = ['text'=>"⬅️ قبلی", 'callback_data'=>"pinManageAllPage_".($page-1)]; 
    } 
 
    $maxPage = (int)floor(max(0, $totalRows - 1) / $limit); 
    if($page < $maxPage){ 
        $nav[] = ['text'=>"➡️ بعدی", 'callback_data'=>"pinManageAllPage_".($page+1)]; 
    } 
    if(!empty($nav)){ 
        $kb[] = $nav; 
    } 
 
    $kb[] = [['text'=>"🧾 وضعیت عملیات‌ها",'callback_data'=>"jobsStatus_0"]];
    $kb[] = [['text'=>"بازگشت", 'callback_data'=>"mainMenu"]]; 
 
    $text = "📌 مدیریت پیام‌های همگانی\n" 
          . "🔖 صفحه ".($page+1)." از ".($maxPage+1)."\n" 
          . "✏️ یک ردیف را ویرایش کنید:"; 
 
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
        sendMessage("❌ این ارسال پیدا نشد.", json_encode(['inline_keyboard'=>[ 
            [['text'=>"بازگشت",'callback_data'=>"pinManageAll"]] 
        ]])); 
        return; 
    } 
 
    $info = $r->fetch_assoc(); 
 
    $title = $info['title'] ?: 'بدون عنوان'; 
    $tp = ($info['type']==='forwardall') ? 'فوروارد' : 'ارسال'; 
    $g  = groupFa($info['target_group'] ?? 'all'); 
    $dt = $info['created_at'] ?? ''; 
	 
	$previewText = trim((string)($info['text'] ?? '')); 
	if($previewText === ''){ 
		$previewText = "— (این پیام متن/کپشن ندارد)"; 
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
        "🧾 ارسال #$bid\n". 
        "🏷 عنوان: $title\n". 
        "📌 نوع: $tp\n". 
        "👥 گروه: $g\n". 
        "🕒 تاریخ: $dt\n\n". 
        "📝 پیش‌نمایش پیام:\n$previewText\n\n". 
        "📊 ارسال شده: $total\n". 
        "📌 پین شده: $pinned\n". 
        "🗑 حذف شده: $deleted\n\n". 
        "عملیات موردنظر را انتخاب کنید:"; 
 
    $kb = [];

    if(!($total > 0 && $deleted >= $total)){
        $kb[] = [['text'=>"📌 پین برای همه", 'callback_data'=>"jobCreate_pin_$bid"]];
        $kb[] = [['text'=>"📍 برداشتن پین", 'callback_data'=>"jobCreate_unpin_$bid"]];
		if(($info['type'] ?? '') !== 'forwardall'){
			$kb[] = [['text'=>"✏️ ویرایش متن/کپشن", 'callback_data'=>"editBroadcastAsk_$bid"]];
		}
    }

    $kb[] = [['text'=>"🗑 حذف پیام از همه", 'callback_data'=>"jobCreate_delete_$bid"]];
    $kb[] = [['text'=>"🧾 وضعیت عملیات‌ها", 'callback_data'=>"jobsStatusBid_{$bid}_0"]];
    $kb[] = [['text'=>"بازگشت", 'callback_data'=>"pinManageAll"]];

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
        sendMessage("⚠️ برای این ارسال هیچ عملیاتی ثبت نشده است.", json_encode([ 
            'inline_keyboard'=>[ 
                [['text'=>"بازگشت",'callback_data'=>"pinManageSelect_$bid"]], 
            ] 
        ])); 
        return; 
    } 
 
    $txt = "🧾 وضعیت عملیات‌های ارسال ID:$bid\n🔖 صفحه ".($page+1)." از ".($maxPage+1)."\n\n"; 
    $kb = []; 
 
    while($row = $res->fetch_assoc()){ 
        $jid = (int)$row['id']; 
 
        $act = $row['action']; 
		$actFa = ($act=='pin'?'📌 پین':($act=='unpin'?'📍 آنپین':($act=='edit'?'✏️ ویرایش':'🗑 حذف')));
 
        $st  = (int)$row['state']; 
        $stFa = ($st==1?'در صف':($st==2?'در حال اجرا':($st==3?'تمام شد':'لغو شد'))); 
 
        $offx = (int)$row['offset'];
		$okc   = (int)($row['ok_cnt'] ?? 0);
		$failc = (int)($row['fail_cnt'] ?? 0);
        $title = $row['title'] ?: 'بدون عنوان'; 
        $group = groupFa($row['target_group'] ?? 'all'); 
 
		$txt .= "🧩 فرآیند #$jid | $actFa | $stFa | پردازش:$offx | ✅:$okc | ❌:$failc\n";
        $txt .= "🏷 $title | 👥 $group\n"; 
        $txt .= "────────────\n"; 
 
        if($st==1 || $st==2){ 
            $kb[] = [[ 'text'=>"⛔️ لغو فرآیند ID:$jid", 'callback_data'=>"jobCancel_$jid" ]]; 
        } 
    } 
 
    $nav = []; 
    if($page > 0) $nav[] = ['text'=>"⬅️ قبلی", 'callback_data'=>"jobsStatusBid_{$bid}_".($page-1)]; 
    if($page < $maxPage) $nav[] = ['text'=>"➡️ بعدی", 'callback_data'=>"jobsStatusBid_{$bid}_".($page+1)]; 
    if(!empty($nav)) $kb[] = $nav; 
 
    $kb[] = [['text'=>"بازگشت",'callback_data'=>"pinManageSelect_$bid"]]; 
 
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
        editText($message_id, "❌ این ارسال پیدا نشد یا هنوز کامل نشده است.", getMainKeys());
        return;
    }

    $row = $rr->fetch_assoc();
    if(($row['type'] ?? '') === 'forwardall'){
        editText($message_id, "⚠️ ویرایش برای پیام‌های فورواردی فعال نیست.", json_encode([
            'inline_keyboard'=>[
                [['text'=>"بازگشت",'callback_data'=>"pinManageSelect_$bid"]],
            ]
        ]));
        return;
    }

    setUser("bc_edit_$bid");
    sendMessage("✏️ متن/کپشن جدید را ارسال کنید.", $cancelKey, null);
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
        editText($message_id, "❌ این ارسال وجود ندارد یا هنوز کامل نشده است.", json_encode([ 
            'inline_keyboard'=>[ 
                [['text'=>"🔙 بازگشت",'callback_data'=>"pinManageAll"]], 
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
            editText($message_id, "⚠️ این ارسال قبلاً برای همه حذف شده است و امکان پین/آنپین ندارد.", json_encode([
                'inline_keyboard'=>[
                    [['text'=>"بازگشت",'callback_data'=>"pinManageSelect_$bid"]],
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
        editText($message_id, "⚠️ این عملیات از قبل در صف/حال اجراست.", json_encode([ 
            'inline_keyboard'=>[ 
                [['text'=>"🧾 وضعیت عملیات‌ها",'callback_data'=>"jobsStatusBid_{$bid}_0"]], 
                [['text'=>"بازگشت",'callback_data'=>"pinManageSelect_$bid"]], 
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
        editText($message_id, "⚠️ خطا در ساخت عملیات، دوباره تلاش کنید.", json_encode([ 
            'inline_keyboard'=>[ 
                [['text'=>"بازگشت",'callback_data'=>"pinManageSelect_$bid"]], 
            ] 
        ])); 
        return; 
    } 
 
    $actFa = ($action=='pin'?'📌 پین':($action=='unpin'?'📍 برداشتن پین':'🗑 حذف پیام')); 
 
    editText($message_id, "✅ عملیات با ID:$jobId ثبت شد.\n⚠️ اجرای $actFa برای ارسال با ID:$bid به صورت زمانبندی انجام می شود.", json_encode([ 
        'inline_keyboard'=>[ 
            [['text'=>"🧾 وضعیت عملیات‌ها",'callback_data'=>"jobsStatusBid_{$bid}_0"]], 
            [['text'=>"بازگشت",'callback_data'=>"pinManageSelect_$bid"]], 
        ] 
    ])); 
}

if(preg_match('/^bc_edit_(\d+)$/', $userInfo['step'], $m)
   && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $bid = (int)$m[1];
    $input = trim((string)($text ?? $caption ?? ''));

    if($input === $buttonValues['cancel']){
        setUser();
        sendMessage("❌ عملیات ویرایش لغو شد.", $removeKeyboard, null);
        return;
    }

    if($input === ''){
        sendMessage("⚠️ متن خالی است.", $cancelKey, null);
        setUser("bc_edit_$bid");
        return;
    }

    if(mb_strlen($input,'UTF-8') > 4096){
        sendMessage("⚠️ متن خیلی طولانی است.", $cancelKey, null);
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
        sendMessage("❌ این ارسال وجود ندارد یا کامل نشده است.", $removeKeyboard, null);
        return;
    }

    $info = $rr->fetch_assoc();
    if(($info['type'] ?? '') === 'forwardall'){
        setUser();
        sendMessage("⚠️ ویرایش برای پیام‌های فورواردی فعال نیست.", $removeKeyboard, null);
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
        sendMessage("⚠️ عملیات ویرایش برای این ارسال از قبل در حال اجراست.", $removeKeyboard, null);
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
        sendMessage("❌ خطا در ثبت عملیات ویرایش.", $removeKeyboard, null);
        return;
    }

    $u = $connection->prepare("UPDATE `send_list` SET `text`=? WHERE `id`=? LIMIT 1");
    if($u){
        $u->bind_param("si", $input, $bid);
        $u->execute();
        $u->close();
    }

    sendMessage("✅ عملیات ویرایش ثبت شد.", $removeKeyboard, null);

    sendMessage(
        "✏️ ویرایش با ID:$jid ثبت شد و به‌صورت تدریجی اعمال می‌شود.",
        json_encode([
            'inline_keyboard'=>[
                [['text'=>"🧾 وضعیت عملیات‌ها",'callback_data'=>"jobsStatusBid_{$bid}_0"]],
                [['text'=>"بازگشت",'callback_data'=>"pinManageSelect_$bid"]],
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
        sendMessage("⚠️ هیچ عملیاتی ثبت نشده است.", json_encode([
            'inline_keyboard'=>[
                [['text'=>"بازگشت",'callback_data'=>"pinManageAll"]],
            ]
        ]));
        return;
    }

    $txt = "🧾 وضعیت عملیات‌ها\n🔖 صفحه ".($page+1)." از ".($maxPage+1)."\n\n";
    $kb = [];

    while($row = $res->fetch_assoc()){
        $jid = (int)$row['id'];
        $bid = (int)$row['broadcast_id'];

        $act = $row['action'];
		$actFa = ($act=='pin'?'📌 پین':($act=='unpin'?'📍 آنپین':($act=='edit'?'✏️ ویرایش':'🗑 حذف')));

        $st  = (int)$row['state'];
        $stFa = ($st==1?'در صف':($st==2?'در حال اجرا':($st==3?'تمام شد':'لغو شد')));

        $offx = (int)$row['offset'];
		$okc   = (int)($row['ok_cnt'] ?? 0);
		$failc = (int)($row['fail_cnt'] ?? 0);
        $title = $row['title'] ?: 'بدون عنوان';
        $group = groupFa($row['target_group'] ?? 'all');

		$txt .= "🧩 فرآیند #$jid | $actFa | $stFa | پردازش:$offx | ✅:$okc | ❌:$failc\n";
        $txt .= "🏷 $title | 👥 $group\n";
        $txt .= "────────────\n";

        if($st==1 || $st==2){
            $kb[] = [[ 'text'=>"⛔️ لغو فرآیند ID:$jid", 'callback_data'=>"jobCancel_$jid" ]];
        }
    }

    $nav = [];
    if($page > 0) $nav[] = ['text'=>"⬅️ قبلی", 'callback_data'=>"jobsStatus_".($page-1)];
    if($page < $maxPage) $nav[] = ['text'=>"➡️ بعدی", 'callback_data'=>"jobsStatus_".($page+1)];
    if(!empty($nav)) $kb[] = $nav;

    $kb[] = [['text'=>"بازگشت",'callback_data'=>"pinManageAll"]];

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
        editText($message_id, "⛔️ فرآیند با ID:$jid لغو شد.", json_encode([
            'inline_keyboard'=>[
                [['text'=>"🧾 وضعیت عملیات‌ها",'callback_data'=>"jobsStatus_0"]],
                [['text'=>"بازگشت",'callback_data'=>"pinManageAll"]],
            ]
        ]));
    } else {
        editText($message_id, "⚠️ این فرآیند قابل لغو نیست (یا قبلاً تمام/لغو شده) است.", json_encode([
            'inline_keyboard'=>[
                [['text'=>"🧾 وضعیت عملیات‌ها",'callback_data'=>"jobsStatus_0"]],
                [['text'=>"بازگشت",'callback_data'=>"pinManageAll"]],
            ]
        ]));
    }
}
// END pin Manage
