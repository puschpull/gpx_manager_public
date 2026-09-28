<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

/** Shluk fotek pořízených na jednom místě v jednom časovém úseku. */
final class Stop
{
    /** @param list<Photo> $photos */
    public function __construct(
        public readonly array $photos,
        public readonly \DateTimeImmutable $startAt,
        public readonly \DateTimeImmutable $endAt,
        public readonly ?float $lat,
        public readonly ?float $lon,
        public ?string $placeName = null,
    ) {}

    public function durationSeconds(): int
    {
        return $this->endAt->getTimestamp() - $this->startAt->getTimestamp();
    }

    public function photoCount(): int
    {
        return count($this->photos);
    }

    /**
     * Delší zastavení (svačina, rozhlédnutí) vs. průchod, kde se jen cvaklo
     * pár snímků za chůze. Práh je záměrně nízký — tři minuty na jednom místě
     * už znamenají, že se člověk zastavil.
     */
    public function isRest(): bool
    {
        return $this->durationSeconds() >= 180;
    }
}
