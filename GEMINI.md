# GEMINI.md - Project Context & Guidelines

## Project Overview
- **Project:** `FABulous`
- **Description:** A community platform for sharing software and hardware projects.
- **Core Features:** Google OAuth 2.0, Email MFA, Password Resets, Messaging, Commissions (PayMongo), Social Feed, Friendships, and Admin Moderation.
- **Local Root:** `C:\xampp\htdocs\Fab-ulous`
- **Database:** MySQL (`fab_ulous`) via XAMPP.

## Tech Stack
- **Backend:** PHP 8.2 (using `mysqli` with prepared statements).
- **Frontend:** HTML5, CSS3 (Custom + `login.css`), JavaScript (Vanilla + AJAX), Bootstrap 5.3.
- **Auth:** Manual login (email/username), Google OAuth 2.0 (Sign-in only), Email MFA (mandatory).
- **Integrations:** Gmail SMTP (via PHPMailer or `send_mail` helpers), PayMongo (Commissions).

## Database Schema & Migrations
The database must be initialized and updated in this specific order:
1. `database/setup.sql`: Base tables (accounts, posts, comments, commissions).
2. `database/migration_v2.sql`: Friendships and initial notifications.
3. `database/migration_v3_mfa.sql`: Adds `mfa_code` and `mfa_code_expires_at` to `accounts`.
4. `database/migration_v4.sql`: Profile-related enhancements (e.g., `profile_pic`).
5. `database/migration_v5.sql`: Creates `password_resets` and `messages` tables; updates commissions.
6. `database/migration_v6_paymongo.sql`: Payment tracking for commissions.
7. `database/migration_v7_notifications.sql`: Expanded notification types.

## Key Architecture Rules

### 1. Authentication & Security
- **MFA Flow:** All logins must pass through `login/verify_mfa.php`. Do not allow sessions to bypass this.
- **Password Policy:** Minimum 16 characters, 2+ special characters, 2+ numbers.
- **Google OAuth:** Treat as a sign-in method for **existing** accounts only. Do not auto-create accounts unless explicitly requested.
- **Prepared Statements:** Use `mysqli` prepared statements for ALL database interactions involving user input.
- **Lockout Policy:** 5 failed attempts (Login, Admin Login, or MFA) triggers a mandatory 60-second (1 minute) timeout.

### 2. Session Management
- `$_SESSION['user']`: Contains basic profile data. **Only set AFTER MFA verification.**
- `$_SESSION['mfa_verified']`: Boolean; must be true to access the dashboard or profile.
- `$_SESSION['pending_mfa_user']`: Used during the window between password entry and MFA verification.

### 3. File Handling
- **Uploads:** Profile pictures go to `/uploads/profile_pics/`. Post images go to `/uploads/posts/`.
- **Validation:** Always validate MIME types (`image/jpeg`, `image/png`) and file size (max 2MB).
- **Cleanup:** When replacing files (like profile pictures), attempt to `unlink()` the old file to save space.

### 4. Design & UI Tokens
- **Typography:** 
  - Display/Headings: `Josefin Sans, sans-serif`
  - Body: `Inter, sans-serif`
- **Color Palette:** Maintain the "FABulous Green" aesthetic (e.g., `#4E7A5E`).
- **Responsiveness:** Use Bootstrap 5 grid system; verify layouts on mobile and desktop.
- **Animations:** Auth pages use a 3-stage Viewport Slider. Login (Pos 0) ↔ Admin (Pos 1) ↔ Register (Pos 2). **Structural Requirement:** Every auth page must wrap its card-container in `.auth-viewport` and `.auth-slider` to allow seamless transitions. The slider must contain dummy cards for unoccupied positions.

## Core Directory Map
- `/admin/`: Dashboard, user management, audit logs, and commission updates.
- `/login/`: `login.php`, `verify_mfa.php`, `forgot_password.php`, `reset_password.php`.
- `/post/`: `post.php` (feed), `messages.php`, `commissions.php`, and related AJAX APIs.
- `/profile/`: `profile.php` for account settings and password updates.
- `/register/`: `register.php` and verification flows.
- `config.php`: The single source of truth for DB credentials, SMTP, and global helper functions.

## Guardrails (Do Not Violate)
- **No MFA Removal:** Never suggest changes that bypass or weaken the MFA requirement.
- **No Inline SQL:** Do not use string interpolation for SQL queries.
- **No Silent Failures:** Email sending failures (MFA or Password Reset) must be captured via `get_last_mail_error()` and displayed to the user.
- **Friendship Privacy:** The main feed (`post.php`) logic should generally prioritize posts from friends; discovery logic must not leak private data.
- **Relative Paths:** Keep links compatible with `http://localhost/Fab-ulous/`.

## Verification Checklist for AI
1. **Syntax:** Verify PHP syntax (`php -l`) on any modified script.
2. **Pathing:** Ensure `require_once` and asset links account for the subdirectory structure.
3. **CSRF/Security:** Ensure sensitive POST actions are protected and sessions are validated at the top of the file.
4. **Database:** If a change depends on a specific column, check if it was introduced in a migration and handle cases where it might be missing.