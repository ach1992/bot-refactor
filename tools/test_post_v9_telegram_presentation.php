<?php
/**
 * Current-source, network-free acceptance for opt-in Telegram presentation.
 * No production token, account, database or Telegram API operation is used.
 */
require_once __DIR__ . '/../src/Telegram/Presentation.php';

use function AchBot\Telegram\Presentation\prepare;
use function AchBot\Telegram\Presentation\dispatch;
use function AchBot\Telegram\Presentation\decoratedMarkup;

$cases = 0;
function requireSame($expected, $actual, string $why): void {
    global $cases;
    $cases++;
    if ($expected !== $actual) {
        throw new RuntimeException($why . ' expected=' .
            json_encode($expected,JSON_UNESCAPED_UNICODE) . ' actual=' .
            json_encode($actual,JSON_UNESCAPED_UNICODE));
    }
}
function presentationPolicy(bool $premium=true, bool $style=true): array {
    return [
        'premium_emoji_enabled' => $premium,
        'premium_emoji_ids' => [
            '✅' => '1234567890123456789',
            '🧑‍💻' => '9876543210123456789',
            '❌' => '1234567890',
        ],
        'button_styles_enabled' => $style,
        'button_styles' => [
            'callback_data' => ['confirm'=>'success','delete'=>'danger'],
            'reply_text' => ['📞 ارسال شماره'=>'primary'],
        ],
    ];
}

$keys = json_encode([
    'inline_keyboard' => [[
        ['text'=>'✅ تأیید','callback_data'=>'confirm'],
        ['text'=>'🧑‍💻 ارتباط','callback_data'=>'contact'],
        ['text'=>'❌ حذف','callback_data'=>'delete'],
    ]],
], JSON_UNESCAPED_UNICODE);
$base = [
    'chat_id'=>123,'text'=>'✅ <b>انجام شد</b>','parse_mode'=>'HTML',
    'reply_markup'=>$keys
];

unset($GLOBALS['achbotTelegramPresentation']);
requireSame($base, prepare('sendMessage',$base),'default identity for message');
requireSame(['reply_markup'=>$keys],prepare('editMessageReplyMarkup',['reply_markup'=>$keys]),
    'default identity for keyboard edit');
requireSame(['text'=>'*✅ bold*','parse_mode'=>'Markdown'],prepare('sendMessage',
    ['text'=>'*✅ bold*','parse_mode'=>'Markdown']),'old Markdown remains unchanged');
$wire = [];
$ok = (object)['ok'=>true,'result'=>(object)['message_id'=>100]];
requireSame($ok, dispatch('sendMessage',$base,static function ($value) use (&$wire,$ok) {
    $wire[]=$value;
    return $ok;
}),'disabled output unchanged');
requireSame([$base],$wire,'disabled exactly one unchanged wire call');

$GLOBALS['achbotTelegramPresentation']=presentationPolicy();
$expected = $base;
$expected['text']='<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji> <b>انجام شد</b>';
$expectedKeys=json_decode($keys,true);
$expectedKeys['inline_keyboard'][0][0]['text']='تأیید';
$expectedKeys['inline_keyboard'][0][0]['style']='success';
$expectedKeys['inline_keyboard'][0][0]['icon_custom_emoji_id']='1234567890123456789';
$expectedKeys['inline_keyboard'][0][1]['text']='ارتباط';
$expectedKeys['inline_keyboard'][0][1]['icon_custom_emoji_id']='9876543210123456789';
$expectedKeys['inline_keyboard'][0][2]['text']='حذف';
$expectedKeys['inline_keyboard'][0][2]['style']='danger';
$expectedKeys['inline_keyboard'][0][2]['icon_custom_emoji_id']='1234567890';
$decorated=prepare('sendMessage',$base);
requireSame($expected['text'],$decorated['text'],'HTML mapped by grapheme');
requireSame($expectedKeys,json_decode($decorated['reply_markup'],true),'inline actions/style/id');
requireSame($base['chat_id'],$decorated['chat_id'],'recipient unchanged');

$complex='✅ <code>✅</code> <pre>✅</pre> <a href="https://example.invalid/?x=✅&amp;y=3">✅</a> <tg-emoji emoji-id="9">✅</tg-emoji> <i>🧑‍💻</i> &amp; &#9989;';
$converted=prepare('sendMessage',['text'=>$complex,'parse_mode'=>'HTML'])['text'];
requireSame(
    '<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji> '.
    '<code>✅</code> <pre>✅</pre> <a href="https://example.invalid/?x=✅&amp;y=3">✅</a> '.
    '<tg-emoji emoji-id="9">✅</tg-emoji> '.
    '<i><tg-emoji emoji-id="9876543210123456789">🧑‍💻</tg-emoji></i> &amp; &#9989;',
    $converted,'preserve code, pre, link, existing custom emoji and entities');
$literal='A < B & C ✅ "quoted"';
requireSame([
    'text'=>'A &lt; B &amp; C <tg-emoji emoji-id="1234567890123456789">✅</tg-emoji> &quot;quoted&quot;',
    'parse_mode'=>'HTML'
],prepare('sendMessage',['text'=>$literal]),'plain to safely escaped HTML');
requireSame([
    'caption'=>'&lt;x&gt; <tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
    'parse_mode'=>'HTML'
],prepare('sendPhoto',['caption'=>'<x> ✅']),'plain media caption safety');
foreach (['sendPhoto','sendDocument','sendVideo','sendAudio','sendVoice',
          'sendAnimation','editMessageCaption'] as $method) {
    requireSame('<tg-emoji emoji-id="1234567890123456789">✅</tg-emoji>',
        prepare($method,['caption'=>'✅','parse_mode'=>'HTML'])['caption'],
        'caption method: '.$method);
}
requireSame(['text'=>'*✅*','parse_mode'=>'Markdown'],prepare('sendMessage',
    ['text'=>'*✅*','parse_mode'=>'Markdown']),'legacy Markdown untouched');
requireSame(['text'=>'*✅*','parse_mode'=>'MarkdownV2'],prepare('sendMessage',
    ['text'=>'*✅*','parse_mode'=>'MarkdownV2']),'MarkdownV2 untouched');
requireSame(['text'=>'✅','entities'=>[]],prepare('sendMessage',
    ['text'=>'✅','entities'=>[]]),'explicit entities untouched');
requireSame(['text'=>'✅'],prepare('answerCallbackQuery',
    ['text'=>'✅']),'callback alert remains literal');

$reply = ['keyboard'=>[[['text'=>'📞 ارسال شماره','request_contact'=>true]],
                        [['text'=>'✅ انجام']]],'resize_keyboard'=>true];
$decoratedReply=prepare('sendMessage',['text'=>'Hello',
    'reply_markup'=>json_encode($reply,JSON_UNESCAPED_UNICODE)])['reply_markup'];
$actualReply=json_decode($decoratedReply,true);
requireSame('📞 ارسال شماره',$actualReply['keyboard'][0][0]['text'],
    'reply message text stable for input routing');
requireSame(true,$actualReply['keyboard'][0][0]['request_contact'],
    'reply special action stable');
requireSame('primary',$actualReply['keyboard'][0][0]['style'],
    'reply style configured');
requireSame(false,isset($actualReply['keyboard'][1][0]['icon_custom_emoji_id']),
    'reply input never substituted automatically');
requireSame($reply['resize_keyboard'],$actualReply['resize_keyboard'],'reply layout');
requireSame(['remove_keyboard'=>true],decoratedMarkup(['remove_keyboard'=>true],
    presentationPolicy(),presentationPolicy()['premium_emoji_ids']),
    'remove keyboard unchanged');

$GLOBALS['achbotTelegramPresentation']=presentationPolicy(false,false);
requireSame($base,prepare('sendMessage',$base),'explicit inactive identity');
$GLOBALS['achbotTelegramPresentation']=presentationPolicy(true,false);
requireSame(true,prepare('sendMessage',$base)['text'] !== $base['text'],
    'premium independent of style switch');

$GLOBALS['achbotTelegramPresentation']=presentationPolicy();
$GLOBALS['achbotTelegramPresentation']['premium_emoji_ids']['✅']='invalid';
$invalid=prepare('sendMessage',['text'=>'✅','parse_mode'=>'HTML']);
requireSame(['text'=>'✅','parse_mode'=>'HTML'],$invalid,'reject invalid custom emoji id');

$GLOBALS['achbotTelegramPresentation']=presentationPolicy();
$sent=[];
$rejected=(object)['ok'=>false,'error_code'=>400,
    'description'=>'Bad Request: cannot use custom emoji'];
$send = static function (array $payload) use (&$sent,$rejected,$ok) {
    $sent[]=$payload;
    return count($sent)===1 ? $rejected : $ok;
};
requireSame($ok,dispatch('sendMessage',$base,$send),'known 400 recovered');
requireSame(2,count($sent),'one rejection => one fallback send');
requireSame($base,$sent[1],'retry uses exact original payload');
foreach ([
    null,
    (object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests'],
    (object)['ok'=>false,'error_code'=>500,'description'=>'Server Error'],
    (object)['ok'=>false,'error_code'=>400,'description'=>'chat not found'],
    (object)['ok'=>true,'result'=>(object)['message_id'=>11]],
] as $response) {
    $calls=0;
    $returned=dispatch('sendMessage',$base,static function ($payload) use (&$calls,$response) {
        $calls++;return $response;
    });
    requireSame($response,$returned,'preserve ambiguous/ordinary response');
    requireSame(1,$calls,'do not duplicate ambiguous send');
}
$objects=[(object)['ok'=>false,'error_code'=>400,'description'=>"can't parse entities"],
          ['ok'=>false,'error_code'=>400,'description'=>'CUSTOM_EMOJI_INVALID']];
foreach($objects as $response) {
    $calls=0;
    dispatch('sendMessage',$base,static function ($payload) use (&$calls,$response,$ok) {
        $calls++;return $calls===1?$response:$ok;
    });
    requireSame(2,$calls,'known formatting rejection original retry');
}
echo json_encode(['status'=>'PASS','cases'=>$cases,'pass'=>true,
    'scope'=>'isolated-presentation-no-network','default_unchanged'=>true],
    JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
