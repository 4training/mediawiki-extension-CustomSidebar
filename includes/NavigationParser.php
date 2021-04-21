<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ForTrainingCustomSidebar;

use Skin;
use Title;

class NavigationParser
{
    /** @var Skin */
    private $skin;

    /** @var int */
    private $index;

    /** @var list<string> */
    private $lines;

    private function __construct(Skin $skin, array $lines)
    {
        $this->skin = $skin;
        $this->lines = $lines;
        $this->index = -1;
    }

    public static function parse(Skin $skin, string $text): array
    {
        $lines  = explode("\n", $text);
        $parser = new self($skin, $lines);

        return $parser->parseLines();
    }

    private function parseLines(): array
    {
        $root = [
            'children' => [],
            'level'    => 0,
        ];

        $this->parseItems($root);

        return $root['children'];
    }

    private function parseItems(array &$parent): void
    {
        while ($item = $this->parseItem($parent['level'])) {
            if (($item['level'] - $parent['level']) === 1) {
                $parent['children'][] = $item;
                continue;
            }

            $key = array_key_last($parent['children']);
            if ($key === null) {
                throw new \RuntimeException('Unable to add item to navigation tree. No parent found');
            }

            $parent['children'][$key]['children'][] = &$item;
            $this->parseItems($item);
            unset($item);
        }
    }

    private function parseItem(int $parentLevel): ?array
    {
        $item = $this->getNextItem($parentLevel);

        if ($item === null) {
            return null;
        }

        $pos    = strpos($item['href'], 'Special:MyLanguage/');
        $active = false;

        if (($pos === 1) && ($this->skin->getTitle() !== null)) {
            $linkdest = substr($item['href'], 19);        // das "Special:MyLanguage" am Anfang entfernen
            $title    = $this->skin->getTitle()->getLocalURL();
            $active   = (substr($title, 0, strlen($linkdest)) === $linkdest);    // Fängt der Artikel damit an? (auch Unterseiten wie "Prayer/de" müssen berücksichtigt werden)
        }

        $item['id']       = $item['text'] ? 'n-' . strtr($item['text'], ' ', '-') : null;
        $item['text']     = $item['text'] ? $this->translate($item['text']) : null;
        $item['active']   = $active;
        $item['children'] = [];

        return $item;
    }

    private function getNextItem(int $parentLevel): ?array
    {
        $this->index++;

        if (!isset($this->lines[$this->index])) {
            return null;
        }

        // Line does not contain a link, try to get next line
        if(! preg_match('/^(\*+)\s(.+)$/', $this->lines[$this->index], $matches)) {
            return $this->getNextItem($parentLevel);
        }

        $level = strlen($matches[1]);
        if ($level <= $parentLevel) {
            $this->index--;
            return null;
        }

        $text  = $matches[2];
        $line  = array_map('trim', explode('|', $text, 2));
        $link  = self::translate($line[0]);

        // Line does not contain a valid link,, try to get next line
        if ($link === '-') {
            return $this->getNextItem($parentLevel);
        }

        if (preg_match('/^(?:' . wfUrlProtocols() . ')/', $link)) {
            $href = $link;
        } else {
            $title = Title::newFromText($link);
            if ($title) {
                $href = $title->fixSpecialName()->getLocalURL();
            } else {
                $href = 'INVALID-TITLE';
            }
        }

        return [
           'level' => $level,
           'text'  => $line[1] ?? $line[0],
           'href'  => $href
        ];
    }

    private function translate(string $text): string
    {
        $message = wfMessage($text)->inContentLanguage();

        #custom4training Bug
        if ($message->isBlank()) {
            return $text;
        }

        return $message->text();
    }
}
