<?php

namespace Kamva\Crud\Timeline;

use DateTimeInterface;

/**
 * A single event on a {@see Timeline}. Immutable value object.
 *
 * Sources return an iterable of these (or array-compatible structures —
 * see {@see Timeline::addSource()}). The timeline merges all sources and
 * sorts by `at` descending for rendering.
 */
final class TimelineEvent
{
    public function __construct(
        public readonly DateTimeInterface $at,
        public readonly string $title = '',
        public readonly string $body = '',
        public readonly string $icon = '',
        public readonly string $actor = '',
        public readonly string $type = '',
    ) {}

    /**
     * Convenience constructor from an associative array. Accepts the same
     * keys as the property names. `at` may be a DateTimeInterface, a parsable
     * date string, or null (null becomes "now").
     */
    public static function fromArray(array $a): self
    {
        $at = $a['at'] ?? 'now';
        if (! $at instanceof DateTimeInterface) {
            $at = new \DateTimeImmutable((string) $at);
        }
        return new self(
            at: $at,
            title: (string) ($a['title'] ?? ''),
            body:  (string) ($a['body']  ?? ''),
            icon:  (string) ($a['icon']  ?? ''),
            actor: (string) ($a['actor'] ?? ''),
            type:  (string) ($a['type']  ?? ''),
        );
    }
}
