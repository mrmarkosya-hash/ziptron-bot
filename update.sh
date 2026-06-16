#!/bin/sh
cd /opt/ziptronbot || exit 0
git fetch -q origin main
if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
  git reset --hard origin/main
  systemctl restart ziptronbot
fi
