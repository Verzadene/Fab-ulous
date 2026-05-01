# FABulous

Community platform for sharing software and hardware projects in one space. The current codebase includes username/email login, Google OAuth, email MFA, password reset by email, social posting, friendships, messaging, commissions, profile management, and admin moderation tools.

- Repository: `https://github.com/Verzadene/Fab-ulous`
- Local app root: `C:\xampp\htdocs\Fab-ulous`
- Default landing page: `http://localhost/Fab-ulous/landing/landing.html`

## Current Status

- Auth: user login, admin login, Google sign-in, email MFA, registration verification, forgot-password, and reset-password flows are implemented.
- UI: login, admin login, forgot-password, and reset-password screens share the same auth styling in `login/login.css`.
- Community: users can create posts with optional images, like posts, comment, add friends, and receive notifications.
- Messaging: direct messages are available through `post/messages.php` and `post/messages_api.php` when the `messages` table exists.
- Commissions: users can submit commissions and admins can update status and notes from both the admin dashboard and the commissions page.
- Profile: users can update account details, change password, and upload profile pictures.
- Admin: admins can manage users, moderate posts, review commissions, and write audit-log entries. Super admins can promote and demote admins.

## Project Structure

```text
Fab-ulous/
|-- admin/
|-- database/
|-- documentation/
|-- images/
|-- landing/
|-- login/
|-- oauth/
|-- post/
|-- profile/
|-- register/
|-- uploads/
|-- CLAUDE.md
|-- README.md
`-- config.php
```

## Setup

1. Clone the repository.

```bash
git clone https://github.com/Verzadene/Fab-ulous.git
```

2. Place it in XAMPP `htdocs`.

```text
C:\xampp\htdocs\Fab-ulous
```

3. Start Apache and MySQL from XAMPP.
4. Create a MySQL database named `fab_ulous`.
5. Run the schema files in this order:

```text
database/setup.sql
database/migration_v3_mfa.sql
database/migration_v4.sql
database/migration_v5.sql
```

6. Open the app at `http://localhost/Fab-ulous/landing/landing.html`.

## Important Config

`config.php` is the single source of truth for:

- MySQL connection settings
- Google OAuth settings
- SMTP settings for MFA, registration verification, and password reset emails
- Shared session/auth helper functions

If reset codes or MFA emails are not being delivered, check the SMTP values in `config.php` first.

## Key Routes

| Route | Purpose |
|---|---|
| `landing/landing.html` | Public landing page |
| `login/login.php` | User sign-in with username/email, password, and Google OAuth entry |
| `login/verify_mfa.php` | Email MFA verification after login |
| `login/forgot_password.php` | Request a 6-digit password reset code |
| `login/reset_password.php` | Submit reset code and set a new password |
| `register/register.html` | Registration UI |
| `register/register.php` | Registration handler |
| `register/verify_registration.php` | Email verification for new accounts |
| `post/post.php` | Main feed, notifications, and friend tools |
| `post/messages.php` | Messaging UI |
| `post/commissions.php` | Commission request/history UI |
| `profile/profile.php` | Profile and account settings |
| `admin/admin.php` | Admin dashboard |
| `admin/admin_login.php` | Admin sign-in |

## External Integrations

| Integration | What it is used for | Location in repo |
|---|---|---|
| Google OAuth 2.0 | Redirect users to Google sign-in and fetch profile data | `login/login.php`, `oauth/oauth2callback.php`, `config.php` |
| Gmail SMTP | Send MFA codes, registration verification codes, and password reset codes | `config.php`, `login/login.php`, `admin/admin_login.php`, `login/forgot_password.php`, `register/register.php` |
| Bootstrap 5.3.3 CDN | Shared UI framework | Auth pages, landing page, admin pages, profile page, post pages |
| Google Fonts | `Josefin Sans` and `Inter` typography | Auth pages, landing page, admin pages, profile page, post pages |
| Chart.js CDN | Charts on the admin dashboard | `admin/admin.php` |

## Internal API and AJAX Endpoints

| Endpoint | Method(s) | Used by | Purpose |
|---|---|---|---|
| `register/prefill.php` | `GET` | `register/register.js` | Returns Google-prefilled registration data as JSON |
| `post/like.php` | `POST` | `post/post.php` | Toggle a post like and return updated like count |
| `post/comment.php` | `GET`, `POST` | `post/post.php` | Fetch comments for a post or add a new comment |
| `post/friends.php` | `GET`, `POST` | `post/post.php` | Check friendship state, send requests, accept, reject, cancel, or remove |
| `post/notifications.php` | `GET`, `POST` | `post/post.php` | List notifications, count unread items, and mark items as read |
| `post/messages_api.php` | `GET`, `POST` | `post/messages.php` | Load conversation history and send messages |
| `admin/commission_update.php` | `POST` | `admin/admin.php` | Update commission status and admin notes from the dashboard |
| `post/edit_post.php` | `POST` | `post/post.php` | Edit a user-owned post caption |
| `post/delete_post.php` | `POST` | `post/post.php` | Delete a user-owned post |
| `post/create_post.php` | `POST` | `post/post.php` form submit | Create a new post with optional uploaded image |
| `post/commissions.php` | `GET`, `POST` | `post/commissions.php` | Submit a commission request and, for admins, update commission status inline |

## Database Notes

- `migration_v3_mfa.sql` is required for MFA columns on `accounts`.
- `migration_v4.sql` supports later profile-related schema changes.
- `migration_v5.sql` creates `password_resets` and `messages`, and updates commission/audit-log schema.
- Without `migration_v5.sql`, forgot-password and messaging will show availability errors.

## Collaboration

Pull the latest code:

```bash
git pull origin master
```

Push your changes:

```bash
git add .
git commit -m "fix: short description"
git push origin master
```

## Documentation

- Project notes for AI/code assistants: `CLAUDE.md`
- Longer project documentation: `documentation/FABulous_ProjectDocs_v0.2.0.docx`
