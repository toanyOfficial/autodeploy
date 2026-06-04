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
