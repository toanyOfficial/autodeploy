-- dandora_official Auto Deploy registration template
-- DO NOT RUN until COMMIT_HASH_HERE is replaced with the first stable commit hash.
-- Repository URL placeholder for operator reference: GITHUB_REPOSITORY_URL_HERE

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
