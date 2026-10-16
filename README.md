<div align="center">

# Converse

**A modern, real-time chat platform built with PHP, MySQL, WebSockets, and vanilla JavaScript.**

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Made with Love](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F-red.svg)](#)

</div>

---

## 📖 Table of Contents

- [About](#-about)
- [Features](#-features)
- [Tech Stack](#-tech-stack)
- [Project Structure](#-project-structure)
- [Screenshots](#-screenshots)
- [Installation](#-installation)
- [Configuration](#-configuration)
- [Running the App](#-running-the-app)
- [Deployment](#-deployment)
- [Troubleshooting](#-troubleshooting)
- [Contributing](#-contributing)
- [License](#-license)

---

## 🎯 About

**Converse** is a self-hosted, real-time messaging application that runs on nothing but PHP and MySQL. It uses a native, dependency-free WebSocket server (RFC 6455) implemented entirely in PHP, meaning **no Composer, no Node.js, no build step** is required to get started.

Whether you want a private team chat, a community room system, or a learning reference for building real-time apps with classic LAMP infrastructure, Converse gives you a complete, production-ready starting point — with file sharing, presence, calling, theming, and role-based permissions included.

---

## ✨ Features

### 🔐 Authentication & Accounts
- Single login/register page with tabbed switching
- Bcrypt password hashing with automatic rehash
- CSRF-protected POST endpoints
- Session regeneration on login
- Account persistence with configurable profile (display name, bio, status message)
- Custom avatar upload or generated colored initials

### 💬 Real-Time Messaging
- Instant delivery via WebSocket (RFC 6455, pure PHP)
- HTTP polling fallback (auto-activates if WebSocket is unreachable)
- Typing indicators with debounce
- Message grouping by author and timestamp
- Day separators (Today / Yesterday / exact date)
- Optimistic UI for outgoing messages
- Link auto-detection and image lightbox
- Reply-to-message with quote preview
- Pin/unpin messages (room admin only)
- Soft-delete messages (author or room admin)
- Disappearing messages (5m / 1h / 24h / 7d per room)

### 🎨 Rich Media
- Image attachments with inline preview + lightbox
- Video and audio attachments with native HTML5 players
- Document attachments (PDF, DOCX, XLSX, PPTX, ZIP, CSV, TXT)
- Composer emoji picker (200+ grouped emojis)
- Sticker picker (32 expressive stickers)
- Size limits: 5 MB images, 30 MB files
- Safe uploads (PHP execution disabled in `uploads/`)

### 🏠 Rooms
- Public rooms visible to all
- Room avatars (upload custom image)
- Room rename (admin only)
- Room description editing
- 10 wallpaper presets (aurora, sunset, ocean, forest, midnight, lavender, paper, space, nebula, default)
- Room-specific themes (light, dark, midnight, emerald, rose)
- Room-specific fonts (inter, roboto, mono, serif, rounded)
- Disappearing-message timer
- Member count and last-message preview in sidebar

### 👥 Membership & Roles
- Three roles: **owner**, **admin**, **member**
- Owner auto-assigned to room creator
- Promote/demote members (owner only)
- Add members by search (admin only)
- Remove members with owner protection
- Leave room (owner must delete or transfer)
- **Auto-push notifications** when added or removed

### 🌐 Presence & Status
- Four presence states: **online**, **away**, **busy**, **offline** (invisible)
- Status messages (custom text)
- Last-seen timestamp with toggle (hide/show)
- Live presence updates via WebSocket
- Compact member list with status dots

### 🔔 Notifications
- In-app notification badge with unread counter
- Dropdown panel listing latest 50 notifications
- Mark individual or all as read
- Auto-polling every 30 seconds
- Events: added to room, removed from room (extendable)

### 📞 Audio & Video Calls
- WebRTC-based one-to-many calls
- Local + remote video previews
- Mute microphone toggle
- Camera on/off toggle
- Call timer
- Emergency end-call fallback button
- Room-level call invitations via WebSocket signaling

### 🎨 Theming & Personalization
- User-level theme (light, dark, midnight, emerald, rose)
- User-level font family
- Per-room wallpaper override
- Full dark-first design system
- Responsive from 320px phone to 4K ultrawide

### 📱 Responsiveness
- Mobile-first media queries
- Sidebar slides in on phones
- Touch-friendly hit targets (≥42px)
- Landscape phone optimization
- Reduced-motion support
- High-contrast preference support

---

## 🛠 Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.0+ (uses match expressions, typed properties, arrow functions) |
| **Database** | MySQL 5.7+ / MariaDB 10.3+ (InnoDB, utf8mb4) |
| **Real-Time** | Native PHP WebSocket server (RFC 6455, zero dependencies) |
| **Frontend** | Vanilla JavaScript (ES2020+, no framework) |
| **Styling** | Modern CSS with custom properties, grid, and flexbox |
| **Fonts** | Inter + JetBrains Mono (Google Fonts) |
| **Server** | Apache 2.4+ (XAMPP-compatible) |

---

## 📂 Project Structure
chat-app/
├── .htaccess # Root protection for config/ and database/
├── README.md # This file
├── LICENSE # MIT license
├── .gitignore # Ignore patterns
│
├── config/
│ ├── database.php # PDO singleton
│ └── bootstrap.php # Session, CSRF, helpers, notifications
│
├── database/
│ └── schema.sql # Full schema — import once
│
├── src/
│ ├── api/
│ │ ├── messages.php # GET (list) + POST (send, pin, delete)
│ │ ├── rooms.php # GET (list) + POST (create, update, delete)
│ │ ├── members.php # GET (list) + POST (add, remove, promote)
│ │ ├── profile.php # GET (me) + POST (update)
│ │ ├── notifications.php # GET (list) + POST (read, clear)
│ │ ├── users.php # GET (all users except self)
│ │ └── upload.php # Multipart uploads (avatar, room, file)
│ │
│ ├── auth/
│ │ ├── register.php # POST — create account
│ │ ├── login.php # POST — verify credentials
│ │ └── logout.php # POST — destroy session
│ │
│ └── websocket/
│ └── server.php # Native WebSocket server (RFC 6455)
│
└── public/
├── .htaccess # Public access rules
├── index.php # Login / register page
├── chat.php # Main chat interface
│
├── assets/
│ ├── css/
│ │ ├── base.css # Design tokens, buttons, toasts
│ │ ├── auth.css # Login/register styling
│ │ ├── chat.css # Chat interface + new feature styles
│ │ └── responsive.css # Cross-cutting media queries
│ │
│ └── js/
│ ├── auth.js # Login/register handlers
│ ├── ui.js # Toast, modal, date helpers
│ ├── websocket.js # Reconnecting WS client
│ └── chat.js # Full chat controller
│
└── uploads/
├── .htaccess # Deny PHP execution
├── avatars/ # User avatars
├── rooms/ # Room images
└── files/ # Chat attachments



---

## 🚀 Installation

### Prerequisites

- **XAMPP** (or any Apache + PHP 8.0+ + MySQL 5.7+ stack)
- **Windows, macOS, or Linux** — tested on Windows 10/11 with XAMPP 8.2

### 1. Clone or extract the project

```bash
cd C:\xampp\htdocs
git clone https://github.com/yourname/chat-app.git
# or extract the ZIP into C:\xampp\htdocs\chat-app

2. Create the database
Open phpMyAdmin: http://localhost/phpmyadmin

Click Import

Choose chat-app/database/schema.sql

Click Go

Or via CLI:
mysql -u root -p < database/schema.sql

3. Create upload directories
cd C:\xampp\htdocs\chat-app\public
mkdir uploads
mkdir uploads\avatars
mkdir uploads\rooms
mkdir uploads\files

4. Configure PHP (php.ini)
Open C:\xampp\php\php.ini and ensure these values:

ini
file_uploads = On
upload_max_filesize = 30M
post_max_size = 32M
max_execution_time = 120
memory_limit = 256M

extension=fileinfo
extension=gd
extension=openssl
extension=pdo_mysql
extension=mysqli
Remove any leading ; to enable them, then restart Apache.

5. Verify database credentials
Edit config/database.php if your MySQL uses non-default credentials:

php
private const DB_HOST = 'localhost';
private const DB_NAME = 'chat_app';
private const DB_USER = 'root';
private const DB_PASS = '';       // change if you set a root password
⚙️ Running the App
You need two terminal windows running simultaneously.

Terminal 1 — Apache + MySQL
Start them from the XAMPP Control Panel, or:

bash
C:\xampp\xampp_start.exe
Terminal 2 — WebSocket server
powershell
cd C:\xampp\htdocs\chat-app
php src\websocket\server.php
Expected output:

text
[Converse] WebSocket server listening on ws://0.0.0.0:8080
[Converse] Press Ctrl+C to stop.
Keep this running. If it stops, the app falls back to HTTP polling (still works, just less real-time).

Open the app
text
http://localhost/chat-app/public/
Register an account → you're redirected to the login page → sign in → you're in.

To test real-time:

Open an incognito window

Register a second account

Open both in the same room

Type — messages appear instantly

⚙️ Configuration
Changing default database credentials
Edit config/database.php:

php
private const DB_HOST = 'localhost';
private const DB_NAME = 'chat_app';
private const DB_USER = 'root';
private const DB_PASS = 'your_password';
Changing WebSocket port
Edit src/websocket/server.php:

php
private const PORT = 8080;   // change to whatever
And in public/chat.php:

javascript
wsUrl: (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.hostname + ':8080',
Both must match.

Adding wallpaper presets
Add CSS in public/assets/css/chat.css:

css
.chat-main[data-wallpaper="mytheme"] {
    background: linear-gradient(135deg, #111, #222);
}
Add a swatch to public/chat.php inside #wallpaperPicker:

html
<button class="wallpaper-swatch" data-wallpaper="mytheme">
    <span class="wp-mytheme"></span>My Theme
</button>
Add the swatch background:

css
.wp-mytheme { background: linear-gradient(135deg, #111, #222); }
Add 'mytheme' to the allowed list in src/api/rooms.php (search for $allowed = [).

Adding notification triggers
Anywhere in PHP, call:

php
pushNotification(
    $userId,                    // recipient
    'added_to_room',            // type
    'Added to room',            // title
    'You were added to "Team"', // body
    'chat.php'                  // click link
);
🌍 Deployment
Deploying to shared hosting (InfinityFree, 000webhost, etc.)
InfinityFree and most free hosts block long-running processes, so WebSockets won't work. But Converse automatically falls back to HTTP polling every 3 seconds.

Steps:

Upload all files except src/websocket/ via FTP to htdocs/

Import database/schema.sql via phpMyAdmin on the host

Edit config/database.php with the host's DB credentials

In public/chat.php, disable WebSocket:

javascript
wsUrl: '',   // empty string → app uses polling
Ensure public/uploads/ and its subfolders are writable (chmod 755)

Set public/uploads/.htaccess to the version above

Deploying to a VPS / Render / Railway (with WebSocket support)
For true real-time on production, host the WebSocket server separately.

On Render.com (free tier):

Push your repo to GitHub

Create a new Web Service pointing at the repo

Build command: echo "no build needed"

Start command: php src/websocket/server.php

Note the assigned HTTPS URL, e.g. wss://converse-ws.onrender.com

In public/chat.php, update:

javascript
wsUrl: 'wss://converse-ws.onrender.com',
Ensure the server binds to 0.0.0.0 and respects $PORT env variable (modify server.php if needed)

The PHP app (Apache) and WebSocket server then run on different hosts, connected over the internet.

Docker (optional)
Create Dockerfile:

dockerfile
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/public/uploads
RUN a2enmod rewrite headers expires deflate

EXPOSE 80
And docker-compose.yml:

yaml
version: '3'
services:
  web:
    build: .
    ports: ['8080:80']
    volumes: ['./:/var/www/html']
    depends_on: [db]
  db:
    image: mysql:8
    environment:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: chat_app
    volumes:
      - ./database/schema.sql:/docker-entrypoint-initdb.d/schema.sql
  ws:
    build: .
    command: php /var/www/html/src/websocket/server.php
    ports: ['8090:8080']
🐛 Troubleshooting
"Forbidden — You don't have permission to access this resource"
Your .htaccess is blocking access. Verify:

public/.htaccess contains <RequireAll> Require all granted </RequireAll> (or Require all granted for Apache 2.4)

Root .htaccess only blocks config/ and database/

"Upload failed (server error)"
Open DevTools → Network → click the upload.php request → Response tab. Common causes:

Response	Fix
404 Not Found	src/api/upload.php missing, or JS is calling /src/upload.php (should be /src/api/upload.php)
403 Invalid session token	CSRF token stale — hard refresh (Ctrl+Shift+R)
File type not allowed	fileinfo extension not enabled in php.ini
Upload directory not writable	Run icacls "C:\xampp\htdocs\chat-app\public\uploads" /grant Everyone:F /T
move_uploaded_file failed	Folder doesn't exist — create avatars/, rooms/, files/
"UI.parseDate is not a function"
Your ui.js is out of date — make sure parseDate is exported on window.UI.

WebSocket never connects (status stays "Polling mode")
Is php src/websocket/server.php running in another terminal?

Is port 8080 free? Check with netstat -ano | findstr :8080

Firewall blocking? Allow php.exe on private networks.

Camera/microphone "permission denied"
Chrome requires a secure context. http://localhost counts; other IPs do not.

Allow camera/mic in chrome://settings/content/camera and chrome://settings/content/microphone

Only one app can hold the camera at a time — close Zoom/Teams/OBS

Check Windows Privacy Settings → Camera → allow desktop apps

Database connection fails
text
Database connection failed
Is MySQL running in XAMPP?

Does the DB chat_app exist? Import schema.sql.

Are credentials correct in config/database.php?

🤝 Contributing
Contributions are welcome! Please follow these steps:

Fork the repo

Create a feature branch:

bash
git checkout -b feature/amazing-feature
Commit with a descriptive message following Conventional Commits:

bash
git commit -m "feat(api/messages): add message editing"
Push:

bash
git push origin feature/amazing-feature
Open a Pull Request

Code style
PHP: PSR-12, declare(strict_types=1) at top of every file

JavaScript: ES2020+, 'use strict', IIFE-wrapped modules

CSS: custom properties, mobile-first media queries

Commits: Conventional Commits format

📄 License
This project is licensed under the MIT License — see the LICENSE file for details.

🙏 Acknowledgements
Inter by Rasmus Andersson — UI typeface

JetBrains Mono — monospace typeface

The RFC 6455 WebSocket spec — for making dependency-free real-time possible in PHP

The PHP and MySQL communities — for infrastructure that just works

<div align="center">
Built with ❤️ by developers who believe real-time shouldn't require a build step.

⭐ If you find Converse useful, star the repo!

</div> ```
🎁 Bonus Files You Should Also Add
LICENSE (MIT)
text
MIT License

Copyright (c) 2026 Converse

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
.gitignore
text
# Dependencies
/vendor/
/node_modules/

# User uploads (do not commit user data)
/public/uploads/avatars/*
/public/uploads/rooms/*
/public/uploads/files/*
!/public/uploads/**/.gitkeep

# Config
.env
config/database.local.php

# IDE
.idea/
.vscode/
*.sublime-*
.DS_Store
Thumbs.db

# Logs
*.log
logs/

# Composer (if you ever add it)
composer.lock
public/uploads/**/.gitkeep
Create empty .gitkeep files in each of avatars/, rooms/, files/ so Git tracks the folders:

powershell
cd C:\xampp\htdocs\chat-app\public\uploads
type nul > avatars\.gitkeep
type nul > rooms\.gitkeep
type nul > files\.gitkeep