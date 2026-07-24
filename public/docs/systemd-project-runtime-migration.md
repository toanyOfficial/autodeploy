# Auto Deploy systemd project runtime migration

## 변경된 아키텍처

기존 구조는 DeployService가 배포 중 `nohup ... &`로 프로젝트 프로세스를 직접 띄웠습니다. 이 경우 `dandorak-post-reboot.service` 같은 oneshot 작업에서 시작된 장기 실행 프로세스가 부모 작업의 cgroup에 남을 수 있습니다.

변경 후 구조는 다음과 같습니다.

1. DeployService는 DB의 `project_key`, `runtime_type`, `server_path`, `port`를 사용해 프로젝트별 systemd instance 이름을 계산합니다.
2. DeployService는 `/etc/auto_deploy/projects/{id}-{project_key}.env` 파일에 프로젝트 실행 환경과 시작 명령을 기록합니다.
3. DeployService는 `/usr/local/sbin/auto-deploy-project-control` wrapper를 sudo로 호출합니다.
4. wrapper는 `systemctl stop/restart/is-active/reset-failed auto-deploy-project@{id}-{project_key}.service`만 실행합니다.
5. 장기 실행 프로세스는 `auto-deploy-project@.service` cgroup 안에서만 실행됩니다.
6. 로그는 `journalctl -u auto-deploy-project@{id}-{project_key}.service`로 확인합니다.

Auto Deploy 웹 서버도 post-reboot 스크립트에서 직접 `nohup php -S ... &`로 띄우지 않고 `auto-deploy-web.service`로 재시작합니다.

## 추가되는 unit/wrapper 파일

- `/etc/systemd/system/auto-deploy-project@.service`
- `/etc/systemd/system/auto-deploy-web.service`
- `/usr/local/bin/auto-deploy-project-runner`
- `/usr/local/sbin/auto-deploy-project-control`
- `/usr/local/sbin/auto-deploy-web-control`
- `/etc/sudoers.d/auto-deploy-project-systemd`
- `/etc/sudoers.d/auto-deploy-web-systemd`

## 기존 구조와 차이점

| 항목 | 기존 | 변경 후 |
| --- | --- | --- |
| 프로젝트 시작 | `nohup ... &` | `systemctl restart auto-deploy-project@...service` |
| 프로젝트 종료 | 포트/PID 기반 kill | `systemctl stop auto-deploy-project@...service` |
| 실행 cgroup | 배포를 호출한 process/service에 남을 수 있음 | 프로젝트별 독립 systemd unit |
| 로그 | 프로젝트 경로 `app.log` | journald |
| 중복/재시작 영향 | needrestart가 post-reboot service를 재시작 대상으로 오인 가능 | 장기 실행 프로세스가 project/web unit에 귀속 |

## 운영 서버 migration 절차

```bash
cd /srv/auto_deploy
sudo install -m 0755 ops/usr/local/bin/auto-deploy-project-runner /usr/local/bin/auto-deploy-project-runner
sudo install -m 0755 ops/usr/local/sbin/auto-deploy-project-control /usr/local/sbin/auto-deploy-project-control
sudo install -m 0755 ops/usr/local/sbin/auto-deploy-web-control /usr/local/sbin/auto-deploy-web-control
sudo install -m 0644 ops/etc/systemd/system/auto-deploy-project@.service /etc/systemd/system/auto-deploy-project@.service
sudo install -m 0644 ops/etc/systemd/system/auto-deploy-web.service /etc/systemd/system/auto-deploy-web.service
sudo install -m 0440 ops/sudoers.d/auto-deploy-project-systemd /etc/sudoers.d/auto-deploy-project-systemd
sudo install -m 0440 ops/sudoers.d/auto-deploy-web-systemd /etc/sudoers.d/auto-deploy-web-systemd
sudo install -d -m 0755 -o root -g root /etc/auto_deploy/projects
sudo systemctl daemon-reload
sudo systemctl enable --now auto-deploy-web.service
```

기존 `nohup` 기반 프로젝트 프로세스는 최초 systemd 배포 전에 운영자가 점검 후 정리해야 합니다. 새 DeployService는 프로젝트별 systemd unit만 stop/restart 하며 임의 PID kill을 수행하지 않습니다.

```bash
# 예: 기존 포트 점유 프로세스 확인
sudo ss -ltnp

# 프로젝트별 안정화버전 재배포로 systemd env와 unit instance 생성
sudo -u appuser -H bash -lc 'cd /srv/auto_deploy && php scripts/deploy_all_stable.php'
```

## 배포/rollback 동작

- `python_static`: git fetch/reset 후 systemd env를 기록하고 프로젝트 unit을 restart합니다.
- `nextjs_bun`: candidate worktree에서 install/build/검증을 완료한 뒤 프로젝트 unit을 stop하고 운영 산출물을 교체한 다음 restart합니다.
- `node_app`: 기존 안전 배포 흐름을 유지하면서 장기 실행 시작/중지를 systemd로 수행합니다.
- rollback은 이전 commit/산출물을 복구한 뒤 같은 project unit을 restart합니다.

## 테스트 절차

```bash
php -l app/Services/DeployService.php
php -l app/Controllers/ApiController.php
php -l app/Services/RebootAutomationStatusService.php
python3 scripts/check_deployservice_methods.py
bash -n ops/usr/local/bin/auto-deploy-project-runner \
  ops/usr/local/sbin/auto-deploy-project-control \
  ops/usr/local/sbin/auto-deploy-web-control \
  ops/usr/local/sbin/dandorak-post-reboot.sh \
  ops/usr/local/sbin/auto-reboot-deploy.sh
sudo visudo -cf ops/sudoers.d/auto-deploy-project-systemd
sudo visudo -cf ops/sudoers.d/auto-deploy-web-systemd
systemd-analyze verify \
  ops/etc/systemd/system/auto-deploy-project@.service \
  ops/etc/systemd/system/auto-deploy-web.service \
  ops/etc/systemd/system/dandorak-post-reboot.service
```

## 운영 확인 명령

```bash
systemctl status auto-deploy-web.service
systemctl status 'auto-deploy-project@*.service'
journalctl -u auto-deploy-web.service -n 100 --no-pager
journalctl -u 'auto-deploy-project@{id}-{project_key}.service' -n 100 --no-pager
systemd-cgls /system.slice/auto-deploy-project@{id}-{project_key}.service
```
