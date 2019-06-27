<?php declare(strict_types = 1);

namespace SFC\Staticfilecache\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;

class StaticFileCacheFallbackMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $possibleStaticFile = false;
        $uri = $request->getUri();
        $cacheDirectory = PATH_site . 'typo3temp/tx_staticfilecache/';

        $handle = true;
        if ($uri->getQuery() !== '') {
            $handle = false;
        } elseif ($request->getMethod() !== 'GET') {
            $handle = false;
        } elseif (isset($_COOKIE[$GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName']])) {
            $handle = false;
        } elseif (isset($_COOKIE['staticfilecache']) && $_COOKIE['staticfilecache'] === 'fe_typo_user_logged_in') {
            $handle = false;
        } else {
            $possibleStaticFile = realpath($cacheDirectory . $uri->getScheme() . DIRECTORY_SEPARATOR . $uri->getHost() . DIRECTORY_SEPARATOR . ($uri->getPort() ?: '80') . $uri->getPath() . DIRECTORY_SEPARATOR . 'index.html');

            if (false === $possibleStaticFile) {
                $handle = false;
            } else {
                // Check if we can support compressed files
                $headers = ['Content-Type' => 'text/html; charset=utf-8'];
                foreach ($request->getHeader('accept-encoding') as $acceptEncoding) {
                    if (strpos($acceptEncoding, 'gzip') !== false) {
                        $headers['Content-Encoding'] = 'gzip';
                        $possibleStaticFile .= '.gz';
                        break;
                    }
                }
            }
        }

        // check if the file really is part of the cache directory
        if ($possibleStaticFile === false) {
            $handle = false;
        } elseif (false === file_exists($possibleStaticFile) && false === is_readable($possibleStaticFile)) {
            $handle = false;
        } elseif (strpos($possibleStaticFile, $cacheDirectory) !== 0) {
            $handle = false;
        }

        if (false === $handle) {
            return $handler->handle($request);
        }

        return new HtmlResponse(file_get_contents($possibleStaticFile), 200, $headers);
    }
}
