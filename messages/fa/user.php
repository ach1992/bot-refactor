<?php
/**
 * AChBot متن‌های قابل مشاهده برای کاربر و برچسب‌های مشترک.
 * فقط مقدار متن را ویرایش کنید؛ کلیدها، نام پارامترها و جای‌نگهدارها ثابت‌اند.
 * اعداد نمایشی انگلیسی باشند. متن دکمه و پیام ساده نباید تگ HTML بگیرد.
 * بخش‌های این فایل موضوع‌بندی شده‌اند؛ فایل دیگری برای ویرایش متن‌ها لازم نیست.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

return [

    // ── پیام‌های اصلی و متن‌های قابل سفارشی‌سازی قدیمی ──
    'legacy_messages' => [
        'banned' => '⛔️ شما مسدود شدید.' . "\n" . '🛑 برای آزادسازی به پشتیبانی پیام دهید.' . "\n" . '' . "\n" . '💬 پشتیبانی: SUPPORTID',
        'customer_custome_plan_day' => '📅 لطفا  تعداد روز اشتراک را وارد کنید.' . "\n" . '💰 هزینه هر روز: DAY-PRICE',
        'customer_custome_plan_volume' => '📦 لطفا مقدار گیگابایت سرویس رو وارد کنید.' . "\n" . '💰 هزینه هر گیگ: VOLUME-PRICE',
        'customer_custome_plan_name' => '🔢 اسم اشتراک رو وارد کنید (حروف انگیلیسی و عدد باهم وارد کنید)',
        'amount_decreased_from_your_wallet' => '✅ مبلغ AMOUNT تومان از حساب شما کم شد.',
        'brw_cart_to_cart_detail' => '💳 <b>کارت‌به‌کارت بانکی</b>' . "\n" . 'لطفا مبلغ را به شماره کارت زیر واریز کنید.' . "\n" . '⚠️ <b>مبلغ دقیق و غیر رند زیر را کپی و واریز کنید در غیر این صورت رسید تایید نمی شود.</b>' . "\n" . '' . "\n" . '💰 مبلغ به تومان ← <code>PRICE-TOMAN</code>' . "\n" . '💵 مبلغ به ریال ← <code>PRICE-RIAL</code>' . "\n" . '' . "\n" . 'شماره کارت:' . "\n" . '<code>ACCOUNT-NUMBER</code>' . "\n" . '👤 صاحب حساب: HOLDER-NAME' . "\n" . '' . "\n" . '⏰ <b>بعد پرداخت فقط تا 30 دقیقه فرصت دارید رسید را بفرستید.</b>' . "\n" . '🛑 <b>به هیچ وجه رسیدی که قبلا ارسال شده را دوباره ارسال نکنید اکانت شما مسدود می شود.</b>' . "\n" . '<b>📤 ارسال رسید</b> را بزنید و بعد رسید را بفرستید.',
        'brw_cart_to_cart_gift' => '🎁 <b>پرداخت با گیفت‌کارت</b>' . "\n" . '' . "\n" . '💰 مبلغ سفارش: <code>PRICE-TOMAN-ROUND</code> تومان' . "\n" . 'ابتدا از طریق دکمه «خرید گیفت‌کارت»، گیفت‌کارت مطابق مبلغ سفارش تهیه کنید.' . "\n" . 'سپس پس از خرید، تصویر واضح گیفت‌کارت را با «ارسال تصویر گیفت‌کارت» در ربات ثبت کنید.' . "\n" . '' . "\n" . '⏳ ارسال تصویر به معنی تأیید یا تکمیل پرداخت نیست. نتیجه پس از بررسی مدیر اعلام می‌شود.' . "\n" . '⚠️ تصویر یا کد گیفت‌کارت را در جای دیگری منتشر نکنید.',
        'brw_cart_to_cart_usdt' => '💎 <b>تتر دستی (USDT)</b>' . "\n" . '' . "\n" . '💰 مبلغ سفارش: <code>PRICE-TOMAN-ROUND</code> تومان' . "\n" . 'مبلغ تتر: <code>PRICE-USDT USDT</code>' . "\n" . '🌐 شبکه: <b>USDT-NETWORK</b>' . "\n" . '' . "\n" . '📋 آدرس کیف پول مقصد:' . "\n" . '<code>USDT-WALLET</code>' . "\n" . '' . "\n" . '⚠️ فقط توکن USDT را روی شبکهٔ اعلام‌شده ارسال کنید. شبکه یا آدرس اشتباه ممکن است باعث از دست رفتن وجه شود.' . "\n" . '✅ پس از انتقال، از تأییدیهٔ انتقال همراه با هش تراکنش عکس بگیرید و دکمهٔ «ارسال رسید تتر» را بزنید.' . "\n" . '' . "\n" . '⏳ ارسال عکس به معنی تأیید تراکنش یا تحویل سرویس نیست؛ بررسی دستی انجام می‌شود.',
        'brw_cart_to_cart_trx' => '🔴 <b>پرداخت دستی TRX روی شبکهٔ TRON</b>' . "\n" . '' . "\n" . '💰 مبلغ سفارش: <code>PRICE-TOMAN-ROUND</code> تومان' . "\n" . 'مبلغ قابل انتقال: <code>PRICE-TRX TRX</code>' . "\n" . '🌐 شبکه: <b>TRON</b>' . "\n" . '' . "\n" . '📋 آدرس کیف پول مقصد:' . "\n" . '<code>TRX-WALLET</code>' . "\n" . '' . "\n" . '⚠️ فقط ارز اصلی TRX را روی شبکهٔ TRON منتقل کنید؛ تتر TRC20 یا ارز دیگری به این آدرس واریز نکنید.' . "\n" . '✅ پس از انتقال، تصویر رسید شامل مبلغ، شبکه و هش تراکنش را در ربات ارسال کنید.' . "\n" . '' . "\n" . '⏳ بررسی و تأیید کاملاً دستی است؛ ارسال رسید به معنی پرداخت نهایی یا تحویل سرویس نیست.',
        'please_send_trx_image' => '🔴 تصویر واضح رسید انتقال TRX روی شبکهٔ TRON، همراه مبلغ و هش تراکنش را ارسال کنید؛ بررسی دستی انجام می‌شود.',
        'please_send_usdt_image' => '💎 تصویر واضح رسید انتقال USDT شامل شبکه، مبلغ و هش تراکنش را ارسال کنید. این رسید به‌صورت دستی بررسی می‌شود.',
        'please_send_receipt_image' => '📤 تصویر واضح رسید کارت‌به‌کارت بانکی را ارسال کنید. رسید برای بررسی ثبت خواهد شد.',
        'please_send_gift_card_image' => '🎁 تصویر واضح گیفت‌کارت خریداری‌شده را ارسال کنید. بررسی و تأیید آن توسط مدیر انجام می‌شود.',
        'operation_canceled' => '❌ عملیات مورد نظر لغو شد.',
        'invite_second_text' => '🔰 پیام یا لینک بالا رو برای دوستتون بفرستید، هر کسی با استفاده از اون ربات را <b>استارت</b> یا <b>شروع</b> کند، به عنوان <b>زیرمجموعه شما</b> ثبت می‌شود.' . "\n" . '' . "\n" . '💎 همین که دوست شما ربات رو استارت کنه شما <b>{INVITE_REWARD}</b> تومان هدیه دعوت دریافت می کنید.' . "\n" . '⚠️ ربات به صورت دوره ای افراد دعوت شده را بررسی می کند در صورتی که فیک تشخیص داده شوند مبلغ هدیه از کیف پول شما کسر می شود یا اکانت خریدای شده غیرفعال می شود.' . "\n" . '' . "\n" . '💵 همچنین <b>به ازای هر خرید</b> دوست شما مبلغ <b>{INVITE_AMOUNT}</b> کیف پول شما شارژ می شود.' . "\n" . '' . "\n" . '💡 هرچی <b>تعداد زیرمجموعه های شما بیشتر</b> باشه، <b>سود شما هم بیشتره!</b> ' . "\n" . '' . "\n" . '♾️ <b>هیچ محدودیتی</b> در <b>تعداد خرید</b> یا <b>زیرمجموعه گیری</b> وجود ندارد و با هر دعوت و خرید دوستتان این <b>مبالغ برای شما شارژ می شود</b>.',
        'order_submitted' => '✅ ممنون از اعتمادتون، درخواست شما با موفقیت ثبت شد.' . "\n" . '🔰 لطفا کمی صبر کنید تا تایید شود. 🌹' . "\n" . '🚫 ارسال درخواست تکراری باعث مسدودی اکانت می شود.' . "\n" . '<blockquote>' . "\n" . '⏳ معمولا در کمتر از 3 دقیقه تایید می شود، در ساعات غیر کاری ممکن است تا 30 دقیقه و بیشتر طول بکشد.' . "\n" . '❌ لطفا قبل از 45 دقیقه برای تایید رسید به پشتیبانی پیام ندهید رسید شما رد می شود.' . "\n" . '</blockquote>' . "\n" . '📨 پشتیبانی:' . "\n" . '💬 پشتیبانی: SUPPORTID',
        'please_send_only_image' => '⚠️ لطفاً فقط تصویر (عکس) را داخل ربات بفرستید متن یا فایل مورد تایید نیست.',
        'out_of_connection_capacity' => '⚠️ ظرفیت این کانکشن پر شده است.',
        'out_of_server_capacity' => '⚠️ ظرفیت این سرور پر شده است.',
        'can_create_specific_account' => '📋 روی این پلن فقط AMOUNT اکانت میشه ساخت',
        'invite_buy_new_account' => ' 💳 خرید کارت به کارت توسط زیرمجموعه' . "\n" . '' . "\n" . '🎫 شناسه عددی: USER-ID' . "\n" . '👤 اسم کاربر: NAME' . "\n" . '🔖 نام کاربری: @USERNAME' . "\n" . '💰 مبلغ پرداختی: PRICE تومان' . "\n" . '✏️ نام اشتراک یا پلن: REMARK' . "\n" . 'شناسه پرداخت: HASHID',
        'waiting_message' => '⏳ در حال انتظار ...',
        'join_channel_message' => '⭕️ لطفا اول در کانال زیر عضو شوید و بعد روی دکمه عضو شدم بزنید.' . "\n" . '⚠️ برای عضویت روی دکمه <b>عضویت در کانال</b> بزنید.' . "\n" . '' . "\n" . '⁉️ آیدی پشتیبانی:' . "\n" . '💬 پشتیبانی: SUPPORTID' . "\n" . '',
        'bot_is_updating' => '⏳ ربات در حال بروزرسانی می باشد ...',
        'select_one_to_show_detail' => '🔅 یکی از اشتراک های زیر را انتخاب کنید.' . "\n" . '💡 پیشنهاد می کنیم همیشه کیف پول خودتون رو قبل از پایان اشتراک یا تمام شدن حجم شارژ کنید تا هر زمان که خواستید بدون نیاز به تایید ادمین بتوانید اشتراک خود را تمدید کنید.',
        'you_dont_have_config' => '📋 هیچ سفارشی ندارید 🙁 باید یه اشتراک خریداری کنید.',
        'no_order_found' => '⚠️ اشتراک مورد نظر پیدا نشد، لطفا نام کاربری صحیح را ارسال کنید.',
        'config_details_message' => '📋 وضعیت اشتراک: STATE' . "\n" . '    ' . "\n" . '🔮 نام کاربری اشتراک: ' . "\n" . '<code>NAME</code>' . "\n" . '' . "\n" . '🔌 لینک تکی کانفیگ:' . "\n" . 'CONNECT-LINK' . "\n" . '' . "\n" . '🌐 لینک اصلی اشتراک:' . "\n" . 'SUB-LINK' . "\n" . '',
        'please_wait_message' => '⏳ لطفا کمی صبر کنید.',
        'send_only_number' => '🔢 لطفا فقط عدد ارسال کنید. دقت کنید اعداد انگلیسی باشند.',
        'saved_successfuly' => '✅ با موفقیت ذخیره شد.',
        'invited_user_joined_message' => '🎉 تبریک یه نفر با لینک شما وارد ربات شد.' . "\n" . '💵 در صورتی که خریدی انجام دهد مبلغ پورسانت به صورت خودکار به کیف پول شما شارژ می شود.',
        'please_select_from_below_buttons' => '🔘 لطفا فقط از کلید زیر استفاده کنید.',
        'use_iranian_number_only' => '📱 لطفا فقط با شماره ایرانی اقدام کنید.',
        'phone_confirmed' => '✅ شماره شما با موفقیت تایید شد.',
        'send_your_phone_number' => '📱 سلام عزیزم، برای استفاده از ربات شماره تماس خود را با استفاده از کلید زیر ارسال کنید 👇',
        'not_joine_yet' => '📢 هنوز عضو کانال نشدید.',
        'insert_discount_code' => '🎁 کد تخفیف را وارد کنید:',
        'not_valid_discount_code' => '⚠️ کد تخفیف نامعتبر است.',
        'discount_not_found' => '⚠️ کد تخفیف وارد شده اشتباه است.',
        'discount_not_for_user' => '⛔️ این کد برای حساب شما فعال نیست.',
        'discount_not_for_service' => '⚠️ این کد برای این سرویس قابل استفاده نیست.',
        'discount_min_price' => '⚠️ این کد فقط برای سفارش‌های بالاتر از MINPRICE قابل استفاده است.',
        'discount_user_limit' => '⚠️ از این کد به حداکثر تعداد مجاز استفاده کرده‌اید.',
        'valid_discount_code' => '✅ کد تخفیف با موفقیت استفاده شد.' . "\n" . '🎁 مقدار تخفیف AMOUNT',
        'send_config_uuid' => '🔰 از این بخش می توانید اشتراکی که خارج از ربات خریداری کرده اید را به ربات اضافه کنید، تمدید کنید یا مشخصات آن را ببینید.' . "\n" . '❗️ لینک اشتراک را بفرستید، نام کاربری قبول نیست. بعد از ارسال کمی صبر کنید تا اطلاعات برایتان ارسال شود.' . "\n" . '' . "\n" . '⚠️ لطفا قبل از ادامه مراحل اول بزنید روی آی دی زیر تا آموزش برایتان ارسال شود.' . "\n" . '' . "\n" . '💬 پشتیبانی: SUPPORTID',
        'not_correct_text' => '⚠️ متن وارد شده معتبر نمی باشد.',
        'receving_information' => '♻️ در حال دریافت جزییات ... ',
        'agency_request_sent' => '✅ درخواست شما با موفقیت ارسال شد، لطفا به پشتیبانی پیام دهید!',
        'agency_request_already_sent' => '⏳ درخواست شما قبلاً ارسال شده، لطفا منتظر باشید!',
        'agency_request_declined' => '❌ درخواست شما برای همکاری رد گردید.' . "\n" . '' . "\n" . '⭕️ لطفا برای دریافت شرایط همکاری با پشتیبانی ارتباط بگیرید.' . "\n" . '' . "\n" . '💬 پشتیبانی: SUPPORTID',
        'agency_request_approved' => '✅ درخواست شما برای همکاری تایید شد. تخفیف همکاری به طور خودکار برای شما هنگام خرید اعمال می شود.' . "\n" . '💳 توصیه می کنیم همیشه قبل از خرید یا تمدید اکانت کیف پول خود را در ساعات کاری شارژ کنید تا برای تایید رسیدها منتظر نمایند.' . "\n" . '⚠️  ربات را دوباره /start کنید.',
        'agent_setting_message' => '🧚🏻 همکار محترم:' . "\n" . '' . "\n" . '💡از این بخش میتوانید تعداد خریدهای همکاری خود را ببینید و همچنین تاریخ عضویت خودتان را مشاهده کنید.' . "\n" . '🎁 تخفیف شما در هر خرید به صورت خودکار محاسبه می شود.' . "\n" . '💳 توصیه می کنیم همیشه قبل از خرید یا تمدید اکانت کیف پول خود را در ساعات کاری شارژ کنید تا برای تایید رسیدها منتظر نمایند.',
        'selling_is_off' => '⛔️ فعلا فروش نداریم',
        'no_server_available' => '⚠️ در حال حاضر هیچ سرور فعالی نداریم لطفا بعدا مجدد تست کنید.',
        'buy_sub_select_location' => '🧭 مرحلهٔ اول' . "\n" . '' . "\n" . '➕ لطفا یک مورد را انتخاب کنید.' . "\n" . '💡 شما می توانید کیف پول خودتون رو قبل از پایان اکانت یا اتمام حجم شارژ کنید تا هر زمان که خواستید بدون نیاز به تایید ادمین بتوانید اکانت جدید خریداری کنید یا تمدید کنید.',
        'category_not_avilable' => '⚠️ هیچ دسته بندی برای این سرور وجود ندارد.',
        'receive_categories' => '♻️ دریافت دسته بندی ...',
        'buy_sub_select_category' => '📂 مرحلهٔ دوم' . "\n" . '' . "\n" . '📅 لطفا یک مورد را انتخاب کنید:',
        'no_plan_available' => '💡 پلنی در این سرویس وجود ندارد.',
        'receive_plans' => '📍 در حال دریافت لیست پلن ها',
        'buy_sub_select_plan' => '📋 مرحلهٔ سوم' . "\n" . '' . "\n" . '🔰 یکی از پلن هارو انتخاب کنید:',
        'buy_custom_plan' => '➕ پلن دلخواه خودتو بخر',
        'enter_account_amount' => '♻️ تعداد اکانت درخواستی رو وارد کن حداکثر در هر بار 5 عدد می توانید دریافت کنید.' . "\n" . '' . "\n" . '⚠️ نکته: در صورت وارد کردن عددی بالاتر از 5 و ایجاد اختلال مسئولیت هزینه کسر شده از حساب شما به عهده خودتان می باشد.',
        'send_positive_number' => '🔢 لطفا عددی بزرگتر از 0 وارد کنید',
        'buy_subscription_detail' => '〽️ نام پلن: PLAN-NAME' . "\n" . '        ➖' . "\n" . '        💰 قیمت پلن: PRICE' . "\n" . '        ➖' . "\n" . '' . "\n" . '' . "\n" . 'توضیحات: DESCRIPTION' . "\n" . '		' . "\n" . '🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید.' . "\n" . '',
        'buy_much_subscription_detail' => '〽️ نام پلن: PLAN-NAME' . "\n" . '        🔢 تعداد اکانت: ACCOUNT-COUNT' . "\n" . '        💰 قیمت پلن: PRICE' . "\n" . '        ➖' . "\n" . '        🧮 قیمت کل: TOTAL-PRICE' . "\n" . '        ➖' . "\n" . '' . "\n" . '' . "\n" . '' . "\n" . 'توضیحات: DESCRIPTION' . "\n" . '		' . "\n" . '🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید.' . "\n" . '',
        'buy_custom_subscription_detail' => '〽️ نام پلن: PLAN-NAME' . "\n" . '        📦 حجم اختصاصی: VOLUME GB' . "\n" . '        📅 مدت اختصاصی: DAYS روز' . "\n" . '        ➖' . "\n" . '        💰 قیمت پلن: PRICE' . "\n" . '        ➖' . "\n" . '' . "\n" . '' . "\n" . '' . "\n" . 'توضیحات: DESCRIPTION' . "\n" . '		' . "\n" . '🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید.' . "\n" . '',
        'send_config_remark' => '🔄 لطفا نام کاربری اشتراک را وارد کنید.',
        'enter_ticket_title' => '<b>⁉️ لطفا موضوع تیکت را ارسال کنید!</b>' . "\n" . '	' . "\n" . '🛑 از ارسال درخواست های نامفهوم و بیجا خودداری کنید، بدون اخطار اکانت شما مسدود می شود.' . "\n" . '🚫 فقط به درخواست هایی که به زبان فارسی تایپ می شوند پاسخ داده می شود.',
        'enter_ticket_description' => '💠 لطفا متن و یا عکس تیکت را بصورت ساده و مختصر ارسال کنید!',
        'config_not_found' => '⚠️ مشکلی پیش آمده است، لطفا دوباره انجام دهید.',
        'incorrect_config_name' => '⚠️ اسم وارد شده معتبر نمی باشد.',
        'config_doesnt_exist' => '⚠️ اشتراک یافت نشد.',
    ],

    // ── دکمه‌ها و برچسب‌های اصلی ──
    'legacy_buttons' => [
        'back_to_main' => '🔙 بازگشت',
        'cart_to_cart' => '💳 کارت‌به‌کارت بانکی',
        'gift_card' => '🎁 خرید گیفت‌کارت',
        'manual_usdt' => '💎 رمزارز دستی (USDT / TRX)',
        'now_payment_gateway' => '💎 پرداخت با رمز ارز',
        'zarinpal_gateway' => '🌐 پرداخت آنلاین (زرین پال)',
        'nextpay_gateway' => '🌐 پرداخت آنلاین (نکست پی)',
        'approve' => '✅ تایید',
        'approved' => '✅ تایید شد',
        'decline' => '❌ عدم تایید',
        'declined' => '❌ رد شد',
        'back_button' => '🔙 برگشت',
        'renew_connection_link' => '🔗 تغییر لینک اشتراک',
        'update_config_connection' => '🔄 بروزرسانی اشتراک',
        'increase_config_volume' => '📦 افزایش حجم اشتراک',
        'increase_config_days' => '📅 افزایش زمان اشتراک',
        'renew_config' => '♻️ تمدید اشتراک',
        'change_config_location' => '🌐 تغییر سرویس',
        'selected_protocol' => '🚦 پروتکل انتخابی',
        'volume_left' => '📦 حجم باقیمانده:',
        'expire_date' => '📅 تاریخ انقضاء:',
        'buy_date' => '📅 تاریخ خرید:',
        'plan_name' => '🚀 نام پلن:',
        'on' => '✅ روشن',
        'off' => '❌ خاموش',
        'active' => '🟢 فعال',
        'deactive' => '🔴 غیرفعال',
        'send_phone_number' => '📱 ارسال شماره',
        'cancel' => '❌ لغو عملیات',
        'join_channel' => '📢 عضویت در کانال',
        'have_joined' => '✅ عضو شدم',
        'test_account' => '🎁 اشتراک تست رایگان',
        'invite_friends' => '🎁 کسب درآمد با معرفی',
        'my_info' => '👤 حساب کاربری',
        'my_subscriptions' => '📋 تمدید یا مشاهده اشتراک ها',
        'buy_subscriptions' => '🛍 خرید اشتراک جدید',
        'shared_existence' => '❕موجودی اشتراکی',
        'individual_existence' => '❗️موجودی اختصاصی',
        'application_links' => '📚 آموزش و قوانین',
        'my_tickets' => '💬 تیکت های من',
        'search_config' => '🔎 افزودن یا مشاهده مشخصات اشتراک',
        'delete_config' => '🗑 حذف اشتراک',
        'change_plan' => '🔄 تغییر پلن',
        'pay_with_wallet' => '💰 پرداخت با موجودی',
        'request_agency' => '🤝 درخواست همکاری',
        'agency_setting' => '🤝 پنل همکاری',
        'agent_one_buy' => '🛍 خرید تکی',
        'agent_much_buy' => '🛍 خرید انبوه',
        'agent_bought_accounts' => 'تعداد خرید',
        'agent_joined_date' => 'تاریخ عضویت',
        'agent_agency_date' => 'تاریخ همکاری',
        'search_agent_config' => '🔎 جستجوی اشتراک',
        'sharj' => '💰 شارژ کیف پول',
        'qr_config' => '🔳 کیوآرکد کانفیگ',
        'qr_sub' => '🔳 کیوآرکد لینک اشتراک',
        'start_bot' => '🛍 خرید اشتراک',
        'enable_config' => '✅ فعال سازی اشتراک',
        'disable_config' => '⛔️ غیرفعال سازی اشتراک',
    ],

    // ── شروع، حساب و تنظیمات ربات ──
    'bot' => [
        // bot.webhook_ingress_authenticated
        'bot.webhook_ingress_authenticated.message.5e45680470' => ['text' => '⛔️ اکانت شما به دلیل اسپم مسدود شده است.' . "\n" . 'زمان آزادسازی اکانت شما: ' . "\n" . '{time}', 'parameters' => ['time']],
        'bot.webhook_ingress_authenticated.alert.d4b0660226' => 'این دکمهٔ پرداخت معتبر نیست؛ لطفاً از فاکتور جدید استفاده کنید.',
        'bot.webhook_ingress_authenticated.inviter_msg.0a64da8ef5' => '🎉 تبریک! کاربر جدیدی با لینک دعوت شما عضو شد.' . "\n" . '' . "\n" . '',
        'bot.webhook_ingress_authenticated.text.750b1aaad1' => '💰 مبلغ هدیه شما: ',
        'bot.webhook_ingress_authenticated.text.81a62ff6c6' => ' تومان' . "\n" . '',
        'bot.webhook_ingress_authenticated.text.87d0794e8e' => '💰 موجودی قبل: ',
        'bot.webhook_ingress_authenticated.text.447278518a' => '💰 موجودی جدید: ',
        'bot.webhook_ingress_authenticated.text.2cc98f7f7c' => ' تومان',
        // bot.invite_friends
        'bot.invite_friends.caption.16ca7a4696' => ['text' => '🎁 هدیه هر دعوت: <b>{inviteReward} تومان</b>' . "\n" . '', 'parameters' => ['inviteReward']],
        'bot.invite_friends.text.c14f10907e' => ['text' => '💵 پورسانت هر خرید: <b>{inviteAmount}</b>', 'parameters' => ['inviteAmount']],
        'bot.invite_friends.alert.56fd80c6d5' => '⛔️ این قسمت غیر فعال است.',
        // bot.my_info
        'bot.my_info.button.3d0ddff816' => '💰 شارژ کیف پول',
        'bot.my_info.button.2aa445c2af' => '🔀 جابجایی موجودی',
        'bot.my_info.message.4fb6af58ae' => ['text' => '' . "\n" . '📊 اطلاعات حساب شما:' . "\n" . '' . "\n" . '🎫 شناسه عددی: <code>{from_id}</code>' . "\n" . '🔖 نام کاربری: @{username}' . "\n" . '👤 اسم: {first_name}' . "\n" . '💰 موجودی: <b>{myWallet}</b>' . "\n" . '' . "\n" . '🌐 تعداد کل اشتراک ها: <b>{totalBuys}</b> عدد' . "\n" . '' . "\n" . '💡 پیشنهاد می کنیم همیشه کیف پول خودتون رو قبل از پایان اکانت یا تمام شدن حجم شارژ کنید تا هر زمان که خواستید بدون نیاز به تایید ادمین بتوانید اکانت خود را تمدید کنید.' . "\n" . '', 'parameters' => ['from_id', 'username', 'first_name', 'myWallet', 'totalBuys']],
        // bot.process_callback_query
        'bot.process_callback_query.message.616c172303' => '⛔️ سیستم فروش غیرفعال است.',
        'bot.process_callback_query.message.dda719f1cb' => '🟢 موجودی پلن اشتراکی:',
        'bot.process_callback_query.message.8386c28679' => '📋 نام کاربری اشتراک موردنظر را وارد کنید:',
        'bot.process_callback_query.message.a4712f82bb' => 'ℹ️ این درخواست قبلاً ثبت شده است. برای شروع جدید دوباره دکمه را انتخاب کنید.',
        // bot.recive_applications
        'bot.recive_applications.message.ec86dead1a' => ['text' => '📱 <b>نرم‌افزارها و آموزش اتصال</b>' . "\n" . '' . "\n" . 'نرم‌افزار مناسب دستگاه خود را از دکمه‌های زیر انتخاب کنید. اگر اتصال در یک نرم‌افزار برقرار نشد، آموزش را بررسی کنید و یک نرم‌افزار دیگر را امتحان کنید.' . "\n" . '' . "\n" . '📥 برای مشاهده آموزش و دریافت نرم‌افزار، یکی از گزینه‌های زیر را بزنید.' . "\n" . '' . "\n" . '💬 پشتیبانی: {supportID}' . "\n" . '', 'parameters' => ['supportID']],
        // bootstrap.unavailable
        'bootstrap.unavailable' => 'این بخش فعلاً در دسترس نیست؛ کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
    ],

    // ── کیف پول و انتقال موجودی ──
    'wallet' => [
        // admin_wallet.admin_wallet_report_result
        'admin_wallet.admin_wallet_report_result.customer.a5b15e454e' => '✅ مبلغ ',
        'admin_wallet.admin_wallet_report_result.text.bfb7221456' => ' تومان ',
        'admin_wallet.admin_wallet_report_result.text.cac43b05c7' => 'به کیف پول شما افزوده شد.',
        'admin_wallet.admin_wallet_report_result.text.587ace91f5' => 'از کیف پول شما کسر شد.',
        'admin_wallet.admin_wallet_report_result.text.25f7017bbb' => '' . "\n" . 'موجودی کیف پول شما پس از این تغییر: ',
        'admin_wallet.admin_wallet_report_result.text.2cc98f7f7c' => ' تومان',
        // wallet_recharge.wallet_topup_amount_problem
        'wallet_recharge.wallet_topup_amount_problem.label.7fab54f38e' => '⚠️ مبلغ شارژ معتبر نیست. لطفاً فقط عدد صحیح با ارقام انگلیسی وارد کنید.',
        'wallet_recharge.wallet_topup_amount_problem.label.a5b8f4d84e' => '⚠️ لطفا مقداری بیشتر یا مساوی 100,000 تومان وارد کنید.',
        'wallet_recharge.wallet_topup_amount_problem.label.2ee8d817fc' => '⚠️ حداکثر مبلغ قابل شارژ کیف پول 10,000,000 تومان می‌باشد.',
        'wallet_recharge.wallet_topup_amount_problem.label.9185f287a6' => '⚠️ مبلغ واردشده باید مضربی از 50,000 تومان باشد.',
        'wallet_recharge.wallet_topup_amount_problem.text_msg.a8d7f77dea' => '✅ با شارژ کیف پول می‌توانید هنگام خرید از <b>دکمه پرداخت با موجودی</b> استفاده کنید و منتظر تأیید ادمین <b>نمانید</b>.' . "\n" . '' . "\n" . '❇️ لطفاً مبلغ شارژ کیف پول را انتخاب کنید:',
        'wallet_recharge.wallet_topup_amount_problem.button.e0736904e5' => '💰 مبلغ 100,000 تومان',
        'wallet_recharge.wallet_topup_amount_problem.button.15bec8537a' => '💰 مبلغ 200,000 تومان',
        'wallet_recharge.wallet_topup_amount_problem.button.c18668d617' => '💰 مبلغ 300,000 تومان',
        'wallet_recharge.wallet_topup_amount_problem.button.d960e787a3' => '💰 مبلغ 500,000 تومان',
        'wallet_recharge.wallet_topup_amount_problem.button.79fa13dc2b' => '💰 مبلغ 1,000,000 تومان',
        'wallet_recharge.wallet_topup_amount_problem.button.1f4dbe4fcb' => '💰 مبلغ 2,000,000 تومان',
        'wallet_recharge.wallet_topup_amount_problem.button.f4f1019c8d' => '💰 مبلغ 5,000,000 تومان',
        'wallet_recharge.wallet_topup_amount_problem.button.5e7e50f6fe' => '✏️ مبلغ دلخواه',
        'wallet_recharge.wallet_topup_amount_problem.message.778b5776b6' => '⚠️ این گزینه مربوط به درخواست فعلی نیست. شارژ کیف پول را دوباره از منو آغاز کنید.',
        'wallet_recharge.wallet_topup_amount_problem.message.983d706c66' => '⚠️ وضعیت درخواست شارژ نیازمند بررسی است؛ قبل از تلاش مجدد، وضعیت را بررسی کنید.',
        'wallet_recharge.wallet_topup_amount_problem.message.d0531e17b9' => '⚠️ این درخواست قبلاً پردازش یا جایگزین شده است. شارژ کیف پول را دوباره از منو آغاز کنید.',
        'wallet_recharge.wallet_topup_amount_problem.message.ae69d93a89' => '💰 لطفاً مبلغ شارژ را وارد کنید.' . "\n" . '✅ حداقل مبلغ قابل شارژ <b>100,000 تومان</b> می باشد.' . "\n" . 'حداکثر مبلغ قابل شارژ <b>10,000,000 تومان</b> می باشد.' . "\n" . 'مبلغ باید فقط مضربی از <b>50,000 تومان</b> باشد.' . "\n" . '🔢 لطفاً فقط اعداد انگلیسی وارد کنید.',
        'wallet_recharge.wallet_topup_amount_problem.alert.1a885aaca4' => '⚠️ پرداخت این سفارش با این روش مجاز نیست یا قبلاً به روش دیگری آغاز شده است.',
        'wallet_recharge.wallet_topup_amount_problem.alert.07ee06b4bc' => '⚠️ اطلاعات این پرداخت قابل بازیابی نیست؛ با پشتیبانی تماس بگیرید.',
        'wallet_recharge.wallet_topup_amount_problem.alert.7a9c30e938' => '⚠️ نمایش اطلاعات پرداخت تأیید نشد. از همان دکمه دوباره تلاش کنید و وجهی ارسال نکنید.',
        'wallet_recharge.wallet_topup_amount_problem.alert.d06bc731eb' => '⚠️ این رسید با روش پرداخت انتخاب‌شده مطابقت ندارد یا سفارش در انتظار پرداخت نیست.',
        'wallet_recharge.wallet_topup_amount_problem.alert.560f05f822' => '⌛️ مهلت شروع ارسال رسید پایان یافته یا فاکتور در حال بررسی است. اگر وجهی فرستاده‌اید با پشتیبانی تماس بگیرید.',
        'wallet_recharge.wallet_topup_amount_problem.alert.882596d0f1' => '⚠️ این سفارش قابل ثبت رسید نیست؛ برای بررسی با پشتیبانی تماس بگیرید.',
        'wallet_recharge.wallet_topup_amount_problem.alert.795c4bddb8' => '⚠️ ثبت یا ارسال رسید در جریان است. همان تصویر را دوباره ارسال کنید؛ در صورت تداوم مشکل با پشتیبانی تماس بگیرید.',
        'wallet_recharge.wallet_topup_amount_problem.message.c346a0d62e' => '📥 تصویر برای پیگیری ثبت شده اما تأیید ارسال به مدیر دریافت نشده است. لطفاً همان تصویر را دوباره بفرستید یا با پشتیبانی تماس بگیرید. پرداخت مجدد انجام ندهید.',
        // wallet_transfer.wallet_transfer_restart
        'wallet_transfer.wallet_transfer_restart.message.1286cd29ed' => '⚠️ آماده‌سازی درخواست انتقال با مشکل روبه‌رو شد؛ لطفاً انتقال را دوباره شروع کنید.',
        'wallet_transfer.wallet_transfer_restart.message.03d3c27bc2' => '⛔️ این پیام برای مرحلهٔ فعلی انتقال معتبر نیست؛ لطفاً از منوی کیف پول انتقال تازه‌ای شروع کنید.',
        'wallet_transfer.wallet_transfer_restart.button.79f89c9b92' => '🔀 شروع دوباره انتقال',
        'wallet_transfer.wallet_transfer_restart.message.60862c92c3' => '🔀 در صورتی که قصد انتقال مبلغ دلخواه از موجودی خودتان به کاربری دیگری را دارید از اینجا می توانید انجام دهید.' . "\n" . '🔢 لطفا شناسه عددی کاربری که می خواهید موجودی به حساب آن منتقل کنید را وارد کنید.',
        'wallet_transfer.wallet_transfer_restart.alert.e05ab0cee3' => '⛔️ موجودی حساب شما کم است. موجودی حساب شما باید بیشتر از 0 باشد.',
        'wallet_transfer.wallet_transfer_restart.message.9d368ab3bb' => '⚠️ لطفا شناسه عددی کاربری غیر از خودتان را ارسال کنید.',
        'wallet_transfer.wallet_transfer_restart.message.8bd13292ba' => '💰 لطفا مبلغ مورد نظر را به تومان و با اعداد انگلیسی وارد کنید.',
        'wallet_transfer.wallet_transfer_restart.message.71963e9012' => '⛔️ کاربری با این شناسه عددی یافت نشد.',
        'wallet_transfer.wallet_transfer_restart.message.dcff0b471b' => '⛔️ مبلغ انتقال نامعتبر یا خارج از محدوده مجاز است.',
        'wallet_transfer.wallet_transfer_restart.message.a04cf98a41' => '🔢 لطفا عددی بزرگتر از صفر وارد کنید.',
        'wallet_transfer.wallet_transfer_restart.message.ecde76fc7f' => '⛔️ این درخواست انتقال قبلاً پردازش شده یا معتبر نیست.',
        'wallet_transfer.wallet_transfer_restart.message.bf9e4dcf22' => '⚠️ موجودی حساب شما کم است.',
        'wallet_transfer.wallet_transfer_restart.message.8fb7125cfb' => '⛔️ انتقال این مبلغ در حال حاضر امکان‌پذیر نیست؛ مبلغی کسر نشد.',
        'wallet_transfer.wallet_transfer_restart.message.6924ce253f' => '⚠️ وضعیت درخواست انتقال شما نیاز به بررسی دارد. ',
        'wallet_transfer.wallet_transfer_restart.message.f933fbcd9d' => 'لطفاً موجودی کیف پول را بررسی کنید و در صورت نیاز ',
        'wallet_transfer.wallet_transfer_restart.message.e9940862a9' => ['text' => 'کد پیگیری {reference} را به پشتیبانی بدهید.', 'parameters' => ['reference']],
        'wallet_transfer.wallet_transfer_restart.text.bda050585a' => '—',
        'wallet_transfer.wallet_transfer_restart.text.ecbce64eed' => ['text' => ' تومان به کیف پول شما توسط کاربر {from_id} انتقال یافت.', 'parameters' => ['from_id']],
        'wallet_transfer.wallet_transfer_restart.text.f7206f6d33' => ['text' => '📥 <b>مقصد</b>: <code>{to_id}</code>' . "\n" . '', 'parameters' => ['to_id']],
        'wallet_transfer.wallet_transfer_restart.text.431f74945a' => '💰 مبلغ انتقال: <b>',
        'wallet_transfer.wallet_transfer_restart.text.b971f2d37a' => '</b> تومان',
        'wallet_transfer.wallet_transfer_restart.text.8e5e1ee663' => ' تومان به کیف پول کاربر مورد نظر شما انتقال یافت.',
        'wallet_transfer.wallet_transfer_restart.text.b059640ccc' => 'لطفا یکی از کلید های زیر را انتخاب کنید.',
        // wallet_settlement.process_wallet_payment
        'wallet_settlement.process_wallet_payment.alert.244ca46c8f' => '⛔️ پرداخت این اشتراک در حال حاضر امکان‌پذیر نیست؛ مبلغی از کیف پول کسر نشد.',
        'wallet_settlement.process_wallet_payment.alert.669afe458a' => '⛔️ این سفارش متعلق به شما نیست.',
        'wallet_settlement.process_wallet_payment.alert.978c658074' => '⏳ وضعیت درخواست هنوز در حال بررسی است. لطفاً وضعیت سفارش را کنترل کنید و پیش از ثبت دوباره با پشتیبانی هماهنگ شوید.',
        'wallet_settlement.process_wallet_payment.alert.86fa6b6506' => '⛔️ روش پرداخت این سفارش قبلاً تعیین شده یا در حال بررسی است.',
        'wallet_settlement.process_wallet_payment.alert.98b1616048' => '⛔️ پرداخت این سفارش از کیف پول در حال حاضر امکان‌پذیر نیست. وضعیت سفارش را بررسی کنید.',
        'wallet_settlement.process_wallet_payment.alert.2d688eba81' => '⚠️ مبلغ این سفارش معتبر نیست؛ موجودی کیف پول تغییر نکرد.',
        'wallet_settlement.process_wallet_payment.alert.b691af4dc7' => '⛔️ این سفارش قبلاً با روش دیگری آغاز شده است؛ پرداخت دوباره انجام ندهید.',
        'wallet_settlement.process_wallet_payment.alert.5d2e89e5ed' => '⛔️ روش خرید با مشخصات این سفارش مطابقت ندارد؛ مبلغی از کیف پول کسر نشد.',
        'wallet_settlement.process_wallet_payment.alert.ae55877021' => '❌ موجودی کیف پول شما کافی نیست.' . "\n" . '',
        'wallet_settlement.process_wallet_payment.alert.4092b19ef1' => '💰 موجودی فعلی: ',
        'wallet_settlement.process_wallet_payment.alert.f82b5605a9' => '🔺 مورد نیاز: ',
        'wallet_settlement.process_wallet_payment.alert.95b8db0b7f' => '⛔️ نام یا مشخصات این سفارش معتبر نیست؛ مبلغی از کیف پول کسر نشد.',
        'wallet_settlement.process_wallet_payment.alert.b05ed79353' => '⛔️ مشخصات این سفارش معتبر نیست؛ مبلغی از کیف پول کسر نشد.',
        'wallet_settlement.process_wallet_payment.alert.0b74ab3f2e' => '⛔️ ظرفیت این سفارش کافی نیست؛ مبلغی از کیف پول کسر نشد.',
        'wallet_settlement.process_wallet_payment.alert.9538721ade' => '⚠️ وضعیت درخواست پرداخت نیاز به بررسی دارد. لطفاً وضعیت سفارش و پرداخت را بررسی کنید و پیش از تکرار با پشتیبانی هماهنگ شوید.',
        // wallet_start.start_wallet_payment
        'wallet_start.start_wallet_payment.button.e729457ba2' => '💎 پرداخت رمزارزی دستی',
        'wallet_start.start_wallet_payment.message.1f46b2b87f' => 'اطلاعات شارژ:' . "\n" . 'مبلغ ',
        'wallet_start.start_wallet_payment.message.64ab796d01' => ' تومان' . "\n" . '' . "\n" . 'لطفا روش پرداخت را انتخاب کنید:',
    ],

    // ── ساخت و خرید اشتراک ──
    'purchase' => [
        // catalogue.text
        'catalogue.text.dda7ff2974' => 'رایگان',
        'catalogue.text.bfb7221456' => ' تومان ',
        'catalogue.text.1bc7ba6a31' => '/پلن\\s(\\d+)\\sگیگ\\s/',
        // catalogue.alert
        'catalogue.alert.67fda139df' => '⛔️ طرح انتخابی یافت نشد.',
        // catalogue.message
        'catalogue.message.ef774be0a5' => '😡 لطفا فقط عدد ارسال کن',
        'catalogue.message.05308dd049' => '🔢 لطفا عددی بزرگتر از 0 وارد کن',
        'catalogue.message.bf8ff96ef3' => ' عدد اعشاری مجاز نیست',
        'catalogue.message.b989e71b4f' => '❌ عدد وارد شده نمیتواند با 0 شروع شود!',
        'catalogue.message.3fbe481726' => '⛔️ عدد اعشاری مجاز نیست',
        // catalogue.alert
        'catalogue.alert.10fd7f4fa9' => '⛔️ طرح انتخابی برای این درخواست قابل استفاده نیست.',
        'catalogue.alert.201380d8bf' => '⚠️ صدور پیش‌نویس به‌طور قطعی تأیید نشد؛ برای پیگیری از پشتیبانی کمک بگیرید.',
        // catalogue.button
        'catalogue.button.e729457ba2' => '💎 پرداخت رمزارزی دستی',
        'catalogue.button.05c3a1e3f4' => ' 🎟 افزودن کد تخفیف ',
        // core.get_plan_details
        'core.get_plan_details.alert.93f191e066' => '❌ پلن مورد نظر یافت نشد.',
        // core.get_server_info_and_config
        'core.get_server_info_and_config.alert.c0552cb2f5' => '❌ اطلاعات سرور یا تنظیمات آن یافت نشد.',
        // entry_and_bulk.create_multiple_accounts
        'entry_and_bulk.create_multiple_accounts.alert.de33518bf8' => '⚠️ هیچ دسته بندی برای این سرور وجود ندارد.',
        'entry_and_bulk.create_multiple_accounts.alert.174c6a61d7' => '♻️ دریافت دسته بندی ...',
        'entry_and_bulk.create_multiple_accounts.message.2f3dfcbe8e' => '📂 مرحلهٔ دوم' . "\n" . '' . "\n" . 'دسته بندی مورد نظرت رو انتخاب کن 🤭',
        'entry_and_bulk.create_multiple_accounts.alert.5223e5a747' => '💡 پلنی در این دسته بندی وجود ندارد. ',
        'entry_and_bulk.create_multiple_accounts.alert.465c2a0af9' => '📍 در حال دریافت لیست پلن ها',
        'entry_and_bulk.create_multiple_accounts.message.7f4d98ab9b' => '📋 مرحلهٔ سوم' . "\n" . '' . "\n" . 'یکی از پلن هارو انتخاب کن و برو برای پرداختش 🤲',
        'entry_and_bulk.create_multiple_accounts.alert.d3fb64c4d9' => '⛔️ طرح انتخابی در حال حاضر قابل خرید نیست.',
        'entry_and_bulk.create_multiple_accounts.message.c754e347bf' => '❗️ لطفا مدت زمان اکانت را به ( روز ) وارد کن:',
        'entry_and_bulk.create_multiple_accounts.message.f018216ab3' => '❕ حجم اکانت ها رو به گیگابایت ( GB ) وارد کن:',
        'entry_and_bulk.create_multiple_accounts.message.9cc4de866d' => '🔢 عدد باید بیشتر از 0 باشه',
        'entry_and_bulk.create_multiple_accounts.message.141dd787b4' => '😡 مگه نمیگم فقط عدد بفرس نمیفهمی؟ یا خودتو زدی به نفهمی؟',
        'entry_and_bulk.create_multiple_accounts.message.f30d6ad265' => 'مقداری بزرگتر از 0 وارد کن',
        'entry_and_bulk.create_multiple_accounts.alert.dd52ddf02f' => '⛔️ درخواست قدیمی یا نامعتبر است؛ از منوی ساخت گروهی دوباره اقدام کنید.',
        'entry_and_bulk.create_multiple_accounts.message.76499f65f3' => '⚠️ ساخت گروهی نیازمند تطبیق اکانت‌های ثبت‌شده است. ',
        'entry_and_bulk.create_multiple_accounts.message.fbde023d57' => 'از اجرای دوبارهٔ خودکار خودداری کنید.',
        'entry_and_bulk.create_multiple_accounts.alert.c7ce33a4eb' => '⚠️ ساخت گروهی هنوز قطعی نیست؛ وضعیت سرویس‌ها را بررسی کنید.',
        'entry_and_bulk.create_multiple_accounts.message.62d6e89b94' => '☑️ اکانت‌ها ثبت شدند؛ در صورت نقص ارسال، وضعیت را بررسی کنید.',
        // order_writes.ensure_account_creation_succeeded
        'order_writes.ensure_account_creation_succeeded.user_message.7eb9410072' => '❌ اتصال به سرور برقرار نیست. لطفا پشتیبانی را در جریان بگذارید.',
        'order_writes.ensure_account_creation_succeeded.user_message.7a64d65208' => ['text' => '❌ ساخت اشتراک انجام نشد، لطفا پشتیبانی را در جریان بگذارید.', 'parameters' => []],
        'order_writes.ensure_account_creation_succeeded.user_message.b3fe04305c' => '❌ خطایی در ساخت/بروزرسانی اکانت رخ داد، لطفا پشتیبانی را در جریان بگذارید.',
        'order_writes.ensure_account_creation_succeeded.text.f07a81a062' => 'نامشخص',
        // select_plan.alert
        'select_plan.alert.789af84a82' => '⛔️ پلن موردنظر معتبر نیست.',
        // select_plan.button
        'select_plan.button.8719396e1e' => '📥 دریافت رایگان',
        // select_plan.text
        'select_plan.text.2cc98f7f7c' => ' تومان',
        // trial_catalogue.get_test_account
        'trial_catalogue.get_test_account.alert.0143c681c4' => '⚠️ شما اکانت تست را قبلا استفاده کرده اید.',
        'trial_catalogue.get_test_account.message.b059640ccc' => 'لطفا یکی از کلید های زیر را انتخاب کنید.',
        'trial_catalogue.get_test_account.alert.d1ae7ac011' => '⛔️ این بخش موقتا غیر فعال است.',
        // trial_provision.alert
        'trial_provision.alert.efb178e861' => '⛔️ فعال‌سازی سرویس آزمایشی در حال حاضر امکان‌پذیر نیست.',
        'trial_provision.alert.72ed718294' => '⚠️ شما قبلا هدیه رایگان خود را دریافت کردید.',
        'trial_provision.alert.fc60efd929' => '⛔️ درخواست معتبر نیست؛ از منوی اشتراک دوباره اقدام کنید.',
        'trial_provision.alert.cf3893b9c0' => '⚠️ نتیجهٔ درخواست هنوز قطعی نیست؛ برای بررسی با پشتیبانی تماس بگیرید.',
        // trial_provision.text
        'trial_provision.text.8c2d826d72' => 'ندارد',
    ],

    // ── اطلاعات و مدیریت اشتراک ──
    'subscription' => [
        'manual_accounts.import_completed' => ['text'=>"✅ اشتراک <code>{remark}</code> به فهرست اشتراک‌های شما اضافه شد.\nبرای مشاهده و مدیریت آن، «اشتراک‌های من» را باز کنید. برای تمدید، ابتدا از صفحه اشتراک «تغییر پلن» را بزنید و پلن مناسب را انتخاب کنید؛ سپس تمدید را انجام دهید.",'parameters'=>["remark"]],

        'manual_accounts.import_under_review' => "⏳ نتیجه افزودن اشتراک در حال بررسی است. ابتدا فهرست اشتراک‌ها را بررسی کنید و تا مشخص شدن نتیجه، دوباره ثبت نکنید.",

        'manual_accounts.import_unverified' => "⚠️ امکان تأیید این اشتراک وجود ندارد. لینک اصلی اشتراک را از فروشنده بگیرید یا با پشتیبانی تماس بگیرید.",

        'account_details.auto_renew_update_unverified' => "⚠️ تغییر وضعیت تمدید خودکار تأیید نشد. صفحهٔ اشتراک را دوباره باز کنید.",
        'account_details.status_on_hold' => '⏸ در انتظار فعال‌سازی',

        // buy_helpers.build_links_and_send_qr
        'buy_helpers.build_links_and_send_qr.acc_text.698f512161' => ['text' => '' . "\r" . '' . "\n" . '🛍 <b>اطلاعات اشتراک جدید شما</b>' . "\r" . '' . "\n" . '' . "\r" . '' . "\n" . '🎈 نام کاربری اشتراک:' . "\r" . '' . "\n" . '<code>{safeRemark}</code>' . "\n" . '📦 حجم اشتراک: {safeVolume} گیگ' . "\n" . '📅 مدت اشتراک: {safeDays} روز' . "\n" . '' . "\n" . '', 'parameters' => ['safeRemark', 'safeVolume', 'safeDays']],
        'buy_helpers.build_links_and_send_qr.acc_text.2f530a8e37' => '🔌 لینک تکی کانفیگ:' . "\n" . '',
        'buy_helpers.build_links_and_send_qr.acc_text.8eb803f6b0' => '🌐 لینک اصلی اشتراک:' . "\n" . '',
        'buy_helpers.build_links_and_send_qr.acc_text.a2bb8da6b7' => '' . "\n" . '' . "\n" . '💡 همیشه اکانت را با لینک اشتراک وارد کنید تا به همه کانفیگ ها دسترسی داشته باشد.',
        'buy_helpers.build_links_and_send_qr.acc_text.d5f471a600' => '' . "\n" . '⚠️ در صورتی که لینک اصلی اشتراک کار نکرد از لینک کمکی اشتراک استفاده کنید.',
        'buy_helpers.build_links_and_send_qr.start.e4bb9f1ece' => 'شروع',
        'account_deletion.shared_effect_notice' => "🚫 با تأیید، خود اشتراک برای همیشه حذف می‌شود و دسترسی همه افرادی که از آن استفاده می‌کنند قطع خواهد شد.",
        // account_deletion.alert
        'account_deletion.alert.a370755120' => '⛔️ این اشتراک متعلق به حساب شما نیست.',
        // account_deletion.message
        'account_deletion.message.acb79596be' => ['text' => '⚠️ آیا از حذف اشتراک <code>{remark}</code> مطمئن هستید؟' . "\n" . '🛑 دقت کنید با انتخاب گزینه <b>بله</b> اشتراک شما برای همیشه حذف می‌شود، این عمل غیرقابل برگشت بوده و در صورت فعال بودن اشتراک هزینه آن به شما برگشت داده نمی‌شود.', 'parameters' => ['remark']],
        // account_deletion.button
        'account_deletion.button.13159f8c74' => '✅ بله',
        'account_deletion.button.5f5c4c72bd' => '❌ خیر',
        // account_deletion.no_dont_delete
        'account_deletion.no_dont_delete.message.4ca0ffd711' => 'عملیات مورد نظر لغو شد.',
        'account_deletion.no_dont_delete.alert.739703d8ae' => '⛔️ حذف این اشتراک در حال حاضر امکان‌پذیر نیست.',
        'account_deletion.no_dont_delete.alert.fc1b571950' => '⚠️ این اشتراک در حال بررسی است؛ حذف نیازمند پیگیری پشتیبانی است.',
        'account_deletion.no_dont_delete.text.5e6ee5eaa1' => 'نامحدود',
        'account_deletion.no_dont_delete.alert.9347ccc8b0' => '⚠️ نتیجه حذف اشتراک مشخص نیست؛ لطفاً با پشتیبانی تماس بگیرید.',
        'account_deletion.no_dont_delete.alert.e2cbeb044b' => '⚠️ حذف اشتراک نیازمند بررسی است؛ لطفاً با پشتیبانی تماس بگیرید.',
        'account_deletion.no_dont_delete.message.ab50b51b1f' => ['text' => 'اشتراک {remark} با موفقیت حذف شد.', 'parameters' => ['remark']],
        'account_deletion.no_dont_delete.message.2ddfbc22e4' => ['text' => 'آیا از حذف اشتراک {remark} مطمئن هستید؟', 'parameters' => ['remark']],
        'account_deletion.no_dont_delete.alert.bd8e953385' => '⛔️ سفارش یافت نشد.',
        'account_deletion.no_dont_delete.alert.1186ae9204' => '⚠️ این اشتراک در حال بررسی است؛ حذف نیازمند پیگیری است.',
        'account_deletion.no_dont_delete.alert.3c93240b6e' => '⚠️ نتیجه حذف اشتراک مشخص نیست؛ لطفاً بررسی دستی انجام دهید.',
        // account_details.get_order_traffic_info
        'account_details.get_order_traffic_info.text.f07ac0638d' => '⚠️',
        // account_details.get_order_status_text
        'account_details.get_order_status_text.label.ff02acf7a2' => '⚠️ وضعیت اشتراک قابل تأیید نیست؛ نتیجه در حال بررسی است.',
        'account_details.get_order_status_text.text.63ef396546' => '⏰ مدت اشتراک تمام شده است.',
        'account_details.get_order_status_text.text.e9216d0d0c' => '🔋 حجم اشتراک تمام شده است.',
        // account_details.get_order_detail_keys
        'account_details.get_order_detail_keys.button.5c38085697' => '📍 تغییر سرور',
        // account_transfers.alert
        'account_transfers.alert.6b5b63fb0f' => '⛔️ تغییر سرور غیرفعال است.',
        'account_transfers.alert.2c082acb46' => '⛔️ سفارش یا سرور فعلی معتبر نیست.',
        'account_transfers.alert.33278b6a77' => '⚠️ در حال حاضر گزینه مقصد سازگار دیگری برای این اشتراک وجود ندارد.',
        // account_transfers.text
        'account_transfers.text.b233883d0d' => 'بازگشت',
        // account_transfers.message
        'account_transfers.message.3f6bd6c3c8' => '📍 سرور مقصد را انتخاب کنید. تنظیمات مقصد پس از بررسی اشتراک اعمال می‌شود.',
        // account_transfers.alert
        'account_transfers.alert.4da40d7d6f' => '⛔️ انتقال این اشتراک به مقصد انتخاب‌شده امکان‌پذیر نیست.',
        // account_transfers.message
        'account_transfers.message.9d93daa0a7' => ['text' => '✅ سرویس {remark} در ربات به سرور {title} اختصاص یافت. در صورت نیاز، لینک‌های اشتراک را به‌روزرسانی کنید.', 'parameters' => ['remark', 'title']],
        // config_refresh.alert
        'config_refresh.alert.55dda26482' => '⛔️ این دکمه معتبر نیست؛ اشتراک را دوباره باز کنید.',
        'config_refresh.alert.8cc7d4da88' => '⛔️ اطلاعات اشتراک را دوباره باز کنید.',
        'config_refresh.alert.05e5654640' => '⛔️ به‌روزرسانی اطلاعات این اشتراک در حال حاضر امکان‌پذیر نیست.',
        // config_refresh.message
        'config_refresh.message.1d7cb7b50c' => '🔎 کانفیگی برای نمایش انتخاب نشده است؛ لینک اشتراک را در اطلاعات اشتراک بررسی کنید.',
        // customer_routes.cancel_search_keyboard
        'customer_routes.cancel_search_keyboard.button.cc357a4c9f' => '❌ لغو عملیات',
        'customer_routes.cancel_search_keyboard.button.0b8da1e098' => '◀',
        'customer_routes.cancel_search_keyboard.button.0d56714222' => '➡',
        'customer_routes.cancel_search_keyboard.alert.06fdc45418' => '⚠️ نمی توانید نوع این اشتراک را تغییر دهید.',
        'customer_routes.cancel_search_keyboard.message.3034785b83' => '📝 لطفا پورت مورد نظر خود را وارد کنید.' . "\n" . 'برای حذف پورت دلخواه عدد 0 را وارد کنید.',
        'customer_routes.cancel_search_keyboard.message.06d5c646c1' => '✏️ ویرایش تنظیمات پلن:',
        'customer_routes.cancel_search_keyboard.message.d4b5f70a92' => '📝 لطفا sni مورد نظر خود را وارد کنید.' . "\n" . 'برای حذف متن /empty را وارد کنید.',
        'customer_routes.cancel_search_keyboard.alert.77c239f956' => '⛔️ تغییر نوع شبکه برای این اشتراک در دسترس نیست.',
        'customer_routes.cancel_search_keyboard.alert.477e4570c3' => '⛔️ تغییر پروتکل غیر فعال است.',
        'customer_routes.cancel_search_keyboard.alert.724ff90245' => '⛔️ انجام این درخواست برای اشتراک انتخاب‌شده امکان‌پذیر نیست.',
        'customer_routes.cancel_search_keyboard.vraylink.cde59051f4' => 'خطا در دریافت لینک',
        'customer_routes.cancel_search_keyboard.success_message.6d1ad0af39' => '<b>✅ بروزرسانی موفق بود!</b>' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.02845d07de' => '⭕️ دقت کنید باید لینک اشتراک را دوباره وارد برنامه کنید یا داخل برنامه نیز بروزرسانی بزنید.' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.672ff8ac41' => '🔄 <b>در صورتی که کانفیگ ها با لینک اشتراک اصلی یا کمکی برای شما لود نمی شوند، لطفاً مراحل زیر را انجام دهید:</b>' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.caaf926e2a' => 'ابتدا کانفیگ های قدیمی را از نرم‌افزار خود حذف کنید.' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.0aa0ee1c1f' => 'سپس کانفیگ های جدید را از قسمت بالا کپی کنید.' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.1ed8d0a6cf' => 'در پایان کانفیگ ها را به نرم‌افزار اضافه کنید.' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.47dcd18697' => '📱 <i>برای اتصال موفق، حتماً از آخرین نسخه نرم‌افزار استفاده نمایید.</i>' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.0bd7d3c032' => '⚠️ <b>بعد اتصال به کمک کانفیگ های تکی، برای اینکه همه کانفیگ ها را داشته باشید حتما دوباره با لینک اشتراک وارد کنید.</b>',
        'customer_routes.cancel_search_keyboard.error_message.496a7eede8' => '<b>❌ خطا در بروزرسانی</b>' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.fd5bccf56e' => 'متأسفانه عملیات بروزرسانی با مشکل مواجه شد.' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.0d78481f3e' => 'لطفاً مجدداً تلاش کنید یا با پشتیبانی تماس بگیرید.',
        'customer_routes.cancel_search_keyboard.alert.001c06ff23' => '⚠️ نتیجه تغییر لینک نیاز به بررسی دارد؛ درخواست را تکرار نکنید.',
        'customer_routes.cancel_search_keyboard.success_message.eb1ae135ff' => '<b>✅ لینک اشتراک شما با موفقیت تغییر کرد!</b>' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.36244c21e0' => '♻️ لینک اشتراک و کانفیگ‌های شما مجدد ساخته شد.' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.f82d5bd5ad' => '🔄 <b>برای استفاده از لینک جدید این مراحل را انجام دهید:</b>' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.97f8afa554' => 'ابتدا لینک اشتراک قبلی و کانفیگ‌های قبلی را از نرم‌افزار کامل حذف کنید.' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.f3bed97abc' => 'سپس لینک اشتراک جدید را از پیام بالا که شامل اطلاعات اشتراک هست کپی کنید.' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.4267d2ca77' => 'در پایان لینک را وارد نرم افزار کنید تا کانفیگ ها لود شوند اگر لینک اصلی کار نکرد از لینک کمکی استفاده کنید.' . "\n" . '' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.30d20d2a47' => '📱 <i>پیشنهاد می کنیم از نرم‌افزار Karing استفاده کنید.</i>' . "\n" . '',
        'customer_routes.cancel_search_keyboard.text.3c4d2c9dc6' => '⚠️ <b>اگر می‌خواهید همه کانفیگ‌ها را تک تک داشته باشید، بزنید روی دکمه کیوآر کانفیگ تا تک تک ارسال شود.</b>',
        'customer_routes.cancel_search_keyboard.alert.627f7fad2f' => '⛔️ اشتراک معتبر نیست.',
        'customer_routes.cancel_search_keyboard.message.b63b579845' => '⚠️ نتیجه تغییر وضعیت هنوز قطعی نیست؛ برای بررسی با پشتیبانی تماس بگیرید.',
        'customer_routes.cancel_search_keyboard.message.f0a1fc755c' => '⚠️ سفارش مورد نظر یافت نشد.',
        'customer_routes.cancel_search_keyboard.message.a7cb7bc569' => '⚠️ اطلاعات سرور یافت نشد.',
        'customer_routes.cancel_search_keyboard.alert.d6602f696a' => '⛔️ تغییر نوع اتصال برای این اشتراک در دسترس نیست.',
        // external_accounts.extract_username_from_link
        'external_accounts.extract_username_from_link.message.02e023c2c2' => '❌ لینک نامعتبر است.',
        'external_accounts.extract_username_from_link.message.4c80cbd8b8' => '🔍 در حال بررسی اکانت، لطفاً صبر کنید...',
        'external_accounts.extract_username_from_link.message.bb8dfc256d' => '❌ اشتراک موردنظر پیدا نشد. اگر اعتبار آن تمام شده و بیش از 15 روز گذشته باشد، ممکن است حذف شده باشد.',
        'external_accounts.extract_username_from_link.button.e2fee6e8d7' => '🔘 وضعیت اکانت',
        'external_accounts.extract_username_from_link.button.da22eb1607' => '🔖 نام کاربری',
        'external_accounts.extract_username_from_link.button.ba58ec87db' => '↕️ آپلود + دانلود',
        'external_accounts.extract_username_from_link.button.4ab7460a09' => '📦 حجم کلی',
        'external_accounts.extract_username_from_link.button.ae4f062fc5' => '📦 حجم باقیمانده ',
        'external_accounts.extract_username_from_link.button.b7fed20dcb' => '📅 تاریخ اتمام',
        'external_accounts.extract_username_from_link.button.f5f1dcdabf' => '🔄 تعداد روز باقیمانده',
        'external_accounts.extract_username_from_link.button.8ce785e9a5' => '➕ افزودن به حساب مشتری',
        'external_accounts.extract_username_from_link.button.387c334ec1' => '➕ افزودن به مشاهده اشتراک ها',
        'external_accounts.extract_username_from_link.button.a4ebab2a7b' => '🔙 برگشت به منوی اصلی',
        'external_accounts.extract_username_from_link.message.b2f5ea228c' => '🔰 مشخصات اشتراک ارسالی ' . "\n" . '🔄 در اینجا می توانید اشتراکی که برای خودتان یا دیگران است را تمدید کنید. ' . "\n" . '🛑 لطفا دقت کنید فقط اشتراکی که برای خودتان است را به لیست اشتراک ها اضافه کنید و اشتراک دیگران را اضافه نکنید. ' . "\n" . '➕ <b>نکته مهم:</b> اگر اشتراک برای خودتان هست قبل از تمدید اول آن را به ربات اضافه کنید و بعد از بخش مشاهده اشتراک ها پلن خودتان را انتخاب و تمدید کنید. ' . "\n" . '',
        // presentation.present
        'presentation.present.text.d60293ba07' => '🔎 لینک‌های اتصال این اشتراک در حال حاضر در دسترس نیست؛ از بخش اشتراک‌های من دوباره بررسی کنید.',
        'presentation.present.text.3ff0456031' => '' . "\n" . '🔁 لینک کمکی اشتراک:' . "\n" . '',
        'presentation.config_qr_name_suffix' => '…',
        'presentation.config_qr_delivery_unconfirmed' => '⚠️ ارسال کامل QR یا متن کانفیگ تأیید نشد. پیام‌های دریافت‌شده را بررسی کنید و در صورت نیاز دوباره دکمهٔ کد QR کانفیگ را بزنید.',
        'presentation.present.text.6b058a11c6' => 'ℹ️ تعداد یا طول لینک‌ها از ظرفیت نمایش بیشتر است؛ برای دریافت همه تنظیمات از لینک اشتراک استفاده کنید یا با پشتیبانی تماس بگیرید.',
        // presentation.config_message
        'presentation.config_message.text.2953f9a4ad' => '' . "\n" . '🔌 لینک تکی کانفیگ:' . "\n" . 'CONNECT-LINK' . "\n" . '',
        'presentation.config_message.text.359fef2e2b' => '' . "\n" . '' . "\n" . '♻️ در صورتی که قصد دارید حجم دیگری خریداری کنید می توانید از دکمه ← 🔄 <b>تغییر پلن</b> استفاده و بعد تمدید کنید.',
        // presentation.format_traffic
        'presentation.format_traffic.text.2acc4ef207' => ' گیگابایت',
        'presentation.format_traffic.text.4f65338b34' => ' مگابایت',
        // account_details.remaining_traffic
        'account_details.remaining_traffic' => ['text' => '{remaining_gib} GB', 'parameters' => ['remaining_gib']],
    ],

    // ── تمدید و افزایش حجم یا زمان ──
    'renewal' => [
        'extensions.plan_selection_unavailable' => "⚠️ تغییر پلن این اشتراک در حال حاضر ممکن نیست. از صفحه اشتراک دوباره وارد شوید.",
        'extensions.plan_selection_review' => "⚠️ نتیجه تغییر پلن هنوز قابل تأیید نیست. صفحه اشتراک را بررسی کنید.",
        // automatic_renewal.handle_auto_renew_callback
        'automatic_renewal.handle_auto_renew_callback.message.30ad9ebf7b' => '❌ موجودی کیف پول برای تمدید خودکار کافی نیست؛ تمدید خودکار این اشتراک غیرفعال شد. لطفاً موجودی را افزایش دهید و سپس آن را دوباره فعال کنید.',
        'automatic_renewal.handle_auto_renew_callback.message.cab5f13fd1' => '✅ تمدید خودکار اشتراک انجام شد؛ مبلغ ',
        'automatic_renewal.handle_auto_renew_callback.message.1c7e390d77' => ' تومان از کیف پول شما کسر شد.',
        // extensions.alert
        'extensions.alert.d0779ad27d' => '⛔️ افزایش حجم یا مدت برای این اشتراک در حال حاضر امکان‌پذیر نیست.',
        'extensions.alert.6732b29e55' => '⚠️ در حال حاضر هیچ پلنی برای افزایش مدت زمان اشتراک وجود ندارد.',
        // extensions.price
        'extensions.price.dda7ff2974' => 'رایگان',
        // extensions.text
        'extensions.text.2cc98f7f7c' => ' تومان',
        // extensions.button
        'extensions.button.fbb9f61105' => ['text' => 'مدت: {title} روز؛ قیمت: {price}', 'parameters' => ['title', 'price']],
        // extensions.message
        'extensions.message.c2bde2a3e0' => 'لطفا یکی از پلن های افزایشی را انتخاب کنید:',
        // extensions.alert
        'extensions.alert.584426a8a8' => '📅 پلن زمانی در دسترس نیست؛ دوباره از منوی اشتراک انتخاب کنید.',
        'extensions.alert.ae271a2d1d' => '⚠️ ثبت پیش‌نویس به‌طور قطعی تأیید نشد؛ از ایجاد پرداخت مجدد پرهیز کنید.',
        // extensions.button
        'extensions.button.e729457ba2' => '💎 پرداخت رمزارزی دستی',
        // extensions.message
        'extensions.message.48930749a9' => '🧾 روش پرداخت سفارش را انتخاب کنید.',
        // extensions.alert
        'extensions.alert.1a885aaca4' => '⚠️ پرداخت این سفارش با این روش مجاز نیست یا قبلاً به روش دیگری آغاز شده است.',
        'extensions.alert.07ee06b4bc' => '⚠️ اطلاعات این پرداخت قابل بازیابی نیست؛ با پشتیبانی تماس بگیرید.',
        'extensions.alert.7a9c30e938' => '⚠️ نمایش اطلاعات پرداخت تأیید نشد. از همان دکمه دوباره تلاش کنید و وجهی ارسال نکنید.',
        'extensions.alert.d06bc731eb' => '⚠️ این رسید با روش پرداخت انتخاب‌شده مطابقت ندارد یا سفارش در انتظار پرداخت نیست.',
        'extensions.alert.560f05f822' => '⌛️ مهلت شروع ارسال رسید پایان یافته یا فاکتور در حال بررسی است. اگر وجهی فرستاده‌اید با پشتیبانی تماس بگیرید.',
        'extensions.alert.882596d0f1' => '⚠️ این سفارش قابل ثبت رسید نیست؛ برای بررسی با پشتیبانی تماس بگیرید.',
        'extensions.alert.795c4bddb8' => '⚠️ ثبت یا ارسال رسید در جریان است. همان تصویر را دوباره ارسال کنید؛ در صورت تداوم مشکل با پشتیبانی تماس بگیرید.',
        // extensions.text
        'extensions.text.9699c34220' => 'زمان',
        // extensions.message
        'extensions.message.c346a0d62e' => '📥 تصویر برای پیگیری ثبت شده اما تأیید ارسال به مدیر دریافت نشده است. لطفاً همان تصویر را دوباره بفرستید یا با پشتیبانی تماس بگیرید. پرداخت مجدد انجام ندهید.',
        // extensions.alert
        'extensions.alert.7a08f2df0b' => '⛔️ این افزایش زمان رسید دستی معتبر و در انتظار تأیید ندارد.',
        // extensions.button
        'extensions.button.139c829462' => ['text' => '✅ تایید شد توسط {from_id}', 'parameters' => ['from_id']],
        // extensions.hash_id
        'extensions.hash_id.2899228604' => ['text' => '🎫 شناسه پیگیری: {hash_id}', 'parameters' => ['hash_id']],
        // extensions.message
        'extensions.message.24dea9b122' => ['text' => '✅ مدت <b>{volume}</b> روز به مدت زمان اشتراک <code>{remark}</code> اضافه شد.', 'parameters' => ['volume', 'remark']],
        // extensions.alert
        'extensions.alert.ba92f03500' => '⚠️ نتیجه افزایش زمان نیاز به بررسی دارد. وضعیت اشتراک و پرداخت را کنترل کنید؛ از اجرای مجدد خودکار خودداری کنید.',
        'extensions.alert.9b6f1fa91f' => '⛔️ این سفارش متعلق به شما نیست یا روش پرداخت دیگری برای آن ثبت شده است؛ دوباره پرداخت نکنید.',
        'extensions.alert.31d13e9866' => '⛔️ افزایش اشتراک در حال حاضر امکان‌پذیر نیست. اگر پرداختی انجام داده‌اید، وضعیت آن را با پشتیبانی بررسی کنید.',
        'extensions.alert.39c72d8044' => '❌ موجودی کیف پول کافی نیست.' . "\n" . '💰 موجودی فعلی: ',
        'extensions.alert.71a2507661' => ' تومان' . "\n" . '🔺 مورد نیاز: ',
        // extensions.message
        'extensions.message.cce260f97e' => ['text' => '✅ مدت {volume} روز به مدت زمان اشتراک شما اضافه شد.', 'parameters' => ['volume']],
        // extensions.button
        'extensions.button.aba3054570' => '⏳ افزایش زمان اشتراک',
        // extensions.message
        'extensions.message.ba8adc7121' => '⚠️ وضعیت درخواست مشخص نیست. لطفاً وضعیت اشتراک و پرداخت را بررسی کنید و پیش از ثبت دوباره با پشتیبانی هماهنگ شوید.',
        // extensions.alert
        'extensions.alert.65fc393064' => '⚠️ امکان تغییر پلن برای این نوع اشتراک وجود ندارد.',
        // extensions.message
        'extensions.message.655234a584' => '⚠️ هیچ پلنی برای این سرور وجود ندارد.',
        'extensions.message.143c7f81f1' => '🎲 لطفاً یکی از پلن‌های زیر را انتخاب کنید.' . "\n" . '♻️ بعد از تغییر پلن می توانید اشتراک خودتان را مطابق پلن جدید تمدید کنید.',
        'extensions.message.dee2da49f3' => '❗️پلن فعلی شما همین است. لطفاً پلن متفاوتی انتخاب کنید.',
        'extensions.message.3067ddba76' => '✅ پلن اشتراک شما با موفقیت تغییر یافت.',
        // extensions.alert
        'extensions.alert.0340c363d2' => '📆 تاریخ انقضا برای این سفارش نامعتبر است.',
        'extensions.alert.96762b5a42' => '📆 اشتراک شما منقضی شده است. لطفاً آن را تمدید کنید تا بتوانید افزایش حجم دهید.',
        'extensions.alert.e6c27b8039' => '⚠️ در حال حاضر هیچ پلن حجمی وجود ندارد.',
        // extensions.button
        'extensions.button.249377f2b7' => ['text' => 'حجم: {title} گیگ؛ قیمت: {price}', 'parameters' => ['title', 'price']],
        'extensions.button.8405a4154f' => '⤵️ برگرد به منوی اصلی',
        // extensions.message
        'extensions.message.ab954c63e6' => 'لطفا یکی از پلن های حجمی را انتخاب کنید:',
        // extensions.alert
        'extensions.alert.6173c35986' => '📦 پلن حجمی در دسترس نیست؛ دوباره از منوی اشتراک انتخاب کنید.',
        'extensions.alert.f5aab191db' => '📆 اشتراک شما منقضی شده است. ابتدا آن را تمدید کنید.',
        // extensions.planprice
        'extensions.planprice.97e0b2bd8a' => ' رایگان',
        // extensions.button
        'extensions.button.5adbd64a32' => '💰پرداخت با موجودی',
        // extensions.text
        'extensions.text.4123904480' => 'حجم',
        // extensions.alert
        'extensions.alert.733308c9fe' => '⛔️ این افزایش حجم رسید دستی معتبر و در انتظار تأیید ندارد.',
        // extensions.message
        'extensions.message.d5b6a4e6e7' => ['text' => '✅ حجم <b>{volume}</b> گیگ به حجم اشتراک <code>{remark}</code> اضافه شد.', 'parameters' => ['volume', 'remark']],
        // extensions.alert
        'extensions.alert.36f9c089de' => '⚠️ نتیجه افزایش حجم نیاز به بررسی دارد. وضعیت اشتراک و پرداخت را کنترل کنید؛ از اجرای مجدد خودکار خودداری کنید.',
        // extensions.message
        'extensions.message.2d27ba4eea' => ['text' => '✅ حجم {volume} گیگ به حجم اشتراک شما اضافه شد.', 'parameters' => ['volume']],
        // extensions.button
        'extensions.button.9a3cb8176f' => '⏳ افزایش حجم اشتراک',
        // renewal_checkout.button
        'renewal_checkout.button.faa57a99b5' => '✅ تأیید تمدید',
        'renewal_checkout.button.1fe862ea18' => '❌ انصراف',
        // renewal_checkout.message
        'renewal_checkout.message.7159ade06f' => '⚠️ دقت کنید با تمدید اشتراک، در صورتی که حجم باقی مانده داشته باشید منتقل نمی شود.' . "\n" . '✅ می توانید کیف پول خود را شارژ کنید و گزینه تمدید خودکار را روشن کنید تا هر زمان اشتراک شما تمام شد خودکار تمدید شود.' . "\n" . '🔰 در صورت تمایل به ادامه روی دکمه «تایید تمدید» بزنید.',
        // renewal_checkout.alert
        'renewal_checkout.alert.6aa152a253' => '⛔️ تمدید این اشتراک در حال حاضر امکان‌پذیر نیست.',
        // renewal_checkout.message
        'renewal_checkout.message.4c2448e6fa' => '✋ لطفاً ابتدا با زدن دکمه: ' . "\n" . '        ← 🔄 تغییر پلن' . "\n" . 'یک پلن برای اشتراک خود انتخاب کنید و بعد اقدام به تمدید نمایید.',
        // renewal_checkout.alert
        'renewal_checkout.alert.201380d8bf' => '⚠️ صدور پیش‌نویس به‌طور قطعی تأیید نشد؛ برای پیگیری از پشتیبانی کمک بگیرید.',
        // renewal_checkout.button
        'renewal_checkout.button.5b3252c790' => ['text' => '💳 کارت به کارت مبلغ {price}', 'parameters' => ['price']],
        'renewal_checkout.button.cf580700a3' => ['text' => '🎁 گیفت‌کارت مبلغ {price}', 'parameters' => ['price']],
        'renewal_checkout.button.73f7c8deeb' => ['text' => '💎 پرداخت رمزارزی دستی مبلغ {price}', 'parameters' => ['price']],
        'renewal_checkout.button.0eed1dd8e8' => ['text' => '💰پرداخت با موجودی مبلغ {price}', 'parameters' => ['price']],
        'renewal_checkout.button.05c3a1e3f4' => ' 🎟 افزودن کد تخفیف ',
        // renewal_checkout.message
        'renewal_checkout.message.24ae8c4399' => '🧾 روش پرداخت این تمدید را انتخاب کنید. فقط یک روش فعال را ادامه دهید.',
        'renewal_checkout.message.2c9a5e948c' => '⏳ مهلت این خرید به پایان رسیده است.' . "\n" . '♻️ لطفاً مجدداً روی دکمه تمدید اشتراک کلیک کرده و خرید جدیدی ثبت کنید.',
        'renewal_checkout.message.aaa858e3e6' => '❗️پلن شما از زمان شروع پرداخت تا ارسال رسید تغییر کرده است.' . "\n" . '♻️ لطفاً دوباره روی دکمه تمدید اشتراک کلیک کرده و پرداخت جدید انجام دهید.',
        // renewal_checkout.alert
        'renewal_checkout.alert.259244b81a' => '⛔️ این تمدید رسید دستی معتبر و در انتظار تأیید ندارد.',
        // renewal_checkout.message
        'renewal_checkout.message.47b1149fb0' => ['text' => '✅ اشتراک <code>{remark}</code> با موفقیت تمدید شد.', 'parameters' => ['remark']],
        // renewal_checkout.alert
        'renewal_checkout.alert.3955ffc5de' => '⚠️ نتیجه تمدید نیاز به بررسی دارد. وضعیت اشتراک و پرداخت را کنترل کنید؛ پیش از اقدام مجدد با پشتیبانی هماهنگ شوید.',
        'renewal_checkout.alert.0e312f38cb' => '⚠️ وضعیت این تمدید نیاز به بررسی دارد. لطفاً وضعیت اشتراک و پرداخت را با پشتیبانی بررسی کنید.',
        // renewal_checkout.message
        'renewal_checkout.message.cca1075329' => ['text' => '✅ اشتراک {remark} با موفقیت تمدید شد.', 'parameters' => ['remark']],
        // renewal_checkout.button
        'renewal_checkout.button.7deb81ef97' => '♻️ تمدید اشتراک',
        // renewal_checkout.text
        'renewal_checkout.text.6295c35212' => 'کیف پول',
        // renewal_plan.alert
        'renewal_plan.alert.b1e40557e8' => '⛔️ طرح انتخابی با اشتراک شما سازگار نیست.',
        // renewal_selection.text
        'renewal_selection.text.bfb7221456' => ' تومان ',
        // renewal_selection.message
        'renewal_selection.message.51ab077156' => '📋 مرحلهٔ سوم' . "\n" . '' . "\n" . '🤲 یکی از پلن هارو انتخاب کنید.' . "\n" . '➕ اگر سرویس مورد نظر شما در لیست زیر نیست لازم هست اول اشتراک را به لیست اشتراک های خودتان اضافه کنید و بعد با زدن دکمه ← 🔄 تغییر پلن، پلن انتخاب کنید تا بتوانید اشتراک خودتان را تمدید و یا شارژ کنید. ',
        'renewal_selection.message.5223e5a747' => '💡 پلنی در این دسته بندی وجود ندارد. ',
    ],

    // ── خرید، پرداخت و تخفیف ──
    'payment' => [
        'api_payment.bank_invoice' => ['text' => "<b>کارت‌به‌کارت بانکی</b>\nمبلغ دقیق زیر را به شماره حساب اعلام‌شده واریز کنید.\nمبلغ به تومان: <code>{amountToman}</code>\nمبلغ به ریال: <code>{amountRial}</code>\n\nشماره حساب:\n<code>{account}</code>\nدارندهٔ حساب: {holder}", 'parameters' => ["amountToman", "amountRial", "account", "holder"]],
        'api_payment.gift_invoice' => ['text' => "<b>پرداخت با گیفت‌کارت</b>\nمبلغ دقیق فاکتور: <code>{amount}</code> تومان\nبرای این فاکتور، یک گیفت‌کارت با همین مبلغ تهیه کنید.", 'parameters' => ["amount"]],
        // Existing API-assisted payments.
        'api_payment.check' => "بررسی پرداخت",
        'api_payment.receipt' => "ارسال رسید بانکی",
        'api_payment.code' => "ارسال کد گیفت‌کارت",
        'api_payment.bank_pending' => "پرداخت هنوز شناسایی نشده است. بررسی خودکار ادامه دارد؛ پس از 10 دقیقه می‌توانید رسید را هم ارسال کنید.",
        'api_payment.bank_fallback' => "پرداخت هنوز شناسایی نشده است. اگر واریز کرده‌اید، می‌توانید رسید را برای بررسی بفرستید؛ بررسی خودکار نیز ادامه دارد.",
        'api_payment.code_prompt' => "کد کامل یک گیفت‌کارت با مبلغ دقیق این فاکتور را در یک پیام بفرستید. با ارسال کد، اجازهٔ مصرف آن برای همین سفارش را می‌دهید.",
        'api_payment.pending' => "بررسی پرداخت ادامه دارد. کد، رسید یا وجه را دوباره ارسال نکنید.",
        'api_payment.review' => "اطلاعات پرداخت برای بررسی مدیر ثبت شد. نتیجه در همین بخش اعلام می‌شود؛ دوباره پرداخت نکنید.",
        'api_payment.used' => "این گیفت‌کارت قبلاً استفاده شده است. وضعیت سفارش را بررسی کنید؛ برای پیگیری با پشتیبانی تماس بگیرید.",
        'api_payment.revoked' => "این گیفت‌کارت باطل شده است. برای پیگیری با پشتیبانی تماس بگیرید.",
        'api_payment.expired' => "مهلت این گیفت‌کارت پایان یافته است. برای پیگیری با پشتیبانی تماس بگیرید.",
        'api_payment.mismatch' => "مبلغ گیفت‌کارت با مبلغ کامل فاکتور برابر نیست. کارت مصرف نشد؛ می‌توانید کد دیگری بفرستید.",
        'api_payment.confirmed' => "پرداخت تأیید شد. ثبت پرداخت به‌تنهایی به معنی تکمیل یا تحویل سفارش نیست؛ وضعیت سفارش را بررسی کنید.",
        'api_payment.unavailable' => "بررسی پرداخت فعلاً کامل نشد و نتیجه هنوز مشخص نیست. اطلاعات دریافت‌شده برای بررسی نگه داشته شد؛ دوباره پرداخت نکنید.",
        'api_payment.locked_code' => "کد قبلی هنوز در حال بررسی است. تا روشن‌شدن نتیجه، کد دیگری ثبت نکنید.",
        'api_payment.denied' => "این درخواست پرداخت در دسترس نیست.",
        'api_payment.completed' => "سفارش تکمیل شد.",
        'api_payment.receipt_review' => "رسید دریافت شد و در انتظار بررسی است. بررسی خودکار پرداخت نیز ادامه دارد؛ وجه یا رسید را دوباره ارسال نکنید.",
        'api_payment.code_acknowledgement' => ['text' => "کد گیفت‌کارت دریافت شد.\n{reference}درخواست در انتظار بررسی مدیر است؛ ارسال کد به معنی تأیید پرداخت یا تحویل سرویس نیست.", 'parameters' => ['reference']],
        'api_payment.code_received' => "کد دریافت شد. ثبت درخواست بررسی در حال انجام است؛ منتظر نتیجه بمانید.",
        'api_payment.single_code' => "فقط کد کامل یک گیفت‌کارت را در یک پیام بفرستید.",
        // agent_discounts.get_agent_discounts
        'agent_discounts.get_agent_discounts.button.526e310df4' => 'عمومی',
        'agent_discounts.get_agent_discounts.button.2b2966a54e' => '❌',
        'agent_discounts.get_agent_discounts.button.d15a8dbd6d' => '🎁 افزودن تخفیف پلن',
        'agent_discounts.get_agent_discounts.button.82aaebb083' => '🎁 افزودن تخفیف سرور',
        // discounts.apply_discount_to_pay
        'discounts.apply_discount_to_pay.text.2cc98f7f7c' => ' تومان',
        'discounts.apply_discount_to_pay.message.e3c216313a' => 'کد تخفیف با موفقیت اعمال شد. مبلغ تخفیف: ',
        'discounts.apply_discount_to_pay.button.16e81418a3' => '❤️',
        // payment_lifecycle.pay_ux_reference
        'payment_lifecycle.pay_ux_reference.label.edb6e627ea' => '🎫 سفارش: <code>',
        'payment_lifecycle.pay_ux_reference.text.d031212b3c' => '👤 شناسه عددی: <code>',
        // payment_lifecycle.pay_ux_status_text
        'payment_lifecycle.pay_ux_status_text.text.df83eb82dd' => 'پرداخت سفارش',
        'payment_lifecycle.pay_ux_status_text.text.a1026d7b85' => '⌛️ مهلت این درخواست پرداخت پایان یافته است',
        'payment_lifecycle.pay_ux_status_text.text.74adf2adfe' => '🧾 درخواست جدیدتری جایگزین این پیش‌نویس شده است',
        'payment_lifecycle.pay_ux_status_text.text.f8f1a7f2f5' => '❌ درخواست پرداخت تأیید نشد',
        'payment_lifecycle.pay_ux_status_text.text.0a0cf2fd64' => '📥 رسید دریافت شده و در انتظار بررسی مدیر است',
        'payment_lifecycle.pay_ux_status_text.text.8c253d0486' => '📤 در انتظار تصویر رسید یا گیفت‌کارت شما',
        'payment_lifecycle.pay_ux_status_text.text.aa6e41205d' => '⚠️ پرداخت یا تحویل نیازمند بررسی است',
        'payment_lifecycle.pay_ux_status_text.text.7873970550' => '✅ وضعیت پرداخت ثبت شده است',
        'payment_lifecycle.pay_ux_status_text.text.c7ccf032ac' => '🧾 فاکتور در انتظار پرداخت است',
        'payment_lifecycle.pay_ux_status_text.text.8b1195f62f' => 'مهلت ارسال رسید از طریق این فاکتور پایان یافته است. اگر وجهی انتقال داده‌اید، شواهد را فقط از طریق پشتیبانی ارائه کنید؛ انقضا به معنی برگشت وجه نیست.',
        'payment_lifecycle.pay_ux_status_text.text.90290d6783' => 'مهلت این درخواست پایان یافته است. برای پرداخت جدید از منوی ربات اقدام کنید.',
        'payment_lifecycle.pay_ux_status_text.text.4bca689fc3' => 'درخواست ارسال رسید ثبت شد. تصویر معتبر را در گفت‌وگوی ربات بفرستید؛ صرف این دکمه به معنی پرداخت یا شروع بررسی مدیر نیست.',
        'payment_lifecycle.pay_ux_status_text.text.c0ee0533a8' => 'از فاکتور جدید استفاده کنید. لینک قبلی دیگر برای پرداخت قابل استفاده نیست؛ اگر وجهی فرستاده‌اید با پشتیبانی هماهنگ کنید.',
        'payment_lifecycle.pay_ux_status_text.text.b2948cf010' => 'جزئیات بررسی را از پشتیبانی پیگیری کنید.',
        'payment_lifecycle.pay_ux_status_text.text.d5b12a8ef5' => 'ارسال رسید به معنی تأیید مالی نیست. وجه یا رسید را دوباره ارسال نکنید.',
        'payment_lifecycle.pay_ux_status_text.text.368ad58967' => 'ثبت وجه به‌تنهایی به معنی تحویل سرویس نیست؛ وضعیت سفارش را در ربات بررسی کنید.',
        'payment_lifecycle.pay_ux_status_text.text.f1092775b2' => 'فاکتور یا وجه را دوباره پرداخت نکنید؛ با شناسه سفارش از پشتیبانی پیگیری کنید.',
        'payment_lifecycle.pay_ux_status_text.text.5e55076dd5' => 'تنها تا زمانی که وضعیت سفارش اجازه دهد از دکمه پرداخت استفاده کنید.',
        'payment_lifecycle.pay_ux_status_text.text.d251ffde03' => 'موضوع: ',
        'payment_lifecycle.pay_ux_status_text.text.20714482be' => ['text' => '' . "\n" . 'مبلغ: {amount} تومان' . "\n" . '' . "\n" . '', 'parameters' => ['amount']],
        // payment_lifecycle.pay_ux_status_keyboard
        'payment_lifecycle.pay_ux_status_keyboard.button.9e25b02e86' => '🔄 بررسی وضعیت سفارش',
        'payment_lifecycle.pay_ux_status_keyboard.button.1121ffbf88' => '🏠 منوی اصلی',
        // referral_reward.give_referral_reward
        'referral_reward.give_referral_reward.buyer_msg.51caba68b5' => '🎁 پاداش معرفی شما به مبلغ ',
        'referral_reward.give_referral_reward.text.b87aec1eb7' => ' تومان ثبت شد.' . "\n" . '💰 موجودی قبلی: ',
        'referral_reward.give_referral_reward.text.c0780ce268' => ' تومان' . "\n" . '💼 موجودی جدید: ',
        // checkout.button
        'checkout.button.e401f3d5a2' => '🛍 خرید اشتراک جدید',
        // checkout.hash_id_local
        'checkout.hash_id_local.09f20c4022' => ['text' => '🎫 شناسه پیگیری: {hashIdLocal}', 'parameters' => ['hashIdLocal']],
        // checkout.message
        'checkout.message.2a6cba0152' => '⚠️ تحویل خرید سفارشی کیف پول نیازمند بررسی است. ',
        'checkout.message.4f5582ec37' => 'پرداخت و سرویس ثبت شده‌اند؛ ساخت مجدد خودکار ممنوع است.',
        // checkout.alert
        'checkout.alert.7f1f245514' => '⛔️ اطلاعات این اشتراک در حال حاضر قابل نمایش نیست.',
        'checkout.alert.bfa5553444' => '⛔️ لینک اشتراک در حال حاضر در دسترس نیست.',
        'checkout.alert.baff5f2488' => '⛔️ کانفیگ قابل نمایش در دسترس نیست.',
        'checkout.alert.1a885aaca4' => '⚠️ پرداخت این سفارش با این روش مجاز نیست یا قبلاً به روش دیگری آغاز شده است.',
        'checkout.alert.07ee06b4bc' => '⚠️ اطلاعات این پرداخت قابل بازیابی نیست؛ با پشتیبانی تماس بگیرید.',
        'checkout.alert.7a9c30e938' => '⚠️ نمایش اطلاعات پرداخت تأیید نشد. از همان دکمه دوباره تلاش کنید و وجهی ارسال نکنید.',
        'checkout.alert.d06bc731eb' => '⚠️ این رسید با روش پرداخت انتخاب‌شده مطابقت ندارد یا سفارش در انتظار پرداخت نیست.',
        'checkout.alert.560f05f822' => '⌛️ مهلت شروع ارسال رسید پایان یافته یا فاکتور در حال بررسی است. اگر وجهی فرستاده‌اید با پشتیبانی تماس بگیرید.',
        'checkout.alert.882596d0f1' => '⚠️ این سفارش قابل ثبت رسید نیست؛ برای بررسی با پشتیبانی تماس بگیرید.',
        'checkout.alert.795c4bddb8' => '⚠️ ثبت یا ارسال رسید در جریان است. همان تصویر را دوباره ارسال کنید؛ در صورت تداوم مشکل با پشتیبانی تماس بگیرید.',
        // checkout.text
        'checkout.text.04029e7ea7' => 'کارت به کارت',
        // checkout.message
        'checkout.message.c346a0d62e' => '📥 تصویر برای پیگیری ثبت شده اما تأیید ارسال به مدیر دریافت نشده است. لطفاً همان تصویر را دوباره بفرستید یا با پشتیبانی تماس بگیرید. پرداخت مجدد انجام ندهید.',
        // checkout.alert
        'checkout.alert.873a4950ab' => '⛔️ تأیید پرداخت فقط توسط مدیر مجاز است.',
        'checkout.alert.e733abf9ba' => '⛔️ این خرید رسید دستی معتبر و در انتظار تأیید ندارد.',
        // checkout.message
        'checkout.message.3b8e6e3a15' => '⚠️ تأیید خرید دستی نیازمند بررسی سرویس و پرداخت است. از اجرای دوباره خودکار خودداری کنید.',
        'checkout.message.eccb64d3cb' => '' . "\n" . '🎫 ',
        // checkout.alert
        'checkout.alert.6fa04cd4c0' => '⚠️ نتیجه تأیید نیاز به بررسی دستی دارد؛ وضعیت سرویس را پیش از اقدام مجدد کنترل کنید.',
        // checkout.message
        'checkout.message.c97c0eb0f0' => '⚠️ تحویل خرید دستی بعد از ثبت سفارش نیاز به بررسی دارد.',
        'checkout.message.6cee937835' => '' . "\n" . 'سرویس را بدون تطبیق مجدد ایجاد نکنید.',
        'checkout.message.5c1a932720' => '✅ اشتراک ثبت شده است.',
        // checkout.button
        'checkout.button.139c829462' => ['text' => '✅ تایید شد توسط {from_id}', 'parameters' => ['from_id']],
        // checkout.remark
        'checkout.remark.4348a3fd9b' => ['text' => '🔄 {remark}', 'parameters' => ['remark']],
        // checkout.hash_id
        'checkout.hash_id.2899228604' => ['text' => '🎫 شناسه پیگیری: {hash_id}', 'parameters' => ['hash_id']],
        // checkout.message
        'checkout.message.3f598b595a' => '⚠️ پرداخت و سفارش ثبت شده‌اند، اما اطلاع‌رسانی نیازمند بررسی است.',
        'checkout.message.cfed3110bf' => '' . "\n" . 'سرویس یا پاداش را بدون تطبیق دوباره صادر نکنید.',
        // checkout.text
        'checkout.text.cca1075329' => ['text' => '✅ اشتراک {remark} با موفقیت تمدید شد.', 'parameters' => ['remark']],
        // checkout.message
        'checkout.message.54c81d71b7' => '⚠️ پرداخت هنوز نهایی نشده؛ ارسال انجام نشد.' . "\n" . '',
        // checkout.hash_id
        'checkout.hash_id.286c7d3faf' => ['text' => '🎫 شناسه پیگیری: {hashId}', 'parameters' => ['hashId']],
        // checkout.message
        'checkout.message.c150d2e039' => '⚠️ تحویل اشتراک کیف پول نیاز به بررسی دارد.',
        'checkout.message.e09fe6fd6b' => '' . "\n" . '🔢 موارد: ',
        'checkout.message.7aa67614a5' => '⚠️ پرداخت و سرویس ثبت شده‌اند، اما گزارش مشخصات خرید نیازمند بررسی است.',
        'checkout.message.f8ec758aca' => '' . "\n" . 'سفارش را با سرویس صادرشده تطبیق دهید؛ آن را دوباره ایجاد نکنید.',
        // pre_payment.alert
        'pre_payment.alert.8fbaa8aebc' => 'این سفارش برای شما قابل مشاهده نیست.',
        // pre_payment.text
        'pre_payment.text.ea9faa7b63' => 'ترون دستی (TRX)',
        'pre_payment.text.16b71e846a' => 'تتر دستی',
        'pre_payment.text.ace4b5ad89' => 'کارت‌به‌کارت بانکی',
        'pre_payment.text.46daee8b43' => 'پرداخت رمزارزی',
        // pre_payment.next_pay
        'pre_payment.next_pay.68fdcf6af2' => 'درگاه نکست‌پی',
        // pre_payment.zarinpal
        'pre_payment.zarinpal.4497ba648f' => 'درگاه زرین‌پال',
        // pre_payment.text
        'pre_payment.text.f07a81a062' => 'نامشخص',
        'pre_payment.text.c8886e46d5' => '' . "\n" . 'روش: ',
        // pre_payment.body
        'pre_payment.body.ff6ceb47bf' => '' . "\n" . '📅 تاریخ تأیید: <code>',
        // pre_payment.button
        'pre_payment.button.ea1db2132d' => '🔄 بررسی دوباره وضعیت',
        // pre_payment.alert
        'pre_payment.alert.fdf0d1d6f9' => 'وضعیت سفارش از اطلاعات ثبت‌شده خوانده شد.',
        'pre_payment.alert.d4b0660226' => 'این دکمهٔ پرداخت معتبر نیست؛ لطفاً از فاکتور جدید استفاده کنید.',
        'pre_payment.alert.192c7f896f' => 'این روش پرداخت پشتیبانی نمی‌شود.',
        // pre_payment.message
        'pre_payment.message.6fe859eb01' => '❌ سفارش یافت نشد یا حذف شده است.',
        // pre_payment.alert
        'pre_payment.alert.4b56f62df2' => 'این درخواست پرداخت متعلق به حساب شما نیست.',
        'pre_payment.alert.bf99a9262b' => 'این سفارش دیگر در انتظار پرداخت نیست.',
        'pre_payment.alert.cb95ca3290' => 'این روش پرداخت برای گروه کاربری شما فعال نیست.',
        'pre_payment.alert.15d949aabf' => 'مهلت این درخواست پایان یافته است.',
        // pre_payment.message
        'pre_payment.message.e3aaf3c571' => '⌛️ این درخواست پرداخت منقضی شده است. از منوی ربات درخواست جدید بسازید.',
        // pre_payment.alert
        'pre_payment.alert.fa55f5201f' => '⛔️ انجام این درخواست برای اشتراک انتخاب‌شده امکان‌پذیر نیست. با پشتیبانی تماس بگیرید.',
        'pre_payment.alert.6871e8bfbf' => 'مهلت لینک آنلاین پایان یافته است؛ وضعیت را پیگیری کنید.',
        // pre_payment.message
        'pre_payment.message.904e919e0e' => '⏳ مهلت لینک فاکتور گذشته است؛ پرداخت یا تحویل هنوز باید بررسی شود. اگر وجهی ارسال کرده‌اید مجدداً پرداخت نکنید.',
        // pre_payment.alert
        'pre_payment.alert.e3336d72e2' => 'وضعیت فاکتور درگاه نامشخص است؛ از پرداخت تکراری پرهیز کنید.',
        'pre_payment.alert.b801192b87' => 'این پرداخت قبلاً ثبت شده یا در حال بررسی است.',
        // pre_payment.message
        'pre_payment.message.34461be871' => '📋 پلن اشتراک تغییر کرده ولی وضعیت پرداخت/فاکتور قبلی محفوظ است. لطفاً برای تطبیق پرداخت با پشتیبانی تماس بگیرید.',
        'pre_payment.message.6a728137d5' => '❗️ پلن فعلی شما تغییر کرده است.' . "\n" . '♻️ لطفاً دوباره روی دکمه تمدید اشتراک بزنید تا قیمت جدید محاسبه شود.',
        'pre_payment.message.f7acca2890' => 'وضعیت پرداخت این سفارش تغییر کرده است؛ برای جلوگیری از پرداخت تکراری با پشتیبانی تماس بگیرید.',
        // pre_payment.alert
        'pre_payment.alert.a421affc40' => 'این سفارش قبلاً در یک درگاه پرداخت ثبت شده است.',
        // pre_payment.button
        'pre_payment.button.9638a59ddc' => '🔄 مشاهده وضعیت فاکتور',
        // pre_payment.message
        'pre_payment.message.08a2b0a6f6' => '💳 فاکتور این سفارش محفوظ است؛ با لینک زیر وضعیت آن را بررسی کنید. اگر پرداختی انجام داده‌اید دوباره پرداخت نکنید.',
        'pre_payment.message.59e3040876' => '⏳ وضعیت صدور فاکتور این سفارش در حال بررسی است. برای جلوگیری از درخواست تکراری با پشتیبانی تماس بگیرید.',
        // pre_payment.alert
        'pre_payment.alert.5ba8749860' => 'مهلت پیش‌نویس پایان یافته یا وضعیت آن نیازمند بررسی است.',
        // pre_payment.button
        'pre_payment.button.f81a8cfcdd' => '💳 پرداخت فاکتور',
        // pre_payment.alert
        'pre_payment.alert.093b359bab' => 'فاکتور آمادهٔ پرداخت است.',
        // pre_payment.text
        'pre_payment.text.52bdfa0dce' => '⏳ پردازش رمزارزی ممکن است بیش از چند دقیقه طول بکشد.',
        'pre_payment.text.64b1e46fd3' => '⚠️ بازگشت از درگاه، به‌تنهایی تأیید مالی نیست.',
        // pre_payment.message
        'pre_payment.message.b3b3cf5aae' => '🧾 <b>فاکتور آماده است</b>' . "\n" . '',
        'pre_payment.message.69b0efb715' => '' . "\n" . 'پس از پرداخت، وضعیت سفارش را در ربات بررسی کنید.',
        'pre_payment.message.526bf7b198' => '⛔️ روش پرداخت ناشناخته است.',
    ],

    // ── رسیدهای پرداخت دستی ──
    'manual_payment' => [
        // automatic_receipts.text
        'automatic_receipts.text.2cc98f7f7c' => ' تومان',
        // automatic_receipts.message
        'automatic_receipts.message.38dd599e83' => 'افزایش حساب شما با موفقیت تایید شد.' . "\n" . '✅ مبلغ ',
        'automatic_receipts.message.6f42cf1050' => ' تومان به حساب شما اضافه شد.',
        'automatic_receipts.message.809aa9e256' => '⚠️ پرداخت ثبت شده است، اما تکمیل ساخت اشتراک هنوز قطعی نیست. ',
        'automatic_receipts.message.c0b5e951d8' => 'برای بررسی با پشتیبانی تماس بگیرید؛ خرید را تکرار نکنید.',
        'automatic_receipts.message.cca1075329' => ['text' => '✅ اشتراک {remark} با موفقیت تمدید شد.', 'parameters' => ['remark']],
        'automatic_receipts.message.ca1eaa01e6' => '⚠️ نتیجهٔ تمدید هنوز قطعی نیست. پرداخت ثبت شده و سرویس نیازمند تطبیق است؛ پرداخت یا تمدید را تکرار نکنید.',
        'automatic_receipts.message.7c7851027c' => ['text' => '✅ مدت {volume} روز به مدت اشتراک شما اضافه شد.', 'parameters' => ['volume']],
        'automatic_receipts.message.6223bac2fc' => '⚠️ نتیجهٔ افزایش روز هنوز قطعی نیست. پرداخت محفوظ است؛ درخواست را تکرار نکنید.',
        'automatic_receipts.message.1660fa3e00' => ['text' => '✅ حجم {volume} گیگ به حجم اشتراک شما اضافه شد.', 'parameters' => ['volume']],
        'automatic_receipts.message.530f1966b8' => '⚠️ نتیجهٔ افزایش حجم هنوز قطعی نیست. پرداخت محفوظ است؛ درخواست را تکرار نکنید.',
        'automatic_receipts.message.16c2ec4018' => '⚠️ نتیجهٔ تمدید هنوز قطعی نیست. وضعیت پرداخت محفوظ است؛ درخواست را تکرار نکنید.',
        'automatic_receipts.message.39254abe63' => '⚠️ پرداخت شما محفوظ است، اما نتیجهٔ ساخت اشتراک قطعی نیست. برای بررسی با پشتیبانی تماس بگیرید؛ پرداخت را تکرار نکنید.',
        // receipt_helpers.refund_to_wallet_and_notify
        'receipt_helpers.refund_to_wallet_and_notify.user_msg.a5b15e454e' => '✅ مبلغ ',
        // receipt_helpers.manual_label
        'receipt_helpers.manual_label.text.3904ef0f3d' => 'گیفت‌کارت',
        'receipt_helpers.manual_label.text.2f1fd66193' => 'تتر دستی (USDT)',
        'receipt_helpers.manual_label.text.ea9faa7b63' => 'ترون دستی (TRX)',
        'receipt_helpers.manual_label.text.ace4b5ad89' => 'کارت‌به‌کارت بانکی',
        // receipt_helpers.manual_crypto_options
        'receipt_helpers.manual_crypto_options.u_s_d_t.1974a6095a' => '💎 تتر (USDT) — ',
        'receipt_helpers.manual_crypto_options.t_r_x__t_r_o_n.e3a2653517' => '🔴 ترون (TRX) — TRON',
        // receipt_helpers.manual_acknowledgement
        'receipt_helpers.manual_acknowledgement.label.a5f27211f7' => '📥 تصویر ',
        'receipt_helpers.manual_acknowledgement.text.29e14cf62e' => ' دریافت شد.' . "\n" . '',
        'receipt_helpers.manual_acknowledgement.text.62e569e922' => '⏳ رسید در انتظار بررسی مدیر است؛ ارسال تصویر به معنی تأیید پرداخت یا تحویل سرویس نیست.',
        // receipt_helpers.duplicate_receipt_guard
        'receipt_helpers.duplicate_receipt_guard.label.2ea72e23b7' => '⛔️ شما درخواست مشابه‌ای قبلا ثبت کرده‌اید.' . "\n" . '⚠️ لطفاً منتظر بررسی پشتیبانی بمانید.',
        'receipt_helpers.duplicate_receipt_guard.label.2f5e71511d' => ['text' => '⛔️ یک درخواست مشابه شما در حال بررسی است.' . "\n" . '⚠️ لطفاً حدود {mins} دقیقه دیگر دوباره تلاش کنید.', 'parameters' => ['mins']],
        'receipt_helpers.duplicate_receipt_guard.label.c571825a4a' => ['text' => '⛔️ شما اخیراً یک درخواست مشابه با وضعیت تایید شده داشته‌اید.' . "\n" . '⚠️ لطفاً حدود {mins} دقیقه دیگر دوباره تلاش کنید.', 'parameters' => ['mins']],
        'receipt_helpers.duplicate_receipt_guard.label.a2fecf9749' => ['text' => '⛔️ درخواست مشابه قبلی شما اخیراً رد شده است.' . "\n" . '⚠️ لطفاً {remain} ثانیه دیگر دوباره تلاش کنید.', 'parameters' => ['remain']],
        // receipt_helpers.send_with_start_button
        'receipt_helpers.send_with_start_button.button.dcb9c20928' => '🔄 بررسی وضعیت',
        // manual_crypto.alert
        'manual_crypto.alert.d0d07ef447' => 'این سفارش در انتظار پرداخت نیست یا متعلق به شما نیست.',
        'manual_crypto.alert.4d788a75be' => 'این سفارش قبلاً با روشی دیگر ثبت یا وارد بررسی شده است.',
        'manual_crypto.alert.5ce97176e4' => 'اطلاعات پرداخت قبلی نیازمند بررسی است.',
        // manual_crypto.t_r_x
        'manual_crypto.t_r_x.a23d6fad8d' => '🔴 ترون (TRX) — ',
        // manual_crypto.alert
        'manual_crypto.alert.26fc4467a8' => 'هیچ شبکه‌ای با آدرس و نرخ معتبر فعال نیست.',
        // manual_crypto.button
        'manual_crypto.button.1121ffbf88' => '🏠 منوی اصلی',
        // manual_crypto.message
        'manual_crypto.message.d05b7cfc7d' => '💎 <b>پرداخت رمزارزی دستی</b>' . "\n" . '' . "\n" . '',
        // manual_crypto.text
        'manual_crypto.text.0a49531de8' => 'ارز و شبکه انتقال را انتخاب کنید. فقط به آدرس همان شبکه واریز کنید.' . "\n" . '',
        'manual_crypto.text.ee4ae4b0c7' => 'ارسال رسید به معنی تأیید پرداخت نیست؛ بررسی مدیر لازم است.',
        // manual_crypto.alert
        'manual_crypto.alert.93decba032' => 'این شبکه برای سفارش در دسترس نیست.',
        'manual_crypto.alert.fc9718b40b' => 'این نوع سفارش از این پرداخت پشتیبانی نمی‌کند.',
        'manual_crypto.alert.5216f25f85' => 'وضعیت سفارش قابل خواندن نیست؛ لطفاً بعداً تلاش کنید.',
        // manual_receipts.message
        'manual_receipts.message.f568baa065' => '✅ افزایش حساب شما با موفقیت تایید شد.' . "\n" . '💰 مبلغ <b>',
        'manual_receipts.message.f6331b33af' => ['text' => ' تومان</b> به کیف پول شما اضافه شد.' . "\n" . '' . "\n" . '<blockquote>' . "\n" . '⚠️ لطفا دقت کنید شما فقط کیف پول خودتان را شارژ کرده‌اید، برای خرید یا تمدید باید از دکمه های زیر استفاده کنید:' . "\n" . '🛒 <b>خرید اشتراک جدید</b>' . "\n" . 'یا <b>🔄 تمدید یا مشاهده اشتراک ها</b>' . "\n" . 'و در مرحله پرداخت روی دکمه زیر بزنید:' . "\n" . '💳 <b>پرداخت با موجودی</b>' . "\n" . '</blockquote>' . "\n" . '📨 پشتیبانی:' . "\n" . '💬 پشتیبانی: {supportID}', 'parameters' => ['supportID']],
        // manual_receipts.button
        'manual_receipts.button.e3517e568b' => '🔄 درخواست دوباره',
        // manual_receipts.message
        'manual_receipts.message.bc0155976d' => ['text' => '❌ درخواست شما به دلایل زیر رد شد:' . "\n" . '' . "\n" . '<blockquote>{reason}</blockquote>' . "\n" . '' . "\n" . '⚠️ اطلاعات درخواست:' . "\n" . '', 'parameters' => ['reason']],
        'manual_receipts.message.c4d810a7d3' => ['text' => '💰 مبلغ: <b>{price}</b>' . "\n" . '', 'parameters' => ['price']],
        'manual_receipts.message.61d75413a2' => ['text' => '♾️ نام اشتراک: <code>{remark}</code>' . "\n" . '', 'parameters' => ['remark']],
        'manual_receipts.message.df4f415ed5' => ['text' => '🎫 شناسه پرداخت: <code>{hash_id}</code>', 'parameters' => ['hash_id']],
    ],

    // ── درگاه‌ها و صفحه وضعیت پرداخت ──
    'gateway' => [
        // gateway_initiation.mark_checkout_uncertain
        'gateway_initiation.mark_checkout_uncertain.message.1f5b86dd86' => 'درگاه پرداخت شناسایی نشد!',
        'gateway_initiation.mark_checkout_uncertain.message.32264671e4' => 'این سفارش قبلاً پرداخت شده؛ لینک یا تاییدیه در ربات ارسال می‌شود.',
        'gateway_initiation.mark_checkout_uncertain.message.33526fc91c' => 'این سفارش در حال پردازش یا نیازمند پیگیری است؛ فاکتور جدید صادر نشد.',
        'gateway_initiation.mark_checkout_uncertain.message.2d0a6d7c50' => 'کد پرداخت یافت نشد.',
        'gateway_initiation.mark_checkout_uncertain.message.24fe31bc43' => 'مهلت این فاکتور پایان یافته است؛ از ربات درخواست جدید ثبت کنید.',
        'gateway_initiation.mark_checkout_uncertain.message.8cdb71a59f' => 'فاکتور جدیدتری ثبت شده است. لینک قبلی دیگر برای پرداخت نمایش داده نمی‌شود؛ اگر وجهی فرستاده‌اید برای بررسی با پشتیبانی تماس بگیرید.',
        'gateway_initiation.mark_checkout_uncertain.message.2186b9a9dd' => 'وضعیت درخواست پرداخت قابل اطمینان نیست؛ برای جلوگیری از پرداخت تکراری با پشتیبانی تماس بگیرید.',
        'gateway_initiation.mark_checkout_uncertain.message.32573bc25a' => 'این روش پرداخت برای حساب شما فعال نیست؛ اگر فاکتور قبلی صادر شده با پشتیبانی وضعیت آن را پیگیری کنید.',
        'gateway_initiation.mark_checkout_uncertain.message.2d6b333856' => 'مهلت استفاده از لینک این فاکتور پایان یافته است. وضعیت پرداخت در حال پیگیری است؛ وجه را دوباره ارسال نکنید.',
        'gateway_initiation.mark_checkout_uncertain.message.eaf7a98f3e' => 'وضعیت فاکتور آنلاین نیازمند بررسی است؛ پرداخت مجدد انجام ندهید.',
        'gateway_initiation.mark_checkout_uncertain.message.c2f74dfd7e' => 'پرداخت این اشتراک در حال حاضر امکان‌پذیر نیست؛ برای راهنمایی با پشتیبانی تماس بگیرید.',
        'gateway_initiation.mark_checkout_uncertain.message.c6fe64557e' => 'پلن مورد نظر یافت نشد.',
        'gateway_initiation.mark_checkout_uncertain.message.09d73ebed8' => 'ظرفیت این کانکشن پر شده است.',
        'gateway_initiation.mark_checkout_uncertain.message.bdc1d950b2' => 'ظرفیت این سرور پر شده است.',
        'gateway_initiation.mark_checkout_uncertain.message.091b17d805' => ['text' => 'روی این پلن فقط {acount} اشتراک میشه ساخت.', 'parameters' => ['acount']],
        'gateway_initiation.mark_checkout_uncertain.type.e6343571a4' => 'خرید اشتراک',
        'gateway_initiation.mark_checkout_uncertain.type.df1c62b99e' => 'تمدید اشتراک',
        'gateway_initiation.mark_checkout_uncertain.type.991ae08d64' => 'شارژ کیف پول',
        'gateway_initiation.mark_checkout_uncertain.type.884cfdce97' => 'افزایش زمان اشتراک',
        'gateway_initiation.mark_checkout_uncertain.type.160a34aa6d' => 'افزایش حجم اشتراک',
        'gateway_initiation.mark_checkout_uncertain.message.71632f6c74' => 'پیکربندی پیگیری امن پرداخت هنوز تکمیل نشده است.',
        'gateway_initiation.mark_checkout_uncertain.message.f4702323f6' => 'فاکتور این سفارش در حال صدور است؛ از ایجاد درخواست تکراری جلوگیری شد.',
        'gateway_initiation.mark_checkout_uncertain.message.7de7c0e3e6' => 'پرداخت برای این فاکتور شناسایی شده است و در حال بررسی است. برای مشاهده وضعیت به ربات مراجعه کنید.',
        'gateway_initiation.mark_checkout_uncertain.message.eda31ba620' => 'لینک فاکتور نیاز به بررسی پشتیبانی دارد.',
        'gateway_initiation.mark_checkout_uncertain.message.cdfa4a6049' => 'برای این سفارش قبلاً فاکتور صادر شده است؛ از ایجاد فاکتور تکراری جلوگیری شد.',
        'gateway_initiation.mark_checkout_uncertain.message.c4414cfacc' => 'این روش پرداخت فعلاً در دسترس نیست؛ روش دیگری را انتخاب کنید یا با پشتیبانی تماس بگیرید.',
        'gateway_initiation.mark_checkout_uncertain.message.7f6fcbdd9d' => 'این روش پرداخت فعلاً در دسترس نیست؛ روش دیگری را انتخاب کنید یا با پشتیبانی تماس بگیرید.',
        'gateway_initiation.mark_checkout_uncertain.message.df2a7c68c7' => 'این روش پرداخت فعلاً در دسترس نیست؛ روش دیگری را انتخاب کنید یا با پشتیبانی تماس بگیرید.',
        'gateway_initiation.mark_checkout_uncertain.message.868477ce4f' => 'مبلغ فاکتور معتبر نیست.',
        'gateway_initiation.mark_checkout_uncertain.message.d591bf832a' => 'این روش پرداخت فعلاً در دسترس نیست؛ لطفاً روش دیگری را انتخاب کنید.',
        'gateway_initiation.mark_checkout_uncertain.message.b0a8fa5e67' => 'فاکتور این سفارش در حال صدور یا نیازمند بررسی است؛ درخواست تکراری ایجاد نشد.',
        'gateway_initiation.mark_checkout_uncertain.message.f3414a91d3' => 'وضعیت صدور فاکتور درگاه نامشخص است؛ درخواست جدید صادر نمی‌شود و به بررسی نیاز دارد.',
        'gateway_initiation.mark_checkout_uncertain.message.5e673fef71' => 'صدور فاکتور درگاه تأیید نشد؛ برای جلوگیری از فاکتور تکراری بررسی لازم است.',
        'gateway_initiation.mark_checkout_uncertain.message.9c2d3b3672' => 'پاسخ صدور فاکتور ناقص بود؛ درخواست جدید خودکار صادر نمی‌شود.',
        'gateway_initiation.mark_checkout_uncertain.message.8196433946' => 'هویت یا لینک فاکتور درگاه نامعتبر است و به بررسی نیاز دارد.',
        'gateway_initiation.mark_checkout_uncertain.message.0c31b5ab6a' => 'فاکتور صادر شد اما اتصال ایمن آن به سفارش نیازمند بررسی است؛ درخواست جدید صادر نمی‌شود.',
        'gateway_initiation.mark_checkout_uncertain.button.f81a8cfcdd' => '💳 پرداخت فاکتور',
        'gateway_initiation.mark_checkout_uncertain.button.f0137e1007' => '🧾 بررسی وضعیت سفارش',
        'gateway_initiation.mark_checkout_uncertain.message.cd27d14216' => '🧾 فاکتور ثبت شد.' . "\n" . '',
        'gateway_initiation.mark_checkout_uncertain.message.0c4a9612f7' => 'پرداخت فقط پس از تأیید درگاه و ثبت نتیجه در ربات نهایی است؛ ',
        'gateway_initiation.mark_checkout_uncertain.message.0a2e0bc7bc' => 'پردازش ممکن است بیش از چند دقیقه طول بکشد.',
        'gateway_initiation.mark_checkout_uncertain.message.9dfdbeaa81' => 'یک درخواست پرداخت برای این سفارش قبلاً صادر شده است؛ تغییر درگاه نیازمند بررسی است.',
        'gateway_initiation.mark_checkout_uncertain.message.1f2efee2e5' => 'وضعیت صدور فاکتور نامشخص است؛ برای جلوگیری از پرداخت تکراری نیاز به بررسی دارد.',
        'gateway_initiation.mark_checkout_uncertain.message.afca7c1801' => 'وضعیت ایجاد تراکنش نامشخص است؛ درخواست پرداخت جدید خودکار صادر نمی‌شود.',
        // gateway_return.np_reconcile_batch
        'gateway_return.np_reconcile_batch.message.f8a82e5394' => 'کد پرداخت نامعتبر است.',
        'gateway_return.np_reconcile_batch.message.f48a802b77' => 'خطا!',
        'gateway_return.np_reconcile_batch.message.e898fa59b8' => 'تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.',
        'gateway_return.np_reconcile_batch.message.28954419df' => 'خطا',
        'gateway_return.np_reconcile_batch.message.678120fc1c' => 'درخواست پرداخت ثبت شده و بدون نیاز به این صفحه، خودکار بررسی می‌شود.',
        'gateway_return.np_reconcile_batch.message.da0bc406d8' => 'پیگیری پرداخت',
        'gateway_return.np_reconcile_batch.message.bd2edf700f' => 'این فاکتور قدیمی نیازمند بررسی تایید مبلغ توسط پشتیبانی است؛ وضعیت آن بدون تغییر محفوظ ماند.',
        'gateway_return.np_reconcile_batch.message.0116c30fc4' => 'پرداخت شما ثبت شده است؛ نتیجه و لینک سرویس در ربات ارسال می‌شود.',
        'gateway_return.np_reconcile_batch.message.5cf3e172d8' => 'پرداخت ثبت‌شده',
        'gateway_return.np_reconcile_batch.message.e46087af54' => 'پرداخت در حال بررسی خودکار است؛ برای دریافت سرویس نیازی به زدن دکمه بازگشت نیست.',
        'gateway_return.np_reconcile_batch.message.f71cd91dad' => 'در حال بررسی',
        'gateway_return.np_reconcile_batch.message.c4374c5a30' => 'نتیجه پرداخت در این صفحه قابل تایید نیست؛ وضعیت سفارش را در ربات پیگیری کنید.',
        'gateway_return.np_reconcile_batch.message.cf271d9662' => 'شناسه پرداخت زرین پال با سفارش مطابقت ندارد؛ نیازمند بررسی است.',
        'gateway_return.np_reconcile_batch.message.c0199d5243' => 'درگاه زرین پال',
        'gateway_return.np_reconcile_batch.message.e72296ca09' => 'این پرداخت نیازمند تطبیق امن با سفارش است.',
        'gateway_return.np_reconcile_batch.message.67fa35fa63' => 'شناسه پرداخت نیازمند بررسی پشتیبانی است؛ سفارش محفوظ ماند.',
        'gateway_return.np_reconcile_batch.message.498c9fb2b1' => 'شناسه تراکنش یکتا نیست؛ تسویه برای بررسی متوقف شد.',
        'gateway_return.np_reconcile_batch.message.965bc59eaf' => 'وضعیت تراکنش از پذیرنده قابل تایید نبود؛ سفارش محفوظ است.',
        'gateway_return.np_reconcile_batch.message.858b02ccfe' => 'پیگیری زرین پال',
        'gateway_return.np_reconcile_batch.message.26b2f2ff4c' => 'نتیجه تراکنش نیازمند تطبیق با پذیرنده است؛ سفارش لغو نشد.',
        'gateway_return.np_reconcile_batch.message.2a57015571' => 'هویت یا مبلغ این پرداخت نیازمند بررسی است.',
        'gateway_return.np_reconcile_batch.message.149dd8c934' => 'شناسه تراکنش نیازمند تطبیق پشتیبانی است؛ سفارش محفوظ ماند.',
        'gateway_return.np_reconcile_batch.message.faca1dabd3' => 'کد پرداخت یافت نشد یا یکتا نیست.',
        'gateway_return.np_reconcile_batch.message.cef6ec0c00' => 'این پرداخت قبلاً بررسی شده است.',
        'gateway_return.np_reconcile_batch.message.d9ed0f4231' => 'وضعیت پرداخت تغییر کرده است؛ با پشتیبانی تماس بگیرید.',
        'gateway_return.np_reconcile_batch.message.4406d657a1' => 'پرداخت شما انجام نشد!',
        'gateway_return.np_reconcile_batch.message.26003a476f' => 'درگاه نکست پی',
        'gateway_return.np_reconcile_batch.message.463d7338a5' => 'وضعیت پرداخت هنوز قطعی نیست یا استعلام در دسترس نیست؛ لطفاً بعداً دوباره بررسی کنید.',
        'gateway_return.np_reconcile_batch.message.1d15981671' => 'درگاه پرداخت شناسایی نشد.',
        // gateway_return.np_defer_uncertain_remote_effect
        'gateway_return.np_defer_uncertain_remote_effect.message.adae4bb365' => 'پرداخت تایید شده است اما نتیجه عملیات سرویس هنوز قطعی نیست. وضعیت برای بررسی امن ثبت می‌شود و بازپرداخت خودکار انجام نمی‌شود.',
        // gateway_return.perform_payment_effect
        'gateway_return.perform_payment_effect.text.275b4903d9' => '↪️ بازگشت به منوی اصلی',
        'gateway_return.perform_payment_effect.message.c79ed0ecc0' => 'مبلغ سفارش پس از صدور فاکتور تغییر کرده است؛ تحویل متوقف شد.',
        'gateway_return.perform_payment_effect.message.a64929caf4' => 'پرداخت تأیید شده است اما ثبت نتیجهٔ سفارش تکمیل نشده است. لطفاً دوباره پرداخت نکنید، مشخصات پرداخت را نگه دارید و با پشتیبانی تماس بگیرید.',
        'gateway_return.perform_payment_effect.message.e3ee1490e9' => 'این پرداخت در حال پردازش یا قبلاً نهایی شده است.',
        'gateway_return.perform_payment_effect.message.bd952e9bc9' => '⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.',
        'gateway_return.perform_payment_effect.message.cf0e1ca461' => 'پرداخت شما انجام شد ولی ظرفیت این کانکشن پر شده است، مبلغ ',
        'gateway_return.perform_payment_effect.message.606f350948' => ' تومان به کیف پول شما اضافه شد.',
        'gateway_return.perform_payment_effect.message.6ae4e71c95' => '⚠️ پرداخت شما با موفقیت انجام شد. ولی اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ... مبلغ ',
        'gateway_return.perform_payment_effect.message.c042799f80' => ' به کیف پولت اضافه شد.',
        'gateway_return.perform_payment_effect.message.3768e94d51' => ['text' => '✅ پرداخت شما با موفقیت انجام شد، اما اشتراک آماده نشد. مبلغ ', 'parameters' => []],
        'gateway_return.perform_payment_effect.message.3828187c8e' => ' به کیف پول شما اضافه شد.',
        'gateway_return.perform_payment_effect.message.7ea4d9ee52' => '⚠️ پرداخت شما با موفقیت انجام شد. ولی خطا داد لطفا به مدیر اطلاع دهید ... مبلغ ',
        'gateway_return.perform_payment_effect.message.a0b19d8503' => ' تومان به کیف پولت اضافه شد.',
        'gateway_return.perform_payment_effect.message.effdee0c2d' => '✅ پرداخت تأیید شد؛ وضعیت تحویل سرویس را در ربات بررسی کنید.',
        'gateway_return.perform_payment_effect.button.b9d052f74c' => ['text' => '💳 خرید از درگاه {gateType}', 'parameters' => ['gateType']],
        'gateway_return.perform_payment_effect.hash_id.2899228604' => ['text' => '🎫 شناسه پیگیری: {hash_id}', 'parameters' => ['hash_id']],
        'gateway_return.perform_payment_effect.message.bbf27ebefd' => '✅ پرداخت شما با موفقیت انجام شد، مبلغ ',
        'gateway_return.perform_payment_effect.message.dd866497c7' => ['text' => ' تومان به حساب شما اضافه شد.' . "\n" . '' . "\n" . '<blockquote>' . "\n" . '⚠️ لطفا دقت کنید شما فقط کیف پول خودتان را شارژ کرده‌اید، برای خرید یا تمدید باید از دکمه های زیر استفاده کنید:' . "\n" . '' . "\n" . '🛒 <b>خرید اشتراک جدید</b>' . "\n" . 'یا <b>🔄 تمدید یا مشاهده اشتراک ها</b>' . "\n" . '' . "\n" . 'و در مرحله پرداخت روی دکمه زیر بزنید:' . "\n" . '' . "\n" . '💰 <b>پرداخت با موجودی</b>' . "\n" . '' . "\n" . '</blockquote>' . "\n" . '📨 پشتیبانی:' . "\n" . '' . "\n" . '💬 پشتیبانی: {supportID}', 'parameters' => ['supportID']],
        'gateway_return.perform_payment_effect.message.a3b46e7d37' => ['text' => '✅ پرداخت شما با موفقیت انجام شد. اشتراک {remark} با موفقیت تمدید شد.', 'parameters' => ['remark']],
        'gateway_return.perform_payment_effect.message.8ab1d455cf' => ['text' => '✅ پرداخت شما با موفقیت انجام شد. {plusDays} روز به مدت زمان اشتراک شما اضافه شد.', 'parameters' => ['plusDays']],
        'gateway_return.perform_payment_effect.message.9f4f326fa4' => '⚠️ پرداخت شما با موفقیت انجام شد ولی به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5 دقیقه دیگر دوباره تست کنید مبلغ ',
        'gateway_return.perform_payment_effect.message.20ac3e8049' => ['text' => '✅ پرداخت شما با موفقیت انجام شد. {plusVolume} گیگ به حجم اشتراک شما اضافه شد.', 'parameters' => ['plusVolume']],
        'gateway_return.perform_payment_effect.message.28ba281162' => '⚠️ پرداخت شما با موفقیت انجام شد ولی مشکل فنی در ارتباط با سرور، لطفا سلامت سرور را بررسی کنید مبلغ ',
        'gateway_return.perform_payment_effect.button.82f7af5445' => 'صفحه اصلی 🏘',
        'gateway_return.perform_payment_effect.reason.3b9fd32170' => 'پرداخت انجام شد ولی تمدید به دلیل خطای سرور انجام نشد.' . "\n" . '',
        'gateway_return.perform_payment_effect.text.9eb6edd609' => 'لطفاً به مدیریت اطلاع دهید.' . "\n" . '',
        'gateway_return.perform_payment_effect.text.c957e6dfa7' => 'کد خطا: ',
        'gateway_return.perform_payment_effect.message.68e2abdadb' => 'پرداخت شما با موفقیت انجام شد ولی مشکل فنی در تمدید.' . "\n" . 'مبلغ ',
        'gateway_return.perform_payment_effect.message.81c4bb3acb' => '✅ پرداخت شما با موفقیت انجام شد.',
        // gateway_return.do_action
        'gateway_return.do_action.text.83188c9e00' => 'پرداخت تأیید شده است اما نتیجهٔ تکمیل سفارش هنوز قطعی نیست. لطفاً دوباره پرداخت نکنید، مشخصات پرداخت را نگه دارید و با پشتیبانی تماس بگیرید.',
        'gateway_return.do_action.text.30e9647ee1' => 'پرداخت تأیید شده است اما ثبت نتیجهٔ سفارش هنوز قطعی نیست. لطفاً دوباره پرداخت نکنید، مشخصات پرداخت را نگه دارید و با پشتیبانی تماس بگیرید.',
        // payment_page.render_payment_page
        'payment_page.render_payment_page.text.0134bd87ce' => 'وضعیت پرداخت',
        'payment_page.render_payment_page.text.e5b6190b3e' => 'عملیات ثبت و تکمیل شد',
        'payment_page.render_payment_page.text.0aa6b2235a' => 'پرداخت انجام نشد',
        'payment_page.render_payment_page.text.a5c5d3b4fb' => 'در انتظار نتیجه',
        'payment_page.render_payment_page.text.9c641f40e0' => 'نیازمند بررسی',
        'payment_page.render_payment_page.text.cb52f9e6dc' => 'وضعیت سفارش',
        // payment_page.browser_text
        'payment_page.browser_text.cc431d379c' => ' — وضعیت سفارش',
        'payment_page.browser_text.d364810dea' => 'اطلاع‌رسانی سفارش',
        'payment_page.browser_text.e71900f476' => 'این صفحه به‌تنهایی مدرک تسویه یا تحویل سرویس نیست. نتیجه نهایی را در ربات پیگیری کنید. بررسی بعضی پرداخت‌ها بیش از پنج دقیقه زمان می‌برد.',
        'payment_page.browser_text.df24f2d651' => 'بازگشت به ربات و پیگیری سفارش',
        'payment_page.browser_text.911c66993a' => 'برای پیگیری، از داخل ربات وارد بخش سفارش‌های خود شوید.',
        'payment_page.browser_text.58121493fd' => 'در صورت مغایرت، شناسه سفارش را برای پشتیبانی ارسال کنید.',
    ],

    // ── اعلان‌های اشتراک ──
    'notifications' => [
        // warnings.send
        'warnings.send.1e5349d9b2' => '1 روز',
        'warnings.send.0458ad0612' => '0.5 گیگ',
        // warnings.msg
        'warnings.msg.804056ecc4' => ['text' => '💡 کاربر گرامی، اشتراک  <code>{remark}</code>  به زودی تمام می شود. کمتر از  <b>{send}</b>  مانده است. ' . "\n" . '🔰 می‌توانید از ← 🔄 <b>مشاهده اشتراک ها</b> آن را تمدید کنید یا اشتراک جدید خریداری کنید.  ' . "\n" . '🛑 بعد از اتمام در صورتی که <b>تا 3 روز</b> تمدید نکنید از لیست اشتراک های شما حذف می شود.' . "\n" . '💳 می توانید <b>قبل از اتمام، کیف پول خودتان را شارژ کنید</b> و از بخش <b>مشاهده اشتراک ها تمدید خودکار را روشن کنید</b> تا به صورت خودکار وقتی اشتراک تمام شد تمدید شود.', 'parameters' => ['remark', 'send']],
        'warnings.msg.c7907b6342' => ['text' => '💡 کاربر گرامی، اشتراک  <code>{remark}</code>  تمام شده بود و از لیست اشتراک ها حذف گردید. لطفا اشتراک جدید خریداری کنید.', 'parameters' => ['remark']],
    ],

    // ── پشتیبانی و تیکت ──
    'support' => [
        'admin_reply.button.f8a34e79d9' => '💬 پاسخ به تیکت',
        'admin_reply.button.173875ae6b' => '💬 بستن تیکت',
        'queue_and_history.all_tickets.text.61cf036058' => '📷 تصویر /dlPic',
        // create_ticket.message
        'create_ticket.message.5c9c3dda00' => '✅ پیام شما با موفقیت ثبت شد.',
        'create_ticket.message.b059640ccc' => '📝 لطفا یکی از کلید های زیر را انتخاب کنید.',
        'create_ticket.message.19602d80df' => '💬 پیام مورد نظر پشتیبانی نمی شود',
        // customer_conversation.user_all_tickets
        'customer_conversation.user_all_tickets.text.13ad0eef28' => 'پشتیبانی',
        'customer_conversation.user_all_tickets.text.5bf4b36161' => 'کاربر',
        'customer_conversation.user_all_tickets.button.2f9a0fbfd0' => '💬 آخرین پیام ها',
        'customer_conversation.user_all_tickets.txt.d10f40bde9' => ['text' => '🔘 موضوع: {title}' . "\n" . '            		💭 دسته بندی:  {category}' . "\n" . '            		' . "\n" . '' . "\n" . '            		{sentType}: {caption}', 'parameters' => ['title', 'category', 'sentType', 'caption']],
        'customer_conversation.user_all_tickets.message.dfae2b8b30' => ['text' => '🔘 موضوع: {title}' . "\n" . '            		💭 دسته بندی:  {category}' . "\n" . '            		' . "\n" . '' . "\n" . '            		{sentType}: {lastmsg}', 'parameters' => ['title', 'category', 'sentType', 'lastmsg']],
        'customer_conversation.user_all_tickets.text.7555aab9b2' => '📄 موارد بیشتر',
        'customer_conversation.user_all_tickets.button.29582a35e0' => '📥 دریافت',
        'customer_conversation.user_all_tickets.alert.69716a6209' => '⚠️ تیکتی یافت نشد',
        'customer_conversation.user_all_tickets.title_category.f58d7d9818' => ['text' => 'موضوع: {title} 🟰 {category} ' . "\n" . '' . "\n" . '', 'parameters' => ['title', 'category']],
        'customer_conversation.user_all_tickets.text.54487cb6cb' => 'این تیکت بسته شد' . "\n" . ' به این تیکت رأی بدهید',
        'customer_conversation.user_all_tickets.button.67c753a13d' => 'بسیار بد',
        'customer_conversation.user_all_tickets.button.6f4e0b97a2' => 'بد',
        'customer_conversation.user_all_tickets.button.e26806a4af' => 'خوب',
        'customer_conversation.user_all_tickets.button.3ed1f2e72a' => 'بسیار خوب',
        'customer_conversation.user_all_tickets.button.3d61f01bbd' => 'عالی',
        'customer_conversation.user_all_tickets.message.13e7a1cdbb' => '💠 لطفا متن پیام خود را بصورت ساده و مختصر ارسال کنید!',
        'customer_conversation.user_all_tickets.message.1d456424c3' => '✅',
        // entry.support_section
        'entry.support_section.message.7089955c27' => '🛂 به بخش پشتیبانی خوش آمدید.' . "\n" . '❇️ لطفا، یکی از دکمه های زیر را انتخاب نمایید.',
        'entry.support_section.button.47b4e6ab96' => '✉️ ثبت تیکت',
        'entry.support_section.button.a203213e37' => '💬 تیکت های باز',
        'entry.support_section.button.b15cb57126' => '📮 لیست تیکت ها',
        // entry.users_new_ticket
        'entry.users_new_ticket.message.adca0ba3c2' => '⁉️ لطفا واحد مورد نظر خود را انتخاب نمایید!' . "\n" . '🛑 از ارسال درخواست های نامفهوم و بیجا خودداری کنید، بدون اخطار اکانت شما مسدود می شود.' . "\n" . '🚫 فقط به درخواست هایی که به زبان فارسی تایپ می شوند پاسخ داده می شود.',
        'entry.users_new_ticket.alert.46a8c85d10' => '⚠️ ای وای، ببخشید الان نیستم',
    ],

    // ── همکاری و همکاران ──
    'agent' => [
        // applications.request_agency
        'applications.request_agency.message.b059640ccc' => 'لطفا یکی از کلید های زیر را انتخاب کنید.',
    ],

    // ── دکمه‌های دریافت رسید ──
    'telegram' => [
        // markup.receipt_and_cancel
        'markup.receipt_and_cancel.button.063f65fc8d' => '📤 ارسال تصویر گیفت‌کارت',
        'markup.receipt_and_cancel.button.4595a70ab5' => '🎁 خرید گیفت‌کارت',
        'markup.receipt_and_cancel.text.75b57f3066' => '🔴 ارسال رسید TRX',
        'markup.receipt_and_cancel.text.3630d712b2' => '💎 ارسال رسید تتر',
        'markup.receipt_and_cancel.button.ae15b282fd' => '📤 ارسال رسید بانکی',
        'markup.receipt_and_cancel.button.cc357a4c9f' => '❌ لغو عملیات',
        // update_bindings.text
        'update_bindings.text.32f665d21c' => ' ندارد ',
    ],

    // ── برچسب‌های مشترک ──
    'labels' => [
        // labels.payment_type
        'labels.payment_type.label.e6343571a4' => 'خرید اشتراک',
        'labels.payment_type.label.df1c62b99e' => 'تمدید اشتراک',
        'labels.payment_type.label.991ae08d64' => 'شارژ کیف پول',
        'labels.payment_type.label.884cfdce97' => 'افزایش زمان اشتراک',
        'labels.payment_type.label.160a34aa6d' => 'افزایش حجم اشتراک',
        'labels.payment_type.label.1af21c4ced' => 'پرداخت',
    ],

    // ── واژه‌های تاریخ و زمان (بدون اموجی) ──
    'date' => [
        // jdf.jdate
        'jdf.jdate.text.97c8436cd1' => 'ق.ظ',
        'jdf.jdate.text.0c5015067c' => 'ب.ظ',
        'jdf.jdate.text.a8554c7a06' => 'قبل از ظهر',
        'jdf.jdate.text.67c8744cfc' => 'بعد از ظهر',
        'jdf.jdate.text.f06d8475f4' => ' ،',
        'jdf.jdate.text.11735aabd3' => '، ',
        'jdf.jdate.out.9de4642363' => 'ام',
        // jdf.jdate_words
        'jdf.jdate_words.text.8ac71c873a' => 'ده',
        'jdf.jdate_words.text.57d5767cf6' => 'یازده',
        'jdf.jdate_words.text.bf2558f509' => 'دوازده',
        'jdf.jdate_words.text.24c27340c1' => 'سیزده',
        'jdf.jdate_words.text.0e5c6c20c4' => 'چهارده',
        'jdf.jdate_words.text.5ae225e758' => 'پانزده',
        'jdf.jdate_words.text.cf20908d6d' => 'شانزده',
        'jdf.jdate_words.text.3c0d6a9786' => 'هفده',
        'jdf.jdate_words.text.3cd22200d6' => 'هجده',
        'jdf.jdate_words.text.a0748d2617' => 'نوزده',
        'jdf.jdate_words.text.1a51e219ec' => ' و ',
        'jdf.jdate_words.text.24bb93ca32' => 'بیست',
        'jdf.jdate_words.text.f9bd23e4d1' => 'سی',
        'jdf.jdate_words.text.54b58e0a94' => 'چهل',
        'jdf.jdate_words.text.a5b7b060db' => 'پنجاه',
        'jdf.jdate_words.text.75e495e488' => 'شصت',
        'jdf.jdate_words.text.c9ecd884e4' => 'هفتاد',
        'jdf.jdate_words.text.8f8040072c' => 'هشتاد',
        'jdf.jdate_words.text.dd905fd6a1' => 'نود',
        'jdf.jdate_words.text.fdebba67c0' => 'یک',
        'jdf.jdate_words.text.44db1d0eb9' => 'دو',
        'jdf.jdate_words.text.5c777e7f21' => 'سه',
        'jdf.jdate_words.text.f36ac14abd' => 'چهار',
        'jdf.jdate_words.text.4a1a9e0101' => 'پنج',
        'jdf.jdate_words.text.3155888dfb' => 'شش',
        'jdf.jdate_words.text.c8641c6a56' => 'هفت',
        'jdf.jdate_words.text.f651432e38' => 'هشت',
        'jdf.jdate_words.text.b0e91feb14' => 'نه',
        'jdf.jdate_words.text.d025e64fdc' => 'هزار و دویست',
        'jdf.jdate_words.text.789475ce59' => 'هزار و سیصد',
        'jdf.jdate_words.text.acecc99d12' => 'هزار و چهارصد',
        'jdf.jdate_words.text.87d414234d' => 'هزار و نهصد',
        'jdf.jdate_words.text.e788887c33' => 'دوهزار',
        'jdf.jdate_words.text.606f195ef4' => 'فروردین',
        'jdf.jdate_words.text.85d23ae9bc' => 'اردیبهشت',
        'jdf.jdate_words.text.26020bdf0f' => 'خرداد',
        'jdf.jdate_words.text.e666598ef2' => 'تیر',
        'jdf.jdate_words.text.73461f2073' => 'مرداد',
        'jdf.jdate_words.text.88eb449d9d' => 'شهریور',
        'jdf.jdate_words.text.8a4bc93ff1' => 'مهر',
        'jdf.jdate_words.text.2171b57a2e' => 'آبان',
        'jdf.jdate_words.text.ca7ec525a9' => 'آذر',
        'jdf.jdate_words.text.2ae9c04860' => 'دی',
        'jdf.jdate_words.text.569bd7b0d5' => 'بهمن',
        'jdf.jdate_words.text.fd6af47420' => 'اسفند',
        'jdf.jdate_words.text.8c4c6b36e7' => 'بیست و یک',
        'jdf.jdate_words.text.d3ff544bea' => 'بیست و دو',
        'jdf.jdate_words.text.439b063855' => 'بیست و سه',
        'jdf.jdate_words.text.c9d9ba3716' => 'بیست و چهار',
        'jdf.jdate_words.text.af0e478da6' => 'بیست و پنج',
        'jdf.jdate_words.text.bd9dc6e277' => 'بیست و شش',
        'jdf.jdate_words.text.820843a493' => 'بیست و هفت',
        'jdf.jdate_words.text.bb6b11a383' => 'بیست و هشت',
        'jdf.jdate_words.text.9d0e2bc724' => 'بیست و نه',
        'jdf.jdate_words.text.96470812b8' => 'سی و یک',
        'jdf.jdate_words.text.58f0fe06c4' => 'یکشنبه',
        'jdf.jdate_words.text.cd9be78f65' => 'دوشنبه',
        'jdf.jdate_words.text.1b2a908a00' => 'سه شنبه',
        'jdf.jdate_words.text.2415ff5948' => 'چهارشنبه',
        'jdf.jdate_words.text.7a75d6fc3d' => 'پنجشنبه',
        'jdf.jdate_words.text.ce9bd10902' => 'جمعه',
        'jdf.jdate_words.text.7c580b5cf1' => 'شنبه',
        'jdf.jdate_words.text.40bf697661' => 'مار',
        'jdf.jdate_words.text.159c77fc3f' => 'اسب',
        'jdf.jdate_words.text.d8c87c7360' => 'گوسفند',
        'jdf.jdate_words.text.86275fa4b8' => 'میمون',
        'jdf.jdate_words.text.ff94940c89' => 'مرغ',
        'jdf.jdate_words.text.61426b93ae' => 'سگ',
        'jdf.jdate_words.text.3aae7a9973' => 'خوک',
        'jdf.jdate_words.text.b30fa541f5' => 'موش',
        'jdf.jdate_words.text.88accd6ad2' => 'گاو',
        'jdf.jdate_words.text.65e0559b01' => 'پلنگ',
        'jdf.jdate_words.text.cda84920a1' => 'خرگوش',
        'jdf.jdate_words.text.b91e9abb45' => 'نهنگ',
        'jdf.jdate_words.text.f27cd3e3e4' => 'حمل',
        'jdf.jdate_words.text.1d2d7fee62' => 'ثور',
        'jdf.jdate_words.text.70fa3386b5' => 'جوزا',
        'jdf.jdate_words.text.963dcc40e1' => 'سرطان',
        'jdf.jdate_words.text.12051e6ea6' => 'اسد',
        'jdf.jdate_words.text.f617c2fbf3' => 'سنبله',
        'jdf.jdate_words.text.9e1021c56a' => 'میزان',
        'jdf.jdate_words.text.55b950d068' => 'عقرب',
        'jdf.jdate_words.text.6a46b71cf7' => 'قوس',
        'jdf.jdate_words.text.892c828347' => 'جدی',
        'jdf.jdate_words.text.26d96d8de4' => 'دلو',
        'jdf.jdate_words.text.17524579e9' => 'حوت',
        'jdf.jdate_words.text.0915a0498e' => 'بهار',
        'jdf.jdate_words.text.ea3b03e1db' => 'تابستان',
        'jdf.jdate_words.text.1e73cac3a1' => 'پاییز',
        'jdf.jdate_words.text.58aa8ecda3' => 'زمستان',
        'jdf.jdate_words.text.0fe3742522' => 'فر',
        'jdf.jdate_words.text.cc3fbbc47c' => 'ار',
        'jdf.jdate_words.text.799e0eab15' => 'خر',
        'jdf.jdate_words.text.089d5e89d0' => 'تی‍',
        'jdf.jdate_words.text.ae392b88fc' => 'مر',
        'jdf.jdate_words.text.0912ebf6ec' => 'شه‍',
        'jdf.jdate_words.text.a8e9299515' => 'مه‍',
        'jdf.jdate_words.text.569d81c129' => 'آب‍',
        'jdf.jdate_words.text.5d5caabe1a' => 'آذ',
        'jdf.jdate_words.text.d43f14f514' => 'به‍',
        'jdf.jdate_words.text.ca47cec5c4' => 'اس‍',
        'jdf.jdate_words.text.65fe35f471' => 'ی',
        'jdf.jdate_words.text.b15aa8e25e' => 'د',
        'jdf.jdate_words.text.861183a44b' => 'س',
        'jdf.jdate_words.text.154ec3b687' => 'چ',
        'jdf.jdate_words.text.7078edd9b1' => 'پ',
        'jdf.jdate_words.text.51c9a584ad' => 'ج',
        'jdf.jdate_words.text.119c5451ad' => 'ش',
    ],

    // ── منوها و گزارش‌های مدیریت ──
    'admin_settings' => [
        // config.check
        'config.check.you_do_not_have_access.816f78fb2c' => 'شما به این بخش دسترسی ندارید.',
        // config.get_main_keys
        'config.get_main_keys.button.b7d6aa7173' => '🔰 دریافت پنل همکاری',
        // config.get_gate_ways_keys
        'config.get_gate_ways_keys.text.2cc98f7f7c' => ' تومان',
        // config.get_callback_from_command
        'config.get_callback_from_command.message.616c172303' => '⛔️ سیستم فروش غیرفعال است.',
        'config.get_callback_from_command.message.ce7759512b' => '⛔️ لطفا از دکمه خرید تکی یا خرید انبوه استفاده کنید.',
        // config.get_order_detail_keys
        'config.get_order_detail_keys.alert.2871efbc4d' => '❌ سفارش مورد نظر یافت نشد.',
        'config.get_order_detail_keys.alert.8338c921ca' => '⚠️ امکان فعالسازی تمدید خودکار برای این نوع اشتراک وجود ندارد.',
        'config.get_order_detail_keys.alert.1bd8f019af' => '⚠️ شما اجازه تغییر این سفارش را ندارید.',
        'config.get_order_detail_keys.alert.7e78156592' => '⚠️ دسترسی ادمین ندارید.',
        'config.get_order_detail_keys.alert.fb223619e1' => '⚠️ پلن این سفارش مشخص نیست؛ برای بررسی با پشتیبانی تماس بگیرید.',
        'config.get_order_detail_keys.alert.4f0ac830c9' => '⚠️ پلن مربوط به این سفارش پیدا نشد؛ برای بررسی با پشتیبانی تماس بگیرید.',
        'config.get_order_detail_keys.alert.50f82fc4e9' => '⚠️ خطایی در بروزرسانی صفحه رخ داد. دوباره امتحان کنید.',
        'config.get_order_detail_keys.alert.d0438c36e0' => ['text' => '🔄 وضعیت تمدید خودکار سفارش «{order_remark}» به حالت ', 'parameters' => ['order_remark']],
        'config.get_order_detail_keys.alert.be9fd3bd1b' => 'روشن',
        'config.get_order_detail_keys.alert.841b8cb341' => 'خاموش',
        'config.get_order_detail_keys.alert.c826d2d1f7' => ' تغییر کرد.',
    ],

    // ── پیام‌های زمان‌بندی‌شده ──
    'jobs' => [
        // gift2all.gift_text
        'gift2all.gift_text.8f714dee13' => ['text' => '{volume} مگابایت حجم', 'parameters' => ['volume']],
        // gift2all.text
        'gift2all.text.1a51e219ec' => ' و ',
        'gift2all.text.b70363d685' => ['text' => ' {day} روز زمان', 'parameters' => ['day']],
        // gift2all.message
        'gift2all.message.1bf0942a3d' => ['text' => '🎁 به اشتراک {remark} شما {giftText} اضافه شد', 'parameters' => ['remark', 'giftText']],
        // sub_link.method_not_allowed
        'sub_link.method_not_allowed.371b26886b' => 'این روش درخواست مجاز نیست.',
        // sub_link.wrong_token
        'sub_link.wrong_token.9730d4c268' => 'توکن معتبر نیست.',
        // sub_link.legacy_subscription_link_retired_use
        'sub_link.legacy_subscription_link_retired_use.6e4eb03e96' => 'این لینک قدیمی دیگر فعال نیست؛ از لینک فعلی اشتراک استفاده کنید.',
    ],
    // متن‌ها و نشانی‌های قابل تنظیم هر ربات
    'instance_content' => [
        'unconfigured' => ['text' => '📝 متن این بخش هنوز تنظیم نشده است.' . "\n" . 'مدیر ربات می‌تواند آن را از «تنظیمات ربات ← متن‌های ربات» تنظیم کند.' . "\n" . '🎫 شناسه عددی: {userId}', 'parameters' => ['userId']],
        'help_button' => '📚 کانال آموزش و راهنما',
    ],
];
