<?php

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\RedirectResponse;

$app->before(function (Request $req) {
    if (!class_exists('Tideways\Profiler')) {
        return;
    }

    $actionName = $req->get('_route');
    if (strpos($actionName, '__') === 0) {
        $actionName = $req->get('_controller');
    }
    \Tideways\Profiler::setTransactionName($req->getMethod().' '.$actionName);
}, 8);

$app->get('/', function () use ($app) {
    $logos = glob(__DIR__.'/../web/img/logo-composer-transparent*.png');
    $logo = basename($logos[array_rand($logos)]);

    $versions = array();
    foreach (glob(__DIR__.'/../web/download/*', GLOB_ONLYDIR) as $version) {
        $versions[] = basename($version);
    }
    usort($versions, 'version_compare');
    $versions = array_reverse($versions);

    foreach ($versions as $version) {
        if (strpos($version, '-') === false) {
            $latestStable = $version;
            break;
        }
    }

    return $app['twig']->render('index.html.twig', array('logo' => $logo, 'latestStable' => $latestStable));
})
->bind('home');

$app->get('/download/', function () use ($app) {
    $versions = array();
    foreach (glob(__DIR__.'/../web/download/*', GLOB_ONLYDIR) as $version) {
        $versions[basename($version)] = ['date' => new \DateTime('@'.filemtime($version.'/composer.phar')), 'sha256sum' => preg_replace('{^(\S+).*}', '$1', file_get_contents($version.'/composer.phar.sha256sum'))];
    }

    uksort($versions, 'version_compare');
    $versions = array_reverse($versions);

    foreach ($versions as $version => $versionMeta) {
        if (strpos($version, '-') === false) {
            $latestStable = $version;
            break;
        }
    }

    $data = array(
        'page' => 'download',
        'versions' => $versions,
        'latestStable' => $latestStable,
        'windows' => false !== strpos($app['request']->headers->get('User-Agent'), 'Windows'),
    );

    return $app['twig']->render('download.html.twig', $data);
})
->bind('download');

$app->get('/download/{version}/composer.phar', function () {
    return new Response('Version Not Found', 404);
})
->bind('download_version');

$app->get('/composer-stable.phar', function () {
    $versions = [];
    foreach (glob(__DIR__.'/../web/download/*', GLOB_ONLYDIR) as $version) {
        $versions[] = basename($version);
    }

    usort($versions, 'version_compare');
    $versions = array_reverse($versions);

    foreach ($versions as $version) {
        if (strpos($version, '-') === false) {
            $latestStable = $version;
            break;
        }
    }

    return new BinaryFileResponse(__DIR__.'/../web/download/'.$latestStable.'/composer.phar', 200, [], false, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
})
->bind('download_stable');

$app->get('/schema.json', function () use ($app) {
    return new Response(file_get_contents($app['composer.doc_dir'].'/../res/composer-schema.json'), 200, [
        'content-type' => 'application/json',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET',
        'Access-Control-Allow-Headers' => 'X-Requested-With,If-Modified-Since',
    ]);
})
->bind('schema');

$app->get('/doc/', function () use ($app) {
    $scan = function ($dir, $prefix = '') use ($app) {
        $finder = new Finder();
        $finder->files()
            ->in($dir)
            ->sortByName()
            ->depth(0);

        $filenames = array();

        foreach ($finder as $file) {
            $filename = basename($file->getPathname(), '.md');
            $url = $app['url_generator']->generate(
                'docs.view',
                array('page' => $prefix.$filename.'.md')
            );

            $metadata = null;
            $contents = file_get_contents($file->getPathname());
            if (preg_match('{^<!--(.*)-->}s', $contents, $match)) {
                preg_match_all('{^ *(?P<keyword>\w+): *(?P<value>.*)}m', $match[1], $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $metadata[$match['keyword']] = $match['value'];
                }
            }

            if (preg_match('{^(<!--.+?-->\n+)?# (.+?)\n}s', $contents, $match)) {
                $displayName = $match[2];
            } else {
                $displayName = preg_replace('{^\d{2} }', '', ucwords(str_replace('-', ' ', $filename)));
            }
            $filenames[$displayName] = array('link' => $url, 'metadata' => $metadata);
        }

        return $filenames;
    };

    $book = $scan($app['composer.doc_dir']);
    $articles = $scan($app['composer.doc_dir'].'/articles', 'articles/');
    $faqs = $scan($app['composer.doc_dir'].'/faqs', 'faqs/');

    return $app['twig']->render('doc.list.html.twig', array(
        'book' => $book,
        'articles' => $articles,
        'faqs' => $faqs,
        'page' => 'docs'
    ));
})
->bind('docs');

$app->get('/doc/{page}', function ($page) use ($app) {
    $filename = $app['composer.doc_dir'].'/'.$page;

    if (!file_exists($filename)) {
        $app->abort(404, 'Requested page was not found.');
    }

    $contents = file_get_contents($filename);
    $content = $app['markdown']->text($contents);
    $content = str_replace(array('class="language-json', 'class="language-js'), 'class="language-javascript', $content);
    $content = str_replace('class="language-sh', 'class="language-bash', $content);
    $content = str_replace('class="language-ini', 'class="language-clike', $content);

    $dom = new DOMDocument();
    $dom->loadHtml('<?xml encoding="UTF-8">' . $content);
    $xpath = new DOMXPath($dom);

    $toc = array();
    $ids = array();

    $isSpan = function ($node) {
        return XML_ELEMENT_NODE === $node->nodeType && 'span' === $node->tagName;
    };

    $genId = function ($node) use (&$ids, $isSpan) {
        $count = 0;
        do {
            if ($isSpan($node->lastChild)) {
                $node = clone $node;
                $node->removeChild($node->lastChild);
            }

            $id = preg_replace('{[^a-z0-9]}i', '-', strtolower(trim($node->nodeValue)));
            $id = preg_replace('{-+}', '-', $id);
            if ($count) {
                $id .= '-'.($count+1);
            }
            $count++;
        } while (isset($ids[$id]));
        $ids[$id] = true;
        return $id;
    };

    $getDesc = function ($node) use ($isSpan) {
        if ($isSpan($node->lastChild)) {
            return $node->lastChild->nodeValue;
        }

        return null;
    };

    $getTitle = function ($node) use ($isSpan) {
        if ($isSpan($node->lastChild)) {
            $node = clone $node;
            $node->removeChild($node->lastChild);
        }

        return $node->nodeValue;
    };

    // build TOC & deep links
    $firstTitle = null;
    $h1 = $h2 = $h3 = $h4 = 0;
    $nodes = $xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4]');
    foreach ($nodes as $node) {
        // set id and add anchor link
        $id = $genId($node);
        $title = $getTitle($node);
        if ('03-cli.md' === $page && 'Options' === $title) {
            continue;
        }
        $desc = $getDesc($node);
        $node->setAttribute('id', $id);
        $link = $dom->createElement('a', '#');
        $link->setAttribute('href', '#'.$id);
        $link->setAttribute('class', 'anchor');
        $node->appendChild($link);

        if (empty($firstTitle)) {
            $firstTitle = $title;
        }

        // parse into a tree
        switch ($node->nodeName) {
            case 'h1':
                $toc[++$h1] = array('title' => $title, 'id' => $id, 'desc' => $desc);
            break;

            case 'h2':
                $toc[$h1][++$h2] = array('title' => $title, 'id' => $id, 'desc' => $desc);
            break;

            case 'h3':
                $toc[$h1][$h2][++$h3] = array('title' => $title, 'id' => $id, 'desc' => $desc);
            break;

            case 'h4':
                $toc[$h1][$h2][$h3][++$h4] = array('title' => $title, 'id' => $id, 'desc' => $desc);
            break;
        }
    }

    // save new content with IDs
    $content = $dom->saveHtml();
    $content = preg_replace('{.*<body>(.*)</body>.*}is', '$1', $content);

    // add class to footer nav
    $content = preg_replace('{<p>(&larr;.+?|.+?&rarr;)</p>}', '<p class="prev-next">$1</p>', $content);

    return $app['twig']->render('doc.show.html.twig', array(
        'doc' => $content,
        'file' => $page,
        'page' => $page == '00-intro.md' ? 'getting-started' : 'docs',
        'toc' => $toc,
        'title' => $firstTitle
    ));
})
->assert('page', '[a-z0-9/\'-]+\.md')
->bind('docs.view');

$shortcuts = [
    '/commit-deps' => ['faqs/should-i-commit-the-dependencies-in-my-vendor-directory.md', ''],
    '/xdebug' => ['articles/troubleshooting.md', '#xdebug-impact-on-composer'],
    '/root' => ['faqs/how-to-install-untrusted-packages-safely.md', ''],
    // TODO enable once 2.0 is stable '/repoprio' => ['articles/repository-priorities.md', ''],
    '/repoprio' => ['https://github.com/Seldaek/composer/blob/b6bad4eef62f663d32bc6c78ea9ff214b12bdd9c/doc/articles/repository-priorities.md', ''],
];

foreach ($shortcuts as $url => $page) {
    $app->get($url, function () use ($app, $page) {
        if (substr($page[0], 0, 6) === 'https:') {
            return new RedirectResponse($page[0]);
        }

        $url = $app['url_generator']->generate(
            'docs.view',
            array('page' => $page[0])
        );

        return new RedirectResponse($url . $page[1]);
    })->bind('shortcut_'.trim($url, '/'));
}
