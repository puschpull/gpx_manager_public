<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  constants.php — Magic numbers / application-wide constants
 *  Single source of truth for numeric thresholds and limits.
 * ===========================================================
 */

// Cookie lifetime
const COOKIE_TTL_DAYS    = 365;
const COOKIE_TTL_SECONDS = COOKIE_TTL_DAYS * 24 * 3600;

// Photo auto-assignment radius in metres — see photo_helper.php
const PHOTO_AUTO_ASSIGN_RADIUS_M = 500;

// GPX speed below which a point is considered stationary (m/s) — see gpx_parser.php
const MOVING_THRESHOLD_MS = 0.5;

// Auto-detect activity_type = 'Auto' threshold (km/h, max speed) — see activity.php
const ACTIVITY_AUTO_THRESHOLD_KMH = 80;

// Default items per page on index / filter pages
const DEFAULT_PER_PAGE = 50;

// Photo upload limits
const PHOTO_MAX_PER_ZIP    = 200;
const PHOTO_MAX_FILE_SIZE  = 50 * 1024 * 1024; // 50 MB
const PHOTO_MAX_MEGAPIXELS = 50_000_000;        // 50 MP

// GPX content max size for save_cleaned
const GPX_MAX_CONTENT_SIZE = 50 * 1024 * 1024; // 50 MB
