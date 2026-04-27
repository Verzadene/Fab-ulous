
---

```markdown
# FABulous 🛠️⭐

A community platform for sharing software and hardware projects in a single unified space.
FABulous bridges the gap between digital and physical creation — from code to builds.

---

## 🌐 About the Project

As technology evolves, sharing projects in both software and hardware becomes increasingly important.
FABulous provides a community where makers, engineers, and students can share their projects,
collaborate with others, and access Fab Lab services — all in one platform.

---

## 📁 Project Structure

```
FAB-ULOUS/
├── admin/
├── database/
├── documentation/
│   └── FABulous_ProjectDocs_v0.2.0.docx
├── images/
│   ├── source/
│   ├── Big Logo.png
│   └── Top Left Logo.png
├── landing/
│   ├── landing.css
│   └── landing.html
├── login/
│   ├── login.css
│   └── login.php
├── oauth/
│   └── oauth2callback.php
├── post/
│   ├── post.css
│   └── post.html
├── profile/
├── register/
│   ├── register.css
│   ├── register.html
│   ├── register.js
│   └── register.php
└── README.md
```

---

## ✅ Current Features

### 🏠 Landing Page
- Navigation bar with Settings, Sign In, and hamburger menu button
- WELCOME hero section with search bar
- Sign Up and About Us call-to-action buttons
- Double green divider lines under nav (`#477C5A`)
- Sign In routes to Login page
- Sign Up routes to Register page

### 🔐 Authentication
- User registration with full client-side validation via `register.js`
  - Minimum 16 character password
  - Requires at least 2 special characters and 2 numbers
  - Confirm password matching check
- Server-side duplicate check — blocks same email or username from registering twice
- User login with show/hide password toggle — matches by username OR email
- Return button on Login and Register routes back to Landing page
- Continue with Google (OAuth 2.0) on both Login and Register pages
- Google OAuth prevents duplicate accounts — if email already exists, logs in instead of inserting
- Forgot Password link (UI only — not yet wired)

### 📋 Post Dashboard
- Three-column layout: sidebar, news feed, notifications panel
- Left sidebar with avatar placeholder, username, email, and nav links
- News Feed, Messages, Uploads, Settings sidebar navigation
- Post card placeholders in the main feed
- Notification panel on the right
- Top nav with Home (active), Projects, Commissions, History, and hamburger menu

### 🎨 UI & Design
Consistent color palette across all pages:

| Color | Hex | Used For |
|---|---|---|
| Dark Green | `#25342B` | Page background |
| Near Black | `#1E1E1E` | Sign In button, accents |
| Sage Green | `#4E7A5E` | Left panel, input focus |
| Light Grey | `#D9D9D9` | Right panel background |
| Bright Green | `#77CC81` | Sign Up button |
| Nav Divider | `#477C5A` | Double lines under nav |
| White | `#FFFFFF` | Text, Sign In button fill |

- Roboto Condensed font applied globally (400, 500, 600, 700, 800)
- Bootstrap 5.3.3 integrated — loaded before custom CSS to prevent conflicts
- Hover effects: lift + box-shadow on all buttons
- Active press effect: scale on click
- Real logo images applied on Login and Post pages

### 🗄️ Database
- MySQL via XAMPP (phpMyAdmin)
- `fab_ulous` database with `accounts` table
- PHP `mysqli` with prepared statements and `bind_param` for all queries
- Duplicate check on both `email` and `username` during registration
- Google OAuth stores `google_id` in accounts table
- Server-side password validation as a security layer alongside client-side JS

### 🔑 Google OAuth
- OAuth 2.0 flow via `oauth/oauth2callback.php`
- Exchanges authorization code for access token
- Fetches Google user profile (name, email, Google ID)
- Checks for existing account by email before inserting
- Auto-generates username from Google display name
- Sets PHP session on successful login
- Available on both Login and Register pages

---

## 🚧 Upcoming Features

### 📝 Post Creation
- Allow users to create and publish posts to the news feed
- Support for images, descriptions, and project tags
- Like and comment functionality

### 💬 Messages
- Direct messaging between users

### 👤 User Profiles
- Profile pages with project portfolios and bio

### 💳 Commission & Transaction System
A built-in marketplace for Fab Lab services:
- Request 3D printing commissions directly through the platform
- Track order status from request to completion
- File upload support for 3D model submissions (.stl, .obj)
- Pricing calculator based on material and print time

### 🔧 And More Coming Soon...
- Admin dashboard for managing users, posts, and commissions
- Forgot Password flow
- Real-time notifications
- Search functionality across posts and users
- Mobile responsive design

---

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, JavaScript (ES6) |
| UI Framework | Bootstrap 5.3.3 |
| Font | Roboto Condensed (Google Fonts) |
| Backend | PHP 8.2 |
| Database | MySQL 8 (via phpMyAdmin) |
| Local Server | XAMPP (Apache + MySQL) |
| Authentication | Google OAuth 2.0 |
| Version Control | Git & GitHub |
| Design | Figma |
| Code Editor | Visual Studio Code |

---

## 🚀 Getting Started

### Prerequisites
- XAMPP installed with **Apache** and **MySQL** running
- phpMyAdmin accessible at `http://localhost/phpmyadmin`

### Database Setup
1. Open phpMyAdmin
2. Create a new database called `fab_ulous`
3. Run the following SQL:

```sql
CREATE TABLE accounts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name  VARCHAR(100) NOT NULL,
  username   VARCHAR(100) NOT NULL UNIQUE,
  email      VARCHAR(150) NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  google_id  VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Setup
1. Clone the repository
   ```bash
   git clone https://github.com/Verzadene/Fab-ulous.git
   ```
2. Move the folder to your XAMPP htdocs directory
   ```
   C:\xampp\htdocs\Fab-ulous
   ```
3. Start **Apache** and **MySQL** in XAMPP Control Panel

4. Open your browser and go to
   ```
   http://localhost/Fab-ulous/landing/landing.html
   ```

> ⚠️ Do NOT open HTML files directly in the browser — PHP will not run without Apache.

---

## 👥 Collaboration Guide

### Pull latest changes
```bash
git pull origin master
```

### Push your changes
```bash
git add .
git commit -m "feat: your feature description"
git push origin master
```

### Work on a feature branch (recommended)
```bash
git checkout -b feature/your-feature-name
git push origin feature/your-feature-name
```
Then open a Pull Request on GitHub to merge into `master`.

### Commit Message Convention

| Prefix | Use For |
|---|---|
| `feat:` | New feature or page |
| `fix:` | Bug fix |
| `style:` | CSS or visual changes only |
| `refactor:` | Code restructuring |
| `docs:` | Documentation updates |

---

## 📄 Documentation

Full project documentation including design specs, file structure, Git guide, and upcoming features:

`documentation/FABulous_ProjectDocs_v0.2.0.docx`

---

*FABulous — The Future of Fablab is Here.* ⭐
```

---

### How to update it on GitHub:

1. Go to `https://github.com/Verzadene/Fab-ulous`
2. Click `README.md`
3. Click the **pencil icon** to edit
4. Select all and paste the content above
5. Click **Commit changes**
6. Then sync locally:
```bash
git pull origin master
