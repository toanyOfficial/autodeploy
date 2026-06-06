#!/usr/bin/env bash
set -Eeuo pipefail

LOG_DIR="/var/log/auto_deploy"
LOG_FILE="${LOG_DIR}/reboot-deploy.log"
AUTO_DEPLOY_DIR="/srv/auto_deploy"
POST_REBOOT_SERVICE="dandorak-post-reboot.service"
AUTO_DEPLOY_URL="http://127.0.0.1:9090/login"

mkdir -p "${LOG_DIR}"
touch "${LOG_FILE}"
chmod 0755 "${LOG_DIR}"
chmod 0664 "${LOG_FILE}"
chown appuser:appuser "${LOG_DIR}" "${LOG_FILE}" 2>/dev/null || true

exec >> "${LOG_FILE}" 2>&1

log() {
  echo "[$(date -Is)] $*"
}

cleanup() {
  local exit_code=$?
  if [ "${exit_code}" -eq 0 ]; then
    log "post-reboot 작업이 완료되어 ${POST_REBOOT_SERVICE}를 disable 합니다."
    systemctl disable "${POST_REBOOT_SERVICE}" || true
  else
    log "post-reboot 작업이 실패했습니다. 원인 확인을 위해 ${POST_REBOOT_SERVICE} enable 상태를 유지합니다. exit_code=${exit_code}"
  fi
}
trap cleanup EXIT

log "DB 시작 스크립트를 실행합니다."
/srv/dandorak/start-database.sh

db_ready() {
  sudo -u appuser -H bash -lc "cd '${AUTO_DEPLOY_DIR}' && php -r 'require \"app/Core/Autoloader.php\"; App\Config\Env::load(\".env\"); App\Config\Database::connection()->query(\"SELECT 1\");' >/dev/null 2>&1"
}

log "DB 준비 상태를 대기합니다."
for attempt in $(seq 1 60); do
  if db_ready; then
    log "DB가 준비되었습니다. attempt=${attempt}"
    break
  fi

  if [ "${attempt}" -eq 60 ]; then
    log "DB 준비 대기 시간이 초과되었습니다."
    exit 1
  fi

  sleep 2
done

log "Auto Deploy를 appuser 권한으로 실행합니다."
sudo -u appuser -H bash -lc '
cd /srv/auto_deploy
nohup php -S 0.0.0.0:9090 -t public > app.log 2>&1 &
'

log "Auto Deploy 준비 상태를 대기합니다."
for attempt in $(seq 1 60); do
  if curl -fsS --max-time 2 "${AUTO_DEPLOY_URL}" >/dev/null 2>&1; then
    log "Auto Deploy가 준비되었습니다. attempt=${attempt}"
    break
  fi

  if [ "${attempt}" -eq 60 ]; then
    log "Auto Deploy 준비 대기 시간이 초과되었습니다."
    exit 1
  fi

  sleep 2
done

log "전체 활성 프로젝트 안정화버전 배포 CLI를 appuser 권한으로 실행합니다."
sudo -u appuser -H bash -lc "cd '${AUTO_DEPLOY_DIR}' && php scripts/deploy_all_stable.php"

log "Caddy 설정을 검증합니다."
caddy validate

log "Caddy를 reload 합니다."
systemctl reload caddy

log "서버 재부팅 + 기본설정 + 전체 안정화버전 자동배포 작업이 완료되었습니다."
