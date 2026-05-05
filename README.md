# FABulous

Community platform for sharing software and hardware projects in one space. The codebase covers username/email login, Google OAuth 2.0, email-based MFA, password reset by email, email-verified registration, social posting, friendships, direct messaging, commission requests with PayMongo payment, profile management, and role-separated admin tooling.

- **Repository:** `https://github.com/Verzadene/Fab-ulous`
- **Local app root:** `C:\xampp\htdocs\Fab-ulous`
- **Default entry point:** `http://localhost/Fab-ulous/landing/landing.html`

---

## Current Feature Status

| Feature | Status | Key files |
|---|---|---|
| User login (password + MFA) | ✅ Complete | `login/login.php`, `login/verify_mfa.php` |
| Admin login (password + MFA) | ✅ Complete | `admin/admin_login.php`, `login/verify_mfa.php` |
| Google OAuth sign-in | ✅ Complete | `login/login.php`, `oauth/oauth2callback.php` |
| Email-verified registration | ✅ Complete | `register/register.php`, `register/verify_registration.php` |
| Forgot-password / reset | ✅ Complete | `login/forgot_password.php`, `login/reset_password.php` |
| Social posts (create, like, comment) | ✅ Complete | `post/post.php`, `post/create_post.php`, `post/like.php`, `post/comment.php` |
| Friendships | ✅ Complete | `post/friends.php` |
| Direct messaging | ✅ Complete | `post/messages.php`, `post/messages_api.php` |
| Notifications | ✅ Complete | `post/notifications.php` |
| Commissions (submit + status tracking) | ✅ Complete | `post/commissions.php` |
| PayMongo payment checkout | ✅ Complete | `post/paymongo_checkout.php`, `post/paymongo_webhook.php` |
| Profile management | ✅ Complete | `profile/profile.php` |
| Admin dashboard (users, posts, commissions, audit log) | ✅ Complete | `admin/admin.php`, `admin/commission_update.php` |

---

## Project Structure

```text
Fab-ulous/
├── admin/                        # Admin-only pages and tools
│   ├── admin.php                 # Main admin dashboard (user mgmt, commissions, audit log)
│   ├── admin_login.php           # Admin credential + MFA entry point
│   ├── admin_logout.php          # Session teardown for admin
│   ├── admin.css                 # Admin dashboard styles
│   └── commission_update.php     # AJAX endpoint: update commission status/notes from dashboard
│
├── config.php                    # Single source of truth — all constants, DB, auth helpers, SMTP
│
├── database/
│   ├── setup.sql                 # Fresh install: creates all tables from scratch
│   ├── migration_v2.sql          # Adds super_admin role, friendships, notifications, commission fields
│   ├── migration_v3_mfa.sql      # Adds mfa_code + mfa_code_expires_at to accounts
│   ├── migration_v4.sql          # Adds profile_pic to accounts; creates pending_registrations
│   ├── migration_v5.sql          # Expands commission statuses; adds password_resets, messages, audit visibility
│   ├── migration_v6_paymongo.sql # Creates commission_payments table for PayMongo tracking
│   └── migration_v7_notifications.sql # Expands notifications.type ENUM for commissions, payments, messages
│
├── documentation/
│   └── FABulous_ProjectDocs_v0.2.0.docx
│
├── images/
│   ├── Big Logo.png              # Large brand logo (left panel of auth pages)
│   ├── Top_Left_Nav_Logo.png     # Small nav logo
│   └── source/                   # Original vector/source assets
│       ├── Green_Logo_Tab.png
│       └── Green_Logo_Top_left.png
│
├── landing/
│   ├── landing.html              # Public-facing landing page
│   └── landing.css
│
├── login/
│   ├── login.php                 # User login: credential check → MFA challenge → verify_mfa.php
│   ├── login.css                 # Shared auth page styles (used by login, admin login, MFA, forgot-pw, reset-pw)
│   ├── logout.php                # User session teardown → landing
│   ├── verify_mfa.php            # Email MFA code entry; completes session after correct code
│   ├── verify_mfa.css            # MFA-specific style overrides
│   ├── forgot_password.php       # Request 6-digit reset code by email
│   └── reset_password.php        # Submit reset code + new password
│
├── oauth/
│   └── oauth2callback.php        # Receives Google OAuth redirect; links google_id, calls begin_user_session()
│
├── post/
│   ├── post.php                  # Main authenticated feed (posts, friend actions, notifications)
│   ├── post.css                  # Feed page styles (also defines topnav for all post/ pages)
│   ├── post.html                 # Static shell / redirect shim
│   ├── create_post.php           # POST handler: create post with optional image upload
│   ├── edit_post.php             # POST handler: edit post caption (owner only)
│   ├── delete_post.php           # POST handler: delete post (owner only)
│   ├── like.php                  # POST handler: toggle like; returns updated count as JSON
│   ├── comment.php               # GET/POST: fetch or add comments for a post
│   ├── friends.php               # GET/POST: friendship state machine (request, accept, reject, remove)
│   ├── notifications.php         # GET/POST: list notifications, count unread, mark as read
│   ├── messages.php              # Messaging UI (requires messages table from v5)
│   ├── messages_api.php          # GET/POST AJAX: load conversation history and send messages
│   ├── messages.css
│   ├── commissions.php           # Commission submit (users) + status management (admins)
│   ├── commissions.css
│   ├── paymongo_checkout.php     # POST handler: create PayMongo checkout session; redirect to payment URL
│   └── paymongo_webhook.php      # POST handler: receive PayMongo webhook; update commission_payments
│
├── profile/
│   ├── profile.php               # View and edit account details, change password, upload profile pic
│   └── profile.css
│
├── register/
│   ├── register.html             # Registration form UI
│   ├── register.php              # POST handler: validate → upsert pending_registrations → send verify email
│   ├── register.js               # Prefills form fields from Google OAuth prefill data
│   ├── register.css
│   ├── prefill.php               # GET: returns Google-prefilled data as JSON for register.js
│   ├── verify_registration.php   # Accepts 6-digit code; moves pending → accounts on success
│   └── verify_registration.css
│
├── uploads/                      # User-generated content — gitignored
│   ├── profile_pics/             # Profile pictures (named by account ID)
│   ├── posts/                    # Post images
│   └── commissions/              # Commission attachments (PDF, STL)
│
├── CLAUDE.md                     # AI assistant context and project guardrails
└── README.md                     # This file
```

---

## Architecture

FABulous is a **folder-modular monolith** running on XAMPP (Apache + PHP 8.2 + MySQL). There is no framework; each feature area owns its folder, its PHP files, and its CSS. All pages share one configuration entry point.

### Dependency graph

```
landing/landing.html
    └─► login/login.php  ──────────────────────────────────────────┐
            ├─► login/verify_mfa.php  (MFA gate)                   │
            │       └─► post/post.php (user) / admin/admin.php (admin)
            ├─► oauth/oauth2callback.php  (Google sign-in, bypasses MFA)
            ├─► login/forgot_password.php
            │       └─► login/reset_password.php
            └─► register/register.html
                    └─► register/register.php
                            └─► register/verify_registration.php
                                    └─► login/login.php
```

### config.php — central hub

Every PHP file does `require_once __DIR__ . '/../config.php'` (or equivalent relative path). `config.php` provides:

- All `define()` constants (DB credentials, SMTP, Google OAuth, PayMongo, MFA timers)
- `db_connect()` — returns a `mysqli` connection
- `begin_user_session()` — writes `$_SESSION['user']` and `$_SESSION['mfa_verified']`
- `dashboard_path_for_role()` — returns the correct redirect target per role
- `clear_pending_auth()` — wipes `pending_mfa_user` + `pending_mfa_sent_at` from session
- `login_lockout_remaining()` / `record_login_failure()` / `clear_login_lockout()` — session-based lockout
- `store_mfa_code()` / `clear_mfa_code()` / `accounts_support_mfa()` — MFA DB helpers
- `send_mfa_code_email()` / `send_password_reset_email()` / `send_registration_verification_email()` — SMTP wrappers
- `create_notification()` — inserts notification rows (checks `notification_type_supported()` first)
- `mask_email_address()` — returns `j***@gmail.com` style masked address for display

### Session variables

| Variable | Type | Meaning |
|---|---|---|
| `$_SESSION['user']` | `array` | Set only after full auth (including MFA). Keys: `id`, `username`, `email`, `name`, `role`, `google_id`, `profile_pic`, `auth_method` |
| `$_SESSION['mfa_verified']` | `bool` | `true` when MFA was completed (or Google OAuth used). Must be `true` for dashboard access |
| `$_SESSION['pending_mfa_user']` | `array` | Temporary — holds user data between credential check and MFA verification |
| `$_SESSION['pending_mfa_sent_at']` | `int` | Unix timestamp when MFA code was last sent; controls resend cooldown |
| `$_SESSION['{bucket}_attempts']` | `int` | Increments on each failed login attempt |
| `$_SESSION['{bucket}_lockout_until']` | `int` | Unix timestamp when lockout expires |

### Role hierarchy

| Role | Access |
|---|---|
| `user` | Feed, messages, commissions, profile |
| `admin` | All user access + admin dashboard (users, posts, commissions, audit log visible to all admins) |
| `super_admin` | All admin access + promote/demote admins + see super_admin-only audit entries |

---

## Security Stack

### Authentication flow (password path)

```
1. login.php          → validate credentials
2.                    → if admin role: reject (must use admin_login.php)
3.                    → generate 6-digit code → store_mfa_code() (10-min TTL)
4.                    → send_mfa_code_email()
5.                    → write $_SESSION['pending_mfa_user'] + ['pending_mfa_sent_at']
6.                    → redirect → verify_mfa.php
7. verify_mfa.php     → check code matches + not expired
8.                    → begin_user_session() — writes ['user'] + ['mfa_verified'] = true
9.                    → redirect → dashboard_path_for_role()
```

### Authentication flow (Google OAuth path)

```
1. login.php?google=1 → redirect to Google consent screen
2. oauth2callback.php → exchange code for token → fetch profile
3.                    → look up account by google_id OR email
4.                    → if not found: redirect to login with register-first message
5.                    → begin_user_session(..., mfaVerified: true, authMethod: 'google')
6.                    → redirect → dashboard_path_for_role()
```

> **Important:** Google OAuth **bypasses email MFA** by design — Google itself is the identity provider. `begin_user_session()` is called directly with `$mfaVerified = true`.

### Failed login lockout

Implemented entirely in `config.php` via session variables. Both login pages use the same mechanism with separate buckets.

| Setting | Value |
|---|---|
| Max attempts before lockout | 5 |
| Lockout duration | 60 seconds |
| Resets on | Successful credential + MFA completion (`clear_login_lockout()`) |
| User login bucket | `fab_user_login` |
| Admin login bucket | `fab_admin_login` |

**Flow:** `record_login_failure($bucket)` increments `$_SESSION['{bucket}_attempts']`. On the 5th failure it sets `$_SESSION['{bucket}_lockout_until'] = time() + 60` and clears the counter. `login_lockout_remaining($bucket)` returns seconds remaining (0 = not locked). The browser-side JS timer re-enables the form when `remaining` reaches 0 and reloads.

**Known quirk in repo version of `login.php`:** Admin credentials entered into the *user* login form correctly record a failure (they are rejected at the role check), but the error message is not shown to the user until the attempt count crosses the lockout threshold — meaning up to 4 silent rejections. This is intentional as a mild security measure (avoids confirming that admin accounts exist).

### MFA security

- Codes are 6-digit integers generated with `random_int(100000, 999999)` (cryptographically secure).
- Codes are stored as plaintext in `accounts.mfa_code` with an expiry in `accounts.mfa_code_expires_at`.
- `MFA_CODE_TTL_MINUTES = 10` — codes expire after 10 minutes.
- `MFA_RESEND_COOLDOWN_SECONDS = 60` — minimum gap between resend requests.
- `verify_mfa.php` checks expiry **before** comparing the code value to avoid timing side-channels on expired codes.
- `clear_mfa_code()` zeros out `mfa_code` and `mfa_code_expires_at` immediately after successful verification.

> **AI safety note:** `verify_mfa.php` in the current repo has a minimal bypass guard (`$_SESSION['user'] + mfa_verified` check at the top). The pending session state is the only gate — there is no per-user wrong-code rate limiting in this version. Do not suggest code that removes the `pending_mfa_user` guard or allows skipping to `begin_user_session()` without passing the code check.

### Upload security

All uploads (profile pics, post images, commission attachments) validate:
1. File extension against an explicit allowlist
2. MIME type via `finfo` (for commission attachments)
3. File size (10 MB limit for commissions)
4. `move_uploaded_file()` used exclusively — no manual copy

Upload folders are outside the codebase but inside the web root (`uploads/`). Uploaded filenames are sanitised/namespaced (e.g., `{userId}_{timestamp}.{ext}`) to prevent overwrites and enumeration.

---

## Database

### Fresh install

Run **only** `database/setup.sql`. It creates all tables in their final schema including all columns added by later migrations. The migration files exist only for upgrading an **already-running** database.

```bash
mysql -u root < database/setup.sql
```

### Upgrade path (existing database)

Run migrations in version order. Each is safe to re-run (uses `IF NOT EXISTS` / `IF EXISTS` guards where possible).

```bash
mysql -u root fab_ulous < database/migration_v2.sql
mysql -u root fab_ulous < database/migration_v3_mfa.sql
mysql -u root fab_ulous < database/migration_v4.sql
mysql -u root fab_ulous < database/migration_v5.sql
mysql -u root fab_ulous < database/migration_v6_paymongo.sql
mysql -u root fab_ulous < database/migration_v7_notifications.sql
```

### Migration reference

| File | What it adds | Required by |
|---|---|---|
| `setup.sql` | All tables for a fresh install | Fresh installs only |
| `migration_v2.sql` | `super_admin` role; `friendships` table; `notifications` table (initial types); `commission_name` + `admin_note` columns on `commissions` | `post/friends.php`, `post/notifications.php`, admin commission notes |
| `migration_v3_mfa.sql` | `mfa_code` + `mfa_code_expires_at` on `accounts` | `login/login.php`, `login/verify_mfa.php`, `admin/admin_login.php` — **MFA will not function without this** |
| `migration_v4.sql` | `profile_pic` on `accounts`; `pending_registrations` table | `profile/profile.php`, `register/register.php`, `register/verify_registration.php` |
| `migration_v5.sql` | Expanded `commissions.status` ENUM; `visibility_role` on `audit_log`; `password_resets` table; `messages` table | `login/forgot_password.php`, `login/reset_password.php`, `post/messages.php` |
| `migration_v6_paymongo.sql` | `commission_payments` table | `post/paymongo_checkout.php`, `post/paymongo_webhook.php` |
| `migration_v7_notifications.sql` | Expanded `notifications.type` ENUM (`commission_submitted`, `commission_approved`, `commission_updated`, `commission_paid`, `message`) | `post/commissions.php`, `post/paymongo_webhook.php`, `post/messages_api.php` |

### Core table summary

| Table | Primary key | Purpose |
|---|---|---|
| `accounts` | `id` | Users and admins; includes role, banned flag, google_id, mfa columns, profile_pic |
| `posts` | `postID` | User posts with optional image |
| `likes` | `likeID` | Post likes (unique per user per post) |
| `comments` | `commentID` | Post comments |
| `commissions` | `commissionID` | Commission requests; status + admin notes + amount tracked here |
| `commission_payments` | `paymentID` | PayMongo payment records linked to commissions |
| `friendships` | `friendshipID` | Directed friendship pairs with `pending`/`accepted` state |
| `notifications` | `notifID` | Event notifications (like, comment, friend, commission, message) |
| `messages` | `messageID` | Direct message threads between two accounts |
| `pending_registrations` | `id` | Staging table for unverified email registrations |
| `password_resets` | `id` | 6-digit reset codes with expiry and used flag |
| `audit_log` | `logID` | Admin actions with visibility separation (admin vs super_admin) |

---

## Setup

### 1. Clone and place

```bash
git clone https://github.com/Verzadene/Fab-ulous.git
# Move to XAMPP:
# C:\xampp\htdocs\Fab-ulous
```

### 2. Start XAMPP

Start **Apache** and **MySQL** from the XAMPP control panel.

### 3. Create the database and run setup

Open `http://localhost/phpmyadmin`, create a database named `fab_ulous`, then run:

```sql
-- Fresh install (creates everything):
source C:/xampp/htdocs/Fab-ulous/database/setup.sql
```

Or via CLI:

```bash
mysql -u root < database/setup.sql
```

### 4. Configure `config.php`

Open `config.php` and fill in or verify:

```php
// Database (defaults work for stock XAMPP)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'fab_ulous');

// SMTP — required for MFA codes, registration verification, and password reset
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     465);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');

// Google OAuth — required for "Continue with Google"
define('GOOGLE_CLIENT_ID',     'your-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your-client-secret');
define('GOOGLE_REDIRECT_URI',  'http://localhost/Fab-ulous/oauth/oauth2callback.php');

// PayMongo — required for commission payment checkout
define('PAYMONGO_SECRET_KEY',      'sk_test_...');
define('PAYMONGO_WEBHOOK_SECRET',  'whsk_...');
```

Alternatively, create an untracked `config.local.php` in the project root and define overrides there — `config.php` does `defined('X') || define('X', ...)` so local values take precedence when defined first.

### 5. Create an admin account

Register normally, then promote via SQL:

```sql
UPDATE accounts SET role = 'admin' WHERE username = 'your_username';
-- Or for super admin:
UPDATE accounts SET role = 'super_admin' WHERE username = 'your_username';
```

### 6. Open the app

```
http://localhost/Fab-ulous/landing/landing.html
```

---

## Key Routes

| Route | Auth required | Purpose |
|---|---|---|
| `landing/landing.html` | None | Public landing page |
| `login/login.php` | None | User sign-in (password or Google) |
| `login/verify_mfa.php` | `pending_mfa_user` session | MFA code verification |
| `login/forgot_password.php` | None | Request 6-digit reset code |
| `login/reset_password.php` | None | Submit code + new password |
| `register/register.html` | None | Registration form |
| `register/verify_registration.php` | None | Email verification for new accounts |
| `post/post.php` | user + mfa_verified | Main social feed |
| `post/messages.php` | user + mfa_verified | Direct messaging |
| `post/commissions.php` | user + mfa_verified | Submit/track commissions (admin sees all) |
| `profile/profile.php` | user + mfa_verified | Account settings, password, profile pic |
| `admin/admin_login.php` | None (admin creds) | Admin sign-in |
| `admin/admin.php` | admin + mfa_verified | Admin dashboard |

---

## External Integrations

| Integration | Purpose | Config keys | Relevant files |
|---|---|---|---|
| Google OAuth 2.0 | Sign-in with existing Google accounts | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | `login/login.php`, `oauth/oauth2callback.php` |
| Gmail SMTP (custom driver) | MFA codes, registration verification, password resets | `SMTP_HOST/PORT/USERNAME/PASSWORD`, `MAIL_FROM_ADDRESS/NAME` | `config.php` (all email functions) |
| PayMongo | Commission payment checkout (GCash, card) | `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET`, `PAYMONGO_PAYMENT_METHOD_TYPES` | `post/paymongo_checkout.php`, `post/paymongo_webhook.php` |
| Bootstrap 5.3.3 (CDN) | UI framework | — | All pages |
| Google Fonts | `Josefin Sans` + `Inter` | — | All pages |
| Chart.js (CDN) | Admin dashboard charts | — | `admin/admin.php` |

> **No external PHP mail library is used.** SMTP is implemented as a raw socket connection inside `config.php`. If emails are not sending, check `smtp_is_configured()` returns `true` and that the Gmail App Password (not account password) is set.

---

## Internal AJAX / API Endpoints

| Endpoint | Method | Purpose | Called by |
|---|---|---|---|
| `register/prefill.php` | GET | Return Google OAuth prefill data as JSON | `register/register.js` |
| `post/like.php` | POST | Toggle like; return updated count | `post/post.php` |
| `post/comment.php` | GET, POST | Fetch or add post comments | `post/post.php` |
| `post/friends.php` | GET, POST | Friendship state machine | `post/post.php` |
| `post/notifications.php` | GET, POST | List / count / mark-read notifications | `post/post.php` |
| `post/messages_api.php` | GET, POST | Load conversation history; send message | `post/messages.php` |
| `post/edit_post.php` | POST | Edit post caption (owner only) | `post/post.php` |
| `post/delete_post.php` | POST | Delete post (owner only) | `post/post.php` |
| `post/create_post.php` | POST | Create post with optional image | `post/post.php` |
| `post/commissions.php` | POST | Submit commission (user); update status (admin) — returns JSON | `post/commissions.php` JS |
| `post/paymongo_checkout.php` | POST | Create PayMongo checkout session; redirect to payment URL | `post/commissions.php` form |
| `post/paymongo_webhook.php` | POST | Receive PayMongo webhook; update `commission_payments` | PayMongo servers |
| `admin/commission_update.php` | POST | Update commission from admin dashboard | `admin/admin.php` |

---

## Collaboration

```bash
# Pull latest
git pull origin master

# Push changes
git add .
git commit -m "feat: short description"
git push origin master
```

### Gitignore notes

`/uploads/` is gitignored — profile pics, post images, and commission attachments are machine-local. `.gitignore` also ignores `config.local.php` for local secret overrides.

---

## Documentation

- **AI/code assistant context:** `CLAUDE.md` — read this before making any code changes
- **Extended project documentation:** `documentation/FABulous_ProjectDocs_v0.2.0.docx`