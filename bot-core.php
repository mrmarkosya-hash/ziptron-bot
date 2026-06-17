<?php
// Ядро бота @ziptron_rent_bot — служба поддержки.
// Аренда → лид в Битрикс. Поломка/ДТП/Менеджер/Юр → город → пересылка в админ-группу (двусторонний чат + фото/видео). Рабочие часы.
declare(strict_types=1);

$botCfg = __DIR__ . '/bot-config.php';
if (is_file($botCfg)) { require_once $botCfg; }
$siteCfg = __DIR__ . '/telegram-config.php';
if (is_file($siteCfg)) { require_once $siteCfg; }

$S = is_file(__DIR__ . '/settings.php') ? (require __DIR__ . '/settings.php') : [];
$GLOBALS['ADMIN_GROUP'] = $S['admin_group'] ?? 0;
$GLOBALS['WORK_START']  = (int) ($S['work_start'] ?? 9);
$GLOBALS['WORK_END']    = (int) ($S['work_end'] ?? 21);
$GLOBALS['TZ']          = $S['timezone'] ?? 'Europe/Moscow';
$GLOBALS['TO_URL']      = $S['to_booking_url'] ?? '';
$GLOBALS['SUPPORT_URL'] = defined('ZIPTRON_SUPPORT_URL') ? ZIPTRON_SUPPORT_URL : 'https://t.me/ziptron_support_bot';

$GLOBALS['BOT_TOKEN'] = defined('ZIPTRON_BOT_TOKEN') ? ZIPTRON_BOT_TOKEN : '';
$GLOBALS['BITRIX']    = defined('BITRIX_WEBHOOK_URL') ? rtrim((string) BITRIX_WEBHOOK_URL, '/') : '';
$GLOBALS['API']       = "https://api.telegram.org/bot{$GLOBALS['BOT_TOKEN']}";

function tg(string $method, array $params): array {
    $ch = curl_init("{$GLOBALS['API']}/{$method}");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $r = curl_exec($ch);
    $d = json_decode((string) $r, true);
    return is_array($d) ? $d : [];
}
function tg_get_updates(int $offset, int $timeout) {
    $url = "{$GLOBALS['API']}/getUpdates?timeout={$timeout}&offset={$offset}&allowed_updates=" . urlencode('["message","callback_query"]');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout + 15]);
    return json_decode((string) curl_exec($ch), true);
}
function send_msg($chat_id, string $text, ?array $inline = null): array {
    $p = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($inline !== null) { $p['reply_markup'] = ['inline_keyboard' => $inline]; }
    return tg('sendMessage', $p);
}
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function data_dir(): string { $d = __DIR__ . '/bot_data'; if (!is_dir($d)) { @mkdir($d, 0775, true); } return $d; }
function kf($id): string { return preg_replace('/[^0-9-]/', '', (string) $id); }
function get_state($id): string { $f = data_dir() . '/state_' . kf($id) . '.txt'; return is_file($f) ? trim((string) file_get_contents($f)) : ''; }
function set_state($id, string $s): void { @file_put_contents(data_dir() . '/state_' . kf($id) . '.txt', $s); }
function clear_state($id): void { @unlink(data_dir() . '/state_' . kf($id) . '.txt'); }
function relay_save(int $gmsg, $client): void { @file_put_contents(data_dir() . "/relay_{$gmsg}.txt", (string) $client); }
function relay_lookup(int $gmsg): string { $f = data_dir() . "/relay_{$gmsg}.txt"; return is_file($f) ? trim((string) file_get_contents($f)) : ''; }

function in_work_hours(): bool {
    try { $now = new DateTime('now', new DateTimeZone($GLOBALS['TZ'])); $h = (int) $now->format('G'); return $h >= $GLOBALS['WORK_START'] && $h < $GLOBALS['WORK_END']; }
    catch (\Throwable $e) { return true; }
}

function main_menu(): array {
    return [
        [['text' => '🛵 Оформить аренду',   'callback_data' => 'rent']],
        [['text' => '🛠 Техподдержка',       'callback_data' => 'support']],
        [['text' => '⚖️ Юридический вопрос', 'callback_data' => 'legal']],
    ];
}
function support_menu(): array {
    return [
        [['text' => '⚠️ Поломка',          'callback_data' => 't:breakdown']],
        [['text' => '🚗 ДТП',              'callback_data' => 't:dtp']],
        [['text' => '🔧 Записаться на ТО',  'callback_data' => 'to']],
        [['text' => '⬅️ В меню',           'callback_data' => 'back']],
    ];
}
function city_kb(string $topic): array {
    return [
        [['text' => 'Москва', 'callback_data' => "city:{$topic}:Москва"], ['text' => 'Ростов-на-Дону', 'callback_data' => "city:{$topic}:Ростов-на-Дону"]],
        [['text' => '⬅️ В меню', 'callback_data' => 'back']],
    ];
}
function topic_label(string $t): string {
    $m = ['breakdown' => '⚠️ Поломка', 'dtp' => '🚗 ДТП', 'manager' => '📞 Менеджер', 'legal' => '⚖️ Юр. вопрос'];
    return $m[$t] ?? $t;
}
function to_button(): array { return [['text' => '🔧 Записаться на ТО', 'callback_data' => 'to']]; }

function create_lead(string $title, string $name, string $comment, string $phone = ''): void {
    $BITRIX = $GLOBALS['BITRIX'];
    if ($BITRIX === '') { error_log('[bot] BITRIX_WEBHOOK_URL не задан'); return; }
    $fields = ['TITLE' => $title, 'NAME' => $name !== '' ? $name : 'Клиент из Telegram', 'SOURCE_ID' => 'TELEGRAM',
        'SOURCE_DESCRIPTION' => 'Бот @ziptron_rent_bot', 'COMMENTS' => $comment, 'OPENED' => 'Y'];
    if ($phone !== '') { $fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'MOBILE']]; }
    $ch = curl_init("{$BITRIX}/crm.lead.add.json");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['fields' => $fields, 'params' => ['REGISTER_SONET_EVENT' => 'Y']], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code < 200 || $code >= 300) { error_log("[bot] Bitrix lead error {$code}: {$body}"); }
}

// переслать сообщение клиента (текст/фото/видео/док) в админ-группу + запомнить связку
function relay_to_group(array $m, string $name, string $topic, string $city, $client): void {
    $AG = $GLOBALS['ADMIN_GROUP'];
    if (!$AG) { return; }
    $head = "👤 <b>" . esc($name) . "</b> · " . topic_label($topic) . " · 🏙 " . esc($city);
    $tail = "\n\n↩️ <i>Reply — ответить клиенту</i>";
    $cap  = isset($m['caption']) ? "\n\n" . esc((string) $m['caption']) : '';
    if (isset($m['photo']) && is_array($m['photo'])) {
        $fid = end($m['photo'])['file_id'] ?? '';
        $r = tg('sendPhoto', ['chat_id' => $AG, 'photo' => $fid, 'parse_mode' => 'HTML', 'caption' => $head . $cap . $tail]);
    } elseif (isset($m['video']['file_id'])) {
        $r = tg('sendVideo', ['chat_id' => $AG, 'video' => $m['video']['file_id'], 'parse_mode' => 'HTML', 'caption' => $head . $cap . $tail]);
    } elseif (isset($m['document']['file_id'])) {
        $r = tg('sendDocument', ['chat_id' => $AG, 'document' => $m['document']['file_id'], 'parse_mode' => 'HTML', 'caption' => $head . $cap . $tail]);
    } else {
        $txt = trim((string) ($m['text'] ?? ''));
        $r = tg('sendMessage', ['chat_id' => $AG, 'parse_mode' => 'HTML', 'text' => $head . "\n\n" . esc($txt !== '' ? $txt : '(пустое сообщение)') . $tail]);
    }
    $gmsg = $r['result']['message_id'] ?? 0;
    if ($gmsg) { relay_save((int) $gmsg, $client); }
}

function parse_state(string $st): array { $p = explode('|', $st); return [$p[0] ?? '', $p[1] ?? '', $p[2] ?? '']; }

function support_greeting($chat, string $topic, string $city, string $name): void {
    $AG = $GLOBALS['ADMIN_GROUP'];
    if (in_work_hours()) {
        send_msg($chat, "Спасибо, что обратились! 👋 Менеджер сейчас подключится.\nОпишите, пожалуйста, ваш вопрос — пишите прямо сюда ✍️");
    } else {
        $ws = $GLOBALS['WORK_START'];
        send_msg($chat, "Спасибо за обращение! 🙏 Сейчас нерабочее время — мы на связи с {$ws}:00 до {$GLOBALS['WORK_END']}:00.\nВаше сообщение <b>никуда не потеряется</b> — администратор свяжется с вами завтра с {$ws}:00 утра, как только начнётся рабочий день. Можете уже описать вопрос — он зафиксирован.");
    }
    if ($AG) {
        $moon = in_work_hours() ? '' : ' 🌙 вне часов';
        tg('sendMessage', ['chat_id' => $AG, 'parse_mode' => 'HTML',
            'text' => "🆕 <b>Новое обращение</b>{$moon}\n" . topic_label($topic) . " · 🏙 " . esc($city) . "\n👤 " . esc($name) . "\n\nКлиент сейчас опишет вопрос — отвечайте <b>reply</b> на его сообщения 👇"]);
    }
}

// ================= обработка апдейта =================
function handle_update(array $u): void {
    $AG = $GLOBALS['ADMIN_GROUP'];

    // ----- кнопки -----
    if (isset($u['callback_query'])) {
        $cq = $u['callback_query'];
        $chat = $cq['message']['chat']['id'];
        $data = $cq['data'] ?? '';
        $name = trim(((string) ($cq['from']['first_name'] ?? '')) . ' ' . ((string) ($cq['from']['last_name'] ?? '')));
        tg('answerCallbackQuery', ['callback_query_id' => $cq['id']]);

        if ($data === 'rent') {
            clear_state($chat);
            $surl = $GLOBALS['SUPPORT_URL'];
            send_msg($chat, "🛵 <b>Оформить аренду</b>\nОформление аренды ведёт наш основной бот — там менеджер подберёт байк, расскажет условия и оформит аренду. Нажмите кнопку ниже 👇", [
                [['text' => '🛵 Перейти к оформлению', 'url' => $surl]],
                [['text' => '⬅️ В меню', 'callback_data' => 'back']],
            ]);
            return;
        }
        if ($data === 'support') { send_msg($chat, "🛠 <b>Техподдержка</b>\nЧто случилось?", support_menu()); return; }
        if ($data === 'legal')   { send_msg($chat, "⚖️ <b>Юридический вопрос</b>\nВыберите ваш город:", city_kb('legal')); return; }
        if ($data === 'to') {
            $url = $GLOBALS['TO_URL'];
            if ($url !== '') {
                send_msg($chat, "🔧 <b>Запись на техобслуживание</b>\nВыберите удобное время онлайн 👇", [[['text' => '📅 Записаться онлайн', 'url' => $url]], [['text' => '⬅️ В меню', 'callback_data' => 'back']]]);
            } else {
                send_msg($chat, "🔧 <b>Запись на ТО</b>\nНапишите, пожалуйста, удобную дату/время и ваш телефон — мы запишем вас и подтвердим. (Онлайн-запись скоро подключим.)", [[['text' => '⬅️ В меню', 'callback_data' => 'back']]]);
            }
            return;
        }
        if (strpos($data, 't:') === 0) { $topic = substr($data, 2); send_msg($chat, topic_label($topic) . "\nВыберите ваш город:", city_kb($topic)); return; }

        if (strpos($data, 'city:') === 0) {
            $parts = explode(':', $data, 3);
            $topic = $parts[1] ?? 'manager';
            $city  = $parts[2] ?? '';
            if ($topic === 'breakdown' || $topic === 'dtp') {
                set_state($chat, "bd_desc|{$topic}|{$city}");
                $what = $topic === 'dtp' ? 'что произошло (ДТП)' : 'что случилось с байком';
                send_msg($chat, "Опишите, пожалуйста, {$what} 👇\nПосле описания можно будет приложить фото или видео 📷");
                if ($AG) {
                    $moon = in_work_hours() ? '' : ' 🌙 вне часов';
                    tg('sendMessage', ['chat_id' => $AG, 'parse_mode' => 'HTML',
                        'text' => "🆕 <b>Новое обращение</b>{$moon}\n" . topic_label($topic) . " · 🏙 " . esc($city) . "\n👤 " . esc($name) . "\n\nКлиент сейчас опишет проблему — отвечайте <b>reply</b> 👇"]);
                }
            } else {
                set_state($chat, "relay|{$topic}|{$city}");
                support_greeting($chat, $topic, $city, $name);
            }
            return;
        }

        if ($data === 'bdphoto:yes') {
            [, $topic, $city] = parse_state(get_state($chat));
            set_state($chat, "bd_photo|{$topic}|{$city}");
            send_msg($chat, "Пришлите фото или видео 📷 (можно несколько).");
            return;
        }
        if ($data === 'bdphoto:no') {
            [, $topic, $city] = parse_state(get_state($chat));
            bd_finalize($chat, $topic, $city);
            return;
        }

        clear_state($chat);
        send_msg($chat, "Чем помочь? Выберите 👇", main_menu());
        return;
    }

    // ----- сообщения -----
    if (isset($u['message'])) {
        $m = $u['message'];
        $chat = $m['chat']['id'];
        $text = trim((string) ($m['text'] ?? ''));
        $name = trim(((string) ($m['from']['first_name'] ?? '')) . ' ' . ((string) ($m['from']['last_name'] ?? '')));

        if ($text === '/chatid') { tg('sendMessage', ['chat_id' => $chat, 'text' => "ID этого чата: <code>{$chat}</code>", 'parse_mode' => 'HTML']); return; }

        if ($text === '/health') {
            $b   = is_file(data_dir() . '/boots.txt')      ? (int) file_get_contents(data_dir() . '/boots.txt')      : 0;
            $st  = is_file(data_dir() . '/started_at.txt') ? (int) file_get_contents(data_dir() . '/started_at.txt') : 0;
            $hb  = is_file(data_dir() . '/heartbeat.txt')  ? (int) file_get_contents(data_dir() . '/heartbeat.txt')  : 0;
            $up  = $st ? (time() - $st) : -1;
            $age = $hb ? (time() - $hb) : -1;
            tg('sendMessage', ['chat_id' => $chat, 'parse_mode' => 'HTML',
                'text' => "🩺 <b>Статус бота</b>\nРаботает без перезапуска: <b>{$up} сек</b>\nВсего запусков: <b>{$b}</b>\nПоследний цикл опроса: <b>{$age} сек назад</b>"]);
            return;
        }

        // админ-группа: reply админа -> клиенту
        if ($AG && (string) $chat === (string) $AG) {
            if (isset($m['reply_to_message']) && $text !== '') {
                $client = relay_lookup((int) $m['reply_to_message']['message_id']);
                if ($client !== '') {
                    send_msg($client, "💬 <b>Менеджер:</b> " . esc($text));
                    tg('sendMessage', ['chat_id' => $AG, 'reply_to_message_id' => $m['message_id'], 'text' => '✅ отправлено клиенту']);
                }
            }
            return;
        }

        if ($text === '/start') {
            clear_state($chat);
            send_msg($chat, "Здравствуйте! 👋 Это <b>Ziptron</b> — аренда грузовых электробайков.\nЧем помочь? Выберите кнопку ниже 👇", main_menu());
            return;
        }

        [$kind, $topic, $city] = parse_state(get_state($chat));

        if ($kind === 'rent') {
            $phone = isset($m['contact']['phone_number']) ? (string) $m['contact']['phone_number'] : '';
            if ($phone === '' && preg_match('/[\d\+][\d\-\s\(\)]{9,}/', $text, $mm)) { $phone = $mm[0]; }
            create_lead("📝 Аренда (бот) — {$name}", $name, "Заявка на аренду из бота:\n" . ($text !== '' ? $text : $phone), $phone);
            clear_state($chat);
            send_msg($chat, "Спасибо! 📞 Заявка принята — менеджер скоро свяжется и поможет оформить аренду.", main_menu());
            return;
        }

        if ($kind === 'bd_desc') {
            relay_to_group($m, $name, $topic, $city, $chat);
            set_state($chat, "bd_photoq|{$topic}|{$city}");
            send_msg($chat, "Принято! Хотите приложить фото или видео поломки?", [
                [['text' => '📎 Да, приложить', 'callback_data' => 'bdphoto:yes']],
                [['text' => 'Нет, всё', 'callback_data' => 'bdphoto:no']],
            ]);
            return;
        }
        if ($kind === 'bd_photo') {
            relay_to_group($m, $name, $topic, $city, $chat);
            bd_finalize($chat, $topic, $city);
            return;
        }
        if ($kind === 'bd_photoq') {
            // клиент пишет вместо выбора кнопки — примем как доп. инфо и завершим
            relay_to_group($m, $name, $topic, $city, $chat);
            bd_finalize($chat, $topic, $city);
            return;
        }
        if ($kind === 'relay') {
            relay_to_group($m, $name, $topic, $city, $chat);
            return;
        }

        send_msg($chat, "Здравствуйте! 👋 Выберите, чем помочь 👇", main_menu());
        return;
    }
}

// завершение сценария поломки/ДТП
function bd_finalize($chat, string $topic, string $city): void {
    if (in_work_hours()) {
        $msg = "Спасибо! ✅ Информация передана менеджеру — при необходимости он подключит мастера. Пожалуйста, будьте на связи 🙌";
    } else {
        $ws = $GLOBALS['WORK_START'];
        $msg = "Спасибо! ✅ Информация сохранена. Сейчас нерабочее время — администратор свяжется с вами с {$ws}:00 утра. Будьте на связи 🙏";
    }
    set_state($chat, "relay|{$topic}|{$city}");   // дальше — обычный чат с менеджером
    send_msg($chat, $msg . "\n\nРекомендуем сразу записаться на техобслуживание 👇", [to_button(), [['text' => '⬅️ В меню', 'callback_data' => 'back']]]);
}
