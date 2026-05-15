<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  helpers.php — Aggregator (TASK-16)
 *  All helper functions are now split into focused modules.
 *  This file only requires them in dependency order.
 * ===========================================================
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/sort_query.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/track_filter.php';
