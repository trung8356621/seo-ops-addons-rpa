# RPA API Continuous Sync Policy

## Precedence

**RPA-only addon continuous sync (`seo-ops-addons-rpa`) is permanently exempt from the SEO-OPS core Sunday-only deployment rule and per-batch Product Owner deploy approval.**

SEO-OPS core (`omnichannel-client`) and other addons/products keep their existing restrictions unchanged.

## Scope & Target

- **Local repository**: `D:\work-app\new\seo-ops-addons-rpa`
- **Sync tool**: `D:\work\seo-ops-sync` (`config.rpa.json`, `start-rpa.ps1`)
- **VPS target**: `/www/wwwroot/seo-ops-addons/social-automation`

## Default Development & Sync Workflow

1. Edit code locally in `D:\work-app\new\seo-ops-addons-rpa`.
2. Run targeted local tests (e.g. PHPUnit `SocialAutomationAddon` / targeted unit tests).
3. Automatically sync changed files to the VPS using `seo-ops-sync` RPA-only mode (no deploy confirmation prompt per RPA addon edit; no Git commit/push required before sync).
4. Verify local vs VPS SHA-256 checksums (handled automatically per upload by `verified_atomic_put` and spot-checked on critical tasks).
5. Verify API health and the modified LIVE endpoints.
6. Report results concisely.

## Preserved Safety Rules

- Sync only `D:\work-app\new\seo-ops-addons-rpa` → `/www/wwwroot/seo-ops-addons/social-automation`.
- Never sync SEO-OPS core (`omnichannel-client`) from RPA-only sync.
- Never delete remote files (`disable_remote_delete: true`).
- Never upload empty PHP files over non-empty remote PHP files.
- Always use atomic `.syncpart.*` upload + SHA-256 verification.
- Keep SSH keepalive, dead SFTP session detection, session invalidation, and retries.
- Never run DB migrations or schema changes automatically.
- Never overwrite `.env`, secrets, or databases.
- Never start the Full watcher for RPA-only tasks; use the RPA-only watcher (`start-rpa.ps1`).
- Never modify repositories outside machine ownership.

## Removed Restrictions (RPA-Only)

- Sunday-only deploy window for RPA-only sync.
- Manual per-change deploy approval for RPA API updates.
- Full multi-hundred-file dry-run ceremony for every small task.
- Requiring Git commit/push prior to sync (syncs directly from worktree).
- Blocking sync on unrelated files with mtime-only drift (skipped via SHA-256 comparison).
