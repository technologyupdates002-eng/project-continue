# KHWWC Master API Matrix

## Scope
This matrix is based on evidence from the app code in `/home/laban/Documents/kirinyaga App/frontend/src/App.tsx` and the Laravel routes in `/home/laban/Documents/kirinyaga App/khwwc/routes/api.php` plus the controllers referenced there.

## Evidence baseline
- Frontend app routes are defined in `frontend/src/App.tsx`.
- Laravel API routes are registered in `khwwc/routes/api.php`.
- The default app test suite passes in the current workspace.
- Production website/domain code was intentionally not treated as part of this app audit.
- For the complete Treasurer-only page/action/API inventory, see [KHWWC Treasurer Frontend/API Matrix](./KHWWC_TREASURER_FRONTEND_API_MATRIX.md).
- For the Admin-role route and page/action/API inventory, see [KHWWC Admin Frontend/API Matrix](./KHWWC_ADMIN_FRONTEND_API_MATRIX.md).
- For the Super Admin route and page/action/API inventory, see [KHWWC Super Admin Frontend/API Matrix](./KHWWC_SUPER_ADMIN_FRONTEND_API_MATRIX.md).
- For the complete bank statement upload → matching/member creation → contribution allocation flow, see [KHWWC Bank Statement Import End-to-End](./KHWWC_BANK_STATEMENT_IMPORT_END_TO_END.md).

## API matrix

| Frontend route | Component | Role | Laravel endpoint | Method | Auth | Authorization | Evidence / status |
|---|---|---|---|---|---|---|---|
| `/login` | `Login` | Public | `/api/auth/login` (alias `/api/auth/v1/token`) | POST | Public | None at route layer; JWT issued by `AuthController` | Verified in `routes/api.php`; `AuthController@token` |
| `/member` | `MemberDashboard` | Member | `/api/member/dashboard` (where implemented in the app API layer) | GET | JWT | Member-only | App route exists; backend contract needs direct verification in member controller set |
| `/member/profile` | `MemberProfile` | Member | member profile API under member namespace | GET/PUT | JWT | Member ownership | Present in app structure; status: partial until controller route is directly traced |
| `/member/contributions` | `MemberDashboard` / contribution UI | Member | contribution read endpoints under member/admin API modules | GET | JWT | Member scope or admin scope | app route exists; verification partial |
| `/admin` | `AdminDashboard` | Admin | `/api/admin/website/dashboard` | GET | JWT | admin middleware | Verified in `routes/api.php`; `WebsiteContentController@adminDashboard` |
| `/admin/members` | `Members` | Admin | admin member list endpoints in `MemberController` / `admin/api` routes | GET | JWT | admin | Present in route tree; exact route list must be traced further |
| `/admin/contributions` | `Contributions` | Admin | admin contribution endpoints | GET/POST | JWT | admin | Present in app; backend contract continues to require route audit |
| `/admin/payments` | `Payments` | Admin | admin payment endpoints | GET/POST | JWT | admin | Present in app; backend contract continues to require route audit |
| `/treasurer` | `TreasurerDashboard` | Treasurer | treasurer financial endpoints | GET | JWT | treasurer/admin | Present in app route tree |
| `/admin/website/dashboard` | `WebsiteManagement` / dashboard summary | Admin | `/api/admin/website/dashboard` | GET | JWT | admin | Verified in `routes/api.php` and `WebsiteContentController@adminDashboard` |
| `/admin/website/content` | `WebsiteContentManagement` | Admin | `/api/admin/website/content` and `/api/admin/website/content/{itemId}` | GET/POST/PUT/POST archive | JWT | admin | Verified in `routes/api.php`; controller `WebsiteContentController` |
| `/admin/website/people` | `WebsiteLeadershipManagement` | Admin | `/api/admin/website/people` and `/api/admin/website/people/leadership-members` | GET/POST/PUT/PATCH | JWT | admin | Verified in `routes/api.php`; controller `WebsitePublicProfileController` |
| `/public/website/contact` | public contact form | Public | `/api/public/website/contact` | POST | Public | throttle:5,1 | Verified in `routes/api.php`; `WebsiteContactController@store` |
| `/public/website/settings` | public site settings | Public | `/api/public/website/settings` | GET | Public | none | Verified in `routes/api.php`; `WebsiteCmsController@publicSettings` |
| `/public/website/content/{contentType}` | public content | Public | `/api/public/website/content/{contentType}` | GET | Public | none | Verified in `routes/api.php`; `WebsiteContentController@publicIndex` |
| `/public/website/leadership` | public leadership | Public | `/api/public/website/leadership` | GET | Public | none | Verified in `routes/api.php`; `WebsitePublicProfileController@index` |
| `/public/website/people/{slug}` | public profile detail | Public | `/api/public/website/people/{slug}` | GET | Public | none | Verified in `routes/api.php`; `WebsitePublicProfileController@show` |
| `/super-admin` | `SuperAdminDashboard` | Super Admin | super-admin API endpoints under `/api/admin` / admin namespace | GET/POST/PUT | JWT | super-admin/admin gating | App route exists; final authorization matrix still needs direct controller audit |
| `/admin/website/media` | `WebsiteMediaLibrary` | Admin | `/api/admin/website/media` | GET | JWT | admin | Verified in `routes/api.php`; `WebsiteCmsController@mediaIndex` |

## Status summary
- Verified with direct code evidence: login, public website content, public leadership, admin website dashboard, admin website content, admin website people, public contact.
- Partial: member portal, admin member management, treasurer financial modules, super-admin routes, and many app-specific administrative workflows.
- Missing: no single authoritative route-to-controller matrix exists for all routes yet; this file is the initial evidence baseline.
