<?php
// Постоянный процесс бота для VPS (long polling, мгновенные ответы).
// Запускается как служба systemd: php bot-daemon.php
declare(strict_types=1);

require __DIR__ . '/bot-core.php';

@set_time_limit(0);
if ($GLOBALS['BOT_TOKEN'] === '') { fwrite(STDERR, "Нет токена в bot-config.php\n"); exit(1); }

// --- единственный экземпляр: блокировка в стабильной папке (не /tmp) ---
// Если демон уже запущен — второй сразу выходит, чтобы два процесса не воровали
// апдейты друг у друга (это и есть 409 Conflict = главная причина "то живой, то мёртвый").
$lockPath = data_dir() . '/daemon.lock';
$lockFp = fopen($lockPath, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Демон уже запущен (lock занят) — выходим.\n");
    exit(0);
}

// --- снести возможный вебхук: иначе getUpdates вечно отдаёт 409 ---
tg('deleteWebhook', ['drop_pending_updates' => false]);

// --- телеметрия для диагностики без SSH (команда /health) ---
$bootFile = data_dir() . '/boots.txt';
$boots = (is_file($bootFile) ? (int) file_get_contents($bootFile) : 0) + 1;
@file_put_contents($bootFile, (string) $boots);
@file_put_contents(data_dir() . '/started_at.txt', (string) time());

$offFile = __DIR__ . '/bot_offset.txt';
$offset  = is_file($offFile) ? (int) file_get_contents($offFile) : 0;

fwrite(STDERR, "Ziptron bot daemon started (boot #{$boots}, offset {$offset})\n");

while (true) {
    try {
        @file_put_contents(data_dir() . '/heartbeat.txt', (string) time());
        $resp = tg_get_updates($offset, 30);   // long-poll 30 сек — ответ приходит мгновенно при сообщении
        if (is_array($resp) && !empty($resp['ok'])) {
            foreach (($resp['result'] ?? []) as $upd) {
                try { handle_update($upd); }
                catch (\Throwable $e) { error_log('[daemon] handle: ' . $e->getMessage()); }
                $offset = ((int) $upd['update_id']) + 1;
            }
            @file_put_contents($offFile, (string) $offset);
        } else {
            // 409 / 429 / сеть — пауза (с учётом retry_after от Telegram), чтобы не молотить API
            $wait = 3;
            if (is_array($resp) && isset($resp['parameters']['retry_after'])) {
                $wait = max(3, (int) $resp['parameters']['retry_after']);
            }
            sleep($wait);
        }
    } catch (\Throwable $e) {
        // никакая ошибка не должна убивать процесс
        error_log('[daemon] loop: ' . $e->getMessage());
        sleep(3);
    }
}
