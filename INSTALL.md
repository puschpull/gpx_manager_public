# GPX Manager — Installation

The full installation and user guide has moved to the `instructions/` folder:

- 🇬🇧 [**English guide**](instructions/en.md)
- 🇨🇿 [**Český návod**](instructions/cs.md)

> **Important (TASK-05 security hardening):** Before running `setup.php`, create an empty file
> named `.setup-allowed` in the project root. Without it, setup returns HTTP 403.
> The wizard deletes the marker file automatically on success.
> See the full guide for details.

> **Database migrations:** After importing `install.sql` (or after updating the application),
> run `php migrate.php` from the command line to apply any pending schema changes.
> The runner is idempotent — running it twice is safe. Web access is restricted to localhost only.

> **Centroid backfill (TASK-11):** After running `php migrate.php` (which applies
> `migrations/0014_centroid.sql`), run the standalone backfill script once to populate
> `centroid_lat`/`centroid_lon` for existing tracks:
>
> ```
> php migrations/0014_centroid_backfill.php
> ```
>
> This is a one-time operation. New imports via `import.php` will populate the centroid
> columns automatically. Running the backfill script twice is safe (idempotent).
