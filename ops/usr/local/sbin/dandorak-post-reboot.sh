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
TARGET_REMOTE_REF="origin/main"

mkdir -p "${LOG_DIR}" "${STATE_DIR}"
touch "${LOG_FILE}"
chmod 0755 "${LOG_DIR}" "${STATE_DIR}"
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

fail() {
  log "[FAILED] stage=${CURRENT_STAGE:-unknown} reason=$*"
  exit 1
}

run_step() {
  local description="$1"
  shift
  log "[STEP] ${description}: $*"
  "$@"
  local exit_code=$?
  log "[OK] ${description}: exit_code=${exit_code}"
}

capture() {
  local description="$1"
  shift
  local output
  echo "[$(date -Is)] [STEP] ${description}: $*" >&2
  set +e
  output=$("$@" 2>&1)
  local exit_code=$?
  set -e
  if [ "${exit_code}" -ne 0 ]; then
    echo "[$(date -Is)] [FAILED] ${description}: exit_code=${exit_code} stderr=${output}" >&2
    return "${exit_code}"
  fi
  echo "[$(date -Is)] [OK] ${description}: ${output:-'(no output)'}" >&2
  printf '%s' "${output}"
}

cleanup() {
  local exit_code=$?
  if [ "${exit_code}" -eq 0 ]; then
    log "[FINAL_SUCCESS] 서버 재부팅/self reboot + 기본설정 + 전체 안정화버전 자동배포 작업이 완료되었습니다."
  else
    log "[FINAL_FAILED] stage=${CURRENT_STAGE:-unknown} exit_code=${exit_code}"
  fi
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

CURRENT_STAGE="base-services"
log "DB 시작 스크립트를 실행합니다."
/srv/dandorak/start-database.sh

db_ready() {
  sudo -u appuser -H bash -lc "cd '${AUTO_DEPLOY_DIR}' && php -r 'require \"app/Core/Autoloader.php\"; App\\Config\\Env::load(\".env\"); App\\Config\\Database::connection()->query(\"SELECT 1\");' >/dev/null 2>&1"
}

log "DB 준비 상태를 대기합니다."
for attempt in $(seq 1 60); do
  if db_ready; then
    log "DB가 준비되었습니다. attempt=${attempt}"
    break
  fi

  if [ "${attempt}" -eq 60 ]; then
    fail "DB 준비 대기 시간이 초과되었습니다."
  fi

  sleep 2
done

CURRENT_STAGE="git-sync"
if [ ! -d "${AUTO_DEPLOY_DIR}/.git" ]; then
  fail "Auto Deploy 저장소가 아닙니다: ${AUTO_DEPLOY_DIR}"
fi

before_commit="$(sudo -u appuser -H git -C "${AUTO_DEPLOY_DIR}" rev-parse --verify HEAD 2>/dev/null || true)"
log "갱신 전 Auto Deploy 커밋: ${before_commit:-unknown}"

if ! sudo -u appuser -H git -C "${AUTO_DEPLOY_DIR}" diff --quiet --ignore-submodules --; then
  fail "Auto Deploy 저장소에 추적 파일 로컬 변경이 있어 reset을 중단합니다."
fi

run_step "Auto Deploy git fetch" sudo -u appuser -H git -C "${AUTO_DEPLOY_DIR}" fetch --all --prune
origin_commit="$(sudo -u appuser -H git -C "${AUTO_DEPLOY_DIR}" rev-parse --verify "${TARGET_REMOTE_REF}^{commit}" 2>/dev/null || true)"
if [ -z "${origin_commit}" ]; then
  fail "${TARGET_REMOTE_REF} 커밋을 확인할 수 없습니다. 원격 브랜치 부재 또는 fetch 실패입니다."
fi
log "origin/main 커밋: ${origin_commit}"

run_step "Auto Deploy git reset" sudo -u appuser -H git -C "${AUTO_DEPLOY_DIR}" reset --hard "${TARGET_REMOTE_REF}"
if [ -d "${AUTO_DEPLOY_DIR}/.next" ]; then
  run_step "Auto Deploy stale .next 제거" rm -rf "${AUTO_DEPLOY_DIR}/.next"
fi
after_commit="$(sudo -u appuser -H git -C "${AUTO_DEPLOY_DIR}" rev-parse --verify HEAD 2>/dev/null || true)"
log "갱신 후 Auto Deploy 커밋: ${after_commit:-unknown}"
if [ "${after_commit}" != "${origin_commit}" ]; then
  fail "갱신 후 HEAD가 origin/main과 일치하지 않습니다. head=${after_commit:-unknown} origin=${origin_commit}"
fi

CURRENT_STAGE="install-ops"
install_exec() {
  local src="$1"
  local dest="$2"
  [ -f "${src}" ] || fail "설치 원본 파일이 없습니다: ${src}"
  install -m 0755 -o root -g root "${src}" "${dest}"
  cmp -s "${src}" "${dest}" || fail "설치 후 파일 불일치: ${dest}"
  log "[INSTALL_OK] ${src} -> ${dest} mode=0755 sha256=$(sha256sum "${dest}" | awk '{print $1}')"
}

install_unit() {
  local src="$1"
  local dest="$2"
  [ -f "${src}" ] || fail "설치 원본 unit이 없습니다: ${src}"
  install -m 0644 -o root -g root "${src}" "${dest}"
  cmp -s "${src}" "${dest}" || fail "설치 후 unit 불일치: ${dest}"
  log "[INSTALL_OK] ${src} -> ${dest} mode=0644 sha256=$(sha256sum "${dest}" | awk '{print $1}')"
}

install_sudoers() {
  local src="$1"
  local dest="$2"
  [ -f "${src}" ] || fail "설치 원본 sudoers가 없습니다: ${src}"
  visudo -cf "${src}" >/dev/null
  install -m 0440 -o root -g root "${src}" "${dest}"
  visudo -cf "${dest}" >/dev/null
  cmp -s "${src}" "${dest}" || fail "설치 후 sudoers 불일치: ${dest}"
  log "[INSTALL_OK] ${src} -> ${dest} mode=0440 owner=root:root sha256=$(sha256sum "${dest}" | awk '{print $1}')"
}

install_exec "${AUTO_DEPLOY_DIR}/ops/usr/local/bin/auto-deploy-project-runner" "/usr/local/bin/auto-deploy-project-runner"
install_exec "${AUTO_DEPLOY_DIR}/ops/usr/local/sbin/auto-deploy-project-control" "/usr/local/sbin/auto-deploy-project-control"
install_exec "${AUTO_DEPLOY_DIR}/ops/usr/local/sbin/auto-deploy-web-control" "/usr/local/sbin/auto-deploy-web-control"
install_exec "${AUTO_DEPLOY_DIR}/ops/usr/local/sbin/auto-reboot-deploy.sh" "/usr/local/sbin/auto-reboot-deploy.sh"
install_exec "${AUTO_DEPLOY_DIR}/ops/usr/local/sbin/dandorak-post-reboot.sh" "/usr/local/sbin/dandorak-post-reboot.sh"
install_unit "${AUTO_DEPLOY_DIR}/ops/etc/systemd/system/auto-deploy-project@.service" "/etc/systemd/system/auto-deploy-project@.service"
install_unit "${AUTO_DEPLOY_DIR}/ops/etc/systemd/system/auto-deploy-web.service" "/etc/systemd/system/auto-deploy-web.service"
install_unit "${AUTO_DEPLOY_DIR}/ops/etc/systemd/system/dandorak-post-reboot.service" "/etc/systemd/system/dandorak-post-reboot.service"
install_sudoers "${AUTO_DEPLOY_DIR}/ops/sudoers.d/auto-deploy-project-systemd" "/etc/sudoers.d/auto-deploy-project-systemd"
install_sudoers "${AUTO_DEPLOY_DIR}/ops/sudoers.d/auto-deploy-web-systemd" "/etc/sudoers.d/auto-deploy-web-systemd"
install_sudoers "${AUTO_DEPLOY_DIR}/ops/sudoers.d/auto-reboot-deploy" "/etc/sudoers.d/auto-reboot-deploy"

CURRENT_STAGE="daemon-reload"
run_step "systemctl daemon-reload" systemctl daemon-reload

CURRENT_STAGE="web-restart"
run_step "Auto Deploy web service restart" /usr/local/sbin/auto-deploy-web-control restart
run_step "Auto Deploy web service is-active" systemctl is-active --quiet "${AUTO_DEPLOY_WEB_SERVICE}"

log "Auto Deploy 준비 상태를 대기합니다."
for attempt in $(seq 1 60); do
  if curl -fsS --max-time 2 "${AUTO_DEPLOY_URL}" >/dev/null 2>&1; then
    log "Auto Deploy가 준비되었습니다. attempt=${attempt}"
    break
  fi

  if [ "${attempt}" -eq 60 ]; then
    fail "Auto Deploy 준비 대기 시간이 초과되었습니다."
  fi

  sleep 2
done

CURRENT_STAGE="permission-verify"
project_permission="$(capture "project wrapper check-permission" sudo -u appuser -H sudo -n /usr/local/sbin/auto-deploy-project-control check-permission)"
[ "${project_permission}" = "OK" ] || fail "project wrapper check-permission 결과가 OK가 아닙니다: ${project_permission}"
web_permission="$(capture "web wrapper check-permission" sudo -u appuser -H sudo -n /usr/local/sbin/auto-deploy-web-control check-permission)"
[ "${web_permission}" = "OK" ] || fail "web wrapper check-permission 결과가 OK가 아닙니다: ${web_permission}"

CURRENT_STAGE="project-inventory"
log "전체 활성 프로젝트 목록과 안정화버전 대상 커밋을 조회합니다."
sudo -u appuser -H bash -lc "cd '${AUTO_DEPLOY_DIR}' && php -r 'require \"app/Core/Autoloader.php\"; App\\Config\\Env::load(\".env\"); \$projects=(new App\\Repositories\\DeployProjectRepository())->all(true); \$versions=new App\\Repositories\\DeployVersionRepository(); foreach (\$projects as \$project) { \$stable=\$versions->findStableByProject((int)\$project[\"id\"]); echo \"[PROJECT_TARGET] id=\".(int)\$project[\"id\"].\" key=\".(\$project[\"project_key\"] ?? \"\").\" runtime=\".(\$project[\"runtime_type\"] ?? \"\").\" port=\".(\$project[\"port\"] ?? \"\").\" target_commit=\".(\$stable[\"git_commit_hash\"] ?? \"(no-stable)\").PHP_EOL; }'"

CURRENT_STAGE="deploy-all-stable"
log "전체 활성 프로젝트 안정화버전 배포 CLI를 appuser 권한으로 실행합니다."
sudo -u appuser -H bash -lc "cd '${AUTO_DEPLOY_DIR}' && php scripts/deploy_all_stable.php"

CURRENT_STAGE="final-verify"
log "프로젝트별 systemd unit 상태를 검증합니다."
sudo -u appuser -H bash -lc "cd '${AUTO_DEPLOY_DIR}' && php -r 'require \"app/Core/Autoloader.php\"; App\\Config\\Env::load(\".env\"); \$projects=(new App\\Repositories\\DeployProjectRepository())->all(true); foreach (\$projects as \$project) { \$id=(int)\$project[\"id\"]; \$key=preg_replace(\"/[^A-Za-z0-9._-]+/\", \"_\", (string)(\$project[\"project_key\"] ?? (\"project-\".\$id))); \$key=trim(\$key, \"._-\"); if (\$key === \"\") { \$key=\"project\"; } echo \$id.\"-\".\$key.PHP_EOL; }'" | while IFS= read -r instance; do
  [ -n "${instance}" ] || continue
  unit="auto-deploy-project@${instance}.service"
  if systemctl is-active --quiet "${unit}"; then
    state="$(systemctl is-active "${unit}" 2>/dev/null || true)"
    log "[PROJECT_UNIT_OK] unit=${unit} state=${state}"
  else
    state="$(systemctl is-active "${unit}" 2>/dev/null || true)"
    log "[PROJECT_UNIT_FAILED] unit=${unit} state=${state}"
    exit 1
  fi
done

log "Caddy 설정을 검증합니다."
caddy validate

log "Caddy를 reload 합니다."
systemctl reload caddy
