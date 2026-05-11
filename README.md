# 🗺 GPX Manager

A web application for managing GPS tracks and photos from hikes and outdoor activities.

> 🇨🇿 [Instalační návod (česky)](instructions/cs.md) &nbsp;|&nbsp; 🇬🇧 [Installation guide (English)](instructions/en.md)

---

## Features

- **GPX import** — single files or batch via ZIP archive
- **Interactive map** for each track with elevation profile
- **Photos on the track** — GPS EXIF → automatic assignment to track, lightbox gallery, timeline
- **Statistics** — track overview, favourites, categories, difficulty, activity type
- **Filter & compare** — advanced filtering, side-by-side track comparison on map
- **Heatmap** and **activity calendar**
- **Visitor mode** — public view-only access with configurable page visibility
- **Multilingual UI** — Czech, English, German, Slovak, Spanish, French, Italian, Polish
- **9 colour themes**

---

## Requirements

| Component | Minimum | Recommended |
|---|---|---|
| PHP | **8.0** | 8.2+ |
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.3 | 10.11+ |
| Apache / Nginx | any current | — |

PHP extensions: `pdo_mysql`, `simplexml`, `gd`, `exif`, `zip`

---

## Quick Start

1. Copy files to the server (or into the WampServer `www/` folder)
2. Create an empty MySQL database (e.g. `gpx_manager`)
3. Open `http://localhost/gpx_manager/setup.php`
4. Enter DB credentials, admin password and your IP address
5. Log in via `login.php`

📖 Full guide → [instructions/en.md](instructions/en.md)

---

## Project Structure

```
gpx_manager/
├── setup.php          ← installation wizard (deletes itself after install)
├── .env.example       ← configuration template
├── install.sql        ← SQL schema for manual import
├── config.php         ← loads configuration from .env
├── index.php          ← track overview
├── import.php         ← GPX import
├── photos.php         ← photo management
├── ...
├── includes/          ← PHP backend logic
├── css/               ← styles and themes
├── js/                ← JavaScript modules
├── lang/              ← translations (8 languages)
├── instructions/      ← installation & user guides (cs, en)
└── uploads/           ← GPX files and photos (writable by server)
```

---

## License

This project is intended for personal and non-commercial use.
