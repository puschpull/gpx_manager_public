<?php
declare(strict_types=1);

/**
 * Načte třídy Cestopisu (src/Cestopis, namespace GpxManager\Cestopis).
 *
 * S Composerem by stačil jeho PSR-4 autoload, ale aplikace musí běžet i bez
 * Composeru (instalace přes FTP — viz config.php). Proto explicitně; když
 * už třídu načetl Composer, require_once ji podruhé nenačte.
 *
 * Načítat jen tam, kde se Cestopis opravdu používá — ne v bootstrap.php.
 */
foreach ([
    'Log', 'Geo', 'Photo', 'Stop', 'StopDetector', 'FactSheet', 'Geocoder', 'PoiFinder',
    'PhotoPicker', 'Narrator', 'Renderer', 'Repository', 'StoryGenerator', 'StoryText', 'CorridorFinder',
] as $_storyClass) {
    require_once __DIR__ . '/../src/Cestopis/' . $_storyClass . '.php';
}
unset($_storyClass);
