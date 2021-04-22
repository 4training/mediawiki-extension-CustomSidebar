Based on https://www.mediawiki.org/wiki/Extension:CustomSidebar which is unfortunately abandoned and not working with current mediawiki versions anymore. Here we make it run with current mediawiki versions (tested with mediawiki 1.35.1)
* TODO check http://wiki.4intra.net/CustomSidebar - have they done a similar thing?
* TODO systematically go through this and maybe at some point make this an official extension again?

## Features

 - Support nested navigation structures

## Configuration

This extension uses a custom php template to generate the navigation for each portal. You may override it by defining
a global configuration variable:

```php
<?php

$wgCustomSidebarItemTemplate = __DIR__ . '/customSidebarItemTeplate.php';
```

The default template is the `templates/item.php` template.
