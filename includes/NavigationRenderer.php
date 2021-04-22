<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ForTrainingCustomSidebar;

final class NavigationRenderer
{
    /** @var string */
    private $template;

    public function __construct(string $template)
    {
        $this->template = $template;
    }

    public static function renderSidebar(string $template, array $items): array
    {
        return (new self($template))->renderPortals($items);
    }

    public function renderPortals(array $items): array
    {
        $portals = [];

        foreach ($items as $item) {
            $portals[$item['text']] = $this->renderPortal($item);
        }

        return $portals;
    }

    public function renderPortal(array $item): string
    {
        ob_start();
        $this->renderItem($item);
        $buffer = ob_get_contents();
        ob_end_clean();

        return $buffer;
    }

    public function renderItem(array $item): void
    {
        require $this->template;
    }
}
