<?php

namespace App\Http\Middleware;

use Closure;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use File;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Class Renderer
 *
 * @see http://thelazylog.com/using-phantomjs-to-serve-html-content-of-single-page-app/
 * @author Ngoc Linh Pham <pnlinh1207@gmail.com>
 *
 * @package App\Http\Middleware
 */
class Renderer
{
    const BOTS = [
        'googlebot',
        'yahoo',
        'bingbot',
        'yandex',
        'baiduspider',
        'facebookexternalhit',
        'twitterbot',
        'rogerbot',
        'linkedinbot',
        'embedly',
        'quora link preview',
        'showyoubot',
        'outbrain',
        'pinterest',
        'developers.google.com/+/web/snippet',
        'slackbot',
    ];

    /** @var string */
    private $rendererHostUrl;

    public function __construct()
    {
        $this->rendererHostUrl = config('renderer.host_url');
    }

    private function shouldShowRendererPage(Request $request)
    {
        $userAgent = strtolower($request->userAgent());
        $bufferAgent = $request->server->get('X-BUFFERBOT');
        $accept = $request->server->get('HTTP_ACCEPT');
        $path = $request->getPathInfo();
        $requestUri = $request->getRequestUri();
        $referer = $request->headers->get('Referer');

        $isRendererPageRequest = false;

        if (! $userAgent) {
            return false;
        }

        if (! $request->isMethod(Request::METHOD_GET) || ! preg_match('/text\/html/', $accept) || preg_match('/^\/(?:assets|api)/', $path)) {
            return false;
        }

        if ($request->query->has('_escaped_fragment_')) {
            $isRendererPageRequest = true;
        }

        foreach (self::BOTS as $botUserAgent) {
            if (Str::contains($userAgent, mb_strtolower($botUserAgent))) {
                $isRendererPageRequest = true;
            }
        }

        if ($bufferAgent) {
            $isRendererPageRequest = true;
        }

        if (! $isRendererPageRequest) {
            return false;
        }

        return true;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $host = $request->headers->get('X-Forwarded-Host');
        $path = $request->getPathInfo();

        $domainName = $this->rendererHostUrl;
        $domainName = preg_replace('~http(s?)\:\/\/~', '', $domainName);

        if ('/' !== $path) {
            $pageUrl = $this->rendererHostUrl.$path;
            if ($request->query()) {
                $pageUrl .= '?'.$request->getQueryString();
            }

            if ($this->shouldShowRendererPage($request)) {
                $domainPath = preg_replace('~\.|\:~', '-', $domainName);
                $fullRenderFilePath = $domainPath.'/';
                $fileExtentions = '.html';
                $fileName = last(explode('/', $path));
                $fullFilePath = public_path('pages/'.$fullRenderFilePath.$fileName.$fileExtentions);

                if (File::exists($fullFilePath)) {
                    $content = file_get_contents($fullFilePath);

                    $lastModified = File::lastModified($fullFilePath);
                    $lastModified = DateTime::createFromFormat('U', $lastModified);
                    $lastModified->setTimezone(new DateTimeZone(config('app.timezone')));
                    $diffTimeInMinutes = now()->diffInMinutes($lastModified);

                    if (config('renderer.time_rerender_file') <= $diffTimeInMinutes) {
                        // Re render new file
                        goto rerender_file;
                    }

                    return new Response($content);
                }

                rerender_file:

                $nodeExcuteCommand = 'node '.base_path('index.js').' '.$pageUrl;
                $process = new Process($nodeExcuteCommand);
                $process->run();
                $output = $process->getOutput();

                if ('URL is not valid!' === $output || 'Please enter URL' === $output) {
                    info($output);
                }

                return new Response($process->getOutput());
            }
        }

        return $next($request);
    }
}
