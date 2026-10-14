# KHWWC Role Permission Matrix

## Roles observed in app structure and backend route usage
- Public / unauthenticated
- Member
- Treasurer
- Secretary
- Chairperson
- Admin
- Super Admin
- Executive / patron / vice roles are present in member leadership model and UI role names

## Permission pattern
The app uses JWT authentication and role-gated layouts in `frontend/src/App.tsx`. The route graph is the clearest signal of the current intended access control. The backend routes in `khwwc/routes/api.php` also use middleware such as `auth.jwt`, `admin`, and `throttle:api`.

| Role | Access pattern | Evidence | Status |
|---|---|---|---|
| Public | Login, public site reads, public contact form | `routes/api.php` with `/public/website/*`, `/api/auth/login` | Verified |
| Member | Personal dashboard, profile, notifications, documents, downloads, payment actions | member route tree in App.tsx | Partial |
| Treasurer | Financial dashboards, contribution reports, wallet reports, bank reconciliation pages | `/treasurer` routes in App.tsx | Partial |
| Secretary | Secretary dashboard, meeting minutes, reviews | `MinutesReview`, `MinutesManagement`, `SecretaryDashboard` | Partial |
| Chairperson | Chairperson dashboard, approval reviews | `ApproveMinutes`, `ChairpersonDashboard` | Partial |
| Admin | Broad admin and website management access | `/admin` route tree and `admin` middleware on website routes | Partial |
| Super Admin | System monitoring, security, financial oversight, admin management | SuperAdmin route definitions in App.tsx | Partial |

## Current evidence-based issues
- The frontend route tree declares access by role, but the exact backend authorization rules are not yet fully mapped for every route.
- `routes/api.php` confirms `auth.jwt` and `admin` middleware are used, but direct per-route policy/gate trace is incomplete in the current workspace.
- UI hiding or route-level navigation is not a substitute for server-side authorization.

## Minimum safe interpretation
- Only verified backend-authenticated routes should be treated as safe for member/admin actions.
- Any route that is not directly verified to be protected by server-side authorization is treated as `PARTIAL` until confirmed.
