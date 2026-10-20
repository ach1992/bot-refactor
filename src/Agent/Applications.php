<?php
/**
 * AchBot Access: Agent request, acceptance and rejection wizard.
 * Scoped, re-entrant, byte-compatible route at original bot.php position.
 * Internal include only. Do not execute via HTTP directly.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if($data=="agencySettings" && $userInfo['is_agent'] == 1){
    editText($message_id, $mainValues['agent_setting_message'] ,getAgentKeys());
}
if($data=="requestAgency"){
    if($userInfo['is_agent'] == 2){
        alert($mainValues['agency_request_already_sent']);
    }elseif($userInfo['is_agent'] == 0){
        $msg = str_replace(["USERNAME", "NAME", "USERID"],[$username, $first_name, $from_id],$mainValues['request_agency_message']);
			$keys = json_encode([
				'inline_keyboard' => [
					[
						['text' => $buttonValues['approve'], 'callback_data' => "agencyApprove" . $from_id],
						['text' => $buttonValues['decline'], 'callback_data' => "agencyDecline" . $from_id]
					]
				]
			]);
			sendMessage($msg, $keys, "html", $admin);
			//sendToAllAdmins('sendMessage', ['text' => $msg,'reply_markup' => $keys,'parse_mode' => "html"]);
			// ارسال پیام به کانال گزارشات
			$rewardChannel = $botState['rewardChannel'];
				if (!empty($rewardChannel)) {
				sendMessage($msg, $keys, "html", $rewardChannel);
			}
        setUser(2, 'is_agent');
        alert($mainValues['agency_request_sent']);
    }elseif($userInfo['is_agent'] == -1) alert($mainValues['agency_request_declined']);
    elseif($userInfo['is_agent'] == 1) editText($message_id,"لطفا یکی از کلید های زیر را انتخاب کنید.",getMainKeys());
}
// Resolve exactly one pending applicant before a state transition. The legacy
// schema does not enforce uniqueness on users.userid; ambiguous IDs fail closed.
function achbotPendingAgencyApplicantId(mysqli $connection, int $applicantUserId): ?int {
    $stmt = $connection->prepare("SELECT `id`, `is_agent` FROM `users` WHERE `userid` = ? LIMIT 2");
    $stmt->bind_param("i", $applicantUserId);
    $stmt->execute();
    $rows = $stmt->get_result();
    $row = $rows->num_rows === 1 ? $rows->fetch_assoc() : null;
    $stmt->close();
    return $row !== null && (int)$row['is_agent'] === 2 ? (int)$row['id'] : null;
}

/**
 * Agency approval is a single-row compare-and-set (is_agent=2 -> 1).
 * The existing users.is_agent secondary index can produce an InnoDB
 * deadlock victim when two admins approve the same pending applicant.
 * Retrying ONLY a rolled-back 1213 statement is safe before ANY Telegram
 * or wizard side effect: the second CAS then observes zero affected rows.
 * A lock timeout (1205) or repeated victim fails CLOSED, never notifies.
 */
function achbotAgencyApprovePendingRow(mysqli $connection, int $rowId,
                                       string $discount, int $time): bool {
    for ($attempt=0; $attempt<2; ++$attempt) {
        $stmt=null;
        try {
            $stmt=$connection->prepare("UPDATE `users` SET `is_agent` = 1,
                `discount_percent` = ?, `agent_date` = ?
                WHERE `id` = ? AND `is_agent` = 2");
            if (!$stmt) return false;
            $stmt->bind_param('sii',$discount,$time,$rowId);
            $ok=$stmt->execute();
            return $ok===true && $stmt->affected_rows===1;
        } catch (mysqli_sql_exception $error) {
            // 1213 is an explicitly rolled-back InnoDB deadlock victim.
            // Do not replay SQL on 1205 (transaction state uncertain).
            if ((int)$error->getCode()===1213 && $attempt===0) {
                usleep(3000);
                continue;
            }
            if (in_array((int)$error->getCode(),[1213,1205],true))
                return false;
            throw $error;
        } finally {
            if ($stmt instanceof mysqli_stmt) {
                try { $stmt->close(); } catch (Throwable $ignored) {}
            }
        }
    }
    return false;
}

if(preg_match('/^agencyDecline([1-9][0-9]*)$/D', (string)$data, $match) &&
    ($from_id == $admin || !empty($userInfo['isAdmin']))){
    $applicant = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rowId = $applicant !== false ? achbotPendingAgencyApplicantId($connection, $applicant) : null;
    if ($rowId !== null) {
        // The acting administrator's setUser() is deliberately NOT used here.
        // Compare-and-set prevents stale/replayed callbacks from succeeding.
        $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = -1 WHERE `id` = ? AND `is_agent` = 2");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if ($changed) {
            editKeys(json_encode(['inline_keyboard'=>[
                [['text'=>$buttonValues['declined'],'callback_data'=>"wizwizch"]]
            ]]));
            sendMessage($mainValues['agency_request_declined'], null, null, $applicant);
        } else {
            alert($mainValues['config_not_found']);
        }
    } else {
        alert($mainValues['config_not_found']);
    }
}
if(preg_match('/^agencyApprove([1-9][0-9]*)$/D', (string)$data, $match) &&
    ($from_id == $admin || !empty($userInfo['isAdmin']))){
    $applicant = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($applicant !== false && achbotPendingAgencyApplicantId($connection, $applicant) !== null) {
        setUser($data . "_" . $message_id);
        sendMessage($mainValues['send_agent_discount_percent'], $cancelKey);
    } else {
        alert($mainValues['config_not_found']);
    }
}
if(preg_match('/^agencyApprove([1-9][0-9]*)_([1-9][0-9]*)$/D', (string)($userInfo['step'] ?? ''), $match) &&
    $text != $buttonValues['cancel'] && ($from_id == $admin || !empty($userInfo['isAdmin']))){
    if(is_numeric($text)){
        $applicant = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $originalMessage = filter_var($match[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rowId = $applicant !== false && $originalMessage !== false
            ? achbotPendingAgencyApplicantId($connection, $applicant) : null;
        if ($rowId !== null) {
            $discount = json_encode(['normal'=>$text]);
            $changed=achbotAgencyApprovePendingRow($connection,$rowId,$discount,$time);
            setUser(); // Only the acting administrator's wizard step is cleared.
            if ($changed) {
                editKeys(json_encode(['inline_keyboard'=>[
                    [['text'=>$buttonValues['approved'],'callback_data'=>"wizwizch"]]
                ]]), $originalMessage);
                sendMessage($mainValues['saved_successfuly']);
                sendMessage($mainValues['agency_request_approved'], null, null, $applicant);
            } else {
                alert($mainValues['config_not_found']);
            }
        } else {
            setUser();
            alert($mainValues['config_not_found']);
        }
    } else sendMessage($mainValues['send_only_number']);
}
