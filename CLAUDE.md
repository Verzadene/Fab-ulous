# CLAUDE.md

## Project Overview
- Project: `FABulous`
- Repository: `https://github.com/Verzadene/Fab-ulous`
- Purpose: community platform for sharing software and hardware projects, with Google OAuth, email MFA, password reset by email, profile management, posts, messages, commissions, and admin tooling.

## Tech Stack
- Backend: PHP 8.2 with `mysqli`
- Frontend: HTML, CSS, JavaScript, Bootstrap 5.3
- Database: MySQL via XAMPP
- Auth: username/email login, Google OAuth 2.0, email MFA

## Local Run Notes
- App root: `C:\xampp\htdocs\Fab-ulous`
- Landing page: `http://localhost/Fab-ulous/landing/landing.html`
- Google callback: `http://localhost/Fab-ulous/oauth/oauth2callback.php`
- Main database setup: `database/setup.sql`
- Existing schema updates: `database/migration_v3_mfa.sql`, `database/migration_v4.sql`, `database/migration_v5.sql`, `database/migration_v6_paymongo.sql`, `database/migration_v7_notifications.sql`, `database/migration_v8_profile_fields.sql`
- Password reset depends on the `password_resets` table from `database/migration_v5.sql`
- PayMongo commission payments depend on the `commission_payments` table from `database/migration_v6_paymongo.sql` and placeholder keys in `config.php` or `config.local.php`
- Commission, payment, and message notifications depend on the expanded `notifications.type` enum from `database/migration_v7_notifications.sql`.
- Forgot-password reset codes should only be created and emailed for existing `accounts.email` values.
- Shared auth page spacing and helper/status styles live in `login/login.css`

## Instructions For Code Changes
1. Read the full flow before editing. Authentication changes must check `login/`, `oauth/`, `register/`, `profile/`, and `config.php` together so redirects, sessions, and MFA stay aligned.
2. Keep database access consistent. Use prepared statements, validate input on the server, and avoid inline SQL with interpolated user data.
3. Preserve session behavior. Changes to login, logout, profile, MFA, or Google OAuth must not break `$_SESSION['user']`, `$_SESSION['mfa_verified']`, or pending verification state.
4. Treat Google OAuth as sign-in for existing accounts only unless product requirements change. If a Google email is missing from `accounts`, show a clear register-first message instead of silently creating a login session.
5. When adding account fields, ship both pieces:
   update `database/setup.sql` for fresh installs
   add a new migration in `database/` for existing databases
6. Keep typography and design tokens consistent across pages. Use `Josefin Sans, sans-serif` for display text and `Inter, sans-serif` for body text wherever the page defines font variables.
7. Preserve the current visual language. Reuse the existing green palette, rounded controls, and responsive layout patterns unless a page redesign is explicitly requested.
8. For uploads, validate MIME type and file size, make sure target folders exist and are writable, and account for browser caching when the same filename is reused.
9. Avoid breaking XAMPP-friendly paths. Keep relative asset links and local callback URLs compatible with `http://localhost/Fab-ulous/...` unless environment config is being updated intentionally.
10. If a feature depends on a schema migration or config change, surface that clearly in the UI or docs instead of failing silently.
11. Password reset emails should go through `send_password_reset_email()` in `config.php` so send failures can be surfaced consistently.
12. **Repository Pattern:** Phase 0 of the architecture migration is complete. Endpoint scripts (`.php`) should act as thin HTTP controllers. Place all database queries and multi-step business logic (e.g., executing an action *and* firing a notification) into composite methods inside the corresponding `*Repository.php` classes.
13. **Account Deletion:** Super admins can delete any user account (except other super admins). Regular admins can delete regular users only. Deletion requires a reason/description which is sent to the user via email before account removal. The deletion action is logged in the audit trail. Email send failures do not prevent deletion but are logged in the audit message.

## Admin Features

### User Account Banning
- **Location:** Admin Dashboard > User Management tab
- **UI:** Ban button appears in the Actions column for eligible users (super admins can ban admins or users; regular admins can ban users only). Banned users show an Unban button instead.
- **Modal:** Clicking Ban opens a Bootstrap modal styled like the Delete modal but with an orange accent (`#e67e22`). It includes:
  - Warning banner with a ban (circle-slash) SVG icon and user details (username, email)
  - Textarea for admin to enter a ban reason (1000 char limit; required before confirming)
  - Character counter
  - Cancel and "Ban Account" buttons
  - A second `confirm()` dialog as a final safeguard before submission
- **Implementation:**
  - Modal HTML uses `.ban-warning`, `.ban-modal-header`, `.ban-modal-title`, `.ban-modal-confirm-btn` classes in `admin.css`
  - `openBanUserModal()` and `confirmBanUser()` JS functions in `admin.php`
  - `ban_reason` is POSTed alongside `action=ban_user` and `target_id`
  - `processBanUser()` in `AdminRepository.php` accepts the optional `$banReason` string and appends it to the audit log entry
- **Page refresh:** POSTing the ban form causes a full redirect (`header('Location: admin.php?msg=...')`) which refreshes the user list and updates the status column automatically
- **Unban:** Remains a direct inline form with a simple `confirm()` — no reason required


- **Location:** Admin Dashboard > User Management tab
- **UI:** Delete button appears in the Actions column for eligible users (only super admins can delete, or admins deleting regular users)
- **Modal:** Clicking Delete opens a Bootstrap modal with:
  - Warning banner with icon and user details (username, email)
  - Textarea for admin to enter deletion reason (1000 char limit, required)
  - Character counter
  - Cancel and "Delete Account Permanently" buttons
- **Implementation:** 
  - Modal logic in `admin.php` with `openDeleteUserModal()` and `confirmDeleteUser()` JS functions
  - `processDeleteUser()` method in `AdminRepository.php` handles email, deletion, and audit logging
  - `send_account_deletion_email()` in `config.php` formats the dismissal email with reason
  - Email includes deletion reason formatted clearly so user knows why account was removed
- **Safeguards:** Cannot delete self, cannot delete super_admin accounts, admin role protection via role checks
- **Email:** Sends via SMTP if configured; email failure is noted in audit log but does not block deletion

## UI Patterns

### Navigation & Help Button
- The top navigation bar (`includes/app_nav.php`) contains a **burger menu** (left-slide drawer) and a **Help button**.
- The Help button triggers a **Bootstrap Offcanvas panel** (slides in from the right, `id="helpOffcanvas"`).
- The offcanvas explains the five main burger menu items: Commissions, Share a Project or Update, Updates/Notifications, Community (from Friends), and Settings.
- **Do not** revert the Help button to a plain anchor link (`<a href="README.md">`). Keep it as a `<button data-bs-toggle="offcanvas">`.
- All Help button and offcanvas styles live at the bottom of `post/post.css` under the `/* ─── Help Button ─── */` and `/* ─── Help Offcanvas ─── */` comment blocks. Since every authenticated page imports `post.css`, these styles are globally available.
- The offcanvas uses the same green palette and `Josefin Sans` / `Inter` font pairing as the rest of the app. Preserve these tokens if editing the Help panel.

## Verification Checklist
1. Run `php -l` on every edited PHP file.
2. Re-test the affected route in the browser after each auth or upload change.
3. For CSS updates, check both desktop and mobile widths.
4. For database-related updates, verify behavior against both a fresh setup and a migrated setup when possible.
5. For password reset changes, verify both the success path and the SMTP failure path so the UI does not falsely claim the code was sent.

## Recommended Optional Project Files
- `CLAUDE.local.md`: personal, untracked preferences or reminders
- `mcp.json`: shared integrations such as GitHub or project tooling
- `.claude/rules/`: modular coding, testing, and API rules
- `.claude/commands/`: repeatable slash-command workflows
- `.claude/skills/`: task-specific auto-loaded procedures
- `.claude/agents/`: specialized helper agent instructions
- `.claude/hooks/`: validation or safety scripts

## Current Repo-Specific Guardrails
- Do not remove MFA behavior when changing login logic.
- Do not auto-link or auto-create accounts for unknown Google emails without an explicit requirement.
- Do not introduce new fonts or CSS tokens when existing page variables already cover the need.
- Do not rely on client-side validation alone for passwords, uploads, or account updates.
- Do not silently swallow password reset email failures; keep the error visible to the user.
- Do not redirect unknown emails into the reset-password flow as though a reset code was sent.
- Keep uploaded user content out of git; `/uploads/` is ignored because local profile, post, and commission files differ between machines.
- Feed posts from other accounts are friend-only; discovery and moderation surfaces must not leak non-friend posts into the user feed.