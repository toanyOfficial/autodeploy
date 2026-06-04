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

## 버전 관리 및 배포 API

- `POST /projects/{projectId}/versions`: 버전명, Commit Hash, 메모, 안정화 여부를 등록합니다.
- `POST|PUT /versions/{versionId}`: 등록된 버전을 수정합니다.
- `POST /versions/{versionId}/deactivate`: 버전을 삭제하지 않고 `is_active = 0`으로 비활성화합니다.
- `POST /projects/{projectId}/deploy/latest`: `origin/main` 기준 최신버전 빌드를 실행합니다.
- `POST /projects/{projectId}/deploy/stable`: `deploy_version.is_stable = 1`인 버전의 Commit Hash 기준으로 배포합니다.
- `POST /projects/{projectId}/deploy/versions/{versionId}`: 특정 등록 버전의 Commit Hash 기준으로 배포합니다.
- `GET /api/deploy/status`: 전역 배포 진행 여부를 확인합니다.

배포 명령어 셋은 DB에 저장하지 않으며, `runtime_type` 값(`python_static`, `nextjs_bun`)에 따라 코드 내부에서 결정됩니다. `nextjs_bun`은 빌드 성공 후에만 기존 포트 프로세스를 종료합니다.

## 배포 이력 및 리포트

- 리포트는 `.env`의 `REPORT_DIR` 하위에 프로젝트 `project_key`별 폴더로 저장합니다. 예: `${REPORT_DIR}/dandorak_web/`
- 리포트 파일명은 `YYYYMMDD_HHMMSS.txt` 형식을 사용합니다.
- `deploy_history.report_file`에는 생성된 txt 리포트의 전체 경로를 저장합니다.
- 프로젝트별 리포트는 최근 5건만 유지하고, 6건 이상이면 가장 오래된 파일을 삭제합니다.
- `GET /api/projects/{projectId}/histories`는 최근 배포 이력 5건을 반환합니다.
- `GET /api/reports/{historyId}`는 해당 배포 이력의 리포트 내용을 반환합니다.
- `GET /reports/{historyId}`는 리포트 상세 화면과 전체 복사 버튼을 제공합니다.
