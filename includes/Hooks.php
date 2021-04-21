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

    private static function getCachedSidebar(string $key, BagOStuff $parserMemc): ?string
    {
        global $wgEnableSidebarCache;
        if (!$wgEnableSidebarCache) {
            return null;
        }

        return $parserMemc->get($key);
    }

    private static function customSidebarProcess($skin, $NewSideBar, Parser $wgParser): array
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

        $navigation = NavigationParser::parse($skin, $text);
        $newBar     = [];

        // FIXME: Get rid of it since we implement our own rendering
        foreach ($navigation as $item) {
            foreach ($item['children'] as $index => $child) {
                unset($item['children'][$index]['children']);
            }
            $newBar[$item['text']] = $item['children'];
        }
        // ENDFIXME

        return $newBar;
    }

    // processes templates and wiki magic words, plus any add'l custom magic words
    private static function customSidebarPreProcess(Skin $skin, $text, Parser $wgParser)
    {
        $text = str_ireplace('{{#__username}}', $skin->getContext()->getUser()->getName(), $text);
        return $wgParser->preprocess(
            preg_replace('%\<noinclude\>(.*)\</noinclude\>%isU', '', $text),
            $skin->getTitle(),
            ParserOptions::newFromUser($skin->getContext()->getUser())
        );
    }
}
