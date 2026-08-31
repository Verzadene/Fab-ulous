# FABulous — Setup & User Guide

FABulous is a community platform for sharing software and hardware projects: accounts with Google OAuth and email MFA, a social feed, friends, direct messages, commission requests with online payment, and an admin dashboard.

This guide has two parts:

1. **Part 1 — Setup**: get the website running on your computer.
2. **Part 2 — Using the Website**: a walkthrough of every feature, for regular users and admins.

---

## Part 1 — Setup

### What you need

| Requirement | Notes |
|---|---|
| **XAMPP** | Provides Apache (web server), PHP 8.2, and MySQL. Download from apachefriends.org. |
| **A code/text editor** | e.g. VS Code — only needed if you plan to edit `config.local.php`. |
| **A Gmail account with an "App Password"** | Needed for MFA codes, registration emails, and password resets. |
| *(Optional)* **Google Cloud OAuth credentials** | Only needed if you want "Sign in with Google" to work. |
| *(Optional)* **PayMongo test account** | Only needed if you want commission payments to work. |

The site does **not** need a fixed hostname or folder name — see the "Portable app URL" note in Step 4. You can install it as `htdocs/Fab-ulous`, `htdocs/FABulous-dev`, or anything else, on any machine, and it will work without editing code.

### Step 1 — Get the project into `htdocs`

Download or clone the project, then place the folder inside your XAMPP web root:

- **Windows:** `C:\xampp\htdocs\Fab-ulous`
- **macOS:** `/Applications/XAMPP/htdocs/Fab-ulous`
- **Linux:** `/opt/lampp/htdocs/Fab-ulous`

The folder name doesn't matter — pick anything, just remember it for the URL in Step 6.

### Step 2 — Start Apache and MySQL

Open the **XAMPP Control Panel** and click **Start** next to both **Apache** and **MySQL**. Wait until both show a green "Running" status.

### Step 3 — Create the databases

FABulous uses **12 separate MySQL databases** (one per feature area — accounts, posts, comments, commissions, etc.). One script creates all of them.

Open a terminal (or XAMPP's **Shell**) and run:

```bash
mysql -u root < /path/to/Fab-ulous/database/fab_ulous_setup_micro_dbs.sql
```

On Windows, from inside `C:\xampp\htdocs\Fab-ulous`:

```bash
mysql -u root < database\fab_ulous_setup_micro_dbs.sql
```

This single script is safe to re-run — it only creates tables/columns that don't already exist, so running it again after an update just applies anything new without touching your existing data.

### Step 4 — Configure `config.local.php`

`config.local.php` is a **gitignored** file (not tracked by version control) that holds your personal/environment secrets — SMTP login, Google OAuth keys, PayMongo keys. Create it in the project root if it doesn't already exist:

```php
<?php
// Google OAuth (leave blank if you won't use "Sign in with Google")
define('GOOGLE_CLIENT_ID', 'your-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your-client-secret');

// PayMongo (leave blank if you won't use commission payments)
define('PAYMONGO_SECRET_KEY', 'sk_test_...');
define('PAYMONGO_WEBHOOK_SECRET', 'whsk_...');
define('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1');
define('PAYMONGO_PAYMENT_METHOD_TYPES', 'card,gcash');

// SMTP — required for MFA codes, registration emails, password resets
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_ENCRYPTION', 'ssl');
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-16-character-app-password');
define('MAIL_FROM_ADDRESS', 'your-email@gmail.com');
define('MAIL_FROM_NAME', 'FABulous');

define('APP_ENV', 'local');
```

> **Where do I get a Gmail "App Password"?** In your Google Account → Security → 2-Step Verification (must be turned on) → App passwords. Use the generated 16-character password, not your normal login password.

> **Portable app URL (no editing needed per machine):** `APP_URL` and `GOOGLE_REDIRECT_URI` are *not* set in `config.local.php` above on purpose. `config.php` automatically detects the correct scheme, hostname, and folder name from the incoming request, so the exact same `config.local.php` (with the same API keys) works whether the project lives at `http://localhost/Fab-ulous`, `http://localhost/FABulous-v2`, or a teammate's machine with a different name. You only need to hardcode `APP_URL`/`GOOGLE_REDIRECT_URI` yourself if you're exposing the site through something like an HTTPS tunnel for OAuth testing — in that case, uncomment and set them in `config.local.php`.

If you want to override database credentials (e.g. a non-default MySQL password), define a full `DB_CONFIG` array in `config.local.php` **before** anything else — see the comment block at the top of `config.php` for the exact array shape. This is optional; the default is `root` with no password, which matches a stock XAMPP install.

### Step 5 — (Optional) Set up Google Sign-In

1. Go to the [Google Cloud Console](https://console.cloud.google.com/) → APIs & Services → Credentials.
2. Create an OAuth 2.0 Client ID (type: Web application).
3. Add an **Authorized redirect URI**: `http://localhost/<your-folder-name>/oauth/oauth2callback.php`.
4. Copy the Client ID and Client Secret into `config.local.php` as shown in Step 4.

If you skip this, everything still works — users just won't see a working "Sign in with Google" button.

### Step 6 — Open the site and create your account

Visit:

```
http://localhost/<your-folder-name>/landing/landing.html
```

Click **Register**, fill in the form with an email from an allowed domain (`@gmail.com`, `@dlsud.edu.ph`, or `@outlook.com`), and check your inbox for the verification code.

### Step 7 — Promote yourself to admin (optional)

Regular registration always creates a `user` role account. To manage the site, promote your account via MySQL:

```sql
USE fab_ulous_accounts;
UPDATE accounts SET role = 'admin' WHERE username = 'your_username';
-- or, for full admin powers (can also manage other admins):
UPDATE accounts SET role = 'super_admin' WHERE username = 'your_username';
```

Log out and back in for the role change to take effect. You'll now see an **Admin Dashboard** link in the navigation menu.

### Troubleshooting

| Symptom | Likely cause |
|---|---|
| "This site can't be reached" | Apache isn't running, or the folder name in the URL doesn't match your `htdocs` folder. |
| Database connection errors | MySQL isn't running, or the setup script (Step 3) hasn't been run yet. |
| No MFA/verification email arrives | SMTP isn't configured correctly in `config.local.php`, or you used your normal Gmail password instead of an App Password. |
| "Sign in with Google" fails or loops | The redirect URI in Google Cloud Console doesn't exactly match `http://<your-host>/<your-folder>/oauth/oauth2callback.php`. |
| Commission "Pay" button errors | PayMongo keys aren't set, or PHP's cURL extension isn't enabled. |

---

## Part 2 — Using the Website

### Creating an account & signing in

1. **Register:** Go to the Register page, fill in your name, username, email, and password. You'll get an email with a 6-digit verification code — enter it to activate your account.
2. **Sign in:** Enter your username/email and password. You'll be sent a second 6-digit code by email (this is Multi-Factor Authentication, or **MFA**) — enter it to complete sign-in.
3. **Sign in with Google:** Click the Google button instead. This works only for an email that's **already registered** on FABulous — it links your existing account, it does not create a new one, and it skips the MFA email step since Google already verified your identity.
4. **Forgot your password?** Use "Forgot Password" on the login page — you'll get a 6-digit reset code by email, then choose a new password.

> **Note:** Repeated failed login attempts (5 in a row) temporarily lock the login form for 60 seconds as a security measure.

### The social feed

Your home page after logging in is the feed (`post/post.php`):

- **Create a post:** Click the compose box, write a caption, optionally attach an image, then choose who can see it:
  - **Friends Only** (default) — only your accepted friends (and you) can see it.
  - **Public** — anyone browsing the Public feed can see it, friends or not.
- **Switch feed view:** A pill toggle near the top-left switches your own feed between **Friends Only** (your posts + friends' posts) and **Public** (your posts + everyone's public posts).
- **Like a post:** Click the like icon; click again to unlike.
- **Comment:** Open a post and type in the comment box. You can delete your own comments.
- **Edit or delete your own posts:** Options appear on posts you authored.

### Friends

From the Friends section you can:
- Search the member directory and send a friend request.
- Accept or decline incoming requests.
- See your current friends list.

Being friends only affects what shows in your **Friends Only** feed — it has no effect on **Public** posts, which anyone can see regardless of friendship.

### Messages

Open **Messages** to see your conversations. Pick a friend to open a chat thread, type a message, and send. New messages trigger a notification for the recipient.

### Notifications

The bell icon shows unread notifications for things like: someone liked or commented on your post, a friend request, a new message, or a commission payment confirmation. Click a notification to mark it read and jump to the relevant page.

### Profile

From your **Profile** page you can:
- Update your name and other account details.
- Change your password.
- Upload or replace your profile picture.

### Commissions & Payment

The **Commissions** page lets you request custom work (e.g. a 3D print or hardware build) and track your own requests — every role (user, admin, super admin) only sees their own requests here.

**Submitting a request:**
1. Go to Commissions and fill out the request form (description, and optionally attach a reference file such as a PDF or STL).
2. Submit — your request starts with status **Pending**.

**Tracking status:** An admin will review your request and move it through: `Pending → Accepted → Ongoing → Delayed (if needed) → Completed` (or `Cancelled`). You'll see the current status, any admin notes, the quoted amount, and which admin (if any) is assigned to it.

**Paying for a commission:**
1. Once an admin sets an amount, a **Pay** button appears on your request.
2. Clicking Pay opens a secure PayMongo checkout (card or GCash).
3. After you complete payment, PayMongo confirms it in the background (a "webhook"), and your commission's payment badge updates automatically:
   - **Ongoing** — payment started but not yet confirmed.
   - **Paid** — payment confirmed; this is permanent and can't be reversed by a second payment attempt.
   - If a checkout is abandoned or fails, it's marked **Failed** and you can simply click Pay again to start a fresh attempt.
4. You'll get a notification once your commission's payment is confirmed as paid.

> You can only pay for your **own** commission, for its exact quoted amount, and only once — the system blocks duplicate or double payments for the same request.

---

## Admin Features

*(Visible only to `admin` and `super_admin` accounts, via the Admin Dashboard link.)*

### User management
- **Ban / Unban:** Regular admins can ban regular users; super admins can also ban other admins. A ban requires a written reason, which is emailed to the user.
- **Delete account:** Requires a reason (emailed to the user before deletion). You cannot delete your own account or a super admin's account.

### Commission management (all requests, not just your own)
- View every commission on the platform, update its **status**, add an **admin note**, and set the **amount** to charge.
- **No self-approval:** if you submitted a commission yourself, you cannot update or approve it — another admin must handle it. This applies even to super admins.
- **Assign a commission to an admin** *(super admins only)*: pick which admin/super admin is responsible for handling a request. Regular admins can't see who's assigned to a commission unless it's assigned to them; the original requester always sees who's assigned so they know who to contact.

### Feed moderation
- Remove any post platform-wide, with a required reason. The post's owner is emailed, likes/comments are cleaned up automatically, and the removal is logged.

### Live Audit Log
A real-time activity feed on the dashboard showing logins, logouts, new posts, likes, comments, and admin actions (bans, deletions, commission changes, etc.).
- Filter by time window (8 hrs up to 30 days).
- Filter by event type (Logins / Logouts / Reacts / Comments / Posts / All).
- Search by admin username or name.
- Regular admins see standard events; super admins additionally see sensitive events like account promotions/demotions and deletions.

### Role management
Super admins can promote a regular user to admin, or demote an admin back to a regular user, from the User Management tab.

---

## Quick Reference — Roles at a Glance

| Role | Can do |
|---|---|
| **user** | Everything in the "Using the Website" section above (feed, friends, messages, commissions, profile). |
| **admin** | Everything a user can do, **plus** the Admin Dashboard: manage users (ban/delete regular users), manage all commissions (except their own), moderate the feed, view the audit log. |
| **super_admin** | Everything an admin can do, **plus** ban/delete other admins, promote/demote roles, assign commissions to admins, and view sensitive audit log entries. |
