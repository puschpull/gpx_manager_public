# 🗺 GPX Manager — Installation & User Guide

> Version 2026-05 &nbsp;|&nbsp; [Česká verze](cs.md)

---

## Table of Contents

1. [Server Requirements](#1-server-requirements)
2. [Preparing the Database](#2-preparing-the-database)
3. [Uploading Files to the Server](#3-uploading-files-to-the-server)
4. [Installation Wizard (setup.php)](#4-installation-wizard-setupphp)
5. [Manual Installation](#5-manual-installation)
6. [uploads/ Folder Permissions](#6-uploads-folder-permissions)
7. [How Login and Access Work](#7-how-login-and-access-work)
8. [First Steps After Installation](#8-first-steps-after-installation)
9. [API Keys for Maps](#9-api-keys-for-maps)
10. [Backup and Maintenance](#10-backup-and-maintenance)
11. [Troubleshooting](#11-troubleshooting)

---

## 1. Server Requirements

### Minimum Versions

| Component | Minimum | Recommended |
|---|---|---|
| **PHP** | 8.0 | 8.2 or newer |
| **MySQL** | 5.7 | 8.0 or newer |
| **MariaDB** | 10.3 | 10.11 or newer |
| **Apache** | 2.4 | any current version |
| **Nginx** | 1.18 | any current version |

> **Why PHP 8.0+?** The application uses `match()` expressions and arrow functions (`fn()`), which require PHP 8.0 or newer.

### Required PHP Extensions

| Extension | Purpose | Required |
|---|---|---|
| `pdo_mysql` | Database connection | ✅ Yes |
| `simplexml` | Parsing GPX files | ✅ Yes |
| `gd` | Generating track thumbnails | ✅ Yes |
| `exif` | Reading GPS data from photos | ✅ Yes |
| `zip` | Importing tracks from ZIP archives | ✅ Yes |
| `json` | Internal data format | ✅ Yes (built into PHP) |
| `mbstring` | Proper UTF-8 text handling | ⚠️ Recommended |

**How to check extensions:** Create a file `info.php` with the content `<?php phpinfo();`, upload it to the server, open it in a browser and look for the extension names. Delete the file after checking.

### Local Development (Windows)

- **WampServer** 3.3+ — download at [wampserver.com](https://www.wampserver.com/)
- **XAMPP** — download at [apachefriends.org](https://www.apachefriends.org/)

### Web Hosting

The application runs on standard shared hosting with PHP 8.0+ and MySQL. Verify support with your provider — most modern hosts (Bluehost, SiteGround, Hostinger, etc.) meet these requirements.

---

## 2. Preparing the Database

The database must exist **before** running the installation.

### Via phpMyAdmin (web hosting or WampServer)

1. Open phpMyAdmin
   - WampServer: click the icon in the system tray → **phpMyAdmin**
   - Web hosting: find the phpMyAdmin link in your hosting control panel
2. In the left panel, click **New database** (or **New**)
3. Database name: `gpx_manager`
4. Collation: `utf8mb4_unicode_ci`
5. Click **Create**

### Via MySQL Command Line

```sql
CREATE DATABASE gpx_manager
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

> **Web hosting:** The database name and user are usually created in your hosting admin panel (cPanel, ISPConfig, etc.). The hosting provider will give you the credentials — keep them handy for the installation.

---

## 3. Uploading Files to the Server

### WampServer / XAMPP (local)

Extract the downloaded ZIP into the folder:
- WampServer: `C:\wamp64\www\gpx_manager\`
- XAMPP: `C:\xampp\htdocs\gpx_manager\`

### Web Hosting (via FTP)

1. Use an FTP client such as [FileZilla](https://filezilla-project.org/) (free)
2. Connect to your hosting (FTP credentials are in your hosting control panel)
3. Upload the contents of the folder to the web root (`public_html/`, `www/`, etc.)
   or into a subfolder, e.g. `public_html/gpx/`

> **Note:** Do **not** upload the `.env` file — it will be created automatically during installation. It is excluded from Git (protected by `.gitignore`).

---

## 4. Installation Wizard (setup.php)

The easiest method — recommended for beginners.

### Step 1 — Database Connection

Open in your browser:
- WampServer: `http://localhost/gpx_manager/setup.php`
- Web hosting: `https://yoursite.com/setup.php` (or with subfolder)

Fill in:
- **Database server:** usually `localhost` (on web hosting it may differ — check your hosting panel)
- **Database name:** `gpx_manager` (or the name you created)
- **User:** `root` (WampServer default) or your hosting database user
- **Password:** empty (WampServer default) or your hosting password

Click **Test connection →**

### Step 2 — Admin Account

- **Username:** any name you like (e.g. `admin`)
- **Password:** at least 8 characters — choose a strong password

### Step 3 — Access & API Keys

- **Admin IP addresses:** the wizard pre-fills your current IP address. Edit as needed (separate multiple IPs with commas).
  - Find your public IP at [whatismyip.com](https://www.whatismyip.com/)
- **API keys:** optional, see section [9. API Keys for Maps](#9-api-keys-for-maps). Can be added later.

Click **Complete installation** — the wizard creates the `.env` file, imports the database tables, and deletes itself.

---

## 5. Manual Installation

If the wizard cannot be used or you prefer manual setup.

### Step 1 — Create the `.env` File

Copy the template:
```
.env.example  →  .env
```

Open `.env` in a text editor and fill in the values:

```env
# Database
DB_HOST=localhost
DB_NAME=gpx_manager
DB_USER=root
DB_PASS=

# Admin account
ADMIN_USER=admin
ADMIN_PASS_HASH=        # see below how to generate

# Admin IP addresses (comma-separated, localhost is always allowed)
ADMIN_IPS=127.0.0.1,::1,123.456.789.0

# API keys (optional, can be added at any time)
TF_API_KEY=
MAPYCOM_API_KEY=
MAPILLARY_TOKEN=
```

### Step 2 — Generate a bcrypt Password Hash

Open a command prompt and run:

```bash
php -r "echo password_hash('YourPassword123', PASSWORD_DEFAULT);"
```

Copy the output (starting with `$2y$...`) and paste it into `ADMIN_PASS_HASH=` in the `.env` file.

### Step 3 — Import the Database Schema

**Via phpMyAdmin:**
1. Select your database
2. Click the **Import** tab
3. Choose the file `install.sql`
4. Click **Import**

**Via command line:**
```bash
mysql -u root -p gpx_manager < install.sql
```

---

## 6. uploads/ Folder Permissions

The `uploads/` folder and its subfolders must be **writable** by the web server.

### WampServer / XAMPP (Windows)

Usually no changes are needed — permissions are set automatically.

### Web Hosting / Linux (VPS)

**Via SSH command line:**
```bash
chmod 755 uploads/
chmod 755 uploads/thumbs/
chmod 755 uploads/photos/
chmod 755 uploads/photos/thumbs/
```

**Via FTP (FileZilla):**
1. Right-click on the `uploads/` folder → **File Permissions**
2. Enter `755`, check **Apply to subdirectories recursively**
3. Confirm

---

## 7. How Login and Access Work

The application distinguishes three roles:

| Role | How they log in | What they can do |
|---|---|---|
| **Admin (IP)** | Accesses from an allowed IP address | Everything — manage tracks, import, delete, settings |
| **Admin (password)** | Logs in via `login.php` | Everything — manage tracks, import, delete, settings |
| **Visitor** | Does not log in | View-only access to permitted pages |

### Automatic Login from IP Address

If you access the app from an IP address listed in `ADMIN_IPS`, you are automatically logged in as admin — no password required. Ideal for home network access.

### Password Login

Anyone with the username and password can log in as admin from any IP address — so use a strong password and share it only with trusted people.

### Visitor Mode

Unauthenticated users can only see pages the admin has enabled in Settings. By default, these are: Statistics, Calendar, Heatmap, Map Search, Nearby Tracks, Filter, Compare.

### Visitor Preview

The admin can click **👁 Preview as visitor** (in the top blue banner) to see the application exactly as an unauthenticated user would see it.

---

## 8. First Steps After Installation

### Logging In

1. Open `http://localhost/gpx_manager/` (or your site URL)
2. Click **Log in** or go to `login.php`
3. Enter your username and password

If you are accessing from an allowed IP address, you will be logged in automatically and will see a blue admin banner at the top of the page.

### Importing Your First Track

1. Click **Import** in the navigation
2. Drag and drop a GPX file (or click to select from your folder)
3. Click **Start import** — the app will analyse the file, save it and generate a thumbnail
4. The track will appear in the overview on the home page

> **Tip:** You can import multiple files at once, or a ZIP archive containing multiple GPX files.

### Setting Language and Theme

The application offers:
- **8 languages:** Czech, English, German, Slovak, Spanish, French, Italian, Polish
- **9 colour themes:** classic, dark, darkblue, darkgreen, blue, green, minimal, lightgray, brown

The language and theme switcher is in the top right corner of every page.

### Controlling Which Pages Visitors Can See

In the **Settings** menu (admin only) you can enable or disable individual pages for unauthenticated visitors:

- Statistics
- Activity calendar
- Heatmap
- Map search
- Nearby tracks
- GPX filter / cleaner
- Track comparison

### Editing Tracks

Clicking on a track in the overview opens the detail page with a map, elevation profile and statistics. Using the **Edit** button (admin only) you can set:

- Name and notes
- Activity type (walking, hiking, cycling, car, running…)
- Difficulty (1–5)
- Category
- Favourite (star)

---

## 9. API Keys for Maps

The application works **without API keys** — the default map is OpenStreetMap (free, no limits). API keys only add optional map tile layers.

You can enter keys during installation (setup.php, step 3) or at any time later in the `.env` file.

---

### Thunderforest — Hiking and Cycling Maps

Provides hiking, cycling, terrain and transport map tiles.

**How to get a key:**
1. Go to [thunderforest.com](https://www.thunderforest.com/docs/apikeys/)
2. Click **Get API key** → register (email only required)
3. Free plan: **150,000 tiles / month** (sufficient for personal use)
4. After registration, copy the API key from the **API Keys** section
5. Add to `.env`: `TF_API_KEY=your_key_here`

---

### Mapy.cz — Czech Aerial and Hiking Maps

Provides aerial imagery of the Czech Republic, hiking maps and the Mapy.cz base map.

**How to get a key:**
1. Go to [developer.mapy.cz](https://developer.mapy.cz/)
2. Log in or register (Seznam.cz or Google account works)
3. In the **My Projects** section, click **Create new project**
4. Name the project (e.g. `GPX Manager`) and confirm
5. Copy the generated **API key**
6. Add to `.env`: `MAPYCOM_API_KEY=your_key_here`

> **Free quota:** Sufficient for personal use (tens of thousands of map loads per month).

---

### Mapillary — Street-Level Photos on the Map

Displays photos taken directly on trails and roads, similar to Street View.

**How to get a token:**
1. Go to [mapillary.com](https://www.mapillary.com/) and register (free)
2. Navigate to [mapillary.com/dashboard/developers](https://www.mapillary.com/dashboard/developers)
3. Click **Register Application**
4. Enter an application name (e.g. `GPX Manager`) and confirm
5. Copy the **Client Token**
6. Add to `.env`: `MAPILLARY_TOKEN=your_token_here`

---

### Applying the Keys

After editing the `.env` file, simply reload the page — the keys are applied immediately, no server restart is needed.

---

## 10. Backup and Maintenance

### What to Back Up

| What | Where | How |
|---|---|---|
| **Database** | MySQL | Export via phpMyAdmin (Export tab → SQL format) or `mysqldump` |
| **GPX files** | `uploads/*.gpx` | Copy the entire `uploads/` folder |
| **Photos** | `uploads/photos/` | Copy the entire folder |
| **Configuration** | `.env` | Copy the file to a safe location |

### Database Export via Command Line

```bash
mysqldump -u root -p gpx_manager > gpx_manager_backup.sql
```

### Updating the Application

1. Back up the database and the `uploads/` folder
2. Upload the new files to the server (they will overwrite the old ones)
3. Do **not** overwrite `.env` — it will be preserved
4. Check whether new database columns have been added (see the GitHub changelog)

### Storage Management

Uploaded GPX files and track thumbnails are stored in `uploads/`. On shared hosting, keep an eye on available disk space — each track takes up tens to hundreds of kilobytes.

---

## 11. Troubleshooting

### "Database connection failed"

- Verify credentials in `.env`
- Check that the MySQL server is running (WampServer: green icon in the system tray)
- On web hosting: check the database hostname in your hosting panel (may differ from `localhost`)
- Verify that the database exists and the user has access to it

### Page Not Displaying / Error 500

- Check that the `.env` file exists and contains correct values
- Check PHP error logs (WampServer: `C:\wamp64\logs\php_error.log`)
- On production, errors are logged to `uploads/_errors.log`

### Admin Access Not Working (no blue banner)

- Check your current IP address at [whatismyip.com](https://www.whatismyip.com/)
- Add it to `ADMIN_IPS=` in `.env` (comma-separated, no spaces)
- After editing `.env`, log out (clear cookies or use a private window) and reload

### Photos Not Showing or Failing to Upload

- Check that the `uploads/photos/` folder is writable (chmod 755)
- Verify that PHP extensions `exif` and `gd` are enabled

### GPX Import Fails

- Verify the file is a valid GPX file (XML format with `.gpx` extension)
- Check that the file contains at least one `<trkseg>` segment with `<trkpt>` points
- Large files (>50 MB): increase limits in `.htaccess` or `php.ini`

### .htaccess Not Working / mod_rewrite Error

- WampServer: click icon → Apache → Apache Modules → check `rewrite`
- Web hosting: ask your hosting support to enable `mod_rewrite`

### "ZipArchive is not available on the server"

- The PHP `zip` extension is not enabled
- WampServer: uncomment `extension=zip` in `php.ini`
- Web hosting: ask support to enable `php-zip`

---

*GPX Manager — Installation & User Guide &nbsp;|&nbsp; Version 2026-05*
