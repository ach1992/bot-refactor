<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../../tools/source_scan.php';
$root=realpath(__DIR__.'/../..');
$selected=[
 'config.php'=>['tgRetryAfter','bot','sendFileToTelegram','normalizeReplyMarkup','sendMessage','answerCallbackQuery',
 'editKeys','editText','delMessage','sendAction','forwardmessage','isForwardedMessageObj','groupFa','stateFa','apiOk',
 'getApiMessageId','logBroadcastUserMessage','pinOne','unpinOne','deleteOne','alert','ip_in_range','getSupportUrl',
 'replaceAllPlaceholdersInMainValues','getMainKeys','getAgentKeys','getAdminKeys','canSeeButton','getCallbackFromCommand',
 'buildConfigLinks','buildSubLink','buildSubBackupSection','buildConfigMessage','sumerize','sumerize2',
 'RandomString','generateUID','getUsersCountByGroup','findOrder','applyDiscountToPay','startWalletPayment'],
 'buy_helpers.php'=>['getPlanDetails','ensureServerCapacity','getServerInfoAndConfig','buildRemark','getNextPortAndIncrease',
 'createServerAccount','insertOrder','decreaseServerCapacity','ensureAccountCreationSucceeded','giveReferralReward',
 'renewConfigOnServer','processWalletPayment','calculateAgentPrice','createIncreaseOrder','payTypeToDescription','addToWallet',
 'notifyAllAdminsSimple','refundToWalletAndNotify','dbFetchPayByHash','inlineKeyboard','generateHigherRandomizedAmount',
 'fetchCartToCartUserEligibility','resolveCartToCartMessageType','getCartToCartMessageKey','buildCartToCartInfoMessage',
 'kbSendReceiptAndCancel','kbOnlyCancel','extractPhotoFileId','duplicateReceiptGuard','countUserPaysByState','sumUserPaysByState',
 'buildAdminKeyboardWithStats','sendReceiptToAdminsAndUpdatePay','sendWithStartButton'],
 'settings/messagewizwiz.php'=>['nowTs','safeClose','hbUpdate','markPaused','isCancelled','markCancelled','markFinished'],
];
$out="<?php\n// Generated in a private test sandbox from current source; never edit.\n";
$provenance=[];
foreach($selected as $file=>$names) {
 $source=file_get_contents($root.'/'.$file); $functions=achbotFunctions($source);
 foreach($names as $name) {
  $matches=array_values(array_filter($functions,fn($f)=>$f['name']===$name));
  if(count($matches)!==1) throw new RuntimeException('Ambiguous or missing function: '.$name);
  $f=$matches[0];
  $code=substr($source,$f['offset'],$f['length']);
  $out.=$code."\n\n";
  $provenance[]=['file'=>$file,'function'=>$name,'line'=>$f['line'],'end_line'=>$f['end_line'],'sha256'=>hash('sha256',$code)];
 }
}
file_put_contents($argv[1],$out);
file_put_contents($argv[2],json_encode($provenance,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
