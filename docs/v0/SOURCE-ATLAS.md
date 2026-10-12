# V0 generated source atlas

Baseline: `a89e63713131d16b5d6461fd41c01bb002d0be63`. Regenerate with `python3 tools/inventory.py --write`.

**Lexical evidence, not proof of runtime reachability, authorization or complete flow coverage.**

## Inventory counts

| Catalog | Records |
|---|---:|
| files | 19 |
| functions | 357 |
| includes | 34 |
| sql | 925 |
| callbacks | 1072 |
| route_conditions | 668 |
| state_writes | 294 |
| settings_access | 789 |
| runtime_effects | 168 |
| legacy_names | 337 |
| tables | 23 |

## Files

| File | Lines | Role |
|---|---:|---|
| [baseInfo.example.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/baseInfo.example.php) | 22 | Application/config/schema |
| [bot.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php) | 14848 | Application/config/schema |
| [buy_helpers.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php) | 1502 | Application/config/schema |
| [config.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php) | 8573 | Application/config/schema |
| [health.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/health.php) | 78 | Application/config/schema |
| [index.html](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/index.html) | 30 | Application/config/schema |
| [pay/back.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/back.php) | 877 | Application/config/schema |
| [pay/index.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/index.php) | 282 | Application/config/schema |
| [phpqrcode/phpqrcode.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/phpqrcode/phpqrcode.php) | 5015 | Bundled third party |
| [phpqrcode/qrlib.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/phpqrcode/qrlib.php) | 7 | Bundled third party |
| [settings/gift2all.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/gift2all.php) | 211 | Application/config/schema |
| [settings/jdf.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/jdf.php) | 647 | Bundled third party |
| [settings/messagewizwiz.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php) | 704 | Application/config/schema |
| [settings/rewardReport.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/rewardReport.php) | 653 | Application/config/schema |
| [settings/subLink.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/subLink.php) | 122 | Application/config/schema |
| [settings/tronChecker.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/tronChecker.php) | 727 | Application/config/schema |
| [settings/values.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/values.php) | 485 | Application/config/schema |
| [settings/warnusers.php](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/warnusers.php) | 570 | Application/config/schema |
| [database/legacy-schema.sql](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/database/legacy-schema.sql) | 812 | Application/config/schema |

## Include edges

Non-`__DIR__` paths are candidates: actual resolution depends on CWD/include_path/SAPI. A missing root-CWD candidate does not prove a missing dependency.

| Source | Expression | Resolved candidates |
|---|---|---|
| [bot.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L3) | `'config.php'` | `config.php` (present) |
| [bot.php:4](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L4) | `'buy_helpers.php'` | `buy_helpers.php` (present) |
| [bot.php:2650](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L2650) | `'phpqrcode/qrlib.php'` | `phpqrcode/qrlib.php` (present) |
| [bot.php:4787](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L4787) | `'phpqrcode/qrlib.php'` | `phpqrcode/qrlib.php` (present) |
| [bot.php:8894](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L8894) | `'phpqrcode/qrlib.php'` | `phpqrcode/qrlib.php` (present) |
| [buy_helpers.php:275](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L275) | `$p` | dynamic |
| [buy_helpers.php:281](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L281) | `'phpqrcode/qrlib.php'` | `phpqrcode/qrlib.php` (present) |
| [buy_helpers.php:478](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L478) | `$p` | dynamic |
| [buy_helpers.php:484](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L484) | `'phpqrcode/qrlib.php'` | `phpqrcode/qrlib.php` (present) |
| [config.php:5](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5) | `"settings/values.php"` | `settings/values.php` (present) |
| [config.php:6](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L6) | `'settings/jdf.php'` | `settings/jdf.php` (present) |
| [config.php:7](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7) | `'baseInfo.php'` | `baseInfo.php` (local-only) |
| [health.php:2](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/health.php#L2) | `__DIR__.'/settings/values.php'` | `settings/values.php` (present) |
| [health.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/health.php#L3) | `__DIR__.'/baseInfo.php'` | `baseInfo.php` (local-only) |
| [pay/back.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/back.php#L3) | `__DIR__ . '/../baseInfo.php'` | `baseInfo.php` (local-only) |
| [pay/back.php:4](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/back.php#L4) | `__DIR__ . '/../config.php'` | `config.php` (present) |
| [pay/back.php:5](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/back.php#L5) | `__DIR__ . '/../buy_helpers.php'` | `buy_helpers.php` (present) |
| [pay/index.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/index.php#L3) | `__DIR__ . '/../baseInfo.php'` | `baseInfo.php` (local-only) |
| [pay/index.php:4](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/index.php#L4) | `__DIR__ . '/../config.php'` | `config.php` (present) |
| [phpqrcode/qrlib.php:6](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/phpqrcode/qrlib.php#L6) | `__DIR__ . '/phpqrcode.php'` | `phpqrcode/phpqrcode.php` (present) |
| [settings/gift2all.php:2](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/gift2all.php#L2) | `__DIR__ . '/../baseInfo.php'` | `baseInfo.php` (local-only) |
| [settings/gift2all.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/gift2all.php#L3) | `__DIR__ . '/../config.php'` | `config.php` (present) |
| [settings/messagewizwiz.php:2](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L2) | `__DIR__ . '/../baseInfo.php'` | `baseInfo.php` (local-only) |
| [settings/messagewizwiz.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L3) | `__DIR__ . '/../config.php'` | `config.php` (present) |
| [settings/messagewizwiz.php:4](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L4) | `__DIR__ . '/jdf.php'` | `settings/jdf.php` (present) |
| [settings/rewardReport.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/rewardReport.php#L3) | `__DIR__ . '/../baseInfo.php'` | `baseInfo.php` (local-only) |
| [settings/rewardReport.php:4](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/rewardReport.php#L4) | `__DIR__ . '/../config.php'` | `config.php` (present) |
| [settings/rewardReport.php:213](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/rewardReport.php#L213) | `'../phpqrcode/qrlib.php'` | `<outside>` (not_at_candidate), `phpqrcode/qrlib.php` (present) |
| [settings/subLink.php:2](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/subLink.php#L2) | `__DIR__ . '/../baseInfo.php'` | `baseInfo.php` (local-only) |
| [settings/subLink.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/subLink.php#L3) | `__DIR__ . '/../config.php'` | `config.php` (present) |
| [settings/tronChecker.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/tronChecker.php#L3) | `__DIR__ . '/../config.php'` | `config.php` (present) |
| [settings/tronChecker.php:119](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/tronChecker.php#L119) | `'../phpqrcode/qrlib.php'` | `<outside>` (not_at_candidate), `phpqrcode/qrlib.php` (present) |
| [settings/warnusers.php:3](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/warnusers.php#L3) | `__DIR__ . '/../baseInfo.php'` | `baseInfo.php` (local-only) |
| [settings/warnusers.php:4](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/warnusers.php#L4) | `__DIR__ . '/../config.php'` | `config.php` (present) |

## Database coverage

| Table | Columns | Literal SQL sites | Operations |
|---|---:|---:|---|
| `add_link` | 5 | 2 | INSERT, SELECT |
| `admins` | 5 | 0 | No direct literal site: inspect dynamic calls |
| `bc_jobs` | 11 | 15 | INSERT, SELECT, UPDATE |
| `bc_messages` | 9 | 12 | INSERT, SELECT, UPDATE |
| `black_list` | 2 | 0 | No direct literal site: inspect dynamic calls |
| `chats` | 7 | 19 | INSERT, SELECT, UPDATE |
| `chats_info` | 5 | 11 | INSERT, SELECT |
| `discounts` | 15 | 25 | DELETE, INSERT, SELECT, UPDATE |
| `gift_list` | 6 | 7 | DELETE, INSERT, SELECT, UPDATE |
| `increase_day` | 3 | 17 | DELETE, INSERT, SELECT, UPDATE |
| `increase_order` | 9 | 23 | INSERT, SELECT |
| `increase_plan` | 3 | 17 | DELETE, INSERT, SELECT, UPDATE |
| `needed_sofwares` | 4 | 1 | SELECT |
| `orders_list` | 19 | 161 | DELETE, INSERT, SELECT, UPDATE |
| `pays` | 21 | 118 | DELETE, INSERT, SELECT, UPDATE |
| `send_list` | 14 | 24 | INSERT, SELECT, UPDATE |
| `servers` | 8 | 0 | No direct literal site: inspect dynamic calls |
| `server_categories` | 6 | 27 | DELETE, INSERT, SELECT, UPDATE |
| `server_config` | 15 | 90 | DELETE, INSERT, SELECT, UPDATE |
| `server_info` | 7 | 52 | DELETE, INSERT, SELECT, UPDATE |
| `server_plans` | 26 | 115 | DELETE, INSERT, SELECT, UPDATE |
| `setting` | 3 | 58 | DELETE, INSERT, SELECT, UPDATE |
| `users` | 18 | 136 | INSERT, SELECT, UPDATE |

## First-party function map

Method names may be shared; nested definitions are identified in the JSON atlas.

| Source | Function | Globals | Lexical callees |
|---|---|---|---|
| [bot.php:1870-2018](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L1870) | `processCallbackQuery` | $admin, $botState, $buttonValues, $connection, $from_id, $mainValues, $userInfo | array_chunk, bind_param, ceil, close, execute, fetch_assoc, get_result, is_array, json_encode, preg_match, prepare, sendMessage |
| [bot.php:7752-7806](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L7752) | `extractUsernameFromLink` |  | base64_decode, explode, preg_match, preg_replace, rawurldecode, str_repeat, strlen, strpos, strtr, substr, trim |
| [bot.php:8102-8153](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L8102) | `detectServerIdFromRemark` |  | close, execute, fetch_assoc, get_result, mb_strlen, mb_strtolower, mb_substr, preg_split, prepare, trim |
| [bot.php:8488-8513](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L8488) | `showOrderManagementPanel` |  | editText, json_encode, sendMessage |
| [bot.php:9655-9663](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L9655) | `cancelSearchKeyboard` |  | json_encode |
| [buy_helpers.php:4-33](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L4) | `getPlanDetails` |  | alert, bind_param, close, execute, fetch_assoc, floor, get_result, microtime, prepare, time |
| [buy_helpers.php:37-59](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L37) | `ensureServerCapacity` |  | alert, bind_param, close, execute, fetch_assoc, get_result, prepare |
| [buy_helpers.php:63-83](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L63) | `getServerInfoAndConfig` |  | alert, bind_param, close, execute, fetch_assoc, get_result, prepare |
| [buy_helpers.php:87-104](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L87) | `buildRemark` |  | rand |
| [buy_helpers.php:108-133](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L108) | `getNextPortAndIncrease` |  | count, explode, file_exists, file_get_contents, file_put_contents, rand, trim |
| [buy_helpers.php:137-232](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L137) | `createServerAccount` |  | RandomString, addInboundAccount, addMarzbanUser, addUser, is_object, rand, strstr |
| [buy_helpers.php:236-456](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L236) | `buildLinksAndSendQr` | $botUsername | CURLFile, RandomString, array_filter, array_map, class_exists, count, explode, file_exists, getConnectionLink, getcwd, implode, json_encode, png, rtrim, sendMessage, sendPhoto, str_replace, unlink |
| [buy_helpers.php:460-513](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L460) | `sendQrFromLink` |  | CURLFile, RandomString, class_exists, file_exists, getcwd, png, rtrim, sendMessage, sendPhoto, unlink |
| [buy_helpers.php:517-556](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L517) | `insertOrder` |  | bind_param, close, execute, prepare |
| [buy_helpers.php:560-586](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L560) | `decreaseServerCapacity` |  | bind_param, close, execute, prepare |
| [buy_helpers.php:590-648](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L590) | `ensureAccountCreationSucceeded` |  | Exception, alert, is_array, is_null, is_object, sendMessage |
| [buy_helpers.php:652-698](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L652) | `giveReferralReward` |  | bind_param, close, execute, fetch_assoc, get_result, number_format, prepare, sendMessage, strtolower |
| [buy_helpers.php:702-722](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L702) | `renewConfigOnServer` |  | editClientTraffic, editInboundTraffic, editMarzbanConfig, getServerInfoAndConfig |
| [buy_helpers.php:726-891](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L726) | `processWalletPayment` | $admin, $from_id | alert, begin_transaction, bind_param, call_user_func, close, commit, execute, fetch_assoc, getMessage, get_result, in_array, number_format, prepare, rollback, sendMessage |
| [buy_helpers.php:895-931](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L895) | `calculateAgentPrice` |  | floor, is_array, json_decode |
| [buy_helpers.php:935-964](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L935) | `createIncreaseOrder` |  | bind_param, close, execute, prepare |
| [buy_helpers.php:968-977](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L968) | `payTypeToDescription` |  | preg_match |
| [buy_helpers.php:981-990](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L981) | `addToWallet` |  | bind_param, close, execute, prepare |
| [buy_helpers.php:994-1010](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L994) | `notifyAllAdminsSimple` | $botState | sendMessage |
| [buy_helpers.php:1014-1040](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1014) | `refundToWalletAndNotify` | $botState | addToWallet, notifyAllAdminsSimple, number_format, sendMessage |
| [buy_helpers.php:1043-1051](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1043) | `dbFetchPayByHash` |  | bind_param, close, execute, fetch_assoc, get_result, prepare |
| [buy_helpers.php:1053-1059](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1053) | `prepareStartFallback` | $data, $text | setUser |
| [buy_helpers.php:1061-1063](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1061) | `inlineKeyboard` |  | json_encode |
| [buy_helpers.php:1065-1079](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1065) | `generateHigherRandomizedAmount` |  | max, rand |
| [buy_helpers.php:1081-1139](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1081) | `fetchCartToCartUserEligibility` |  | bind_param, close, execute, fetch_assoc, get_result, prepare |
| [buy_helpers.php:1141-1181](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1141) | `resolveCartToCartMessageType` | $connection | fetchCartToCartUserEligibility, strtotime, time |
| [buy_helpers.php:1183-1193](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1183) | `getCartToCartMessageKey` |  |  |
| [buy_helpers.php:1195-1241](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1195) | `buildCartToCartInfoMessage` | $USDTPrice, $USDTPriceagent, $connection | bind_param, close, execute, fetch_assoc, generateHigherRandomizedAmount, getCartToCartMessageKey, get_result, is_numeric, number_format, prepare, resolveCartToCartMessageType, round, strtr, trim |
| [buy_helpers.php:1243-1262](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1243) | `kbSendReceiptAndCancel` |  | inlineKeyboard |
| [buy_helpers.php:1264-1270](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1264) | `kbOnlyCancel` |  | inlineKeyboard |
| [buy_helpers.php:1272-1278](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1272) | `handleCancelFlow` |  | delMessage, getMainKeys, sendMessage, setUser |
| [buy_helpers.php:1280-1284](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1280) | `extractPhotoFileId` |  | end |
| [buy_helpers.php:1286-1401](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1286) | `duplicateReceiptGuard` |  | bind_param, ceil, close, execute, fetch_assoc, get_result, in_array, max, prepare |
| [buy_helpers.php:1403-1410](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1403) | `countUserPaysByState` |  | bind_param, close, execute, fetch_assoc, get_result, prepare |
| [buy_helpers.php:1412-1424](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1412) | `sumUserPaysByState` |  | bind_param, close, execute, fetch_assoc, get_result, prepare |
| [buy_helpers.php:1426-1460](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1426) | `buildAdminKeyboardWithStats` |  | countUserPaysByState, inlineKeyboard, number_format, sumUserPaysByState |
| [buy_helpers.php:1462-1486](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1462) | `sendReceiptToAdminsAndUpdatePay` |  | bind_param, close, execute, prepare, sendPhoto, sendToAllAdmins |
| [buy_helpers.php:1488-1500](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L1488) | `sendWithStartButton` |  | json_encode, sendMessage |
| [config.php:15-24](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L15) | `tgRetryAfter` |  | is_object |
| [config.php:26-134](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L26) | `bot` | $botToken | curl_close, curl_errno, curl_error, curl_exec, curl_getinfo, curl_init, curl_setopt, date, defined, file_exists, file_put_contents, filesize, http_build_query, is_array, is_string, json_decode, print_r, substr, tgRetryAfter |
| [config.php:136-221](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L136) | `sendFileToTelegram` | $botToken | curl_close, curl_errno, curl_error, curl_exec, curl_getinfo, curl_init, curl_setopt, date, defined, file_exists, file_put_contents, filesize, is_array, is_string, json_decode, json_encode, print_r, substr |
| [config.php:223-227](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L223) | `normalizeReplyMarkup` |  | is_array, json_encode |
| [config.php:229-254](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L229) | `sendMessage` | $from_id | bot, normalizeReplyMarkup, strpos |
| [config.php:256-311](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L256) | `sendDatabaseBackup` | $connection, $dbName, $dbPassword, $dbUserName | ZipArchive, addFile, basename, close, date, date_default_timezone_set, exec, execute, fetch_assoc, file_exists, filesize, get_result, is_dir, mkdir, open, prepare, sendDocument, unlink |
| [config.php:313-326](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L313) | `sendDocument` | $from_id | CURLFile, file_exists, is_array, realpath, sendFileToTelegram |
| [config.php:328-336](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L328) | `answerCallbackQuery` |  | file_get_contents, http_build_query |
| [config.php:338-348](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L338) | `editKeys` | $from_id, $message_id | bot |
| [config.php:349-360](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L349) | `editText` | $from_id | bot |
| [config.php:362-371](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L362) | `delMessage` | $from_id, $message_id | bot |
| [config.php:372-380](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L372) | `sendAction` | $from_id | bot |
| [config.php:382-388](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L382) | `forwardmessage` |  | bot |
| [config.php:390-397](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L390) | `isForwardedMessageObj` |  | is_object |
| [config.php:399-404](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L399) | `groupFa` |  |  |
| [config.php:406-412](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L406) | `stateFa` |  |  |
| [config.php:413-453](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L413) | `getUsersCountByGroup` |  | close, execute, fetch_assoc, get_result, prepare |
| [config.php:456-458](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L456) | `apiOk` |  | is_object |
| [config.php:460-465](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L460) | `getApiMessageId` |  | is_object |
| [config.php:467-481](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L467) | `logBroadcastUserMessage` |  | bind_param, close, execute, prepare |
| [config.php:483-489](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L483) | `pinOne` |  | bot |
| [config.php:491-503](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L491) | `unpinOne` |  | apiOk, bot |
| [config.php:505-510](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L505) | `deleteOne` |  | bot |
| [config.php:512-539](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L512) | `sendPhoto` | $from_id | bot, normalizeReplyMarkup, strpos |
| [config.php:541-554](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L541) | `getFileUrl` | $botToken | bot |
| [config.php:556-564](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L556) | `alert` | $callbackId | bot |
| [config.php:578-591](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L578) | `check` | $range | ip_in_range |
| [config.php:593-602](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L593) | `curl_get_file_contents` |  | curl_close, curl_exec, curl_init, curl_setopt |
| [config.php:604-615](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L604) | `ip_in_range` |  | explode, ip2long, pow, strpos |
| [config.php:727-766](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L727) | `startWalletPayment` |  | RandomString, bind_param, close, execute, json_encode, number_format, prepare, sendMessage, time |
| [config.php:768-790](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L768) | `replaceAllPlaceholdersInMainValues` |  | is_array, is_string, replaceAllPlaceholdersInMainValues, str_replace |
| [config.php:793-801](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L793) | `getSupportUrl` |  | ltrim, preg_match, trim |
| [config.php:837-923](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L837) | `getMainKeys` | $admin, $botState, $buttonValues, $connection, $from_id, $userInfo | array_merge, array_push, close, count, execute, fetch_assoc, getSupportUrl, get_result, json_encode, prepare, str_replace |
| [config.php:925-941](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L925) | `getAgentKeys` | $buttonValues, $connection, $from_id, $mainValues, $userInfo | bind_param, close, execute, get_result, jdate, json_encode, prepare |
| [config.php:943-985](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L943) | `getAdminKeys` | $admin, $buttonValues, $from_id | canSeeButton, is_callable, json_encode |
| [config.php:987-1003](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L987) | `setSettings` | $botState, $connection | bind_param, close, execute, get_result, json_encode, prepare |
| [config.php:1004-1026](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1004) | `getRejectedAgentList` | $buttonValues, $connection, $mainValues | bot, close, execute, fetch_assoc, get_result, json_encode, prepare |
| [config.php:1027-1082](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1027) | `getAgentDetails` | $buttonValues, $connection, $mainVAlues | bind_param, close, execute, fetch_assoc, get_result, json_encode, number_format, prepare, strtotime |
| [config.php:1083-1108](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1083) | `checkSpam` | $admin, $connection, $from_id, $userInfo | bind_param, close, execute, json_decode, json_encode, prepare, strtotime, time |
| [config.php:1109-1151](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1109) | `getAgentsList` | $buttonValues, $connection, $mainValues | bind_param, bot, close, execute, fetch_assoc, get_result, jdate, json_encode, prepare |
| [config.php:1152-1204](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1152) | `getAgentDiscounts` | $botState, $buttonValues, $connection, $mainValues | bind_param, close, execute, fetch_assoc, get_result, json_decode, json_encode, prepare |
| [config.php:1205-1246](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1205) | `NOWPayments` | $paymentKeys | curl_error, curl_exec, curl_init, curl_setopt, http_build_query, is_array, json_decode, json_encode, var_dump |
| [config.php:1247-1362](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1247) | `getServerConfigKeys` | $buttonValues, $connection, $mainValues | array_merge, bind_param, close, execute, fetch_assoc, get_result, json_encode, prepare |
| [config.php:1363-1429](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1363) | `getServerListKeys` | $buttonValues, $connection, $mainValues | bind_param, close, execute, fetch_assoc, get_result, json_encode, prepare |
| [config.php:1430-1469](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1430) | `getCategoriesKeys` | $buttonValues, $connection, $mainValues | bind_param, close, execute, fetch_assoc, get_result, json_encode, prepare |
| [config.php:1470-1574](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1470) | `getGateWaysKeys` | $buttonValues, $connection, $mainValues | close, execute, fetch_assoc, get_result, is_null, json_decode, json_encode, prepare |
| [config.php:1575-1750](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1575) | `getBotSettingKeys` | $buttonValues, $connection, $mainValues | close, execute, fetch_assoc, get_result, is_null, json_decode, json_encode, prepare |
| [config.php:1753-1766](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1753) | `fetchData` | $connection | bind_param, close, execute, get_result, prepare |
| [config.php:1767-1791](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1767) | `sendChunkedMessages` | $from_id | array_chunk, number_format, sendMessage |
| [config.php:1793-2447](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1793) | `getBotReportKeys` | $connection, $from_id | array_chunk, array_map, bind_param, calculateAgentSales, calculateServerMonthlySales, calculateServerSales, date, execute, fetchData, fetch_all, fetch_assoc, getAgentUsers, getPlanTitle, getServers, getTopPlans, getTopPlansMonth, get_result, implode, in_array, json_encode, number_format, prepare, query, sendChunkedMessages, sendMessage, strtotime, time |
| [config.php:2102-2116](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2102) | `getAgentUsers` | $connection | fetch_assoc, query |
| [config.php:2118-2153](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2118) | `calculateAgentSales` | $connection | array_map, bind_param, date, execute, fetch_assoc, get_result, implode, prepare, time |
| [config.php:2171-2196](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2171) | `getServers` | $connection | execute, fetch_assoc, get_result, prepare |
| [config.php:2199-2223](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2199) | `calculateServerSales` | $connection | bind_param, execute, fetch_assoc, get_result, prepare |
| [config.php:2226-2252](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2226) | `calculateServerMonthlySales` | $connection | bind_param, execute, fetch_assoc, get_result, prepare, strtotime |
| [config.php:2270-2294](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2270) | `getTopPlans` | $connection | execute, fetch_assoc, get_result, prepare |
| [config.php:2297-2324](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2297) | `getTopPlansMonth` | $connection | bind_param, execute, fetch_assoc, get_result, prepare, strtotime |
| [config.php:2327-2346](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2327) | `getPlanTitle` | $connection | bind_param, execute, fetch_assoc, get_result, prepare |
| [config.php:2450-2454](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2450) | `convertPersianDigitsToEnglishForStats` |  | str_replace |
| [config.php:2458-2475](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2458) | `normalizeStatsDate` |  | checkdate, convertPersianDigitsToEnglishForStats, preg_match, sprintf, str_replace, trim |
| [config.php:2479-2516](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2479) | `parseStatsDateRange` |  | convertPersianDigitsToEnglishForStats, count, normalizeStatsDate, preg_match_all, preg_replace, str_replace, trim |
| [config.php:2520-2565](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2520) | `calculateSalesBetweenDates` | $cancelKey, $connection, $removeKeyboard | bind_param, close, date, execute, fetch_assoc, get_result, number_format, prepare, sendMessage, strtotime |
| [config.php:2574-2592](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2574) | `getAdminsKeys` | $buttonValues, $connection, $mainValues | close, execute, fetch_assoc, get_result, json_encode, prepare |
| [config.php:2596-2793](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2596) | `getUserInfoKeys` | $buttonValues, $connection, $mainValues | array_chunk, bind_param, bot, close, date, execute, fetch_assoc, getOrderCountInRange, getPaymentSumInRange, get_result, implode, number_format, prepare, time, trim |
| [config.php:2796-2803](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2796) | `getPaymentSumInRange` |  | bind_param, close, execute, fetch_assoc, get_result, number_format, prepare |
| [config.php:2805-2812](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2805) | `getOrderCountInRange` |  | bind_param, close, execute, fetch_assoc, get_result, prepare |
| [config.php:2815-2871](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2815) | `handleToggleCallbacks` | $connection | bind_param, bot, close, execute, fetch_assoc, getUserInfoKeys, get_result, intval, json_encode, preg_match, prepare |
| [config.php:2875-2922](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2875) | `getCallbackFromCommand` | $admin, $botState, $buttonValues, $chat_id, $connection, $from_id, $userInfo | json_encode, sendMessage |
| [config.php:2976-3315](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2976) | `applyDiscountToPay` |  | array_count_values, bind_param, close, execute, fetch_assoc, floor, get_result, is_array, is_null, json_decode, json_encode, number_format, prepare, sendMessage, str_replace, time |
| [config.php:3318-3476](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3318) | `getDiscountCodeKeys` | $buttonValues, $connection | bind_param, ceil, close, execute, fetch_assoc, get_result, json_encode, number_format, prepare, time |
| [config.php:3478-3502](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3478) | `getMainMenuButtonsKeys` | $buttonValues, $connection, $mainValues | close, execute, fetch_assoc, get_result, json_encode, prepare, str_replace |
| [config.php:3503-3575](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3503) | `getPlanDetailsKeys` | $buttonValues, $connection, $mainValues | bind_param, close, execute, fetch_assoc, get_result, json_encode, number_format, prepare |
| [config.php:3578-3594](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3578) | `findOrder` | $connection | bind_param, close, execute, fetch_assoc, get_result, prepare |
| [config.php:3596-3614](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3596) | `getPlanFullName` | $connection | bind_param, close, execute, fetch_assoc, get_result, prepare |
| [config.php:3616-3626](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3616) | `getServerConfigById` | $connection | bind_param, close, execute, fetch_assoc, get_result, prepare |
| [config.php:3628-3755](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3628) | `getOrderTrafficInfo` |  | array_column, array_search, getJson, getMarzbanUser, json_decode, round |
| [config.php:3757-3781](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3757) | `buildConfigLinks` |  | count, implode, in_array, is_array |
| [config.php:3783-3791](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3783) | `buildSubLink` |  | rtrim |
| [config.php:3793-3804](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3793) | `buildSubBackupSection` |  | array_filter, array_map, explode, implode, rtrim |
| [config.php:3806-3825](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3806) | `buildConfigMessage` | $hideConnectLinkForMarzban, $mainValues | str_replace |
| [config.php:3828-3854](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3828) | `getProtocolNamesRow` |  |  |
| [config.php:3856-3868](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3856) | `buildAdminProtocolButtons` |  | getProtocolNamesRow |
| [config.php:3870-3888](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3870) | `buildUserProtocolButtons` |  | getProtocolNamesRow |
| [config.php:3890-4013](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L3890) | `getUserOrderDetailKeys` | $botState, $botUrl, $buttonValues, $showIndexes | bot, buildConfigLinks, buildConfigMessage, buildSubBackupSection, buildSubLink, findOrder, getOrderTrafficInfo, getPlanFullName, getServerConfigById, jdate, json_decode, json_encode |
| [config.php:4015-4205](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4015) | `getOrderDetailKeys` | $botState, $botUrl, $buttonValues, $mainValues, $showIndexes, $userInfo | buildConfigLinks, buildConfigMessage, buildSubBackupSection, buildSubLink, buildUserProtocolButtons, count, findOrder, getOrderTrafficInfo, getPlanFullName, getServerConfigById, jdate, json_decode, json_encode |
| [config.php:4305-4317](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4305) | `RandomString` |  | rand, strlen |
| [config.php:4318-4335](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4318) | `generateUID` |  | bin2hex, hexdec, openssl_random_pseudo_bytes, sprintf, substr |
| [config.php:4336-4346](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4336) | `checkStep` | $connection | close, execute, fetch_assoc, get_result, prepare |
| [config.php:4347-4382](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4347) | `setUser` | $connection, $first_name, $from_id, $username | bind_param, close, execute, get_result, prepare, time |
| [config.php:4383-4385](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4383) | `generateRandomString` |  | generateUID, md5, substr, time |
| [config.php:4386-4398](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4386) | `addBorderImage` |  | ImageCreateFromPNG, ImagePNG, ImageSx, ImageSy, imageCopyResized, imagecolorallocate, imagecreatetruecolor, imagefilledrectangle |
| [config.php:4399-4409](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4399) | `sumerize` |  | round |
| [config.php:4411-4414](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4411) | `sumerize2` |  | round |
| [config.php:4415-4554](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4415) | `deleteClient` | $connection | array_column, array_keys, array_merge, array_search, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, getJson, get_result, http_build_query, json_decode, json_encode, parse_str, preg_match_all, prepare, rawurlencode, substr |
| [config.php:4555-4654](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4555) | `editInboundRemark` | $connection | array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, getJson, get_result, http_build_query, json_decode, parse_str, preg_match_all, prepare, substr |
| [config.php:4655-4778](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4655) | `editInboundTraffic` | $connection | array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, floor, getJson, get_result, http_build_query, is_null, json_decode, microtime, parse_str, preg_match_all, prepare, resetClientTraffic, resetIpLog, str_ireplace, substr |
| [config.php:4779-4887](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4779) | `changeInboundState` | $connection | RandomString, array_keys, array_merge, array_values, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, getJson, get_result, http_build_query, json_decode, json_encode, parse_str, preg_match_all, prepare, str_ireplace, substr |
| [config.php:4888-5003](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4888) | `renewInboundUuid` | $connection | RandomString, array_keys, array_merge, array_values, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, generateRandomString, getJson, get_result, http_build_query, json_decode, json_encode, parse_str, preg_match_all, prepare, str_ireplace, substr |
| [config.php:5004-5147](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5004) | `changeClientState` | $connection | RandomString, array_keys, array_merge, array_values, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, getJson, get_result, http_build_query, json_decode, json_encode, parse_str, preg_match_all, prepare, rawurlencode, substr |
| [config.php:5148-5296](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5148) | `renewClientUuid` | $connection | RandomString, array_keys, array_merge, array_values, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, generateRandomString, getJson, get_result, http_build_query, json_decode, json_encode, parse_str, preg_match_all, prepare, rawurlencode, substr |
| [config.php:5297-5446](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5297) | `editClientRemark` | $connection | RandomString, array_column, array_keys, array_merge, array_search, array_values, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, getJson, get_result, http_build_query, json_decode, json_encode, parse_str, preg_match_all, prepare, rawurlencode, substr |
| [config.php:5447-5617](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5447) | `editClientTraffic` | $connection | RandomString, array_column, array_keys, array_merge, array_search, array_values, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, floor, getJson, get_result, http_build_query, json_decode, json_encode, microtime, parse_str, preg_match_all, prepare, rawurlencode, resetClientTraffic, resetIpLog, substr |
| [config.php:5618-5718](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5618) | `deleteInbound` | $connection | array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, getJson, get_result, http_build_query, json_decode, parse_str, preg_match_all, prepare, substr |
| [config.php:5719-5794](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5719) | `resetIpLog` | $connection | array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, get_result, http_build_query, json_decode, parse_str, preg_match_all, prepare, substr, urlencode |
| [config.php:5795-5869](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5795) | `resetClientTraffic` | $connection | array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, get_result, http_build_query, json_decode, parse_str, preg_match_all, prepare, rawurlencode, substr |
| [config.php:5870-6053](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L5870) | `addInboundAccount` | $connection | RandomString, array_keys, array_merge, array_values, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, floor, getJson, get_result, http_build_query, intval, is_array, json_decode, json_encode, parse_str, preg_match_all, prepare, substr |
| [config.php:6054-6108](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L6054) | `getNewHeaders` | $connection | explode |
| [config.php:6109-6496](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L6109) | `getConnectionLink` | $connection | RandomString, base64_encode, bind_param, close, count, execute, explode, fetch_assoc, getJson, get_result, is_null, json_decode, json_encode, parse_url, preg_match, prepare, rawurlencode, str_ireplace, str_replace, strlen, strtok, strtolower |
| [config.php:6497-6787](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L6497) | `updateConfig` | $connection | array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, getJson, getNewHeaders, get_result, http_build_query, intval, json_decode, json_encode, parse_str, parse_url, preg_match_all, prepare, substr |
| [config.php:6788-7268](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L6788) | `editInbound` | $connection | RandomString, array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, getJson, getNewHeaders, get_result, http_build_query, intval, json_decode, json_encode, parse_str, parse_url, preg_match_all, prepare, substr |
| [config.php:7269-7307](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7269) | `getMarzbanToken` | $connection | bind_param, close, curl_close, curl_error, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, get_result, http_build_query, json_decode, prepare |
| [config.php:7308-7335](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7308) | `getMarzbanJson` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, get_result, json_decode, prepare |
| [config.php:7336-7367](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7336) | `getMarzbanUserInfo` | $connection | bind_param, close, curl_close, curl_error, curl_exec, curl_init, curl_setopt, execute, explode, fetch_assoc, getMarzbanUser, get_result, prepare |
| [config.php:7368-7397](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7368) | `getMarzbanUser` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, get_result, json_decode, prepare, urlencode |
| [config.php:7398-7427](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7398) | `getMarzbanHosts` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, get_result, json_decode, prepare |
| [config.php:7428-7489](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7428) | `addMarzbanUser` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, getMarzbanUserInfo, get_result, implode, is_object, json_decode, json_encode, prepare, stdClass, time, urlencode |
| [config.php:7490-7558](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7490) | `editMarzbanConfig` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, getMarzbanUser, get_result, implode, is_object, json_encode, prepare, resetMarzbanTraffic, time, urlencode |
| [config.php:7559-7593](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7559) | `resetMarzbanTraffic` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, get_result, implode, is_object, prepare |
| [config.php:7594-7624](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7594) | `renewMarzbanUUID` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, getMarzbanUserInfo, get_result, implode, is_object, json_decode, prepare |
| [config.php:7626-7663](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7626) | `deleteMarzban` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, get_result, implode, is_object, json_decode, prepare, urlencode |
| [config.php:7664-7715](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7664) | `changeMarzbanState` | $connection | bind_param, close, curl_close, curl_exec, curl_init, curl_setopt, execute, fetch_assoc, getMarzbanToken, getMarzbanUser, get_result, implode, is_object, json_decode, json_encode, prepare, urlencode |
| [config.php:7716-7792](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7716) | `getJson` | $connection | array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, get_result, http_build_query, json_decode, parse_str, preg_match_all, prepare, substr |
| [config.php:7793-7866](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7793) | `getNewCert` | $connection | array_keys, array_merge, bind_param, close, curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, get_result, http_build_query, json_decode, parse_str, preg_match_all, prepare, substr |
| [config.php:7867-8506](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L7867) | `addUser` | $connection | RandomString, array_keys, array_merge, bind_param, close, curl_close, curl_error, curl_exec, curl_getinfo, curl_init, curl_setopt, curl_setopt_array, execute, fetch_assoc, floor, getNewCert, getNewHeaders, get_result, http_build_query, json_decode, json_encode, parse_str, parse_url, preg_match_all, prepare, sendMessage, str_ireplace, substr |
| [config.php:8510-8552](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L8510) | `sendToAllAdmins` | $admin, $admins_list, $connection | bind_param, bot, close, execute, json_encode, prepare |
| [config.php:8564-8571](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L8564) | `canSeeButton` | $buttons_only_for_special_admins, $special_admins | in_array |
| [health.php:42-58](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/health.php#L42) | `tg_get` | $botToken | curl_close, curl_exec, curl_init, curl_setopt_array, json_decode |
| [pay/back.php:165-841](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/back.php#L165) | `doAction` | $admin, $botState, $botUrl, $buttonValues, $connection, $from_id, $hideConfigIfMany, $linkIndices, $userInfo | Bot, addToWallet, bind_param, buildLinksAndSendQr, buildRemark, close, count, createIncreaseOrder, createServerAccount, decreaseServerCapacity, editClientTraffic, editInboundTraffic, editMarzbanConfig, ensureAccountCreationSucceeded, execute, fetch_assoc, floor, generateRandomString, getMessage, getNextPortAndIncrease, getPlanDetails, getServerInfoAndConfig, get_result, giveReferralReward, insertOrder, is_array, is_null, is_object, json_decode, json_encode, microtime, mysqli_num_rows, notifyAllAdminsSimple, number_format, payTypeToDescription, preg_match, prepare, refundToWalletAndNotify, renewConfigOnServer, sendMessage, showForm, time |
| [pay/back.php:843-876](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/back.php#L843) | `showForm` |  |  |
| [pay/index.php:241-281](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/pay/index.php#L241) | `showForm` |  |  |
| [settings/messagewizwiz.php:34-34](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L34) | `nowTs` |  | time |
| [settings/messagewizwiz.php:36-38](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L36) | `safeClose` |  | close |
| [settings/messagewizwiz.php:40-48](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L40) | `hbUpdate` |  | bind_param, execute, prepare, safeClose |
| [settings/messagewizwiz.php:50-58](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L50) | `markPaused` |  | bind_param, execute, prepare, safeClose |
| [settings/messagewizwiz.php:60-72](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L60) | `isCancelled` |  | bind_param, execute, fetch_assoc, get_result, prepare, safeClose |
| [settings/messagewizwiz.php:74-82](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L74) | `markCancelled` |  | bind_param, execute, prepare, safeClose |
| [settings/messagewizwiz.php:84-92](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php#L84) | `markFinished` |  | bind_param, execute, prepare, safeClose |
| [settings/tronChecker.php:705-725](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/tronChecker.php#L705) | `getWebsite` |  | curl_close, curl_error, curl_exec, curl_init, curl_setopt, rand |
| [settings/warnusers.php:379-568](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/warnusers.php#L379) | `handleAutoRenewCallback` | $admin, $botState, $buttonValues, $connection, $from_id, $mainValues, $time, $userInfo | alert, bind_param, close, editClientTraffic, editInboundTraffic, editMarzbanConfig, execute, fetch_assoc, get_result, intval, is_null, json_encode, number_format, preg_match, prepare, sendMessage, uniqid |

## Machine-readable detail

`inventory.json` contains every extracted SQL site, schema field, emitted callback expression,
routing condition, `setUser` call, settings access, include, side-effect site, and legacy-name occurrence.
Inspect source-linked expressions for dynamic behavior; do not treat regex-derived table lists as a SQL parser.
