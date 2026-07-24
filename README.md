# Auto Deploy

Linux 서버 내부에서 동작하는 Auto Deploy 웹서비스입니다. 운영 DB(`auto_deploy`)는 이미 생성되어 있다는 전제로 동작하며, 애플리케이션은 DB 스키마를 생성하거나 변경하지 않습니다.

## 실행 준비

```bash
cp .env.example .env
# .env 값을 운영 환경에 맞게 입력
php -S 0.0.0.0:9090 -t public
```

## DB 연결 테스트

```bash
php scripts/test_db_connection.php
```

## 개발 원칙

- migration 생성 금지
- `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE` 실행 금지
- 기존 DB 구조에 대한 ORM 매핑과 Repository/API만 사용
- 로그인 정보는 DB에 저장하지 않고 `.env`의 `ADMIN_ID`, `ADMIN_PASSWORD`, `SESSION_SECRET`를 사용
- `ADMIN_PASSWORD`는 평문도 legacy로 지원하지만, 권장 형식은 `SESSION_SECRET` 기반 HMAC-SHA256 해시(`hmac-sha256:<digest>`)입니다. 생성 명령: `php scripts/hash_admin_password.php '관리자비밀번호'`

## 버전 관리 및 배포 API

- `POST /projects/{projectId}/versions`: 버전명, Commit Hash, 메모, 안정화 여부를 등록합니다.
- `POST|PUT /versions/{versionId}`: 등록된 버전을 수정합니다.
- `POST /versions/{versionId}/deactivate`: 버전을 삭제하지 않고 `is_active = 0`으로 비활성화합니다.
- `POST /projects/{projectId}/deploy/latest`: `origin/main` 기준 최신버전 빌드를 실행합니다.
- `POST /projects/{projectId}/deploy/stable`: `deploy_version.is_stable = 1`인 버전의 Commit Hash 기준으로 배포합니다.
- `POST /projects/{projectId}/deploy/versions/{versionId}`: 특정 등록 버전의 Commit Hash 기준으로 배포합니다.
- `GET /api/deploy/status`: 전역 배포 진행 여부를 확인합니다.

배포 명령어 셋은 DB에 저장하지 않으며, `runtime_type` 값(`python_static`, `nextjs_bun`, `node_app`)과 프로젝트 설정값(`server_path`, `port`, `branch_name`)에 따라 코드 내부 템플릿으로 결정됩니다. 프로젝트 장기 실행 프로세스는 `nohup`이 아니라 `auto-deploy-project@.service` systemd template unit이 직접 관리합니다. DeployService는 배포 전환 시 프로젝트별 환경 파일(`/etc/auto_deploy/projects/{id}-{project_key}.env`)을 갱신하고 `auto-deploy-project-control`을 통해 `systemctl stop/restart/is-active/reset-failed`만 호출합니다. 로그 확인은 `app.log`가 아니라 `journalctl -u auto-deploy-project@{id}-{project_key}.service`를 기준으로 수행합니다. `nextjs_bun` 배포는 candidate worktree에서 의존성 설치와 build를 완료한 뒤 systemd 서비스를 멈추고 운영 commit, `.next`, `node_modules`를 원자적으로 교체한 다음 systemd로 재시작합니다. `python_static`은 운영 경로를 target ref로 갱신한 뒤 `python3 -m http.server` 실행 명령을 template unit에 기록하고 systemd로 재시작합니다. `node_app`도 기존 안전 배포/rollback 흐름을 유지하되 장기 실행 주체는 systemd unit입니다. 포트 LISTEN, HTTP 응답, journal 기반 `EADDRINUSE` 확인에 실패하면 가능한 범위에서 이전 commit과 산출물을 복구하고 systemd로 이전 서비스를 재시작합니다. 프로젝트별 배포는 최대 10분으로 제한되며 개별 명령은 최대 5분으로 제한됩니다. 배포 상태 조회는 12분 이상 남은 `running` 이력을 stale 실패로 전환하고 lock/running 상세 상태를 반환합니다.

## 배포 전 검증

DeployService.php 수정 후에는 PHP 문법 검사와 메서드 중복 검사를 함께 실행합니다. 중복 메서드가 있으면 Auto Deploy 대시보드가 PHP fatal error로 500 응답을 낼 수 있으므로 PR 전 아래 명령이 모두 통과해야 합니다.

```bash
php -l app/Services/DeployService.php
python3 scripts/check_deployservice_methods.py
```

## 배포 이력 및 리포트

- 리포트는 `.env`의 `REPORT_DIR` 하위에 프로젝트 `project_key`별 폴더로 저장합니다. 예: `${REPORT_DIR}/dandorak_web/`
- 리포트 파일명은 `YYYYMMDD_HHMMSS.txt` 형식을 사용합니다.
- `deploy_history.report_file`에는 생성된 txt 리포트의 전체 경로를 저장합니다.
- 프로젝트별 리포트는 최근 5건만 유지하고, 6건 이상이면 가장 오래된 파일을 삭제합니다.
- `GET /api/projects/{projectId}/histories`는 최근 배포 이력 3건을 반환합니다.
- `GET /api/reports/{historyId}`는 해당 배포 이력의 리포트 내용과 감지된 대표 실패 케이스를 반환합니다.
- `POST /api/reports/{historyId}/operation`은 `sync_dependencies`, `check_git_auth`, `fix_permissions`, `kill_port`, `clean_next_build`, `copy_report` 중 whitelist된 리포트 복구/확인 operation만 실행합니다.
- `GET /reports/{historyId}`는 리포트 상세 화면, 전체 복사 버튼, 대표 실패 케이스 안내/복구 액션 카드를 제공합니다.


## 프로젝트 등록 메모

- `dandora_official` 등록 SQL과 운영 반영 전 체크리스트는 `docs/dandora-official-registration.md`를 확인합니다.

## 서버 재부팅 + 기본설정 + 전체 안정화버전 자동배포

관리자 화면의 개발자 설정에는 `서버 재부팅 + 기본설정` 버튼이 있습니다. 이 버튼은 `POST /api/system/reboot-and-restore`만 호출하며, 서버에서 고정 명령인 `sudo /usr/local/sbin/auto-reboot-deploy.sh`만 실행합니다. `self reboot` 버튼은 fd 3 이상을 닫은 상태로 임시 detached 스크립트를 생성해 `/srv/auto_deploy`를 `origin/main`으로 갱신하고, 필요 시 `.next`를 삭제한 뒤 포트 기준 kill 없이 `.autodeploy.pid`에 기록된 Auto Deploy PHP PID만 종료하고 PHP 내장 서버를 다시 기동합니다.

운영 서버 반영 시에는 저장소의 템플릿 파일을 아래 위치로 배치합니다. 전체 복붙 설치 가이드는 `docs/reboot-automation.md`를 확인합니다.

```bash
sudo install -m 0755 ops/usr/local/sbin/auto-reboot-deploy.sh /usr/local/sbin/auto-reboot-deploy.sh
sudo install -m 0755 ops/usr/local/sbin/dandorak-post-reboot.sh /usr/local/sbin/dandorak-post-reboot.sh
sudo install -m 0755 ops/usr/local/bin/auto-deploy-project-runner /usr/local/bin/auto-deploy-project-runner
sudo install -m 0755 ops/usr/local/sbin/auto-deploy-project-control /usr/local/sbin/auto-deploy-project-control
sudo install -m 0755 ops/usr/local/sbin/auto-deploy-web-control /usr/local/sbin/auto-deploy-web-control
sudo install -d -m 0755 -o appuser -g appuser /var/log/auto_deploy
sudo install -d -m 0755 -o appuser -g appuser /var/lib/auto_deploy
sudo touch /var/log/auto_deploy/reboot-deploy.log
sudo chown appuser:appuser /var/log/auto_deploy/reboot-deploy.log
sudo chmod 0664 /var/log/auto_deploy/reboot-deploy.log
sudo install -m 0644 ops/etc/systemd/system/dandorak-post-reboot.service /etc/systemd/system/dandorak-post-reboot.service
sudo install -m 0644 ops/etc/systemd/system/auto-deploy-web.service /etc/systemd/system/auto-deploy-web.service
sudo install -m 0644 ops/etc/systemd/system/auto-deploy-project@.service /etc/systemd/system/auto-deploy-project@.service
sudo install -m 0440 ops/sudoers.d/auto-reboot-deploy /etc/sudoers.d/auto-reboot-deploy
sudo install -m 0440 ops/sudoers.d/auto-deploy-project-systemd /etc/sudoers.d/auto-deploy-project-systemd
sudo install -m 0440 ops/sudoers.d/auto-deploy-web-systemd /etc/sudoers.d/auto-deploy-web-systemd
sudo systemctl daemon-reload
```

`auto-reboot-deploy.sh`는 `/var/lib/auto_deploy/reboot-restore.pending` 1회 실행 마커를 만든 뒤 서비스를 enable 하며, 두 운영 스크립트는 `flock`으로 중복 실행을 차단합니다. 프로젝트 프로세스는 `auto-deploy-project@.service` 아래에 남으므로 post-reboot oneshot cgroup에 장기 실행 프로세스가 남지 않습니다. `dandorak-post-reboot.sh`는 시작 즉시 마커를 소비하고 서비스를 disable/reset-failed 하므로, 실제 재부팅 없는 재시작이나 반복 start 루프에서는 전체 배포를 다시 실행하지 않습니다.

`dandorak-post-reboot.sh`는 프로젝트별 배포 명령을 직접 실행하지 않고, appuser 권한으로 `php scripts/deploy_all_stable.php`를 호출합니다. 해당 CLI는 `StableDeploymentBatchService`를 통해 활성 프로젝트 목록을 조회하고 각 프로젝트에 대해 `DeployService::deployStable()`을 순차 호출합니다.

최근 자동화 로그는 고정 파일 `/var/log/auto_deploy/reboot-deploy.log`의 최근 200줄만 조회하며, 스크립트 실행 시 파일은 최근 400줄로 압축되어 무한정 누적되지 않습니다.
