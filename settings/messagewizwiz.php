<?php 
include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';
include_once __DIR__ . '/jdf.php';

@ignore_user_abort(true);
@set_time_limit(0);

require __DIR__ . '/../src/Jobs/Checkpoint.php';

$inlineStartKey = json_encode([
    'inline_keyboard' => [
        [[
            'text' => $buttonValues['start_bot'],
            'url'  => "https://t.me/$botUsername?start=mainMenu"
        ]]
    ]
], JSON_UNESCAPED_UNICODE);

require __DIR__ . '/../src/Jobs/MessageActions.php';


require __DIR__ . '/../src/Jobs/BroadcastSend.php';

?>
