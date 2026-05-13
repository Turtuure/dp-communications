<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template\Block;

final class EventCardBlock extends NewsletterBlock
{
    public function __construct(
        public readonly string $eventId,
        public readonly ?string $title = null,
        public readonly ?string $whenLabel = null,
    ) {
        if (trim($eventId) === '') {
            throw new \InvalidArgumentException('EventCard eventId cannot be empty');
        }
    }

    /**
     * @return array{type: string, eventId: string, title: ?string, whenLabel: ?string}
     */
    public function toRenderable(): array
    {
        return [
            'type'      => 'event_card',
            'eventId'   => $this->eventId,
            'title'     => $this->title,
            'whenLabel' => $this->whenLabel,
        ];
    }
}
