# KHWWC Frontend API Gap Report

## Current findings

### 1) App test runner was incorrectly including perf files
Evidence: `frontend/perf/playwright-perf.spec.ts` imports `@playwright/test`, but that package is not installed in the current frontend workspace. The default Vitest run therefore reported a failing suite even though the app code was otherwise healthy.

Fix applied: `frontend/vite.config.ts` now excludes the `perf` folder from the default test discovery set.

Status: fixed in workspace.

### 2) Public website and app are separate scopes
The attached prompt includes a public website domain that is not the main KHWWC application. The app under audit is the Laravel + React app in `/frontend` and `/khwwc`.

Status: app scope preserved; website domain intentionally excluded from the app audit scope.

### 3) Route inventory is ahead of full backend contract verification
The frontend route tree clearly defines many modules. However, not every route has a directly inspected backend controller and authorization proof in the current workspace.

Status: route inventory exists; full matrix is still partial in several areas.

### 4) Need for evidence-based route mapping
The prompt requires exact endpoint traces. Current evidence confirms the public website endpoints and the admin website endpoints directly. Many admin/member/treasurer routes still need direct controller tracing before they can be marked as fully verified.

Status: partial but improving.

## Verified routes
- `/api/public/website/settings`
- `/api/public/website/content/{contentType}`
- `/api/public/website/content/{contentType}/{slug}`
- `/api/public/website/leadership`
- `/api/public/website/people/{slug}`
- `/api/public/website/contact`
- `/api/admin/website/dashboard`
- `/api/admin/website/content`
- `/api/admin/website/people`
- `/api/admin/website/media`

## Not yet fully verified
- member-specific API endpoints
- admin member-management endpoints
- treasurer financial endpoints
- super-admin operational endpoints
- direct authorization checks on many pages

## Risk level
Moderate: the app has strong structure, but route-to-controller mapping and authorization proof remain incomplete for many business workflows.
