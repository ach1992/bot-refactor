<?php
/** AChBot per-instance messages and public destinations in existing BOT_STATES. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/../../messages/loader.php';
require_once __DIR__ . '/../Presentation/Text.php';

function achbotInstanceFields(): array {
    return ['start_message','user_welcome_message','reached_main_menu','purchase_note','cooperationUrl','helpChannel','lockChannelJoinUrl'];
}
function achbotInstanceMessageFields(): array {
    return ['start_message','user_welcome_message','reached_main_menu','purchase_note'];
}
function achbotInstanceLabel(string $field): string {
    if (!in_array($field,achbotInstanceFields(),true)) throw new InvalidArgumentException('instance_field');
    return match($field) {
        'start_message'=>achbotMessage('instance_content.field_start_message'),
        'user_welcome_message'=>achbotMessage('instance_content.field_user_welcome_message'),
        'reached_main_menu'=>achbotMessage('instance_content.field_reached_main_menu'),
        'purchase_note'=>achbotMessage('instance_content.field_purchase_note'),
        'cooperationUrl'=>achbotMessage('instance_content.field_cooperationUrl'),
        'helpChannel'=>achbotMessage('instance_content.field_helpChannel'),
        'lockChannelJoinUrl'=>achbotMessage('instance_content.field_lockChannelJoinUrl')
    };
}
/** Telegram entities use UTF-16 offsets; literal input is always escaped. */
function achbotInstanceInputHtml(string $text,array $entities=[]): ?string {
    if (!mb_check_encoding($text,'UTF-8') || trim($text)==='' || mb_strlen($text,'UTF-8')>3000 ||
        preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)) return null;
    $chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);$bounds=[0=>0];$unit=0;$bytes=0;
    foreach ($chars as $char) {
        $unit+=(int)(strlen(mb_convert_encoding($char,'UTF-16LE','UTF-8'))/2);
        $bytes+=strlen($char);$bounds[$unit]=$bytes;
    }
    if ($unit>3000) return null;
    $spans=[];$tags=['bold'=>'b','italic'=>'i','underline'=>'u','strikethrough'=>'s','code'=>'code',
                    'pre'=>'pre','blockquote'=>'blockquote','expandable_blockquote'=>'blockquote'];
    foreach ($entities as $index=>$entity) {
        $e=(array)$entity;$type=$e['type']??'';
        if (!isset($tags[$type]) && !in_array($type,['spoiler','text_link'],true)) continue;
        $start=$e['offset']??null;$length=$e['length']??null;
        if (!is_int($start)||!is_int($length)||$start<0||$length<=0||!isset($bounds[$start],$bounds[$start+$length])) return null;
        if ($type==='text_link') {
            $url=\AchBot\Presentation\Text\destinationUrl($e['url']??'');
            if ($url==='') return null;
            $open='<a href="'.htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">';$close='</a>';
        } elseif ($type==='spoiler') {$open='<tg-spoiler>';$close='</tg-spoiler>';}
        else {$open='<'.$tags[$type].'>';$close='</'.$tags[$type].'>';}
        $spans[]=['start'=>$start,'end'=>$start+$length,'open'=>$open,'close'=>$close,'index'=>$index,'type'=>$type];
    }
    foreach ($spans as $i=>$a) foreach ($spans as $j=>$b) if ($i<$j) {
        if (($a['start']<$b['start'] && $b['start']<$a['end'] && $a['end']<$b['end']) ||
            ($b['start']<$a['start'] && $a['start']<$b['end'] && $b['end']<$a['end'])) return null;
        if (max($a['start'],$b['start'])<min($a['end'],$b['end']) &&
            (in_array($a['type'],['pre','code'],true)||in_array($b['type'],['pre','code'],true))) return null;
    }
    $starts=[];$ends=[];
    foreach($spans as $span){$starts[$span['start']][]=$span;$ends[$span['end']][]=$span;}
    $out='';$position=0;
    foreach (array_merge($chars,['']) as $char) {
        $closing=$ends[$position]??[];
        usort($closing,static fn($a,$b)=>($b['start']<=>$a['start'])?:($b['index']<=>$a['index']));
        foreach($closing as $span)$out.=$span['close'];
        $opening=$starts[$position]??[];
        usort($opening,static fn($a,$b)=>($b['end']<=>$a['end'])?:($a['index']<=>$b['index']));
        foreach($opening as $span)$out.=$span['open'];
        $out.=htmlspecialchars($char,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $position+=(int)(strlen(mb_convert_encoding($char,'UTF-16LE','UTF-8'))/2);
    }
    return $out;
}
function achbotInstanceStored(array $state,string $field): ?string {
    $messages=$state['instanceMessages']??null;
    if (is_array($messages) && array_key_exists($field,$messages)) {
        $value=$messages[$field];
        if (is_array($value) && ($value['format']??null)==='html' && is_string($value['text']??null) &&
            trim($value['text'])!=='' && mb_check_encoding($value['text'],'UTF-8') && strlen($value['text'])<=20000)
            return $value['text'];
        if (is_string($value) && trim($value)!=='' && mb_check_encoding($value,'UTF-8') && mb_strlen($value,'UTF-8')<=3000)
            return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return null; // An explicit clear never resurrects an obsolete alias.
    }
    $legacy=$state[$field]??null;
    return is_string($legacy) && trim($legacy)!=='' && mb_check_encoding($legacy,'UTF-8') && mb_strlen($legacy,'UTF-8')<=3000
        ? htmlspecialchars($legacy,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') : null;
}
function achbotInstanceMessage(array $state,string $field,$userId=''): string {
    $template=achbotInstanceStored($state,$field);
    if ($template===null) return achbotMessageHtml('instance_content.unconfigured',['userId'=>(string)$userId]);
    $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $rendered=strtr($template,[
        'SUPPORTID'=>$escape($state['supportID']??''),'LOCKCHANNEL'=>$escape($state['lockChannel']??''),
        'HELPCHANNEL'=>$escape($state['helpChannel']??''),'COOPERATIONURL'=>$escape($state['cooperationUrl']??''),
        'userid'=>$escape($userId)
    ]);
    $visible=html_entity_decode(strip_tags($rendered),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $limit=$field==='purchase_note'?1600:3800;
    if (strlen(mb_convert_encoding($visible,'UTF-16LE','UTF-8'))/2>$limit)
        return achbotMessageHtml('instance_content.unconfigured',['userId'=>(string)$userId]);
    return $rendered;
}
function achbotInstanceContentValues(array $values,array $state,$userId=''): array {
    foreach(['start_message','user_welcome_message','reached_main_menu'] as $field)
        $values[$field]=achbotInstanceMessage($state,$field,$userId);
    $note=achbotInstanceMessage($state,'purchase_note',$userId);
    foreach(['buy_subscription_detail','buy_much_subscription_detail','buy_custom_subscription_detail'] as $field)
        if (is_string($values[$field]??null))$values[$field]=rtrim($values[$field])."\n\n".$note;
    return $values;
}
function achbotInstanceMenu(array $state): string {
    global $buttonValues;
    $rows=[];
    foreach(achbotInstanceMessageFields() as $field){
        $set=achbotInstanceStored($state,$field)!==null;
        $rows[]=[['text'=>achbotInstanceLabel($field).' | '.($set?achbotMessage('instance_content.configured'):achbotMessage('instance_content.unset')),
                  'callback_data'=>'ic|e|'.$field]];
    }
    $rows[]=[['text'=>$buttonValues['back_button'],'callback_data'=>'botSettings']];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function achbotInstanceCanEdit(int $actor): bool {
    return function_exists('achbotAdminCan') && achbotAdminCan('bot_settings',$actor);
}
/** Recheck current actor/step under the writer lease shared by settings edits. */
function achbotInstanceSave(int $actor,string $field,$value,string $expectedStep): bool {
    global $admin;
    if (!in_array($field,achbotInstanceFields(),true)||!achbotInstanceCanEdit($actor)) return false;
    return achbotMutateBotStates(
        static function(array $state) use($field,$value): array {
            if(in_array($field,achbotInstanceMessageFields(),true)){
                if(!is_array($state['instanceMessages']??null))$state['instanceMessages']=[];
                $state['instanceMessages'][$field]=$value;
            } else $state[$field]=$value;
            return $state;
        },
        static function(mysqli $db) use($actor,$expectedStep,$admin): void {
            $s=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');$s->bind_param('i',$actor);$s->execute();
            $ids=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            if(count($ids)!==1)throw new RuntimeException('instance_actor_unavailable');
            $pk=(int)$ids[0]['id'];$s=$db->prepare('SELECT userid,isAdmin,step FROM users WHERE id=? FOR UPDATE');
            $s->bind_param('i',$pk);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            if(!$row||(int)$row['userid']!==$actor||($actor!==(int)$admin&&(int)$row['isAdmin']!==1)||
                (string)$row['step']!==$expectedStep||!achbotAdminCanLocked($db,$actor,(int)$admin,'bot_settings'))
                throw new RuntimeException('instance_actor_changed');
        }
    );
}
function achbotHandleInstanceContent($update,$data,$text,array $cachedUser): bool {
    global $from_id,$message_id,$connection,$buttonValues,$cancelKey,$mainValues;
    $pending=str_starts_with((string)($cachedUser['step']??''),'achbotInstance:');
    $callback=is_string($data)&&str_starts_with($data,'ic|');
    if(!$callback&&!$pending)return false;
    $actor=(int)($from_id??0);$source=$callback?($update->callback_query??null):($update->message??null);
    $sender=$source->from??null;$message=$callback?($source->message??null):$source;
    $valid=is_object($source)&&is_object($sender)&&is_object($message)&&
        is_int($sender->id??null)&&$sender->id===$actor&&($sender->is_bot??false)!==true&&
        ($message->chat->type??null)==='private'&&($message->chat->id??null)===$actor&&
        ($message->message_id??null)===(int)($message_id??0)&&
        ($callback?($source->data??null)===$data:($source->text??null)===$text);
    if(!$valid||!achbotInstanceCanEdit($actor))return true;
    if($callback){
        if($data==='ic|m'){editText($message_id,achbotMessage('instance_content.title'),achbotInstanceMenu($GLOBALS['botState']??[]),'HTML');return true;}
        if(!preg_match('/^ic\|e\|([A-Za-z_]+)$/D',$data,$m)||!in_array($m[1],achbotInstanceFields(),true))return true;
        $field=$m[1];setUser('achbotInstance:'.$field);
        $vars=['label'=>achbotInstanceLabel($field)];
        $prompt=in_array($field,achbotInstanceMessageFields(),true)
            ? achbotMessageHtml('instance_content.message_prompt',$vars)
            : achbotMessageHtml('instance_content.destination_prompt',$vars);
        sendMessage($prompt,$cancelKey,'HTML');
        return true;
    }
    $s=$connection->prepare('SELECT step FROM users WHERE userid=? LIMIT 2');$s->bind_param('i',$actor);$s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if(count($rows)!==1)return true;$step=(string)$rows[0]['step'];
    if(!preg_match('/^achbotInstance:([A-Za-z_]+)$/D',$step,$m)||!in_array($m[1],achbotInstanceFields(),true))return true;
    $field=$m[1];
    if($text===($buttonValues['cancel']??null)||$text==='/cancel'){
        setUser();
        $isMessage=in_array($field,achbotInstanceMessageFields(),true);
        sendMessage($isMessage?achbotMessage('instance_content.title'):$mainValues['change_bot_settings_message'],
            $isMessage?achbotInstanceMenu($GLOBALS['botState']??[]):getGateWaysKeys(),'HTML');return true;
    }
    if(!is_string($text))return true;
    if($text==='/clear')$value=in_array($field,achbotInstanceMessageFields(),true)?null:'';
    elseif(in_array($field,achbotInstanceMessageFields(),true)){
        $html=achbotInstanceInputHtml($text,(array)($source->entities??[]));
        if($html===null||($field==='purchase_note'&&strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8'))/2>1200)){
            sendMessage(achbotMessage('instance_content.invalid_message'),$cancelKey,'HTML');return true;
        }
        $value=['format'=>'html','text'=>$html];
    } else {
        $value=\AchBot\Presentation\Text\destinationUrl($text);
        if($value===''){sendMessage(achbotMessage('instance_content.invalid_destination'),$cancelKey,'HTML');return true;}
    }
    if(!achbotInstanceSave($actor,$field,$value,$step)){
        sendMessage(achbotMessage('instance_content.save_unavailable'),$cancelKey,'HTML');return true;
    }
    setUser();
    sendMessage(achbotMessage('instance_content.saved'),
        in_array($field,achbotInstanceMessageFields(),true)?achbotInstanceMenu($GLOBALS['botState']??[]):getGateWaysKeys(),'HTML');
    return true;
}
