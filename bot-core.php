<?php
// Общий «движок» бота @ziptron_rent_bot: конфиг + функции + обработчик апдейта.
// Используется и вебхуком (bot.php), и опросом (bot-poll.php).
declare(strict_types=1);

$botCfg = __DIR__ . '/bot-config.php';
if (is_file($botCfg)) { require_once $botCfg; }
$siteCfg = __DIR__ . '/telegram-config.php';   // отсюда берём вебхук Битрикса (как у формы сайта)
if (is_file($siteCfg)) { require_once $siteCfg; }

$GLOBALS['BOT_TOKEN']    = defined('ZIPTRON_BOT_TOKEN') ? ZIPTRON_BOT_TOKEN : '';
$GLOBALS['BITRIX']       = defined('BITRIX_WEBHOOK_URL') ? rtrim((string) BITRIX_WEBHOOK_URL, '/') : '';
$GLOBALS['SUPPORT_URL']  = defined('ZIPTRON_SUPPORT_URL') ? ZIPTRON_SUPPORT_URL : 'https://t.me/ziptron_support_bot';
$GLOBALS['SETUP_SECRET'] = defined('ZIPTRON_BOT_SETUP_SECRET') ? ZIPTRON_BOT_SETUP_SECRET : '';
$GLOBALS['API']          = "https://api.telegram.org/bot{$GLOBALS['BOT_TOKEN']}";

// --- низкоуровневый вызов Telegram API ---
function api_call(string $method, array $params) {
    $ch = curl_init("{$GLOBALS['API']}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    return $res;
}
// getUpdates (для режима опроса)
function tg_get_updates(int $offset, int $timeout) {
    $url = "{$GLOBALS['API']}/getUpdates?timeout={$timeout}&offset={$offset}&allowed_updates=" . urlencode('["message","callback_query"]');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout + 15]);
    $res = curl_exec($ch);
    return json_decode((string) $res, true);
}

function send_menu($chat_id, string $text, array $inline) {
    return api_call('sendMessage', [
        'chat_id'      => $chat_id,
        'text'         => $text,
        'parse_mode'   => 'HTML',
        'reply_markup' => ['inline_keyboard' => $inline],
    ]);
}
function send_contact($chat_id, string $text) {
    return api_call('sendMessage', [
        'chat_id'      => $chat_id,
        'text'         => $text,
        'parse_mode'   => 'HTML',
        'reply_markup' => [
            'keyboard'          => [[['text' => '📱 Отправить мой номер', 'request_contact' => true]]],
            'resize_keyboard'   => true,
            'one_time_keyboard' => true,
        ],
    ]);
}

// --- состояние диалога (файлы во временной папке) ---
function state_file($chat_id): string {
    $d = sys_get_temp_dir() . '/ziptron_bot_state';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (!is_dir($d) || !is_writable($d)) { $d = __DIR__ . '/bot_data'; if (!is_dir($d)) { @mkdir($d, 0775, true); } }
    return $d . '/' . preg_replace('/\D/', '', (string) $chat_id) . '.txt';
}
function get_state($chat_id): string { $f = state_file($chat_id); return is_file($f) ? trim((string) file_get_contents($f)) : ''; }
function set_state($chat_id, string $s): void { @file_put_contents(state_file($chat_id), $s); }
function clear_state($chat_id): void { @unlink(state_file($chat_id)); }

function main_menu(): array {
    return [
        [['text' => '🔥 Горячая линия',     'callback_data' => 'hotline']],
        [['text' => '🛠 Техподдержка',       'callback_data' => 'support']],
        [['text' => '📝 Заявка на аренду',   'callback_data' => 'rent']],
        [['text' => '⚖️ Юридическая помощь', 'callback_data' => 'legal']],
    ];
}
function support_menu(): array {
    return [
        [['text' => '⚠️ Поломка в дороге',  'callback_data' => 'breakdown']],
        [['text' => '🔧 Записаться на ТО',   'callback_data' => 'service']],
        [['text' => '⬅️ Назад',              'callback_data' => 'back']],
    ];
}

function create_lead(string $title, string $name, string $comment, string $phone = ''): void {
    $BITRIX = $GLOBALS['BITRIX'];
    if ($BITRIX === '') { error_log('[bot] BITRIX_WEBHOOK_URL не задан'); return; }
    $fields = [
        'TITLE'              => $title,
        'NAME'               => $name !== '' ? $name : 'Клиент из Telegram',
        'SOURCE_ID'          => 'TELEGRAM',
        'SOURCE_DESCRIPTION' => 'Бот @ziptron_rent_bot',
        'COMMENTS'           => $comment,
        'OPENED'             => 'Y',
    ];
    if ($phone !== '') { $fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'MOBILE']]; }
    $ch = curl_init("{$BITRIX}/crm.lead.add.json");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['fields' => $fields, 'params' => ['REGISTER_SONET_EVENT' => 'Y']], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code < 200 || $code >= 300) { error_log("[bot] Bitrix lead error {$code}: {$body}"); }
}

// ================= обработка одного апдейта =================
function handle_update(array $update): void {
    $SUPPORT_URL = $GLOBALS['SUPPORT_URL'];

    // ----- нажатие кнопки -----
    if (isset($update['callback_query'])) {
        $cq      = $update['callback_query'];
        $chat_id = $cq['message']['chat']['id'];
        $data    = $cq['data'] ?? '';
        api_call('answerCallbackQuery', ['callback_query_id' => $cq['id']]);

        switch ($data) {
            case 'hotline':
                send_menu($chat_id, "Нажмите кнопку ниже — откроется чат с оператором, ответим вживую 👇", [
                    [['text' => '💬 Написать оператору', 'url' => $SUPPORT_URL]],
                    [['text' => '⬅️ В меню', 'callback_data' => 'back']],
                ]);
                break;
            case 'support':
                send_menu($chat_id, "🛠 <b>Техподдержка</b>\nЧто случилось? Выберите 👇", support_menu());
                break;
            case 'breakdown':
                set_state($chat_id, 'await_breakdown');
                send_menu($chat_id, "⚠️ Опишите, пожалуйста, одним сообщением:\n① что случилось\n② где вы (адрес/район)\n\nСразу передам сотрудникам — это срочно.", [[['text' => '⬅️ Назад', 'callback_data' => 'back']]]);
                break;
            case 'service':
                set_state($chat_id, 'await_service');
                send_contact($chat_id, "🔧 <b>Запись на ТО</b>\nОтправьте номер кнопкой ниже (или впишите вручную) и удобные дату/время — мастер свяжется и подтвердит.");
                break;
            case 'rent':
                set_state($chat_id, 'await_rent');
                send_contact($chat_id, "📝 Отлично! Оставьте номер — нажмите кнопку ниже или впишите вручную. Менеджер свяжется и подберёт байк. 📞");
                break;
            case 'legal':
                set_state($chat_id, 'await_legal');
                send_menu($chat_id, "⚖️ Опишите кратко ваш вопрос — передадим юристу, он свяжется с вами.", [[['text' => '⬅️ Назад', 'callback_data' => 'back']]]);
                break;
            case 'back':
            default:
                clear_state($chat_id);
                send_menu($chat_id, "Чем помочь? Выберите 👇", main_menu());
                break;
        }
        return;
    }

    // ----- сообщение -----
    if (isset($update['message'])) {
        $m       = $update['message'];
        $chat_id = $m['chat']['id'];
        $text    = trim((string) ($m['text'] ?? ''));
        $name    = trim(((string) ($m['from']['first_name'] ?? '')) . ' ' . ((string) ($m['from']['last_name'] ?? '')));
        $phone   = isset($m['contact']['phone_number']) ? (string) $m['contact']['phone_number'] : '';

        if ($text === '/chatid') {
            api_call('sendMessage', ['chat_id' => $chat_id, 'text' => "ID этого чата: <code>{$chat_id}</code>", 'parse_mode' => 'HTML']);
            return;
        }

        if ($text === '/start') {
            clear_state($chat_id);
            send_menu($chat_id, "Здравствуйте! 👋 Это <b>Ziptron</b> — аренда грузовых электробайков.\nЧем помочь? Выберите кнопку ниже 👇", main_menu());
            return;
        }

        $state = get_state($chat_id);
        if ($state !== '') {
            $maybePhone = $phone;
            if ($maybePhone === '' && preg_match('/[\d\+][\d\-\s\(\)]{9,}/', $text, $mm)) { $maybePhone = $mm[0]; }
            $detail = $text !== '' ? $text : ('Телефон: ' . $maybePhone);

            if ($state === 'await_breakdown') {
                create_lead("🔥 ПОЛОМКА в дороге — {$name}", $name, "СРОЧНО, поломка:\n{$detail}", $maybePhone);
                api_call('sendMessage', ['chat_id' => $chat_id, 'text' => "Принято! ⚡ Передал сотрудникам — с вами свяжутся как можно скорее.", 'reply_markup' => ['remove_keyboard' => true]]);
            } elseif ($state === 'await_service') {
                create_lead("🔧 Запись на ТО — {$name}", $name, "Запись на ТО:\n{$detail}", $maybePhone);
                api_call('sendMessage', ['chat_id' => $chat_id, 'text' => "Спасибо! 🔧 Заявка на ТО принята — мастер свяжется и подтвердит время.", 'reply_markup' => ['remove_keyboard' => true]]);
            } elseif ($state === 'await_rent') {
                create_lead("📝 Аренда (бот) — {$name}", $name, "Заявка на аренду:\n{$detail}", $maybePhone);
                api_call('sendMessage', ['chat_id' => $chat_id, 'text' => "Спасибо! 📞 Менеджер скоро свяжется и подберёт байк.", 'reply_markup' => ['remove_keyboard' => true]]);
            } elseif ($state === 'await_legal') {
                create_lead("⚖️ Юр. вопрос — {$name}", $name, "Юридический вопрос:\n{$detail}", $maybePhone);
                api_call('sendMessage', ['chat_id' => $chat_id, 'text' => "Спасибо! ⚖️ Передал юристу — с вами свяжутся.", 'reply_markup' => ['remove_keyboard' => true]]);
            }
            clear_state($chat_id);
            send_menu($chat_id, "Чем ещё помочь? 👇", main_menu());
            return;
        }

        // любое другое сообщение → показать меню
        send_menu($chat_id, "Здравствуйте! 👋 Выберите, чем помочь 👇", main_menu());
        return;
    }
}
