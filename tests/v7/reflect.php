<?php
if (PHP_SAPI!=='cli' || getenv('ACHBOT_PRIVATE_TEST')!=='1') exit(3);
require __DIR__.'/buy_helpers.php';
$names=['getPlanDetails','ensureServerCapacity','getServerInfoAndConfig',
'buildRemark','getNextPortAndIncrease','createServerAccount','buildLinksAndSendQr',
'sendQrFromLink','insertOrder','decreaseServerCapacity',
'ensureAccountCreationSucceeded','giveReferralReward','renewConfigOnServer',
'processWalletPayment','calculateAgentPrice','createIncreaseOrder',
'payTypeToDescription','addToWallet','notifyAllAdminsSimple',
'refundToWalletAndNotify','dbFetchPayByHash','prepareStartFallback',
'inlineKeyboard','duplicateReceiptGuard'];
$out=[];
foreach($names as $name){
    if(!function_exists($name)) throw new RuntimeException('Missing facade '.$name);
    $fn=new ReflectionFunction($name);$params=[];
    foreach($fn->getParameters() as $param){
        $params[]=[(string)$param->getName(),(string)$param->getType(),
            $param->isOptional(),$param->isDefaultValueAvailable()?$param->getDefaultValue():null,
            $param->isPassedByReference(),$param->isVariadic()];
    }
    $out[$name]=['parameters'=>$params,'return_type'=>(string)$fn->getReturnType()];
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
