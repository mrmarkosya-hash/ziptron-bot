#!/bin/sh
cd /opt/ziptronbot || exit 0
git fetch -q origin main
if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
  git reset --hard origin/main
  cp /opt/ziptronbot/ziptronbot.service /etc/systemd/system/ziptronbot.service
  systemctl daemon-reload
  systemctl restart ziptronbot
fi
# подстраховка: если демон не запущен — поднять
systemctl is-active --quiet ziptronbot || systemctl start ziptronbot
