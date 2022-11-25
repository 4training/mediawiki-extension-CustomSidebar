<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ForTrainingCustomSidebar;

use BagOStuff;
use MediaWiki\MediaWikiServices;
use ObjectCache;
use Parser;
use ParserOptions;
use Skin;
use Title;
use WikiPage;

class Hooks
{
    public static function onParserFirstCallInit(Parser $parser): void
    {
        // We can't count of the tag being read if the page is cached.  So instead of this code performing any task
        // we leave it here to easily remove the <sidebar> tag read in the SkinBuildSidebar hook
        // aka lazy way to clear tag
        $parser->setHook('sidebar', function () {
            return '';
        });
    }

    public static function onSkinBuildSidebar(Skin $skin, &$bar)
    {
        // this is mostly just the standard sidebar processing function with a custom loader

        $services   = MediaWikiServices::getInstance();
        $mainConfig = $services->getMainConfig();
        $parserMemc = ObjectCache::getInstance($mainConfig->get('ParserCacheType'));
        $wgParser   = $services->getParser();

        global $wgEnableSidebarCache, $wgSidebarCacheExpiry;
        global $wgDefaultSideBarText, $wgDefaultSideBarGroupText, $wgDefaultSidebarNSText;

        $key           = ObjectCache::getLocalClusterInstance()->makeKey('sidebar', $skin->getContext()->getLanguage()->getCode());
        $NewSideBar    = self::getCustomSidebarName($skin, $wgParser);
        $cachedSidebar = self::getCachedSidebar($key, $parserMemc);
        if ($cachedSidebar) {
            return $cachedSidebar;
        }

        $new_bar = self::customSidebarProcess($skin, $NewSideBar, $wgParser);
        if ((count($new_bar) === 0) && ($wgDefaultSideBarText !== false)) {
            $new_bar = $bar;
        }

        // Add customs bar based on user groups
        $groups = array_reverse($skin->getContext()->getUser()->getGroups());
        foreach ($groups as $n => $v) {
            if (is_array($wgDefaultSideBarGroupText) && array_key_exists($v, $wgDefaultSideBarGroupText)) {
                $new_bar = array_merge($new_bar, self::customSidebarProcess($skin, $wgDefaultSideBarGroupText[$v], $wgParser));
            }
        }

        // Add custom bar based on namespace
        $ns = $skin->getTitle()->getNamespace();
        if (is_array($wgDefaultSidebarNSText) && array_key_exists($ns, $wgDefaultSidebarNSText)) {
            $new_bar = array_merge($new_bar, self::customSidebarProcess($skin, $wgDefaultSidebarNSText[$ns], $wgParser));
        }

        if (count($new_bar) > 0) {
            $bar = $new_bar;
        }

        // sidebar cache code
        if ($wgEnableSidebarCache) {
            $parserMemc->set($key, $bar, $wgSidebarCacheExpiry);
        }
        // end sidebar cache code

        return true;
    }

    private static function getCustomSidebarName(Skin $skin, Parser $wgParser): ?string
    {
        global $wgDefaultSideBarText;
        global $wgCustomSidebarCurrent; // #custom4training

        if (!$skin->getContext()->canUseWikiPage() || !$skin->getContext()->getWikiPage()->getContent()) {
            return null;
        }

        $pageText = $wgParser->preprocess(
            $skin->getContext()->getWikiPage()->getContent()->serialize(),
            $skin->getTitle(),
            ParserOptions::newFromUser($skin->getContext()->getUser())
        );

        if (preg_match('%\<sidebar\>(.*)\</sidebar\>%isU', $pageText, $matches)) {
            $wgCustomSidebarCurrent = $matches[1];
            return $matches[1];
        } elseif (isset($wgDefaultSideBarText) and $wgDefaultSideBarText !== false) {
            return $wgDefaultSideBarText;
        }

        return null;
    }

    private static function getCachedSidebar(string $key, BagOStuff $parserMemc)
    {
        global $wgEnableSidebarCache;
        if (!$wgEnableSidebarCache) {
            return null;
        }

        return $parserMemc->get($key);
    }

    private static function customSidebarProcess($skin, $NewSideBar, Parser $wgParser)
    {
        $NewSideBar = self::customSidebarPreProcess($skin, $NewSideBar, $wgParser);

        // custom loader
        if ($NewSideBar === false) {
            return [];
        }

        $text = $NewSideBar;
        if (strpos(trim($NewSideBar), '*') !== 0) {
            do {
                $oldtext = $text;
                if (($titleFromText = Title::newFromText($text))) {
                    $article = new WikiPage($titleFromText);        // #custom4training sonst Bug
                    $text    = $article->getContent() ? $article->getContent()->getNativeData() : '';    // #custom4training sonst Bug
                    $text    = preg_replace('%\<noinclude\s*\>(.*)\</noinclude\s*\>%isU', '', $text);
                    $text    = self::customSidebarPreProcess($skin, $text, $wgParser);
                }

            } while ($text !== $oldtext);
        }

        $lines   = explode("\n", $text);
        $newBar  = [];
        $heading = '';

        // taken directly from MediaWiki source v1.14.0
        foreach ($lines as $line) {
            if (strpos($line, '*') !== 0) {
                continue;
            }

            if (strpos($line, '**') !== 0) {
                $line    = trim($line, '* ');
                $heading = $line;
                if (!array_key_exists($heading, $newBar)) {
                    $newBar[$heading] = [];
                }
                continue;
            }

            if (strpos($line, '|') === false) {
                continue;
            }

            // sanity check
            $line = array_map('trim', explode('|', trim($line, '* '), 2));
            $link = self::translate($line[0]);
            if ($link === '-') {
                continue;
            }

            if (preg_match('/^(?:' . wfUrlProtocols() . ')/', $link)) {
                $href = $link;
            } else {
                $title = Title::newFromText($link);
                if ($title) {
                    $title = $title->fixSpecialName();
                    $href  = $title->getLocalURL();
                } else {
                    $href = 'INVALID-TITLE';
                }
            }

            // #custom4training: Welcher Punkt der Sidebar ist gerade aktiv?
            $pos    = strpos($href, 'Special:MyLanguage/');
            $active = false;
            if (($pos === 1) && ($skin->getTitle() !== null)) {
                $linkdest = substr($href, 19);        // das "Special:MyLanguage" am Anfang entfernen
                $title    = $skin->getTitle()->getLocalURL();
                $active   = (substr($title, 0, strlen($linkdest)) === $linkdest);    // Fängt der Artikel damit an? (auch Unterseiten wie "Prayer/de" müssen berücksichtigt werden)
            }

            $newBar[$heading][] = [
                'text'   => self::translate($line[1]),
                'href'   => $href,
                'id'     => 'n-' . strtr($line[1], ' ', '-'),
                'active' => $active,
            ];
        }
        // End Mediawiki source

        return $newBar;
    }

    // processes templates and wiki magic words, plus any add'l custom magic words
    private static function customSidebarPreProcess(Skin $skin, $text, Parser $wgParser)
    {
        $text = str_ireplace('{{#__username}}', $skin->getContext()->getUser()->getName(), (string) $text);
        return $wgParser->preprocess(
            preg_replace('%\<noinclude\>(.*)\</noinclude\>%isU', '', $text),
            $skin->getTitle(),
            ParserOptions::newFromUser($skin->getContext()->getUser())
        );
    }

    private static function translate(string $text): string
    {
        $message = wfMessage($text);

        #custom4training Bug
        if (wfMessage($text)->inContentLanguage()->isBlank()) {
            return $text;
        }

        return $message->text();
    }
}
