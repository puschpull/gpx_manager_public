# CSS Variables Reference — GPX Manager Theme System

> Documentation file. Not loaded by the application.
> Source: previously `css/variables-reference.css` — relocated in TASK-24 (FE-19).

This document lists all CSS custom properties (variables) used across the 9 themes in `css/theme-*.css`.
Each theme defines all variables in `:root {}`. Pages consume them via `var(--name)` — no hardcoded colors in component CSS.

---

## Variable categories

### 1. Base colors

| Variable | Description | Classic value |
|---|---|---|
| `--bg-color` | Main page background | `#f9f9f9` |
| `--text-color` | Primary text | `#222222` |
| `--text-muted` | Secondary / muted text (notes, metadata) | `#666666` |
| `--border-color` | Borders, dividers, table edges | `#cccccc` |

### 2. Accents and highlights

| Variable | Description | Classic value |
|---|---|---|
| `--accent-color` | Primary accent — links, active elements, buttons | `#005fa3` |
| `--accent-hover` | Accent on hover | `#005a9e` |
| `--highlight-bg` | Background of highlighted areas (active filter etc.) | `#e7f2ff` |
| `--highlight-border` | Border of highlighted areas | `#005fa3` |

### 3. Cards, panels, buttons

| Variable | Description | Classic value |
|---|---|---|
| `--card-bg` | Panel / card / table background | `#ffffff` |
| `--bg-secondary` | Second-level background (badges, pager) | `#f4f4f4` |
| `--bg-button` | Default button background | `#f6f6f6` |
| `--bg-hover` | Background on mouse-over | `#e9f3ff` |
| `--bg-input` | Form input background | `#ffffff` |

### 4. Header

| Variable | Description | Classic value |
|---|---|---|
| `--header-bg` | Page header background | `#e8eef5` |
| `--header-text` | Header text color | `#003366` |

### 5. Tables

| Variable | Description | Classic value |
|---|---|---|
| `--table-header-bg` | Table `<th>` background | `#f2f5fa` |
| `--table-row-bg` | Normal row background | `#ffffff` |
| `--table-row-hover` | Row background on hover | `#f5f9ff` |
| `--table-border-color` | Table cell border color | `#d0d7de` |

---

## Contrast requirements (WCAG 2.2 AA)

All `--accent-color` values must achieve **4.5:1** against their respective `--bg-color`.
All `--text-muted` values must achieve **4.5:1** against their respective `--bg-color`.

### Verified values after TASK-24 contrast fix

| Theme | Variable | Old value | New value | Ratio | Background |
|---|---|---|---|---|---|
| classic | `--accent-color` | `#0078d7` | `#005fa3` | 4.52:1 | `#f9f9f9` |
| minimal | `--accent-color` | `#1e88e5` | `#1565c0` | 4.63:1 | `#ffffff` |
| blue | `--accent-color` | `#1976d2` | `#1565c0` | 4.56:1 | `#f4f7fb` |
| lightgray | `--text-muted` | `#777777` | `#636363` | 4.55:1 | `#eeeeed` |

---

## Reserved variables (not yet in use)

```css
--error-color   /* reserved: error states, #cc0000 equivalent */
--success-color /* reserved: success confirmation, #00aa00 equivalent */
--map-height    /* optional: if map height needs to vary per theme */
--profile-height /* optional: elevation profile height per theme */
```

---

## How the system works

1. Each page that uses the legacy theme system includes `css/style.css` (always) and optionally `css/filter.css`, `css/detail.css` etc.
2. A theme stylesheet (`css/theme-{slug}.css`) is injected **before `<body>`** via an inline `<script>` in `<head>` — this eliminates FOUC (FE-6).
3. JS in `js/theme.js` handles theme *changes* (dropdown select), updating the `<link id="theme-style">` element and saving to `localStorage` + cookie.
4. The two theming systems that coexist:
   - **Legacy** (`css/theme-*.css` + CSS variables): authoritative for most pages (detail, admin, filter, settings, stats, etc.)
   - **Tailwind** (`assets/css/app.css` + `.dark` class): used on `index.php`, `photos.php`, `login.php`, `layout_header.php`
