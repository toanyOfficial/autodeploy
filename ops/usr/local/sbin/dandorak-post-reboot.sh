#!/usr/bin/env bash
set -Eeuo pipefail

LOG_DIR="/var/log/auto_deploy"
LOG_FILE="${LOG_DIR}/reboot-deploy.log"
STATE_DIR="/var/lib/auto_deploy"
PENDING_FILE="${STATE_DIR}/reboot-restore.pending"
LOCK_FILE="/run/dandorak_post_reboot.lock"
MAX_LOG_LINES=400
AUTO_DEPLOY_DIR="/srv/auto_deploy"
POST_REBOOT_SERVICE="dandorak-post-reboot.service"
AUTO_DEPLOY_WEB_SERVICE="auto-deploy-web.service"
AUTO_DEPLOY_URL="http://127.0.0.1:9090/login"

mkdir -p "${LOG_DIR}" "${STATE_DIR}"
touch "${LOG_FILE}"
chmod 0755 "${LOG_DIR}"
chmod 0664 "${LOG_FILE}"
chown appuser:appuser "${LOG_DIR}" "${LOG_FILE}" "${STATE_DIR}" 2>/dev/null || true

compact_log() {
  if [ -f "${LOG_FILE}" ]; then
    local temp_file
    temp_file="$(mktemp)"
    tail -n "${MAX_LOG_LINES}" "${LOG_FILE}" > "${temp_file}" 2>/dev/null || true
    cat "${temp_file}" > "${LOG_FILE}" 2>/dev/null || true
    rm -f "${temp_file}"
  fi
}

compact_log

exec >> "${LOG_FILE}" 2>&1

log() {
  echo "[$(date -Is)] $*"
}

cleanup() {
  local exit_code=$?
  log "post-reboot 작업 종료 처리: ${POST_REBOOT_SERVICE} disable/reset-failed를 실행합니다. exit_code=${exit_code}"
  systemctl disable "${POST_REBOOT_SERVICE}" || true
  systemctl reset-failed "${POST_REBOOT_SERVICE}" || true
  compact_log
}
trap cleanup EXIT

exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  log "post-reboot 작업이 이미 실행 중이므로 중복 실행을 건너뜁니다."
  exit 0
fi

if [ ! -f "${PENDING_FILE}" ]; then
  log "post-reboot 1회 실행 마커가 없어 작업을 건너뜁니다: ${PENDING_FILE}"
  exit 0
fi

marker_created_at="$(cat "${PENDING_FILE}" 2>/dev/null || true)"
rm -f "${PENDING_FILE}"
log "post-reboot 1회 실행 마커를 소비했습니다. marker_created_at=${marker_created_at}"

log "반복 실행 방지를 위해 본 작업 시작 시점에 ${POST_REBOOT_SERVICE}를 disable 합니다."
systemctl disable "${POST_REBOOT_SERVICE}" || true
systemctl reset-failed "${POST_REBOOT_SERVICE}" || true

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

log "Auto Deploy 코드를 origin/main으로 갱신합니다."
sudo -u appuser -H bash -lc "
cd '${AUTO_DEPLOY_DIR}'
git fetch --all
git reset --hard origin/main
if [ -d .next ]; then rm -rf .next; fi
"

log "Auto Deploy web systemd 서비스를 재시작합니다."
systemctl daemon-reload
systemctl restart "${AUTO_DEPLOY_WEB_SERVICE}"

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
deploy_exit=0
set +e
sudo -u appuser -H bash -lc "cd '${AUTO_DEPLOY_DIR}' && php scripts/deploy_all_stable.php"
deploy_exit=$?
set -e
if [ "${deploy_exit}" -ne 0 ]; then
  log "전체 안정화버전 배포 CLI가 실패 상태로 종료되었습니다. 후속 검증은 계속 진행합니다. exit_code=${deploy_exit}"
fi

log "Caddy 설정을 검증합니다."
caddy validate

log "Caddy를 reload 합니다."
systemctl reload caddy

log "서버 재부팅 + 기본설정 + 전체 안정화버전 자동배포 작업이 완료되었습니다."
