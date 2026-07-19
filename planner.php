<?php
/**
 * Plánovač výšlapu — klikáním do mapy naplánuj trasu po cestách
 * (Mapy.com routing), výškový profil, odhad času a export GPX pro Garmin.
 * Jen pro admina (routing utrácí API kvótu) — auth.php přesměruje na login.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/planner_view.php';
