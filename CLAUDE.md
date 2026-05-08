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
- **Database setup:** Run `database/setup_micro_dbs.sql` to create all 12 databases. The old `setup.sql` and migration files are deprecated.
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

---

## Admin Features

### User Account Banning
- **Location:** Admin Dashboard > User Management tab
- **UI:** Ban button (super admins can ban admins or users; regular admins can ban users only). Banned users show an Unban button instead.
- **Modal:** Bootstrap modal with orange accent (`#e67e22`), ban reason textarea (1000 char limit, required), character counter, Cancel + "Ban Account" buttons, and a final `confirm()` dialog.
- **Implementation:** `openBanUserModal()` / `confirmBanUser()` JS in `admin.php`; `processBanUser()` in `AdminRepository.php`.
- **Unban:** Dedicated Bootstrap modal with green accent. Uses `openUnbanUserModal()` / `confirmUnbanUser()`.

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

---

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
- Feed posts are friend-only; discovery and moderation surfaces must not leak non-friend posts into the user feed.
- **Never use cross-database JOIN syntax in new queries.** Always use the qualified-name or app-level-aggregation pattern described in instruction #1 above.

---

## Recommended Optional Project Files
- `CLAUDE.local.md` — personal, untracked preferences or reminders
- `mcp.json` — shared integrations (GitHub, etc.)
- `.claude/rules/` — modular coding, testing, and API rules
- `.claude/commands/` — repeatable slash-command workflows