<?php
// Постоянный процесс бота для VPS (long polling, мгновенные ответы).
// Запускается как служба systemd: php bot-daemon.php
declare(strict_types=1);

require __DIR__ . '/bot-core.php';

@set_time_limit(0);
if ($GLOBALS['BOT_TOKEN'] === '') { fwrite(STDERR, "Нет токена в bot-config.php\n"); exit(1); }

$offFile = __DIR__ . '/bot_offset.txt';
$offset  = is_file($offFile) ? (int) file_get_contents($offFile) : 0;

fwrite(STDERR, "Ziptron bot daemon started (offset {$offset})\n");

while (true) {
    $resp = tg_get_updates($offset, 30);   // long-poll 30 сек — ответ приходит мгновенно при сообщении
    if (is_array($resp) && !empty($resp['ok']) && !empty($resp['result'])) {
        foreach ($resp['result'] as $upd) {
            try { handle_update($upd); }
            catch (\Throwable $e) { error_log('[daemon] ' . $e->getMessage()); }
            $offset = ((int) $upd['update_id']) + 1;
        }
        @file_put_contents($offFile, (string) $offset);
    } elseif (!is_array($resp)) {
        // сеть/ошибка — подождём и повторим
        sleep(3);
    }
}
