# 서버 재부팅 + 기본설정 + 전체 안정화버전 자동배포 설치 가이드

이 문서는 Auto Deploy 관리자 화면의 `서버 재부팅 + 기본설정` 기능을 실제 서버에서 실행하기 위해 필요한 서버 파일을 설치하는 절차입니다.

> 중요: 설치 전에는 Auto Deploy가 재부팅 명령을 실행하지 않습니다. 관리자 화면의 `설치 상태 다시 확인`에서 모든 항목이 통과해야 실제 재부팅 자동화가 실행됩니다.

## 1. 한 번에 복붙 설치

아래 블록은 운영 서버에서 root 권한 또는 sudo 가능한 계정으로 실행합니다.

```bash
set -Eeuo pipefail

sudo install -d -m 0755 /usr/local/sbin
sudo install -d -m 0755 /etc/systemd/system
sudo install -d -m 0755 -o appuser -g appuser /var/log/auto_deploy
sudo install -d -m 0755 -o appuser -g appuser /var/lib/auto_deploy
sudo touch /var/log/auto_deploy/reboot-deploy.log
sudo chown appuser:appuser /var/log/auto_deploy /var/log/auto_deploy/reboot-deploy.log /var/lib/auto_deploy
sudo chmod 0755 /var/log/auto_deploy
sudo chmod 0664 /var/log/auto_deploy/reboot-deploy.log

sudo tee /usr/local/sbin/auto-reboot-deploy.sh >/dev/null <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

LOG_DIR="/var/log/auto_deploy"
LOG_FILE="${LOG_DIR}/reboot-deploy.log"
STATE_DIR="/var/lib/auto_deploy"
PENDING_FILE="${STATE_DIR}/reboot-restore.pending"
LOCK_FILE="/run/auto_deploy_reboot_restore.lock"
MAX_LOG_LINES=400
POST_REBOOT_SERVICE="dandorak-post-reboot.service"

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
trap compact_log EXIT

exec >> "${LOG_FILE}" 2>&1
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  echo "[$(date -Is)] 서버 재부팅 + 기본설정 자동화가 이미 실행 중이므로 중복 요청을 건너뜁니다."
  exit 0
fi

echo "[$(date -Is)] 서버 재부팅 + 기본설정 자동화를 예약합니다."
echo "[$(date -Is)] systemd daemon-reload를 실행합니다."
systemctl daemon-reload

echo "[$(date -Is)] post-reboot 1회 실행 마커를 기록합니다: ${PENDING_FILE}"
date -Is > "${PENDING_FILE}"
chmod 0644 "${PENDING_FILE}"

echo "[$(date -Is)] ${POST_REBOOT_SERVICE}를 enable 합니다."
systemctl enable "${POST_REBOOT_SERVICE}"

echo "[$(date -Is)] 서버를 재부팅합니다."
systemctl reboot
EOF
sudo chmod 0755 /usr/local/sbin/auto-reboot-deploy.sh

sudo tee /usr/local/sbin/dandorak-post-reboot.sh >/dev/null <<'EOF'
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
trap compact_log EXIT

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
EOF
sudo chmod 0755 /usr/local/sbin/dandorak-post-reboot.sh

sudo tee /etc/systemd/system/dandorak-post-reboot.service >/dev/null <<'EOF'
[Unit]
Description=Dandorak post-reboot Auto Deploy restore
After=network-online.target
Wants=network-online.target
ConditionPathExists=/var/lib/auto_deploy/reboot-restore.pending

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/dandorak-post-reboot.sh
RemainAfterExit=no
KillMode=process

[Install]
WantedBy=multi-user.target
EOF
sudo chmod 0644 /etc/systemd/system/dandorak-post-reboot.service

sudo tee /etc/sudoers.d/auto-reboot-deploy >/dev/null <<'EOF'
appuser ALL=(root) NOPASSWD: /usr/local/sbin/auto-reboot-deploy.sh
EOF
sudo chmod 0440 /etc/sudoers.d/auto-reboot-deploy
sudo visudo -cf /etc/sudoers.d/auto-reboot-deploy

sudo systemctl daemon-reload
```

## 2. 설치 파일별 내용

### 2.1 `/usr/local/sbin/auto-reboot-deploy.sh`

```bash
#!/usr/bin/env bash
set -Eeuo pipefail

LOG_DIR="/var/log/auto_deploy"
LOG_FILE="${LOG_DIR}/reboot-deploy.log"
STATE_DIR="/var/lib/auto_deploy"
PENDING_FILE="${STATE_DIR}/reboot-restore.pending"
LOCK_FILE="/run/auto_deploy_reboot_restore.lock"
MAX_LOG_LINES=400
POST_REBOOT_SERVICE="dandorak-post-reboot.service"

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
trap compact_log EXIT

exec >> "${LOG_FILE}" 2>&1
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  echo "[$(date -Is)] 서버 재부팅 + 기본설정 자동화가 이미 실행 중이므로 중복 요청을 건너뜁니다."
  exit 0
fi

echo "[$(date -Is)] 서버 재부팅 + 기본설정 자동화를 예약합니다."
echo "[$(date -Is)] systemd daemon-reload를 실행합니다."
systemctl daemon-reload

echo "[$(date -Is)] post-reboot 1회 실행 마커를 기록합니다: ${PENDING_FILE}"
date -Is > "${PENDING_FILE}"
chmod 0644 "${PENDING_FILE}"

echo "[$(date -Is)] ${POST_REBOOT_SERVICE}를 enable 합니다."
systemctl enable "${POST_REBOOT_SERVICE}"

echo "[$(date -Is)] 서버를 재부팅합니다."
systemctl reboot
```

### 2.2 `/usr/local/sbin/dandorak-post-reboot.sh`

```bash
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
trap compact_log EXIT

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
```

### 2.3 `/etc/systemd/system/dandorak-post-reboot.service`

```ini
[Unit]
Description=Dandorak post-reboot Auto Deploy restore
After=network-online.target
Wants=network-online.target
ConditionPathExists=/var/lib/auto_deploy/reboot-restore.pending

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/dandorak-post-reboot.sh
RemainAfterExit=no
KillMode=process

[Install]
WantedBy=multi-user.target
```


### 2.4 `/etc/systemd/system/auto-deploy-web.service`

```ini
[Unit]
Description=Auto Deploy web dashboard
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=appuser
Group=appuser
WorkingDirectory=/srv/auto_deploy
ExecStart=/usr/bin/php -S 0.0.0.0:9090 -t public
Restart=always
RestartSec=3
KillMode=control-group
SyslogIdentifier=auto-deploy-web

[Install]
WantedBy=multi-user.target
```

### 2.5 `/etc/systemd/system/auto-deploy-project@.service`

```ini
[Unit]
Description=Auto Deploy managed project %i
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=appuser
Group=appuser
EnvironmentFile=/etc/auto_deploy/projects/%i.env
ExecStart=/usr/local/bin/auto-deploy-project-runner %i
WorkingDirectory=/
Restart=always
RestartSec=3
KillMode=control-group
TimeoutStopSec=30
SyslogIdentifier=auto-deploy-project-%i

[Install]
WantedBy=multi-user.target
```

### 2.6 project runner/control/sudoers

프로젝트별 장기 실행 명령은 DeployService가 `/etc/auto_deploy/projects/{id}-{project_key}.env`에 기록하고 `auto-deploy-project-control` wrapper를 통해 `systemctl`로만 제어합니다. 로그는 `journalctl -u auto-deploy-project@{id}-{project_key}.service`로 확인합니다.

### 2.7 sudoers

`/etc/sudoers.d/auto-reboot-deploy`:

```sudoers
appuser ALL=(root) NOPASSWD: /usr/local/sbin/auto-reboot-deploy.sh
```

## 3. 검증 명령

아래 명령을 운영 서버에서 실행해 설치 상태를 검증합니다.

```bash
test -x /usr/local/sbin/auto-reboot-deploy.sh
test -x /usr/local/sbin/dandorak-post-reboot.sh
test -f /etc/systemd/system/dandorak-post-reboot.service
test -f /etc/systemd/system/auto-deploy-web.service
test -f /etc/systemd/system/auto-deploy-project@.service
test -x /usr/local/sbin/auto-deploy-web-control
test -x /usr/local/sbin/auto-deploy-project-control
test -x /usr/local/bin/auto-deploy-project-runner
test -d /var/log/auto_deploy
test -d /var/lib/auto_deploy
test -f /var/log/auto_deploy/reboot-deploy.log
sudo visudo -cf /etc/sudoers.d/auto-reboot-deploy
sudo -u appuser -H sudo -n -l /usr/local/sbin/auto-reboot-deploy.sh
sudo systemctl daemon-reload
```

관리자 화면의 `설치 상태 다시 확인` 버튼에서도 동일한 필수 항목을 확인할 수 있습니다.

## 4. 동작 흐름

설치가 완료된 서버에서만 다음 순서로 동작합니다.

1. 관리자 화면에서 `서버 재부팅 + 기본설정` 클릭
2. Auto Deploy API가 설치 상태를 사전 점검
3. `sudo /usr/local/sbin/auto-reboot-deploy.sh` 실행
4. `dandorak-post-reboot.service` enable
5. 서버 reboot
6. 부팅 후 `/usr/local/sbin/dandorak-post-reboot.sh` 실행
7. `/srv/dandorak/start-database.sh` 실행
8. DB Ready Check: 최대 120초 동안 2초 간격으로 Auto Deploy `.env`를 로드한 PHP PDO `SELECT 1` 연결 테스트 재시도
9. `auto-deploy-web.service`를 systemd로 restart
10. `php scripts/deploy_all_stable.php` 실행
11. 내부 PHP 코드가 `DeployService::deployStable()`을 활성 프로젝트별로 순차 호출
12. Caddy validate
13. Caddy reload
14. `dandorak-post-reboot.service` disable/reset-failed

post-reboot script에는 프로젝트별 `git pull`, `npm ci`, `npm run build`, `pm2 restart` 명령을 작성하지 않습니다. 장기 실행되는 Auto Deploy web과 프로젝트 서버 프로세스는 각각 `auto-deploy-web.service`, `auto-deploy-project@.service` cgroup에서 실행됩니다. 프로젝트별 배포는 Auto Deploy 내부 `DeployService::deployStable()`만 재사용합니다.

## 5. 로그 확인

고정 로그 파일만 사용합니다.

```bash
sudo tail -n 200 /var/log/auto_deploy/reboot-deploy.log
```

관리자 화면의 `최근 로그 조회` 버튼도 이 파일만 조회합니다. 임의 파일 경로 입력 기능은 없습니다.

## 6. 롤백 방법

재부팅 자동화 기능을 제거하려면 아래를 실행합니다.

```bash
sudo systemctl disable --now dandorak-post-reboot.service || true
sudo rm -f /etc/systemd/system/dandorak-post-reboot.service
sudo rm -f /usr/local/sbin/auto-reboot-deploy.sh
sudo rm -f /usr/local/sbin/dandorak-post-reboot.sh
sudo rm -f /etc/sudoers.d/auto-reboot-deploy
sudo systemctl daemon-reload
```

로그까지 삭제하려면 다음을 추가로 실행합니다.

```bash
sudo rm -rf /var/log/auto_deploy
```
