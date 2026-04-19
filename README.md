# FABulous

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
├── documentation/
│   └── FABulous_ProjectDocs_v0.2.0.docx
├── images/
│   ├── source/               # Raw/Unprocessed assets
│   ├── Big Logo.png
│   └── Top Left Logo.png
├── landing/
│   ├── landing.css
│   └── landing.html
├── login/
│   ├── login.css
│   └── login.html
├── post/
│   ├── post.css
│   └── post.html
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
- User registration with full client-side validation via `Register.js`
  - Minimum 16 character password
  - Requires at least 2 special characters and 2 numbers
  - Confirm password matching check
- User login with show/hide password toggle
- Return button on Login and Register routes back to Landing page
- Continue with Google button (UI only — not yet wired)
- Forgot Password link (UI only — not yet wired)

### 🎨 UI & Design
- Consistent color palette across all pages:

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

### 🗄️ Database
- Microsoft SQL Server (SSMS / SQLEXPRESS) integration
- `FAB_ULOUS` database with `ACCOUNTS` table
- PHP backend processes registration and inserts user data
- Windows Authentication connection
- Server-side validation as a security layer alongside client-side JS

---

## 🚧 Upcoming Features

### 📋 Post Dashboard
A social media-style feed where users can:
- Create and share software and hardware project posts
- View, like, and comment on other users' projects
- Filter posts by category (software, hardware, 3D printing, etc.)
- Follow other makers and build a personal feed

### 💳 Commission & Transaction System
A built-in marketplace for Fab Lab services:
- Request 3D printing commissions directly through the platform
- Track order status from request to completion
- Secure transaction handling between clients and Fab Lab operators
- File upload support for 3D model submissions (.stl, .obj)

### 🔧 And More Coming Soon...
- User profile pages with project portfolios
- Admin dashboard for managing users and commissions
- Google OAuth full integration
- Real-time notifications system
- Search functionality across all posts and users
- Messaging between users
- Mobile responsive design

---

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, JavaScript (ES6) |
| UI Framework | Bootstrap 5.3.3 |
| Font | Roboto Condensed (Google Fonts) |
| Backend | PHP 8.2 |
| Database | Microsoft SQL Server (SSMS / SQLEXPRESS) |
| Local Server | XAMPP (Apache) |
| Version Control | Git & GitHub |
| Design | Figma |
| Code Editor | Visual Studio Code |

---

## 🚀 Getting Started

### Prerequisites
- XAMPP installed with Apache running
- Microsoft SQL Server (SQLEXPRESS) running
- PHP sqlsrv drivers installed and enabled in `php.ini`

### Setup
1. Clone the repository
```bash
   git clone https://github.com/Verzadene/Fab-ulous.git
```
2. Move the folder to your XAMPP htdocs directory
```
   C:\xampp\htdocs\Fab-ulous
```
3. Start Apache in XAMPP Control Panel

4. Open your browser and go to
```
   http://localhost/Fab-ulous/html/Landing.html
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

`Documentation/FABulous_ProjectDocs_v0.2.0.docx`

---

*FABulous — The Future of Fablab is Here.*
