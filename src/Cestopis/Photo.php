<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Jedna fotka z tabulky track_photos.
 *
 * Souřadnice fotky pocházejí z telefonu a nejsou spolehlivé — proto je
 * příznak $coordsReliable, který nastavuje StopDetector po kontrole
 * implikované rychlosti mezi snímky.
 */
final class Photo
{
    public bool $coordsReliable = true;

    public function __construct(
        public readonly int $id,
        public readonly \DateTimeImmutable $takenAt,
        public readonly ?float $lat,
        public readonly ?float $lon,
        public readonly ?float $altitude,
        public readonly ?string $caption,
        public readonly string $filename,
    ) {}

    public function hasCoords(): bool
    {
        return $this->lat !== null && $this->lon !== null;
    }
}
