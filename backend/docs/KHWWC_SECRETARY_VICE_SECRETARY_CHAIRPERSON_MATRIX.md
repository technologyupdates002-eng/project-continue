# Secretary, Vice Secretary, and Chairperson Frontend Matrix

This matrix describes the frontend pages, user-visible actions, API calls, and handoffs connecting the three roles.

## Role routes and pages

| Role | Frontend route | Page/component | Main purpose |
|---|---|---|---|
| Secretary | `/secretary` | `SecretaryDashboard` | Dashboard overview, event and member/document summaries, secretary withdrawal approvals |
| Secretary | `/secretary/events` | `AdminEvents` | Create, update, and manage events |
| Secretary | `/secretary/minutes` | `MeetingMinutes` | Prepare, edit, delete, and submit minutes |
| Secretary | `/secretary/review` | `MinutesReview` | Review Vice Secretary submissions; edit, forward, or return them |
| Secretary | `/secretary/signature` | `SecretarySignatureUpload` | Upload and manage Secretary's signature |
| Secretary | `/secretary/withdrawal-approvals` | `SecretaryWithdrawalApprovals` | Review Secretary sign-off requests |
| Vice Secretary | `/vice-secretary` | `ViceSecretaryDashboard` | Dashboard overview for events, documents, members, messages, and notifications |
| Vice Secretary | `/vice-secretary/minutes` | `MeetingMinutes` | Prepare, edit, delete, and submit minutes to the Secretary |
| Chairperson | `/chairperson` | `ChairpersonDashboard` | Executive overview and Chairperson withdrawal approvals |
| Chairperson | `/chairperson/signature` | `SignatureUpload` | Upload and manage Chairperson's signature |
| Chairperson | `/chairperson/approve-minutes` | `ApproveMinutes` | Review, print, approve, or reject Secretary-forwarded minutes |
| Chairperson | `/chairperson/withdrawal-approvals` | `ChairpersonWithdrawalApprovals` | Review Chairperson sign-off requests |

The Vice Secretary and Secretary share the minutes authoring page. Only the Secretary has a separate minutes review page; only the Chairperson has the final approval page.

## Minutes frontend matrix

| Role/page | User action or data | Frontend request | Frontend result |
|---|---|---|---|
| Secretary and Vice Secretary — `MeetingMinutes` | Load existing minutes | `GET /api/minutes?filter=mine` | Lists the caller's minutes |
| Secretary and Vice Secretary — `MeetingMinutes` | Load members and attendance choices | `GET /api/rest/v1/members?...` | Supplies member names and IDs for attendance |
| Secretary and Vice Secretary — `MeetingMinutes` | Prefill active office-bearer names/signatures | `GET /api/signatures/current?role=secretary` and `GET /api/signatures/current?role=chairperson` | Displays saved signature previews and names |
| Secretary and Vice Secretary — `MeetingMinutes` | Create draft | `POST /api/minutes` | Saves minute content and attendance |
| Secretary and Vice Secretary — `MeetingMinutes` | Edit draft/returned minutes | `PUT /api/minutes/{id}` | Saves changes through the minutes API |
| Secretary and Vice Secretary — `MeetingMinutes` | Delete draft/returned minutes | `DELETE /api/minutes/{id}` | Removes the minute from the author's list |
| Vice Secretary — `MeetingMinutes` | Submit prepared minutes | `POST /api/minutes/{id}/submit` | Places the submission in the Secretary review queue |
| Secretary — `MinutesReview` | Load review queue | `GET /api/minutes?filter=secretary_pending` | Lists submissions awaiting Secretary review |
| Secretary — `MinutesReview` | Edit content during review | `PUT /api/minutes/{id}` | Saves review edits |
| Secretary — `MinutesReview` | Approve and forward | `POST /api/minutes/{id}/secretary-review` with `decision: "approve"` | Sends minutes to Chairperson review |
| Secretary — `MinutesReview` | Return for correction | `POST /api/minutes/{id}/secretary-review` with `decision: "reject"` and notes | Returns minutes to the author with notes |
| Chairperson — `ApproveMinutes` | Load approval queue and Chairperson signature | `GET /api/minutes?filter=pending`; `GET /api/signatures/current?role=chairperson` | Lists Chairperson-pending minutes and signature readiness |
| Chairperson — `ApproveMinutes` | Upload and save a signature if missing | `POST /api/storage/v1/object/signatory-signatures/{path}`; `POST /api/signatures` | Saves the signature for approval |
| Chairperson — `ApproveMinutes` | Final approve | `POST /api/minutes/{id}/approve` | Completes the approval action |
| Chairperson — `ApproveMinutes` | Reject with notes | `POST /api/minutes/{id}/reject` | Returns the decision and notes to the minute record |

## Minutes authoring template and member-selection flow

Use this as the screen specification when rebuilding the minutes page in Lovable. The same authoring screen is used by Secretary and Vice Secretary; role controls the submit destination, while the API remains the authority for permissions and workflow state.

### Page layout

1. **Page header:** “Meeting Minutes”, a short description, and a “New Minutes” button. Show the current user's minutes in a list/table with meeting title, date, meeting type, status, and available actions.
2. **Meeting details:** Required title and meeting date; required meeting type with only `General` (`general`) and `Executive` (`executive`) values. Optionally capture venue if the form and API include it.
3. **Attendance:** Searchable member picker with distinct groups for `Present`, `Absent with Apology`, and `Absent without Apology`. Show each selected person's name and phone and a count for each group. A member must be in no more than one group.
4. **Executive meeting controls:** For `executive`, restrict attendance choices to active office bearers. Show a searchable executive-member roster and a visibility control only if the backend's intended visibility rule is implemented; see the current behavior notes below.
5. **Minutes content:** Agenda, discussions, decisions, action items, and next meeting date.
6. **Signature preview:** Display the current Secretary and Chairperson names/signature previews from the signature API. Treat these as server-resolved approval metadata; do not allow a preparer to claim another officer's signature.
7. **Actions:** Create/save draft, edit, delete (only while allowed), and submit. Vice Secretary submits to Secretary review; Secretary submits directly to Chairperson review. Display a confirmation/toast and refresh the relevant list after success.
8. **Review status:** Display canonical statuses: `draft`, `submitted_to_secretary`, `pending_chairperson_approval`, `rejected_by_secretary`, `rejected`, and `approved`. Returned minutes show their rejection notes and can be edited/resubmitted.

### Member choices: source, identity, and filtering

Use stable member IDs in component state and API payloads; names and phone numbers are display/search fields only. Do not identify a selected person by name because two members may share a name.

| Meeting type / purpose | Recommended source | Returned identity/display fields | Behavior |
|---|---|---|---|
| General attendance and absence | `GET /api/rest/v1/members?limit=1000` (or a paginated member search endpoint for larger lists) | `id`, `user_id`, `name`, `phone` | Search by name or phone; let the user assign each member to exactly one attendance bucket |
| Executive attendance and absence | `GET /api/minutes/executive-members` | `id`, `user_id`, `name`, `phone` | Server returns active members with an active executive role; use the returned `id` for attendance |
| General-minutes audience, if restricting visibility | Same member source as general attendance | `user_id`, `name`, `phone` | Send selected `user_id` values in `visible_to_members`; empty/null means all members under the current API contract |
| Executive-minutes audience | Only if the backend adds per-user visibility enforcement | `user_id`, `name`, `phone`, role(s) | Do not present a per-member restriction control until the API stores and enforces it; current backend grants access by executive role |

The current active form has two implementation mismatches worth correcting in a Lovable rebuild:

- Executive choices are currently assembled from generic `/api/rest/v1/user_roles` rows plus a limited member lookup, rather than the dedicated `/api/minutes/executive-members` endpoint. That can include non-executive roles, lose role holders outside the member lookup page, or display “Unknown”.
- The current form stores attendance and audience choices by name, then maps names back to IDs on submit. Store selected IDs from the beginning and derive labels from the fetched member objects instead.

### Attendance payload

Convert the three UI buckets into one array. `member_id` is the member record `id` (not the auth `user_id`); accepted status values are exact uppercase values:

```json
{
  "attendance": [
    { "member_id": "member-record-uuid-1", "status": "PRESENT" },
    { "member_id": "member-record-uuid-2", "status": "ABSENT_WITH_APOLOGY" },
    { "member_id": "member-record-uuid-3", "status": "ABSENT" }
  ]
}
```

The Laravel API validates that each member exists, rejects a duplicate member appearing in multiple buckets, and for executive minutes rejects attendees who are not eligible executive members. It persists normalized rows in `meeting_minute_attendance`; the response hydrates both IDs and display-name arrays (`attendees`, `absent_with_apology`, `absent_without_apology`).

### Create, edit, submit, and review requests

All minute lifecycle writes must use the dedicated `/api/minutes` API, authenticated with the app's bearer token. Do not write meeting-minute rows through generic REST table PATCH/DELETE endpoints.

Create a draft:

```http
POST /api/minutes
Content-Type: application/json
```

```json
{
  "title": "Monthly General Meeting",
  "meeting_date": "2026-10-13",
  "meeting_type": "general",
  "agenda": "Opening; reports; decisions",
  "discussions": "Discussion notes",
  "decisions": "Agreed actions",
  "action_items": "Owner and due date",
  "next_meeting_date": "2026-11-13",
  "attendance": [
    { "member_id": "member-record-uuid-1", "status": "PRESENT" }
  ],
  "visible_to_members": null
}
```

The API returns `201` with `{ "data": <hydrated minute> }`; the created status is `draft`. General minutes default to visibility for all members when `visible_to_members` is `null`. Executive minutes are marked executive and current backend read authorization is limited to executive roles.

| UI action | Request | Expected result |
|---|---|---|
| Load my minutes | `GET /api/minutes?filter=mine` | Only minutes created by the current preparer |
| Load general member choices | `GET /api/rest/v1/members?limit=1000` | Member records for general attendance; paginate/search on the server if this limit is insufficient |
| Load executive member choices | `GET /api/minutes/executive-members` | Active members holding at least one active executive role |
| Edit draft or returned minute | `PUT /api/minutes/{id}` with changed content and, when changed, the complete `attendance` array | Returns updated hydrated minute; returned `rejected`/`rejected_by_secretary` rows are reset to `draft` |
| Delete draft or returned minute | `DELETE /api/minutes/{id}` | `{ "data": { "deleted": true } }` if caller is permitted |
| Submit Vice Secretary draft | `POST /api/minutes/{id}/submit` | Status becomes `submitted_to_secretary` |
| Submit Secretary draft | `POST /api/minutes/{id}/submit` | Status becomes `pending_chairperson_approval` |
| Load Secretary review queue | `GET /api/minutes?filter=secretary_pending` | Secretary/admin queue of `submitted_to_secretary` minutes |
| Secretary approves/forwards | `POST /api/minutes/{id}/secretary-review` with `{ "decision": "approve", "notes": "..." }` | Status becomes `pending_chairperson_approval` |
| Secretary returns for correction | Same endpoint with `{ "decision": "reject", "notes": "Correction details" }` | Notes required; status becomes `rejected_by_secretary` |
| Load Chairperson queue | `GET /api/minutes?filter=pending` | Chairperson/admin queue of `pending_chairperson_approval` minutes |
| Chairperson approves | `POST /api/minutes/{id}/approve` | Status becomes `approved`; officer identity/signature metadata is resolved server-side |
| Chairperson rejects | `POST /api/minutes/{id}/reject` with `{ "notes": "Correction details" }` | Notes required; status becomes `rejected` |

For any failed request, show the API error and preserve the form values. On success, close/reset a create dialog or return to the list, invalidate the minutes query, and invalidate Secretary/Chairperson queue queries after review transitions.

## Handoff matrix

| Step | Responsible role | Frontend page | Queue/action | Next role |
|---|---|---|---|---|
| 1. Prepare | Vice Secretary (or Secretary) | `/vice-secretary/minutes` or `/secretary/minutes` | Save a draft with meeting details, attendance, and available signature/name previews | Secretary, when Vice Secretary submits |
| 2. First review | Secretary | `/secretary/review` | Inspect, edit, approve/forward, or return with notes | Chairperson on forward; author on return |
| 3. Final review | Chairperson | `/chairperson/approve-minutes` | Inspect the minute and approve or reject with notes | Completed record or Secretary for correction |

```text
Vice Secretary
  └─ prepares and submits minutes
       ↓
Secretary review queue
  ├─ edit and approve/forward ─────→ Chairperson approval queue
  └─ reject with notes ────────────→ Author revises and resubmits
                                       ↓
Chairperson approval queue
  ├─ approve ──────────────────────→ Approved minutes
  └─ reject with notes ────────────→ Secretary/author correction
```

The Secretary can also create and submit minutes directly for Chairperson review. Withdrawal sign-offs are separate from the minutes handoff and are available only on the Secretary and Chairperson withdrawal-approval routes.

## Frontend completion points

- Signature images are private; the minutes API provides signed preview URLs for rendering while stored signature paths remain server-managed.
- Names and signatures are prefilled from the current office-bearer records, not typed from memory into the review or print view.
- Attendance is shown as member names in the minutes UI; the form sends member IDs to the minutes API.
- The Secretary page exposes the intermediate review action; the Chairperson page exposes only the final approval/rejection actions.
- The Chairperson print view uses the same returned minute data, including attendee names and signature previews, as the approval workflow.

## Related frontend files

- Routes and role layouts: [`frontend/src/App.tsx`](../../frontend/src/App.tsx)
- Secretary dashboard: [`frontend/src/pages/secretary/SecretaryDashboard.tsx`](../../frontend/src/pages/secretary/SecretaryDashboard.tsx)
- Vice Secretary dashboard: [`frontend/src/pages/vice-secretary/ViceSecretaryDashboard.tsx`](../../frontend/src/pages/vice-secretary/ViceSecretaryDashboard.tsx)
- Shared minutes authoring page: [`frontend/src/pages/secretary/MeetingMinutes.tsx`](../../frontend/src/pages/secretary/MeetingMinutes.tsx)
- Secretary review page: [`frontend/src/pages/secretary/MinutesReview.tsx`](../../frontend/src/pages/secretary/MinutesReview.tsx)
- Chairperson dashboard: [`frontend/src/pages/chairperson/ChairpersonDashboard.tsx`](../../frontend/src/pages/chairperson/ChairpersonDashboard.tsx)
- Chairperson approval page: [`frontend/src/pages/chairperson/ApproveMinutes.tsx`](../../frontend/src/pages/chairperson/ApproveMinutes.tsx)
- Shared minutes API client: [`frontend/src/api/minutes.ts`](../../frontend/src/api/minutes.ts)
- Signature upload pages: [`frontend/src/pages/secretary/SecretarySignatureUpload.tsx`](../../frontend/src/pages/secretary/SecretarySignatureUpload.tsx), [`frontend/src/pages/chairperson/SignatureUpload.tsx`](../../frontend/src/pages/chairperson/SignatureUpload.tsx)
- Shared withdrawal approval page: [`frontend/src/pages/admin/WithdrawalApproval.tsx`](../../frontend/src/pages/admin/WithdrawalApproval.tsx)
