<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if ($data==="getTestAccount") {
    if (($botState['testAccount']??'off')!=='on') return;
    if (in_array($userInfo['freetrial']??null,['used','reserved'],true) && $from_id!=$admin) {
        alert(achbotMessage('purchase.trial_catalogue.get_test_account.alert.0143c681c4'));
        return;
    }
    $plans=achbotAvailablePlans($connection,null,null,'trial');
    if (!$plans) {
        alert(achbotMessage('purchase.trial_catalogue.get_test_account.alert.d1ae7ac011'));
        return;
    }
    alert(achbotMessage('legacy_messages.receving_information'));
    $keyboard=[];
    foreach ($plans as $row) {
        $keyboard[]=[['text'=>$row['category_title'].' '.$row['title'],
            'callback_data'=>'freeTrial'.$row['id'].'_normal']];
    }
    $keyboard[]=[['text'=>achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>'navHome']];
    editText($message_id,achbotMessage('labels.select_option'),json_encode(['inline_keyboard'=>$keyboard]),"HTML");
}
