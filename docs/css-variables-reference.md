# CSS Variables Reference — GPX Manager Theme System

> Documentation file. Not loaded by the application.
> Source: previously `css/variables-reference.css` — relocated in TASK-24 (FE-19).
> Updated 2026-06-25: 9 barevných témat odstraněno; zůstává jen světlý/tmavý režim.

This document lists all CSS custom properties (variables) used by the light/dark mode.
They are defined in **`css/style.css`**: light values in `:root {}`, dark overrides in `html.dark {}`.
Pages consume them via `var(--name)` — no hardcoded colors in component CSS.
The "Classic value" column below is the `:root` (light-mode) value.

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

1. Legacy pages include `css/style.css` (plus optionally `css/filter.css`, `css/detail.css` etc.).
   `css/style.css` defines the variables for both modes: light values in `:root {}`, dark overrides in `html.dark {}`.
2. Dark mode is driven by the Tailwind `.dark` class on `<html>` (key `gpx-theme` in `localStorage`),
   toggled by the light/dark button in `includes/layout_header.php`. An inline `<script>` in `<head>`
   sets the class before `<body>` to avoid FOUC (FE-6).
3. One switch covers everything: the same `.dark` class drives both the Tailwind utility colors
   (`assets/css/app.css`, used on `index.php`, `photos.php`, `login.php`, redesigned pages) and the
   legacy `var(--*)` colors (`css/style.css`, used on detail, admin, filter, settings, stats, etc.).

> **Historical:** until 2026-06-25 there were 9 separate `css/theme-*.css` files selected via a `theme`
> cookie + a `js/theme.js` dropdown (`renderHeaderMeta()` / `<select id="theme-selector">`). That whole
> layer was unused dead code — no UI ever set the cookie — and was removed. The per-theme tables below
> are kept only as a historical reference for the surviving light `:root` values.
