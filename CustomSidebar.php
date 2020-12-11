<?php

/***********************************************************
 * Name:     CustomSidebar
 * Desc:     Easy system for specifying custom sidebars on a per-page basis
 *
 * Version:  0.4.0
 *
 * Author:   Swiftly Tilting (contact@swiftlytilting.com)
 * Homepage: http://www.mediawiki.org/wiki/Extension:CustomSidebar
 *           http://www.swiftlytilting.com/
 *
 * License:  GNU GPL
 *
 ***********************************************************
 */

$wgExtensionCredits['parserhook'][] = array(
       'name' => 'CustomSidebar',
       'author' =>'SwiftlyTilting',
       'url' => 'http://www.mediawiki.org/wiki/Extension:CustomSidebar',
       'description' => 'Easy system for specifying custom sidebars on a per-page and/or per-user group basis',
       'descriptionmsg' => "customsidebar-desc", // Same as above but name of a message, for i18n - string, added in 1.12.0
       'version' => '0.4.0',
       'path' => __FILE__,
       );

$wgHooks['ParserFirstCallInit'][] = 'efCustomSideBarInit';

function efCustomSideBarInit() {
   global $wgParser;
   $wgParser->setHook( 'sidebar', 'efCustomSideBar' );
   return true;
}

function efCustomSideBar( $input, $args, $parser ) {
   // We can't count of the tag being read if the page is cached.  So instead of this code performing any task
   // we leave it here to easily remove the <sidebar> tag read in the SkinBuildSidbar hook
   // aka lazy way to clear tag
   return '';
}

$wgHooks['SkinBuildSidebar'][] = 'fnSidebarHook';

function fnSidebarHook($skin, &$bar)
{
      // this is mostly just the standard sidebar processing function with a custom loader

      global $parserMemc, $wgEnableSidebarCache, $wgSidebarCacheExpiry, $wgParser;
      global $wgDefaultSideBarText, $wgDefaultSideBarGroupText, $wgDefaultSidebarNSText;
      global $wgCustomSidebarCurrent;	// #custom4training

      $NewSideBar = false;

	if ($skin->getContext()->canUseWikiPage() && $skin->getContext()->getWikiPage()->getContent()) {
         $pagetext = $wgParser->preprocess($skin->getContext()->getWikiPage()->getContent()->serialize(), $skin->getTitle(), ParserOptions::newFromUser($skin->getContext()->getUser()));

         if (preg_match('%\<sidebar\>(.*)\</sidebar\>%isU', $pagetext, $matches))
         {	
             $NewSideBar = $matches[1];
             $wgCustomSidebarCurrent = $matches[1]; 
         }  
         else if (isset($wgDefaultSideBarText) and $wgDefaultSideBarText !== false)
         {	$NewSideBar = $wgDefaultSideBarText;
         }
      }
	
		// sidebar cache code

      $key = wfMemcKey( 'sidebar', $skin->getContext()->getLanguage()->getCode() );

      if ( $wgEnableSidebarCache ) {
         $cachedsidebar = $parserMemc->get( $key );
         if ( $cachedsidebar ) {
            return $cachedsidebar;
         }
      }
		// end cache code
		
      $new_bar =  fnCustomSidebarProcess($skin, $NewSideBar);

      if ((count($new_bar) === 0) && ($wgDefaultSideBarText !== false))
      {
         $new_bar = $bar;
      }
		
		// Add customs bar based on user groups
      $groups = array_reverse($skin->getContext()->getUser()->getGroups());
      foreach($groups as $n => $v)
      {  if ( is_array($wgDefaultSideBarGroupText) && array_key_exists($v, $wgDefaultSideBarGroupText))
         {
            $new_bar = array_merge($new_bar, fnCustomSidebarProcess($skin, $wgDefaultSideBarGroupText[$v]));
         }
      }
		
		// Add custom bar based on namespace
      $ns = $skin->getTitle()->getNamespace();
      if (is_array($wgDefaultSidebarNSText) && array_key_exists($ns , $wgDefaultSidebarNSText))
      {
         $new_bar = array_merge($new_bar, fnCustomSidebarProcess($skin, $wgDefaultSidebarNSText[$ns]));
      }

      if (count($new_bar) > 0)
      {
         $bar = $new_bar;
      }
		
		// sidebar cache code
      if ( $wgEnableSidebarCache ) $parserMemc->set( $key, $bar, $wgSidebarCacheExpiry );

		// end sidebar cache code
		
      return true;
}

function fnCustomSidebarProcess($skin, $NewSideBar)
{     global $wgParser;
      
      $NewSideBar = fnCustomSidebarPreProcess($skin, $NewSideBar);
      
      // custom loader
      if ($NewSideBar !== false)
      {  
      	if (strpos(trim($NewSideBar), '*') === 0)
         {  $text = $NewSideBar;
         }
         else
         {  $text = $NewSideBar;
            do
            {  
            	$oldtext = $text;
               if (($titleFromText = Title::newFromText($text)))
               {  
               	$article = new WikiPage($titleFromText);		// #custom4training sonst Bug 
                  $text = $article->getContent()? $article->getContent()->getNativeData() : '';	// #custom4training sonst Bug
                  $text = preg_replace('%\<noinclude\s*\>(.*)\</noinclude\s*\>%isU','',$text);
                  $text = fnCustomSidebarPreProcess($skin, $text);
               }
                              
            } while ( $text !== $oldtext);
         }

         $lines = explode( "\n",  $text );
      }
      else
      {  
         return array();
      }

      $new_bar = array();

      $heading = '';
      
      // taken directly from MediaWiki source v1.14.0
      foreach ($lines as $line) {
         if (strpos($line, '*') !== 0)
            continue;
         if (strpos($line, '**') !== 0) {
            $line = trim($line, '* ');
            $heading = $line;
            if( !array_key_exists($heading, $new_bar) ) $new_bar[$heading] = array();
         } else {
            if (strpos($line, '|') !== false) { // sanity check
               $line = array_map('trim', explode( '|' , trim($line, '* '), 2 ) );
               $link = wfMessage( $line[0] )->inContentLanguage()->text();	#custom4training Bug
               if ($link == '-')
                  continue;

               $text = wfMessage($line[1])->text();	#custom4training Bug
               if (wfMessage($line[1])->inContentLanguage()->isBlank())	#custom4training Bug
                  $text = $line[1];
               if (wfMessage($line[0])->inContentLanguage()->isBlank())	#custom4training Bug
                  $link = $line[0];

               if ( preg_match( '/^(?:' . wfUrlProtocols() . ')/', $link ) ) {
                  $href = $link;
               } else {
                  $title = Title::newFromText( $link );
                  if ( $title ) {
                     $title = $title->fixSpecialName();
                     $href = $title->getLocalURL();
                  } else {
                     $href = 'INVALID-TITLE';
                  }
               }

               // #custom4training: Welcher Punkt der Sidebar ist gerade aktiv?
               $pos = strpos($href,'Special:MyLanguage/');
               $active = false;
               if (($pos === 1) && ($skin->getTitle() !== null)) {
                  $linkdest = substr($href,19);		// das "Special:MyLanguage" am Anfang entfernen
                  $title = $skin->getTitle()->getLocalURL();
                  $active = (substr($title, 0, strlen($linkdest)) === $linkdest);	// Fängt der Artikel damit an? (auch Unterseiten wie "Prayer/de" müssen berücksichtigt werden)
               }
               $new_bar[$heading][] = array(
                  'text' => $text,
                  'href' => $href,
                  'id' => 'n-' . strtr($line[1], ' ', '-'),
                  'active' => $active
               );
            } else { continue; }
         }
      }
		// End Mediawiki source

      if (count($new_bar) > 0)
      {  return $new_bar;
      }
      else
      {  return array();
      }
}

// processes templates and wiki magic words, plus any add'l custom magic words
function fnCustomSidebarPreProcess($skin, $text)
{
	global $wgParser;
	$text = str_ireplace ('{{#__username}}',$skin->getContext()->getUser()->getName(), $text);
	return $wgParser->preprocess( preg_replace('%\<noinclude\>(.*)\</noinclude\>%isU','',$text), $skin->getTitle(), ParserOptions::newFromUser($skin->getContext()->getUser()));
}

?>
