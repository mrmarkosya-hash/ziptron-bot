#!/bin/sh
# Автодеплой: тянет свежий код из GitHub и перезапускает бота при изменениях.
# Запускается systemd-таймером ziptronupdate.timer каждые 2 минуты.
LOG=/var/log/ziptron-update.log
echo "--- $(date '+%F %T') update.sh ---" >> "$LOG"
cd /opt/ziptronbot || { echo "нет /opt/ziptronbot" >> "$LOG"; exit 0; }

# чтобы git не ругался на 'dubious ownership' при запуске от root
git config --global --add safe.directory /opt/ziptronbot 2>/dev/null

git fetch -q origin main 2>>"$LOG"
LOCAL=$(git rev-parse HEAD 2>/dev/null)
REMOTE=$(git rev-parse origin/main 2>/dev/null)
echo "local=$LOCAL remote=$REMOTE" >> "$LOG"

if [ "$LOCAL" != "$REMOTE" ]; then
  echo "обновление кода -> перезапуск" >> "$LOG"
  git reset --hard origin/main >>"$LOG" 2>&1
  cp /opt/ziptronbot/ziptronbot.service /etc/systemd/system/ziptronbot.service
  cp /opt/ziptronbot/ziptronupdate.service /etc/systemd/system/ziptronupdate.service 2>/dev/null
  cp /opt/ziptronbot/ziptronupdate.timer   /etc/systemd/system/ziptronupdate.timer   2>/dev/null
  systemctl daemon-reload
  systemctl restart ziptronbot
fi

# подстраховка: если демон не запущен — поднять
systemctl is-active --quiet ziptronbot || systemctl start ziptronbot
