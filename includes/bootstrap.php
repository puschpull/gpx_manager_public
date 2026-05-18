<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  bootstrap.php — Načtení sdílených souborů z includes/
 *
 *  Jediné místo, kde je definovaný seznam souborů, které se
 *  musí načíst při startu aplikace. Používají ho dvě cesty:
 *
 *   1. Composer — composer.json → autoload.files odkazuje
 *      pouze na tento soubor.
 *   2. Bez Composeru — config.php načte tento soubor přímo,
 *      když složka vendor/ neexistuje (FTP instalace).
 *
 *  Pořadí odpovídá závislostem — neměnit bez rozmyslu.
 * ===========================================================
 */

require_once __DIR__ . '/app_constants.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/sort_query.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/track_filter.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/ajax.php';
