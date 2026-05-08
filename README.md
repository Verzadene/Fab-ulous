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
│   ├── admin_login.css           # Admin login standalone styles
│   ├── admin.css                 # Admin dashboard styles
│   ├── AdminRepository.php       # DB abstraction: user banning, deletion, audit log, commission oversight
│   └── commission_update.php     # AJAX endpoint: update commission status/notes from dashboard
│
├── config.php                    # Single source of truth — all constants, DB, auth helpers, SMTP
│
├── database/
│   ├── setup_micro_dbs.sql       # Canonical fresh install: creates all 12 micro-databases and their tables (use this)
│   ├── setup.sql                 # ⚠️ Deprecated monolith schema — superseded by setup_micro_dbs.sql
│   ├── migration_messages_canonical.sql # Renames sender_id/receiver_id → senderID/receiverID in messages table (idempotent)
│   ├── migration_v2.sql          # Adds super_admin role, friendships, notifications, commission fields
│   ├── migration_v3_mfa.sql      # Adds mfa_code + mfa_code_expires_at to accounts
│   ├── migration_v4.sql          # Adds profile_pic to accounts; creates pending_registrations
│   ├── migration_v5.sql          # Expands commission statuses; adds password_resets, messages, audit visibility
│   ├── migration_v6_paymongo.sql # Creates commission_payments table for PayMongo tracking
│   ├── migration_v7_notifications.sql # Expands notifications.type ENUM for commissions, payments, messages
│   └── migration_v8_profile_fields.sql # Adds bio column to accounts table
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
├── includes/
│   └── app_nav.php               # Shared top nav + burger drawer + Help offcanvas (included by all authenticated pages)
│
├── landing/
│   ├── landing.html              # Public-facing landing page
│   └── landing.css
│
├── login/
│   ├── login.php                 # User login: credential check → MFA challenge → verify_mfa.php
│   ├── login.css                 # Shared auth page styles (used by login, admin login, MFA, forgot-pw, reset-pw)
│   ├── auth_slider.js            # Shared auth-panel slide animation + bfcache Back-button fix (used by login.php, admin/admin_login.php, register/register.html)
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
│   ├── feed_api.php              # GET: returns main feed as JSON
│   ├── create_post.php           # POST handler: create post with optional image upload
│   ├── edit_post.php             # POST handler: edit post caption (owner only)
│   ├── delete_post.php           # POST handler: delete post (owner only)
│   ├── like.php                  # POST handler: toggle like; returns updated count as JSON
│   ├── comment.php               # GET/POST: fetch or add comments for a post
│   ├── messages.php              # Messaging UI (requires messages table from v5)
│   ├── messages.css
│   ├── friends.php               # GET/POST API: friendship state machine and directory
│   ├── notifications.php         # GET/POST API: list notifications, count unread, mark as read
│   ├── messages_api.php          # GET/POST API: load conversation history and send messages
│   ├── PostRepository.php        # DB abstraction: Post data
│   ├── FriendRepository.php      # DB abstraction: Friendships and requests
│   ├── InteractionRepository.php # DB abstraction: Likes and comments across posts
│   ├── MessageRepository.php     # DB abstraction: Messaging operations
│   ├── NotificationRepository.php# DB abstraction: Notification operations
│   ├── CommissionRepository.php  # DB abstraction: Commission requests and updates
│   ├── PaymentRepository.php     # DB abstraction: PayMongo payment lifecycle (pending → checkout → paid)
│   ├── commissions.php           # Commission submit (users) + status management (admins)
│   ├── commissions.css
│   ├── paymongo_checkout.php     # POST handler: create PayMongo checkout session; redirect to payment URL
│   └── paymongo_webhook.php      # POST handler: receive PayMongo webhook; update commission_payments
│
├── profile/
│   ├── profile.php               # View and edit account details, change password, upload profile pic
│   ├── profile.css
│   ├── ProfileRepository.php     # DB abstraction: profile reads, updates, password change, pic upload
│   └── profile_api.php           # GET/POST API: returns or updates profile data as JSON
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

### Micro-Database Design

The application uses **12 separate MySQL databases** (one per domain). Each `*Repository.php` class is the sole owner of its database domain. This enables independent scaling and isolation of each data domain.

```
config.php  →  db_connect('domain')  →  one of 12 MySQL databases
                                         fab_ulous_accounts
                                         fab_ulous_posts
                                         fab_ulous_likes
                                         fab_ulous_comments
                                         fab_ulous_commissions
                                         fab_ulous_commission_payments
                                         fab_ulous_friendships
                                         fab_ulous_notifications
                                         fab_ulous_messages
                                         fab_ulous_pending_registrations
                                         fab_ulous_password_resets
                                         fab_ulous_audit_log
```

### Cross-Database Query Pattern

Because MySQL **does not support JOINs or foreign keys across databases**, all cross-domain reads use one of two patterns:

1. **Fully-qualified names** — `\`db_name\`.\`table\`` in the SQL string. Used for read-heavy queries that run on one connection and reference other databases inline (e.g. `getFeed` in `PostRepository`, `getAllCommissions` in `CommissionRepository`).
2. **Application-level aggregation** — fetch IDs from DB-A, then run a separate prepared statement against DB-B, merge in PHP. Used for all other cross-domain reads (e.g. `getComments`, `getUnreadNotifications`, `getConversation`).

All cascading deletes (e.g. removing a user's posts, likes, friendships when the account is deleted) are handled explicitly in `AdminRepository::processDeleteUser()`.

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

Every PHP file does `require_once __DIR__ . '/../config.php'` (or equivalent). `config.php` provides:

- `DB_CONFIG` — array constant with credentials for all 12 databases (host, user, pass, name, port per domain)
- `db_connect(string $domain): mysqli` — returns a cached `mysqli` connection for a given domain
- `begin_user_session()` — writes `$_SESSION['user']` and `$_SESSION['mfa_verified']`
- `dashboard_path_for_role()` — returns the correct redirect target per role
- `create_notification()` — inserts a notification row into the notifications DB
- `store_mfa_code()` / `clear_mfa_code()` / `accounts_support_mfa()` — MFA DB helpers
- `send_mfa_code_email()` / `send_password_reset_email()` / `send_registration_verification_email()` / `send_account_deletion_email()` — SMTP wrappers
- `mask_email_address()` — returns `j***@gmail.com` style masked address

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

## Admin Features

### User Account Banning & Unbanning
- **Location:** Admin Dashboard > User Management tab
- **Ban eligibility:** Super admins can ban admins or regular users; regular admins can only ban regular users. Banned users show an Unban button instead of Ban.
- **Ban modal:** Clicking Ban opens a Bootstrap modal with an orange accent (`#e67e22`):
  - Warning banner with ban (circle-slash) SVG icon and target user details (username, email)
  - Textarea for ban reason (1000 char limit, required)
  - Character counter
  - Cancel and "Ban Account" buttons; a second `confirm()` dialog before submission
- **Unban modal:** Clicking Unban opens a Bootstrap modal with a green accent (`var(--green-active)`):
  - Confirmation panel with checkmark SVG icon and target user details (username, email)
  - Brief description of the action (no reason required)
  - Cancel and "Restore Access" buttons; a `confirm()` dialog before submission
- **Implementation:**
  - `openBanUserModal()` / `confirmBanUser()` and `openUnbanUserModal()` / `confirmUnbanUser()` JS functions in `admin.php`
  - Ban CSS: `.ban-warning`, `.ban-modal-header`, `.ban-modal-title`, `.ban-modal-confirm-btn` in `admin.css`
  - Unban CSS: `.unban-warning`, `.unban-modal-header`, `.unban-modal-title`, `.unban-modal-confirm-btn` in `admin.css`
  - `processBanUser()` and `processUnbanUser()` in `AdminRepository.php` handle DB update and audit logging
- **Page refresh:** POST → `header('Location: admin.php?msg=...')` redirect refreshes user list and status column automatically

---

### User Account Deletion
- **Location:** Admin Dashboard > User Management tab
- **UI:** Delete button appears in the Actions column for eligible users
- **Eligibility:** Super admins can delete any user (except other super admins); regular admins can only delete regular users
- **Modal:**
  - Warning banner with icon and user details (username, email)
  - Textarea for admin to enter deletion reason (1000 char limit, required)
  - Character counter
  - Cancel and "Delete Account Permanently" buttons
- **Implementation:**
  - Modal UI and JS logic in `admin.php` with `openDeleteUserModal()` and `confirmDeleteUser()` functions
  - `processDeleteUser()` method in `AdminRepository.php` handles email notification, deletion, and audit logging
  - `send_account_deletion_email()` in `config.php` sends a dismissal email with the reason formatted clearly
- **Safeguards:**
  - Cannot delete own account
  - Cannot delete super_admin accounts
  - Admin role protection enforced via role checks
- **Email & Logging:**
  - Deletion reason is sent to user via SMTP before account removal
  - Email send failures do not prevent deletion but are logged in the audit trail
  - Action is logged with full details: admin username, target user, reason summary

---

## Strangler Fig Pattern Migration

FABulous is incrementally transitioning from a monolithic PHP application to microservices using the **Strangler Fig Pattern**. The strategy decouples frontend UI from backend business logic by gradually converting action scripts into RESTful JSON endpoints. Legacy endpoints continue to work during the transition.

### Migration phases

**Phase 0: Foundation (Current)**
- No external services yet; focus on internal refactoring
- Goal: Extract domain logic from scripts into reusable Repository classes
- Benefit: Easier to stub out, mock, or replicate logic when building new services
- Status: ✅ Complete
  - All major domain logic extracted into `*Repository.php` classes.
  - Controllers are now thin wrappers calling composite `process*` methods.

**Phase 1: Internal APIs (Planned)**
- Publish Router/Gateway layer (e.g., `api/v1/router.php`)
- All scripts return JSON; UI calls via AJAX
- Status: Not started

**Phase 2: External Microservices (Future)**
- Extract first domain: Social Graph & Interactions (likes, comments, friendships)
- Deploy as standalone Node.js/Python service
- Gateway routes requests to internal PHP or external service

**Phase 3: Domain-driven migration (Future)**
- Migrate subsequent domains one at a time:
  1. Social Graph & Interactions (likes, comments, friendships)
  2. Notifications
  3. Direct Messaging
  4. Commission + Payment Processing
  5. User Profile & Settings
- Internal load balancing / health checks

### Domains under transition

| Domain | Scripts | Repository | API Status |
|---|---|---|---|
| Social Graph & Interactions | `post/like.php`, `post/comment.php`, `post/friends.php`, `post/create_post.php`, etc. | `InteractionRepository.php`, `PostRepository.php`, `FriendRepository.php` | Extracted / Thin Controllers |
| Notifications | `post/notifications.php` | `NotificationRepository.php` | Extracted / Thin Controllers |
| Direct Messaging | `post/messages_api.php` | `MessageRepository.php` | Extracted / Thin Controllers |
| Commissions | `post/commissions.php` | `CommissionRepository.php` | Extracted / Thin Controllers |
| Profiles | `profile/profile_api.php` | `ProfileRepository.php` | Extracted / Thin Controllers |
| Authentication | `login/login.php`, `admin/admin_login.php` | — | Legacy |

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
| Global login bucket | `fab_global_login` (interconnected across user and admin pages) |

**Flow:** `record_login_failure($bucket)` increments `$_SESSION['{bucket}_attempts']`. On the 5th failure it sets `$_SESSION['{bucket}_lockout_until'] = time() + 60` and clears the counter. `login_lockout_remaining($bucket)` returns seconds remaining (0 = not locked). While locked out, a popup is displayed to the user, and the form inputs + external authentication links (Google, Register, Forgot Password) are completely disabled. The browser-side JS timer re-enables the form when `remaining` reaches 0 and reloads.

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

## Database Architecture

FABulous uses a **micro-database architecture** — 12 independent MySQL databases, one per domain.

> **Why?** Independent databases allow each service domain to evolve its schema, scale, and be deployed independently — the first step in the Strangler Fig migration toward full microservices.

> **Important:** MySQL does not enforce foreign key constraints across databases. All referential integrity and cascading deletes are handled explicitly in PHP, primarily in `AdminRepository::processDeleteUser()`.

### The 12 databases

| Domain key | Database name | Table | Purpose |
|---|---|---|---|
| `accounts` | `fab_ulous_accounts` | `accounts` | Users and admins; role, banned, google_id, MFA columns, profile_pic |
| `posts` | `fab_ulous_posts` | `posts` | User posts with optional image |
| `likes` | `fab_ulous_likes` | `likes` | Post likes (unique per user per post) |
| `comments` | `fab_ulous_comments` | `comments` | Post comments (`comment_text` column) |
| `commissions` | `fab_ulous_commissions` | `commissions` | Commission requests; status, admin notes, amount |
| `commission_payments` | `fab_ulous_commission_payments` | `commission_payments` | PayMongo payment records |
| `friendships` | `fab_ulous_friendships` | `friendships` | Pairs with `user1_id` (requester) / `user2_id` (receiver), `pending`/`accepted` |
| `notifications` | `fab_ulous_notifications` | `notifications` | Event notifications (like, comment, friend, commission, message) |
| `messages` | `fab_ulous_messages` | `messages` | Direct messages (`senderID` / `receiverID` / `message_text`) |
| `pending_registrations` | `fab_ulous_pending_registrations` | `pending_registrations` | Unverified email registrations |
| `password_resets` | `fab_ulous_password_resets` | `password_resets` | 6-digit reset codes with expiry |
| `audit_log` | `fab_ulous_audit_log` | `audit_log` | Admin actions with visibility separation |

### Renamed columns (migration note)

If you are migrating from an older monolithic schema, the following columns were renamed:

| Table | Old column | New column |
|---|---|---|
| `friendships` | `requesterID` | `user1_id` |
| `friendships` | `receiverID` | `user2_id` |
| `messages` | `sender_id` | `senderID` |
| `messages` | `receiver_id` | `receiverID` |
| `comments` | `content` | `comment_text` |

> **Existing installs:** if your `fab_ulous_messages.messages` table still has `sender_id` / `receiver_id`, run `database/migration_messages_canonical.sql` to rename them in place without losing message history. Idempotent — safe on already-canonical databases.

---

## Setup

### 1. Clone and place

```bash
git clone https://github.com/Verzadene/Fab-ulous.git
# Move to XAMPP htdocs:
# Windows: C:\xampp\htdocs\Fab-ulous
# macOS/Linux: /Applications/XAMPP/htdocs/Fab-ulous
```

### 2. Start XAMPP

Start **Apache** and **MySQL** from the XAMPP control panel. You do not need to create any databases manually.

### 3. Run the micro-database setup script

```bash
# Fresh install — creates all 12 databases and their tables from scratch
mysql -u root < C:/xampp/htdocs/Fab-ulous/database/setup_micro_dbs.sql

# Existing install only — rename legacy messages.sender_id / receiver_id
# to canonical senderID / receiverID. Idempotent; no-op on fresh installs.
mysql -u root < C:/xampp/htdocs/Fab-ulous/database/migration_messages_canonical.sql
```

This creates all 12 databases and their tables from scratch. The old `setup.sql` and `migration_v*.sql` files are **deprecated** — do not run them on a fresh install. The `migration_messages_canonical.sql` script is only needed when upgrading a database that predates the messages column rename; it checks `information_schema` and skips when columns are already canonical.

### 4. Configure local credentials via `config.local.php`

Create `config.local.php` in the project root (it is gitignored). This file must define `DB_CONFIG` **before** `config.php` loads it (since PHP constants cannot be redefined).

A minimal `config.local.php`:

```php
<?php
// ── Database (define DB_CONFIG first — it must be set before config.php) ──
define('DB_CONFIG', [
    'accounts'              => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_accounts',              'port' => 3306],
    'posts'                 => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_posts',                 'port' => 3306],
    'likes'                 => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_likes',                 'port' => 3306],
    'comments'              => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_comments',              'port' => 3306],
    'commissions'           => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_commissions',           'port' => 3306],
    'commission_payments'   => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_commission_payments',   'port' => 3306],
    'friendships'           => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_friendships',           'port' => 3306],
    'notifications'         => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_notifications',         'port' => 3306],
    'messages'              => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_messages',              'port' => 3306],
    'pending_registrations' => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_pending_registrations', 'port' => 3306],
    'password_resets'       => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_password_resets',       'port' => 3306],
    'audit_log'             => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'fab_ulous_audit_log',             'port' => 3306],
]);

// ── SMTP (required for MFA, registration, password reset) ──
define('SMTP_HOST',         'smtp.gmail.com');
define('SMTP_PORT',         465);
define('SMTP_ENCRYPTION',   'ssl');
define('SMTP_USERNAME',     'your-email@gmail.com');
define('SMTP_PASSWORD',     'your-app-password');
define('MAIL_FROM_ADDRESS', 'your-email@gmail.com');
define('MAIL_FROM_NAME',    'FABulous');

// ── Google OAuth ──
define('GOOGLE_CLIENT_ID',     'your-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your-client-secret');
define('GOOGLE_REDIRECT_URI',  'http://localhost/Fab-ulous/oauth/oauth2callback.php');

// ── PayMongo ──
define('PAYMONGO_SECRET_KEY',    'sk_test_...');
define('PAYMONGO_WEBHOOK_SECRET','whsk_...');

define('APP_ENV', 'local');
define('APP_URL', 'http://localhost/Fab-ulous');
```

> **Why define `DB_CONFIG` in `config.local.php`?**  
> `config.php` uses `defined('DB_CONFIG') || define('DB_CONFIG', [...])`. If `config.local.php` defines it first, `config.php` skips its default. This is the only way to override the database names without editing the tracked `config.php`.

### 5. Create an admin account

Register normally at `http://localhost/Fab-ulous/register/register.html`, then promote via SQL:

```sql
USE fab_ulous_accounts;
UPDATE accounts SET role = 'admin'       WHERE username = 'your_username';
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
| `post/feed_api.php` | GET | Fetch the main social feed for the user | `post/post.php` JS |
| `post/like.php` | POST | Toggle like; return updated count | `post/post.php` |
| `post/comment.php` | GET, POST | Fetch or add post comments | `post/post.php` |
| `post/friends.php` | GET, POST | Fetch friend directory; Friendship state machine | `post/post.php` JS |
| `post/notifications.php` | GET, POST | List / count / mark-read notifications | `post/post.php` JS |
| `post/messages_api.php` | GET, POST | Load conversation history; send message | `post/messages.php` |
| `post/edit_post.php` | POST | Edit post caption (owner only) | `post/post.php` JS |
| `post/delete_post.php` | POST | Delete post (owner only) | `post/post.php` JS |
| `post/create_post.php` | POST | Create post with optional image | `post/post.php` |
| `post/commissions.php` | GET, POST | List commissions (JSON); submit commission (user); update status (admin) | `post/commissions.php` JS |
| `post/paymongo_checkout.php` | POST | Create PayMongo checkout session (delegates to `PaymentRepository`) | `post/commissions.php` JS |
| `post/paymongo_webhook.php` | POST | Receive PayMongo webhook; idempotently mark paid via `PaymentRepository` and fire `commission_paid` notification | PayMongo servers |
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