<?php 
include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';
include_once __DIR__ . '/jdf.php';

@ignore_user_abort(true);
@set_time_limit(0);

$rateLimit = $botState['rateLimit']??0;
if(time() > $rateLimit){
    $rate = json_decode(curl_get_file_contents("https://api.pooleno.ir/v1/currency/short-name/trx?type=buy"),true);
    $botState['USDRate'] = round($rate['priceUsdt'],2);
    $botState['TRXRate'] = round($rate['priceFiat'] / 10,2);
    $botState['rateLimit'] = strtotime("+1 hour");
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $isExists = $stmt->get_result();
    $stmt->close();
    if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
    else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
    $newData = json_encode($botState);
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $newData);
    $stmt->execute();
    $stmt->close();
}

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
