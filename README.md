AutoCreatePage
==============

MediaWiki extension to automatically create pages with a parser function.

The purpose of this is that wiki pages, especially pages that use templates,
can trigger the creation of auxiliary pages. This makes most sense when using
an extension like Semantic MediaWiki, which can create rich content pages
automatically without much user input.

For example, imagine a wiki that has pages about people and pages about projects,
where many people can be involved in one project. Then it makes sense to have pages
that list all the projects of one person. This can be done automatically with
Semantic MediaWiki, but you would still need to create the page first. With this
extension, the template that is used for person pages can make sure that also a page
with projects of that person is created.

Another example are bi-lingual wikis that use a lot of templates and forms (so many
pages consist mainly of data, not of text). Then one can enter data in one language
(e.g., create a page in German using a Semantic Form), and the templates used can
make sure that the page also exists in another language (e.g., create a page named
"{{PAGENAME}}/en" that contains a Semantic MediaWiki template that queries and
displays all relevant data, but this time with English labels).

Both of these uses were pioneered by the [AIFB Portal Wiki](http://www.aifb.kit.edu/).


Usage
-----

The extension provides one parser function, `createpageifnotex` that takes a page
title and a text to use when creating the page. Example:

`{{#createpageifnotex:Test page 1|The content of test page 1}}`

Pages are only created if they don't exist yet. The author of the new page is the
user who saved the edit to the page that contained the parser function call. Pages
are only created when saving an edit, never when viewing a page.

Pages are only created if the parser function is used on a page in one of MediaWiki's
content namespaces. Nothing will happen if the parser function is called on, e.g.,
template pages.


Installation
------------

Download the repository (including the top directory `AutoCreatePage`) to the extension
directory of your MediaWiki installation. You can do this with git by calling:

`git clone https://github.com/mkroetzsch/AutoCreatePage.git`

from your extension directory. Then add to your LocalSettings.php:

`include_once "$IP/extensions/AutoCreatePage/AutoCreatePage.php";`

The code requires MediaWiki 1.21 to work. It has been tested on MediaWiki 1.23.
Future versions might also work.


Status
------

This code is experimental. Use with care. Internationalization is largely missing.

As of MediaWiki 1.23, the code avoids deprecated functions or hooks. However, it
accesses the parser's `mStripState` member (intended private?) to process nowiki
tags. This might have to be replaced by some other approach in the future.


Credits
-------

The original idea and first implementation was done by Daniel Herzig (AIFB). The code
was modernized to work with MediaWiki 1.23 by Markus Kroetzsch.

The code is licensed under GPLv2.
