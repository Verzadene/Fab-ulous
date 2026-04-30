# CLAUDE.md

## Project Overview
- Project: `FABulous`
- Repository: `https://github.com/Verzadene/Fab-ulous`
- Purpose: community platform for sharing software and hardware projects, with Google OAuth, email MFA, profile management, posts, messages, and admin tooling.

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
- Existing schema updates: `database/migration_v3_mfa.sql`, `database/migration_v4.sql`

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

## Verification Checklist
1. Run `php -l` on every edited PHP file.
2. Re-test the affected route in the browser after each auth or upload change.
3. For CSS updates, check both desktop and mobile widths.
4. For database-related updates, verify behavior against both a fresh setup and a migrated setup when possible.

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
