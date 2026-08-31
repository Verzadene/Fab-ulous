# CLAUDE.md

## Project Overview
- Project: `FABulous`
- Repository: `https://github.com/Verzadene/Fab-ulous`
- Purpose: Community platform for sharing software and hardware projects, with Google OAuth, email MFA, password reset by email, profile management, posts, messages, commissions, and admin tooling.

## Tech Stack
- Backend: PHP 8.2 with `mysqli`
- Frontend: HTML, CSS, JavaScript, Bootstrap 5.3
- Database: **12 separate MySQL databases** (micro-database architecture) via XAMPP. Foreign key constraints are NOT enforced at the DB level — all referential integrity and cascading deletes are handled in PHP.
- Auth: username/email login, Google OAuth 2.0, email MFA

## Local Run Notes
- App root: `C:\xampp\htdocs\Fab-ulous`
- Landing page: `http://localhost/Fab-ulous/landing/landing.html`
- Google callback: `http://localhost/Fab-ulous/oauth/oauth2callback.php`
- **Database setup:** Run `database/setup_micro_dbs.sql` to create all 12 databases.
- Forgot-password reset codes should only be created and emailed for existing `accounts.email` values.
- Shared auth page spacing and helper/status styles live in `login/login.css`.

---

## Instructions For Code Changes

### 1. Multi-Database Architecture
The application uses **12 separate MySQL databases** (one per domain). Because MySQL does not enforce foreign keys across databases:

- All data integrity, referential constraints, and cascading deletes **must be handled in PHP**.
- When deleting a user, `AdminRepository::processDeleteUser()` explicitly deletes their records from every relevant database in sequence.
- No SQL `JOIN` can be written across databases in a normal prepared statement. Use one of two patterns:
  - **(a) Fully-qualified names** — `db_name`.`table_name` in the SQL string (read-heavy queries where the cross-DB fetch happens in a single statement, e.g. `getFeed`, `getAllCommissions`).
  - **(b) Application-level aggregation** — fetch IDs/data from DB-A first, then run a separate prepared statement against DB-B, merge in PHP (e.g. `getComments`, `getUnreadNotifications`, `getConversation`).

### 2. Database Access & Configuration
- `config.php` defines `DB_CONFIG` (a constant array of all 12 database credentials) and the `db_connect(string $domain): mysqli` function.
- `config.local.php` **must define `DB_CONFIG` before `config.php`** (it is loaded first via `require_once` at the top of `config.php`). Because PHP constants cannot be redefined, the local file must provide the complete `DB_CONFIG` array — individual `DB_NAME_*` constants are informational only.
- To get a connection: `db_connect('domain_name')` — e.g. `db_connect('posts')`, `db_connect('accounts')`.
- Repository classes call `$this->getConnection('domain')` which internally calls the factory passed to their constructor.
- All database queries **must use prepared statements**.

### 3. Column Name Reference (post-migration)
These columns were renamed during the monolith → micro-DB migration:

| Table | Old name | New name |
|---|---|---|
| `friendships` | `requesterID` | `user1_id` |
| `friendships` | `receiverID` | `user2_id` |
| `messages` | `sender_id` | `senderID` |
| `messages` | `receiver_id` | `receiverID` |
| `comments` | `content` | `comment_text` |

Always use the **new names** in new code. The canonical schema is in `database/setup_micro_dbs.sql`.

**Migration script for upgrading an older `fab_ulous_messages` database:** Databases created before the column-rename pass may still have `sender_id` / `receiver_id` in the `messages` table. Run `database/migration_messages_canonical.sql` to bring them up to canonical. The script is idempotent (uses `information_schema` checks before each `ALTER`) and safe to run on already-canonical databases. The friendships and comments DBs do not need a migration — they were always created with canonical names. `MessageRepository::getMessagesSchema()` has fallback logic for `message_text`/`content` and `created_at`/`timestamp`, but **no fallback for `senderID`/`receiverID`** — those must be canonical or the messaging UI fails with "Conversation unavailable".

### 4. Repository Pattern
Endpoint scripts (`.php` controllers) should be thin HTTP controllers. Place all:
- Database queries
- Multi-step business logic (e.g. save a record AND fire a notification)
- Cascading deletes

…inside the relevant `*Repository.php` class.

### 5. Authentication & Session
Read the full flow before editing. Authentication changes must keep `$_SESSION['user']`, `$_SESSION['mfa_verified']`, and pending-verification state consistent across `login/`, `oauth/`, `register/`, `profile/`, and `config.php`.

### 6. Google OAuth
Treat as sign-in for **existing accounts only**. If the Google email is not found in `accounts`, show a register-first message — never silently create a session.

### 7. Account Deletion
- Super admins can delete any user except other super admins.
- Regular admins can delete regular users only.
- Deletion requires a reason that is emailed to the user. Email failure does not block deletion but is logged in the audit trail.
- `AdminRepository::processDeleteUser()` performs all cross-DB cascading deletes in sequence.

### 8. UI/UX Consistency
Keep typography (`Josefin Sans`, `Inter`) and design tokens (green palette, rounded controls) consistent. Preserve the current visual language and responsive layout patterns.

### 10. Auth Slider Animations
The three-panel sliding transition between `login.php`, `admin/admin_login.php`, and `register/register.html` is managed by a single shared file: **`login/auth_slider.js`**.

**Architecture:**
- Each auth page loads `auth_slider.js` and calls `AuthSlider.init({ page: '<pageName>' })` at the bottom of `<body>`.
- Valid page names: `'login'` (offset 0), `'admin'` (offset -100%), `'register'` (offset -200%).
- The module uses `window.addEventListener('pageshow', ...)` — **not** `DOMContentLoaded` — so it fires on both fresh loads and Back-Forward Cache (bfcache) restores.
- On a bfcache restore (`event.persisted === true`), the slider is immediately snapped back to its canonical resting position and stale `sessionStorage` is cleared, preventing the `.card-container` disappearing bug.
- On a fresh load, it reads `sessionStorage.slideFrom` to decide whether to animate in, then attaches click interceptors for outgoing navigation.
- Do **not** put any slider `transform` logic inline in the auth pages or in `register.js`. All slider state lives in `auth_slider.js`.

### 9. Uploads & Paths
Validate MIME type and file size. Ensure target folders exist. Keep relative asset links and local callback URLs compatible with `http://localhost/Fab-ulous/...`.

### 11. Auth Entry Point Redirects
All entry points that could show a login or registration form must redirect already-authenticated users to their dashboard immediately — no redundant login, no UI inconsistency.

**Rule:** If `$_SESSION['user']` is set AND `$_SESSION['mfa_verified']` is truthy, the visitor has a complete session. They must be sent to `dashboard_path_for_role(role)` regardless of where they came from.

**PHP files** (`login/login.php`, `admin/admin_login.php`): Add the guard at the very top of the file, immediately after `session_start()` + `require_once config.php`. Example:
```php
if (!empty($_SESSION['user']) && !empty($_SESSION['mfa_verified'])) {
    header('Location: ' . dashboard_path_for_role($_SESSION['user']['role'] ?? 'user'));
    exit;
}
```

**HTML files** (`landing/landing.html`, `register/register.html`): Cannot run PHP. Use a lightweight JS `fetch` to ping `auth_status.php` (project root) on every page load, then `window.location.replace(data.redirect)` if `data.authenticated` is true. Also attach the same check to the `pageshow` event so it fires correctly on Back-Forward Cache (bfcache) restores.

**`auth_status.php`** (project root): A new, dedicated endpoint that starts the session, checks `$_SESSION['user']` and `$_SESSION['mfa_verified']`, and returns `{"authenticated":true,"redirect":"..."}` or `{"authenticated":false}` as JSON. It requires no input and has no side effects.

### 12. Audit Log: Live Audit (Login, Logout, Posts, Reacts, Comments)
User activity is tracked in the `audit_log` table in the `fab_ulous_audit_log` database and surfaced live in Admin Dashboard > Live Audit.

**Login** is recorded inside `login/verify_mfa.php` immediately after `begin_user_session()` succeeds and before the `header('Location: ...')` redirect. The Google OAuth path (`oauth/oauth2callback.php`) calls `begin_user_session()` directly and logs its own `'User Login via Google OAuth'` entry the same way.

**Logout** is recorded inside `login/logout.php` and `admin/admin_logout.php` **before** `session_destroy()` while the user identity is still available in `$_SESSION['user']`.

**Posts, Reacts (likes), and Comments** are recorded from `post/PostRepository.php`:
- `processCreatePost()` logs on a successful post creation (`target_type = 'post'`).
- `processDeletePost()` logs on a successful self-delete of a post (`target_type = 'post'`, pre-existing). Admin-initiated post removal is logged separately by `AdminRepository::processDeletePost()`, also `target_type = 'post'`.
- `processLike()` logs on every like **and** unlike toggle (`target_type = 'like'`).
- `addComment()` logs on a successful new comment (`target_type = 'comment'`).
- `deleteComment()` logs on a successful self-delete of a comment (`target_type = 'comment'`). It looks up the comment's parent `postID` before the row is deleted (needed for `target_id`), and now correctly checks `affected_rows` before reporting success — previously it returned `true` from `$stmt->execute()` alone, which would report success even if the `WHERE commentID = ? AND userID = ?` matched no row (e.g. a non-owner delete attempt).

Each of these repository methods takes an optional `$actorUsername` argument — the calling controller (`post/create_post.php`, `post/like.php`, `post/comment.php`) passes `$_SESSION['user']['username']` in. If it's omitted or empty, no audit row is written for that call, so any new call site must pass the username to be tracked. `PostRepository::logAuditAction()` accepts a `$targetType` parameter (defaults to `'post'` for backward compatibility with the existing self-delete-post call site).

**Schema compatibility:** All of the above reuse the existing `audit_log` columns without any schema change:
- `admin_id` / `target_id` → the acting user's own `id` (or the post ID for post/like/comment events)
- `admin_username` → the acting user's `username`
- `action` → a human-readable description (e.g. `'User Login'`, `'User jdoe liked post #42'`)
- `target_type` → the Live Audit filter category: `'login'`, `'logout'`, `'post'`, `'like'`, or `'comment'`. (Admin-initiated account actions such as ban/unban/promote/demote/delete still use `'account'`, set via `AdminRepository::logAuditAction()`'s heuristic — this is intentionally distinct from `'login'`/`'logout'` so the Live Audit type filter doesn't conflate the two.)
- `visibility_role` → `'admin'` (visible to all admins and super_admins)

**Filter compatibility:** `AdminRepository::searchAuditLogs()` selects `al.target_type` alongside the existing columns. The admin dashboard's Live Audit card renders it as a `data-type` attribute on each `.audit-entry`, and a client-side radio-button filter (All / Logins / Logouts / Reacts / Comments / Posts) in `admin/admin.php`'s `filterAuditLog()` JS combines it with the existing text-search filter — both apply to the log entries already loaded for the selected time window. Because `admin_username`, `action`, `first_name`, and `last_name` are all columns already included in `searchAuditLogs()`, the existing search bar covers all these event types with no additional server-side code beyond the `target_type` SELECT.

**When adding a new activity type to log:** call `logAuditAction()` (on the relevant repository) with a distinct `$targetType`, and add a matching entry to the `$auditTypes` array in `admin/admin.php`'s Live Audit filter markup so it appears as a filter option.

### 13. Email Domain Whitelist
User registration and Google OAuth sign-in are restricted to a whitelist of approved email domains for security and organizational purposes.

**Allowed domains:**
- `@gmail.com`
- `@dlsud.edu.ph`
- `@outlook.com`

**Configuration:**
- The whitelist is defined in `config.php` as the `ALLOWED_EMAIL_DOMAINS` constant (an array of domain strings).
- To modify the whitelist, update `ALLOWED_EMAIL_DOMAINS` in `config.php`. The constant is also available in `config.local.php` for environment-specific overrides.

**Validation layers:**
1. **Frontend (register.html / register.js):** Email domain validation occurs on form submit before sending to the server. The `isEmailDomainAllowed()` function checks the domain and displays an error message if unsupported. This provides immediate user feedback without a server round-trip.
2. **Backend (register.php):** The `is_email_domain_allowed($email)` helper function (defined in `config.php`) validates the email domain. Non-whitelisted domains are rejected with error code `invalid_email_domain`, redirecting to `register.html?error=invalid_email_domain`.
3. **Google OAuth (oauth2callback.php):** After exchanging the authorization code and fetching user info from Google, the email domain is validated **before** checking if the user exists in the database. Non-whitelisted Google emails are rejected with error code `oauth_unsupported_domain`.

**Error messages:**
- **Frontend submit:** "Unsupported email domain. Please use @gmail.com, @dlsud.edu.ph, or @outlook.com."
- **Backend redirect:** Shown in `register.html` with the same message.
- **Google OAuth rejection:** Users see "Unsupported email domain. Please use @gmail.com, @dlsud.edu.ph, or @outlook.com." in the login error panel.

**Important notes:**
- The `is_email_domain_allowed()` function is case-insensitive for domain comparison (uses `strtolower()`).
- Domain validation happens **after** all other checks (e.g., password strength, existing user checks) to provide clear, focused error messages.
- Google OAuth treats unsupported domains as a fatal error — a registration-first message is not shown. If a user attempts Google sign-in with an unsupported domain, they are redirected to login with the error message and cannot proceed to registration.

### 14. Post Visibility & Feed Scope Toggle (Public / Friends Only)

Posts carry a `visibility` column (`fab_ulous_posts`.`posts.visibility`, `ENUM('public','friends')`, default `'friends'`). A top-left pill toggle in the topnav (rendered by `includes/app_nav.php`, gated by `$navShowFeedScope`) lets the viewer switch their feed between:

- **Friends Only** (default) — own posts + accepted friends' posts, regardless of each post's `visibility` value. Unchanged from the original friends-only feed.
- **Public** — own posts + any post platform-wide where `visibility = 'public'`, independent of friendship.

**Key files:**
- `database/setup_micro_dbs.sql` — canonical `posts` table now includes `visibility`.
- `database/migration_posts_visibility.sql` — idempotent `ALTER TABLE` for installs created before this feature (checks `information_schema` first, like `migration_messages_canonical.sql`).
- `post/PostRepository.php` — `getFeed(int $userID, int $limit = 20, string $scope = 'friends')` takes the new `$scope` param; `createPost()` / `processCreatePost()` take a `$visibility` param (sanitized to `'public'` or `'friends'`, defaulting to `'friends'`).
- `post/feed_api.php` — reads `?scope=public|friends` from the query string (sanitized server-side; anything else falls back to `friends`).
- `post/create_post.php` — reads `$_POST['visibility']` (sanitized the same way).
- `includes/app_nav.php` — renders the Friends Only / Public toggle at the **top-left of the topnav, next to the logo**, only when the including page sets `$navShowFeedScope = true`. Currently only `post/post.php` sets this flag. The toggle has no role restriction — it renders identically for `user`, `admin`, and `super_admin` sessions.
- `post/post.php` — sets `$navShowFeedScope = true`; adds the Friends Only / Public radio choice to the Create Post modal (defaults to Friends Only) and mirrors the choice in the live preview; `setFeedScope(scope)` JS toggles the active tab and re-calls `loadFeed()`; `renderFeed()` shows a small visibility badge per post card and a scope-aware empty state.

**Rules:**
- Never change the default visibility of a new post to `public`; the create-post radio must default to `'friends'` and the feed toggle must default to `'friends'` on page load.
- Do not let the `public` scope query touch the friendships table — it must be friendship-independent by design (own posts + any post marked public, full stop).
- Do not let the `friends` scope query filter on `visibility` — friends should keep seeing all of a friend's posts regardless of that post's visibility value, exactly as before this feature was added.

---

## Commission System Architecture

### Role Separation (effective after refactor)

The commission system is split into two strictly separated surfaces:

| Surface | File | Who uses it | What it does |
|---|---|---|---|
| **Client interface** | `post/commissions.php` | All roles (user, admin, super_admin) | Submit a personal commission request; track own requests; pay via PayMongo |
| **Admin management** | `admin/admin.php` → Commissions tab | admin, super_admin only | View all platform commissions; update status/note/amount; view/download attached files |

**Key rules:**
- `post/commissions.php` **always** calls `getAllCommissions(false, $userId)` — it fetches only the signed-in user's own rows, regardless of role. An admin who visits `commissions.php` sees only their personal requests.
- `admin/admin.php` **always** calls `getAllCommissions(true, $adminID)` to get the full platform list.
- The AJAX update endpoint for admin saves is `admin/commission_update.php` (not `commissions.php`).

### No Self-Approval Rule

An admin **cannot approve, reject, or modify** a commission they personally submitted. Enforced at two layers:

1. **UI layer (`admin.php`):** Rows where `$c['userID'] === $adminID` render a `Self-request` placeholder (no lock emoji) instead of the update form. The select, note, amount field, and Save button are never rendered for self-submissions.
2. **Server layer (`admin/commission_update.php`):** Before processing any update, the endpoint fetches the commission's `userID` and compares it to `$_SESSION['user']['id']`. If they match it returns `{success: false, error: '...'}` and exits — the update is blocked regardless of what the client sends.

### Session Variable Used
The check uses `$_SESSION['user']['id']` (set by `begin_user_session()` in `config.php`) compared against `commissions.userID` (now explicitly SELECTed as `c.userID` in `CommissionRepository::getAllCommissions()`).

### Assigned Position for Commission

Each commission can have one admin/super_admin assigned as the "Assigned Position" responsible for handling it, stored as `commissions.assigned_admin_id` (nullable — `NULL` means unassigned).

**Key files:**
- `database/fab_ulous_setup_micro_dbs.sql` — canonical `commissions` table includes `assigned_admin_id` (with a supporting index).
- `database/migration_commissions_assigned_admin.sql` — idempotent `ALTER TABLE` for installs created before this feature (checks `information_schema` first, like `migration_posts_visibility.sql` / `migration_messages_canonical.sql`).
- `post/CommissionRepository.php` — `getAllCommissions()` detects the column via the existing `SHOW COLUMNS` schema-evolution check and, when present, LEFT JOINs back to `` `fab_ulous_accounts`.accounts `` (fully-qualified, cross-DB) to pull the assigned admin's username/name/email for both the admin and requester query paths. `getAdminRoster()` returns active (non-banned) admin/super_admin accounts for the assignment dropdown. `assignCommissionAdmin()` is the raw data write; `processAssignCommission()` layers on validation, audit logging, and the access-control rules below.
- `admin/commission_assign.php` — dedicated AJAX endpoint for assignment (separate from `commission_update.php`, mirroring its auth pattern).
- `admin/admin.php` — Commissions tab renders an "Assigned To" column (role-gated, see below) and fetches `$adminRoster` for the Super Admin dropdown. `assign_commission` is also handled as a POST-action fallback for non-JS submits.
- `post/commissions.php` — the requester's own commission table includes an "Assigned Admin" column with a `mailto:` link to the assigned admin's email.

**Access control (enforced server-side in `processAssignCommission()`, not just hidden in the UI):**
1. **Only a Super Admin may assign or reassign a commission.** Regular admins — including the admin currently assigned to a commission — cannot change any assignment. The UI hides the assignment select for non-super-admins; `commission_assign.php` and `processAssignCommission()` both re-check `$isSuperAdmin` regardless of what the client sends.
2. **An admin cannot be assigned to a commission they submitted themselves.** This extends the existing No Self-Approval rule (see above) from status/note/amount updates to assignment: `processAssignCommission()` rejects the attempt, and the Super Admin's dropdown in `admin.php` skips the requester's own account as an option.
3. **Visibility of the assignment is role-gated per viewer, per commission:**
   - **Super Admin:** always sees who is assigned (or "Unassigned") and gets the reassignment dropdown, for every commission.
   - **The assigned admin:** sees a read-only "Assigned to you" badge for commissions assigned to them.
   - **Any other regular admin ("non-assigned admin"):** cannot see who a commission is assigned to — sees a "Restricted" placeholder instead (no lock emoji). They can still see whether a commission is unassigned (not considered sensitive).
   - **The requesting user** (on `post/commissions.php`): always sees the assigned admin's name and contact email once one is assigned, so they know who to reach — this is the one case where the assignee's identity is intentionally exposed outside the admin/super_admin roles.
4. **Target validation:** the assignee must currently be an active (`banned = 0`) `admin` or `super_admin` account; `processAssignCommission()` checks the candidate against `getAdminRoster()` before writing.

**Audit logging:** assignment changes call `CommissionRepository::logAuditAction()` with `target_type = 'commission'` (the same category as status/note/amount updates — no new Live Audit filter bucket was added for this).

**Not included in this pass:** assigning a commission does not send a notification to the assigned admin. `create_notification()`'s `type` column is a DB-level `ENUM`, so adding a new notification type would require its own schema migration — left as a follow-up rather than folded into this schema change.

---

## Admin Features

### User Account Banning
- **Location:** Admin Dashboard > User Management tab
- **UI:** Ban button (super admins can ban admins or users; regular admins can ban users only). Banned users show an Unban button instead.
- **Modal:** Bootstrap modal with orange accent (`#e67e22`), ban reason textarea (1000 char limit, required), character counter, Cancel + "Ban Account" buttons, and a final `confirm()` dialog.
- **Implementation:** `openBanUserModal()` / `confirmBanUser()` JS in `admin.php`; `processBanUser()` in `AdminRepository.php`.
- **Unban:** Dedicated Bootstrap modal with green accent. Uses `openUnbanUserModal()` / `confirmUnbanUser()`.

### Post Removal
- **Location:** Admin Dashboard > Feed Moderator tab
- **UI:** "Remove" button per post row opens a confirmation modal (`removePostModal`) that clones the red-accented Delete User Account pattern.
- **Modal:** Warning banner (post ID + owner username + caption preview), mandatory "Reason for Removal" textarea (1000 char limit, required, live character counter), Cancel + "Remove Post" buttons, and a final `confirm()` dialog.
- **Implementation:** `openRemovePostModal()` / `confirmRemovePost()` JS in `admin.php`; `processDeletePost()` in `AdminRepository.php`.
- **Email:** `send_post_removal_email()` in `config.php` — notifies the post owner with their post ID, a caption preview, and the admin-typed reason. Email failure does not block removal but is noted in the audit trail.
- **Audit Logging:** Action string includes post ID, owner username, owner userID, reason, and email delivery status. Target type resolves to `'post'` via `logAuditAction()`.
- **Cascade:** Likes and comments are deleted before the post record (referential integrity enforced in PHP per the micro-DB architecture).

### Account Deletion
- **Location:** Admin Dashboard > User Management tab
- **UI:** Delete button for eligible users only.
- **Modal:** Warning banner, deletion reason textarea (required), Cancel + "Delete Account Permanently" buttons.
- **Implementation:** `openDeleteUserModal()` / `confirmDeleteUser()` JS in `admin.php`; `processDeleteUser()` in `AdminRepository.php`.
- **Email:** `send_account_deletion_email()` in `config.php`.
- **Safeguards:** Cannot delete self; cannot delete super_admin accounts.

---

## UI Patterns

### Navigation & Help Button
- Top nav (`includes/app_nav.php`): burger menu (left-slide drawer) + Help button.
- Help button triggers a Bootstrap Offcanvas panel (`id="helpOffcanvas"`) sliding in from the right.
- **Do not** revert the Help button to a plain `<a href="README.md">`. Keep it as `<button data-bs-toggle="offcanvas">`.
- All Help / offcanvas styles live at the bottom of `post/post.css` (globally imported by every authenticated page).

### Admin Dashboard Entry Points
- The burger-menu drawer link to `admin/admin.php` is rendered once, inside `includes/app_nav.php`, gated by `$navIsAdmin` (`$isAdmin ?? in_array($navRole, ['admin', 'super_admin'], true)`). It shows identically for `admin` and `super_admin` — no separate super-admin-only variant.
- Every authenticated page that includes `app_nav.php` (`post/post.php`, `profile/profile.php`, `post/messages.php`, `post/commissions.php`) must set `$role`/`$isAdmin` from `$_SESSION['user']['role']` before the `require` — even though `app_nav.php` has a `$_SESSION`-based fallback, pages should set these explicitly for clarity and consistency.
- `profile/profile.php` additionally has its own persistent right-hand "Account Info" sidebar (separate from the drawer). It shows an "Admin Dashboard" link above Logout, gated by the same `$isAdmin` flag, for both `admin` and `super_admin`.

---

### 15. Payment Status Lifecycle & Portable App Base URL

**Payment status (`commission_payments.status`):** lifecycle is `'ongoing'` → `'paid'` (webhook confirms) or `'failed'` (checkout fails / superseded by a new Pay click / duplicate-paid guard). `createPendingPaymentRecord()` inserts rows as `'ongoing'`; `processWebhookPayment()` only promotes `'ongoing'` rows to `'paid'`. Older installs upgrade via the idempotent backfill in `database/fab_ulous_setup_micro_dbs.sql` (`status = 'pending'` → `'ongoing'`).

**Portable base URL:** `APP_URL` and `GOOGLE_REDIRECT_URI` are no longer hardcoded to `http://localhost/Fab-ulous`. `config.php`'s `fabulous_detect_base_url()` derives scheme + host + folder from the current request (diffing `__DIR__` against `$_SERVER['DOCUMENT_ROOT']`), so the app works under `htdocs` on any machine/folder name without edits, given the same `config.local.php` API keys. `config.local.php` no longer defines `APP_URL`/`GOOGLE_REDIRECT_URI`; set an env var or uncomment the `define()` there only to force a fixed value (e.g. an HTTPS tunnel for OAuth testing).

## Verification Checklist
1. Run `php -l` on every edited PHP file.
2. Re-test the affected route in the browser after each auth or upload change.
3. For CSS updates, check both desktop and mobile widths.
4. For database-related updates, verify behaviour against both a fresh setup and a migrated setup.
5. For password reset changes, verify both the success path and the SMTP failure path.
6. When adding a new cross-domain query, confirm it uses either fully-qualified names *or* application-level aggregation — never a direct JOIN on plain table names across connections.

---

## Current Repo-Specific Guardrails
- Do not remove MFA behaviour when changing login logic.
- Do not auto-link or auto-create accounts for unknown Google emails without an explicit requirement.
- Do not introduce new fonts or CSS tokens when existing page variables already cover the need.
- Do not rely on client-side validation alone for passwords, uploads, or account updates.
- Do not silently swallow password reset email failures.
- Do not redirect unknown emails into the reset-password flow as though a reset code was sent.
- Keep uploaded user content out of git (`/uploads/` is gitignored).
- Feed posts are **friends-only by default** (posts.visibility = 'friends'). A post only becomes visible outside the author's accepted friends when the author explicitly marks it `public` at creation time. Discovery and moderation surfaces must not leak `friends`-visibility posts to non-friends.
- The Public feed tab is opt-in per viewer (Friends Only vs Public toggle) and opt-in per post (visibility radio in the Create Post modal). Never default either control to `public`.
- **Never use cross-database JOIN syntax in new queries.** Always use the qualified-name or app-level-aggregation pattern described in instruction #1 above.
- Keep `audit_log.target_type` values distinct per Live Audit filter category (`login`, `logout`, `post`, `like`, `comment`, `account`, `commission`). Do not reuse an existing value for a new, unrelated event type — it will silently leak into another filter bucket on the admin dashboard.
- **Assigned Position for Commission:** never render `assigned_admin_id`/`assigned_admin_name`/`assigned_admin_username` (or embed them in a `data-*` attribute, search string, etc.) for a regular admin who isn't the assigned admin on that commission — that data must stay server-side-filtered per viewer, not just visually hidden. Only Super Admins and the assigned admin themself may see who a commission is assigned to; the requesting user may see the assigned admin's name/email (not the admin dashboard). Only a Super Admin may change an assignment, and an admin can never be assigned to their own submitted commission.

---

## Recommended Optional Project Files
- `CLAUDE.local.md` — personal, untracked preferences or reminders
- `mcp.json` — shared integrations (GitHub, etc.)
- `.claude/rules/` — modular coding, testing, and API rules
- `.claude/commands/` — repeatable slash-command workflows