# dandora_official 등록 검증 메모

## 1. DB 스키마 검증 결과

이 저장소의 Auto Deploy 애플리케이션은 운영 DB 스키마를 생성/변경하지 않으며, 모델/리포지터리 매핑 기준 테이블명은 다음과 같습니다.

- 프로젝트 테이블: `deploy_project`
- 안정화버전/버전 테이블: `deploy_version`

사용자 초안의 `projects`, `project_versions`는 현재 코드의 실제 테이블명이 아닙니다. 또한 `repository_url`, `name`, `runtime`, `start_mode`, `updated_at` 컬럼은 현재 프로젝트/버전 등록 리포지터리에서 사용하지 않습니다. 기존 프로젝트 row와 동일하게 맞춰야 하는 컬럼명은 아래와 같습니다.

### `deploy_project`

- `id`는 AUTO_INCREMENT 기본키로 취급하므로 INSERT에서 수동 지정하지 않습니다.
- 등록 컬럼: `project_key`, `project_name`, `server_path`, `port`, `runtime_type`, `branch_name`, `is_active`
- `created_at`, `updated_at`은 DB 기본값/트리거가 기존 구조대로 처리하도록 INSERT에서 제외합니다.

### `deploy_version`

- `id`는 AUTO_INCREMENT 기본키로 취급하므로 INSERT에서 수동 지정하지 않습니다.
- 등록 컬럼: `project_id`, `version_name`, `git_commit_hash`, `memo`, `is_stable`, `is_active`
- `version_type`은 모델 컬럼에는 있지만 버전 등록 리포지터리 INSERT에는 포함되지 않으므로 기존 구조대로 DB 기본값을 사용합니다.
- `created_at`은 DB 기본값을 사용하며, `updated_at` 컬럼은 현재 모델/리포지터리 매핑에 없습니다.

## 2. 실행 금지 조건

`repository_url` 컬럼이 현재 스키마에 없기 때문에 repo URL은 Auto Deploy DB 등록 SQL에 포함하지 않습니다. 단, 실제 안정화버전 배포는 `/srv/dandora_official`이 Git 저장소이고 안정화 commit hash가 실제 값이어야 성공합니다.

아래 파일의 SQL에는 아직 placeholder가 포함되어 있습니다. 다음 값을 실제 값으로 치환하기 전에는 실행하지 마세요.

- `COMMIT_HASH_HERE`: 최초 안정화 commit hash

SQL 파일: [`docs/sql/dandora_official_registration.sql`](sql/dandora_official_registration.sql)

## 3. dandora_official 등록 SQL

```sql
USE auto_deploy;

START TRANSACTION;

INSERT INTO deploy_project (
  project_key,
  project_name,
  server_path,
  port,
  runtime_type,
  branch_name,
  is_active
)
VALUES (
  'dandora_official',
  '단도락 공식 홈페이지',
  '/srv/dandora_official',
  3700,
  'python_static',
  'main',
  1
);

SET @project_id := LAST_INSERT_ID();

INSERT INTO deploy_version (
  project_id,
  version_name,
  git_commit_hash,
  memo,
  is_stable,
  is_active
)
VALUES (
  @project_id,
  '초기 안정화버전',
  'COMMIT_HASH_HERE',
  'repo URL placeholder: GITHUB_REPOSITORY_URL_HERE',
  1,
  1
);

COMMIT;
```

## 4. 기존 구조 영향 검증

### dashboard 노출

대시보드는 `DeployProjectRepository::all(true)`로 활성 프로젝트를 조회합니다. 따라서 `deploy_project.is_active = 1`로 등록된 `dandora_official`은 별도 하드코딩 없이 dashboard 카드 목록에 노출됩니다.

### 재부팅 자동복구 대상 포함

재부팅 후 복구 스크립트는 프로젝트 key를 하드코딩하지 않고 `php scripts/deploy_all_stable.php`를 실행합니다. 해당 CLI는 `StableDeploymentBatchService::deployAll()`을 통해 활성 프로젝트 목록을 조회하고 각 프로젝트의 `deployStable()`을 호출합니다. 따라서 `deploy_project.is_active = 1`이고 활성 안정화버전이 등록되어 있으면 `dandora_official`도 기존 구조대로 포함됩니다.

### 포트 3700 충돌 확인

코드상 보호 포트는 Auto Deploy 자체 포트 `9090`입니다. `3700`은 보호 포트와 충돌하지 않습니다. 운영 DB의 기존 프로젝트 포트와의 충돌 여부는 운영 DB에서 아래 쿼리로 최종 확인하세요.

```sql
SELECT id, project_key, project_name, port
FROM deploy_project
WHERE is_active = 1 AND port = 3700;
```

결과가 비어 있어야 기존 활성 프로젝트 포트와 충돌하지 않습니다.

### `.env` 환경변수 상속 차단

Auto Deploy DB 관련 환경변수(`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `DATABASE_URL`, `MYSQL_*`)는 프로젝트 실행 명령 앞에 `env -u ...`를 붙이는 기존 `projectEnvCommand()`를 통해 제거됩니다. `python_static` 시작 명령도 동일한 래퍼를 사용하도록 맞췄으므로 Auto Deploy의 DB 환경변수가 정적 사이트 프로세스로 상속되지 않습니다. 단, 향후 `/srv/dandora_official/.env`에 같은 DB 키를 명시하면 기존 구조대로 해당 프로젝트 `.env` 값은 재주입됩니다.

## 5. Caddy 설정

이 저장소의 Auto Deploy 배포 로직은 Caddy site block을 생성하지 않습니다. 재부팅 복구 스크립트도 `caddy validate`와 `systemctl reload caddy`만 실행하므로 Caddy 설정은 운영 서버에서 수동으로 관리하는 구조입니다.

수동 Caddyfile 예시:

```caddyfile
단체도시락.com, www.단체도시락.com, dandorak.com, www.dandorak.com {
    reverse_proxy 127.0.0.1:3700
}
```

적용 전 검증 예시:

```bash
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

## 6. 안정화버전 배포 전 체크리스트

1. `/srv/dandora_official`을 실제 Git 저장소로 준비합니다.
2. `index.html`, `styles.css`, `script.js`가 배포할 commit에 포함되어 있는지 확인합니다.
3. `docs/sql/dandora_official_registration.sql`의 `COMMIT_HASH_HERE`를 실제 최초 안정화 commit hash로 치환합니다.
4. 운영 DB에서 포트 중복 쿼리를 실행합니다.
5. SQL을 실행합니다.
6. dashboard에서 `dandora_official` 카드가 표시되는지 확인합니다.
7. 안정화버전 배포 후 `ss -ltnp | grep ':3700 '`와 `curl -I http://127.0.0.1:3700/`로 확인합니다.
