#!/usr/bin/env bash
set -Eeuo pipefail

LOG_DIR="/var/log/auto_deploy"
LOG_FILE="${LOG_DIR}/reboot-deploy.log"
POST_REBOOT_SERVICE="dandorak-post-reboot.service"

mkdir -p "${LOG_DIR}"
touch "${LOG_FILE}"
chmod 0755 "${LOG_DIR}"
chmod 0644 "${LOG_FILE}"

exec >> "${LOG_FILE}" 2>&1

echo "[$(date -Is)] 서버 재부팅 + 기본설정 자동화를 예약합니다."
echo "[$(date -Is)] systemd daemon-reload를 실행합니다."
systemctl daemon-reload

echo "[$(date -Is)] ${POST_REBOOT_SERVICE}를 enable 합니다."
systemctl enable "${POST_REBOOT_SERVICE}"

echo "[$(date -Is)] 서버를 재부팅합니다."
systemctl reboot
