<?php
// Ядро бота @ziptron_rent_bot — служба поддержки (Этап 1).
// Аренда → лид в Битрикс. Поддержка/менеджер/юр → пересылка в админ-группу (двусторонний чат). Рабочие часы.
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

$GLOBALS['BOT_TOKEN'] = defined('ZIPTRON_BOT_TOKEN') ? ZIPTRON_BOT_TOKEN : '';
$GLOBALS['BITRIX']    = defined('BITRIX_WEBHOOK_URL') ? rtrim((string) BITRIX_WEBHOOK_URL, '/') : '';
$GLOBALS['API']       = "https://api.telegram.org/bot{$GLOBALS['BOT_TOKEN']}";

// ---------- Telegram API ----------
function tg(string $method, array $params): array {
    $ch = curl_init("{$GLOBALS['API']}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
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

// ---------- состояние и связки (файлы) ----------
function data_dir(): string { $d = __DIR__ . '/bot_data'; if (!is_dir($d)) { @mkdir($d, 0775, true); } return $d; }
function k($id): string { return preg_replace('/[^0-9-]/', '', (string) $id); }
function get_state($id): string { $f = data_dir() . '/state_' . k($id) . '.txt'; return is_file($f) ? trim((string) file_get_contents($f)) : ''; }
function set_state($id, string $s): void { @file_put_contents(data_dir() . '/state_' . k($id) . '.txt', $s); }
function clear_state($id): void { @unlink(data_dir() . '/state_' . k($id) . '.txt'); }
// связка: id сообщения в группе -> chat_id клиента
function relay_save(int $gmsg, $client): void { @file_put_contents(data_dir() . "/relay_{$gmsg}.txt", (string) $client); }
function relay_lookup(int $gmsg): string { $f = data_dir() . "/relay_{$gmsg}.txt"; return is_file($f) ? trim((string) file_get_contents($f)) : ''; }

function in_work_hours(): bool {
    try {
        $now = new DateTime('now', new DateTimeZone($GLOBALS['TZ']));
        $h = (int) $now->format('G');
        return $h >= $GLOBALS['WORK_START'] && $h < $GLOBALS['WORK_END'];
    } catch (\Throwable $e) { return true; }
}

function main_menu(): array {
    return [
        [['text' => '🛵 Оформить аренду',        'callback_data' => 'rent']],
        [['text' => '🛠 Техподдержка',            'callback_data' => 'support']],
        [['text' => '📞 Связаться с менеджером',  'callback_data' => 'hotline']],
        [['text' => '⚖️ Юридический вопрос',      'callback_data' => 'legal']],
    ];
}
function city_kb(string $topic): array {
    return [
        [['text' => 'Москва', 'callback_data' => "city:{$topic}:Москва"], ['text' => 'Ростов-на-Дону', 'callback_data' => "city:{$topic}:Ростов-на-Дону"]],
        [['text' => '⬅️ В меню', 'callback_data' => 'back']],
    ];
}
function topic_label(string $t): string {
    $m = ['breakdown' => '⚠️ Поломка в дороге', 'service' => '🔧 Запись на ТО', 'manager' => '📞 Менеджер', 'legal' => '⚖️ Юр. вопрос'];
    return $m[$t] ?? $t;
}

function create_lead(string $title, string $name, string $comment, string $phone = ''): void {
    $BITRIX = $GLOBALS['BITRIX'];
    if ($BITRIX === '') { error_log('[bot] BITRIX_WEBHOOK_URL не задан'); return; }
    $fields = ['TITLE' => $title, 'NAME' => $name !== '' ? $name : 'Клиент из Telegram',
        'SOURCE_ID' => 'TELEGRAM', 'SOURCE_DESCRIPTION' => 'Бот @ziptron_rent_bot', 'COMMENTS' => $comment, 'OPENED' => 'Y'];
    if ($phone !== '') { $fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'MOBILE']]; }
    $ch = curl_init("{$BITRIX}/crm.lead.add.json");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['fields' => $fields, 'params' => ['REGISTER_SONET_EVENT' => 'Y']], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code < 200 || $code >= 300) { error_log("[bot] Bitrix lead error {$code}: {$body}"); }
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
            set_state($chat, 'rent');
            send_msg($chat, "🛵 <b>Оформить аренду</b>\nНапишите, пожалуйста, ваш номер телефона — менеджер свяжется и подберёт байк. 📞");
            return;
        }
        if ($data === 'support') {
            send_msg($chat, "🛠 <b>Техподдержка</b>\nЧто случилось?", [
                [['text' => '⚠️ Поломка в дороге', 'callback_data' => 't:breakdown']],
                [['text' => '🔧 Записаться на ТО',  'callback_data' => 't:service']],
                [['text' => '⬅️ В меню',            'callback_data' => 'back']],
            ]);
            return;
        }
        if ($data === 'hotline') { send_msg($chat, "📞 <b>Связь с менеджером</b>\nВыберите ваш город:", city_kb('manager')); return; }
        if ($data === 'legal')   { send_msg($chat, "⚖️ <b>Юридический вопрос</b>\nВыберите ваш город:", city_kb('legal')); return; }
        if (strpos($data, 't:') === 0) { $topic = substr($data, 2); send_msg($chat, topic_label($topic) . "\nВыберите ваш город:", city_kb($topic)); return; }

        if (strpos($data, 'city:') === 0) {
            $parts = explode(':', $data, 3);
            $topic = $parts[1] ?? 'manager';
            $city  = $parts[2] ?? '';
            set_state($chat, "support|{$topic}|{$city}");
            if (in_work_hours()) {
                send_msg($chat, "Спасибо, что обратились! 👋 Менеджер сейчас подключится.\nПока опишите, пожалуйста, что у вас случилось — пишите прямо сюда ✍️");
            } else {
                $ws = $GLOBALS['WORK_START']; $we = $GLOBALS['WORK_END'];
                send_msg($chat, "Извините за ожидание 🙏 Мы работаем с {$ws}:00 до {$we}:00.\nВаше обращение уже передано — менеджер свяжется с вами завтра с {$ws}:00 утра. Постараемся решить ваш вопрос максимально оперативно. Спасибо за понимание! 🙌\n\nМожете уже описать вопрос — он будет ждать менеджера.");
            }
            if ($AG) {
                $moon = in_work_hours() ? '' : ' 🌙 вне часов';
                tg('sendMessage', ['chat_id' => $AG, 'parse_mode' => 'HTML',
                    'text' => "🆕 <b>Новое обращение</b>{$moon}\n" . topic_label($topic) . " · 🏙 " . esc($city) . "\n👤 " . esc($name) . "\n\nКлиент сейчас опишет вопрос — отвечайте <b>reply</b> на его сообщения 👇"]);
            }
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

        if ($text === '/chatid') {
            tg('sendMessage', ['chat_id' => $chat, 'text' => "ID этого чата: <code>{$chat}</code>", 'parse_mode' => 'HTML']);
            return;
        }

        // --- сообщения в админ-группе: ответ админа (reply) -> клиенту ---
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

        // --- личный чат клиента ---
        if ($text === '/start') {
            clear_state($chat);
            send_msg($chat, "Здравствуйте! 👋 Это <b>Ziptron</b> — аренда грузовых электробайков.\nЧем помочь? Выберите кнопку ниже 👇", main_menu());
            return;
        }

        $st = get_state($chat);

        if ($st === 'rent') {
            $phone = isset($m['contact']['phone_number']) ? (string) $m['contact']['phone_number'] : '';
            if ($phone === '' && preg_match('/[\d\+][\d\-\s\(\)]{9,}/', $text, $mm)) { $phone = $mm[0]; }
            create_lead("📝 Аренда (бот) — {$name}", $name, "Заявка на аренду из бота:\n" . ($text !== '' ? $text : $phone), $phone);
            clear_state($chat);
            send_msg($chat, "Спасибо! 📞 Заявка принята — менеджер скоро свяжется и подберёт байк.", main_menu());
            return;
        }

        if (strpos($st, 'support|') === 0) {
            $parts = explode('|', $st, 3);
            $topic = $parts[1] ?? 'manager';
            $city  = $parts[2] ?? '';
            if ($AG && $text !== '') {
                $r = tg('sendMessage', ['chat_id' => $AG, 'parse_mode' => 'HTML',
                    'text' => "👤 <b>" . esc($name) . "</b> · " . topic_label($topic) . " · 🏙 " . esc($city) . "\n\n" . esc($text) . "\n\n↩️ <i>Reply — ответить клиенту</i>"]);
                $gmsg = $r['result']['message_id'] ?? 0;
                if ($gmsg) { relay_save((int) $gmsg, $chat); }
            }
            return;
        }

        send_msg($chat, "Здравствуйте! 👋 Выберите, чем помочь 👇", main_menu());
        return;
    }
}
