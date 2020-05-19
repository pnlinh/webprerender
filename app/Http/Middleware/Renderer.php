<?php

namespace App\Http\Middleware;

use Log;
use File;
use Closure;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
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
    const IS_DESKTOP = 0;
    const IS_MOBILE = 1;

    /** @var string */
    private $rendererHostUrl;

    /** @var array */
    private $bots;

    public function __construct()
    {
        $config = app()['config']->get('renderer');

        $this->rendererHostUrl = $config['host_url'];
        $this->bots = $config['bots'];
    }

    private function shouldShowRendererPage(Request $request)
    {
        $userAgent = strtolower($request->userAgent());
        $bufferAgent = $request->server->get('X-BUFFERBOT');
        $accept = $request->server->get('HTTP_ACCEPT');
        $path = $request->getPathInfo();

        $isRendererPageRequest = false;

        if (! $userAgent) {
            return false;
        }

        if (! $request->isMethod(Request::METHOD_GET) || ! preg_match('/text\/html/', $accept) || preg_match('/^\/(?:assets|api|.*api)/', $path)) {
            return false;
        }

        if ($request->query->has('_escaped_fragment_')) {
            $isRendererPageRequest = true;
        }

        foreach ($this->bots as $botUserAgent) {
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
        info($request->getUri());

        $isReponsiveMode = config('renderer.reponsive_mode');
        $isMobile = static::IS_DESKTOP;
        $pathByDeviceMode = 'desktop/';

        if ($isReponsiveMode === false) {
            if (preg_match('~Linux|Android|iPhone|Nexus~i', $request->userAgent())) {
                $isMobile = static::IS_MOBILE;
                $pathByDeviceMode = 'mobile/';
            }
        }

        $protocol = 'https';

        if (config('renderer.debug_mode')) {
            $host = $this->rendererHostUrl;
        } else {
            $host = $request->getHttpHost();
        }

        $path = $request->getPathInfo();
        $path = rtrim($path,'/');

        $domainName = $host;
        $domainName = preg_replace('~http(s?):\/\/~', '', $domainName);

        if ('/' !== $path) {
            $pageUrl = $protocol.'://'.$host.$path;

            if ($request->query()) {
                $pageUrl .= '?'.$request->getQueryString();
            }

            if ($this->shouldShowRendererPage($request)) {
                $domainPath = preg_replace('~[.:]~', '-', $domainName);
                $fullRenderFilePath = $domainPath.'/';
                $fileExtentions = '.html';
                $fileName = last(explode('/', $path));

                if ('' == $fileName) {
                    $fileName = $domainName;
                }

                $fullFilePath = public_path('pages/'.$fullRenderFilePath.$pathByDeviceMode.$fileName.$fileExtentions);

                if (preg_match('~\.html\.html|\.htm\.htm~', $fullFilePath)) {
                    $fullFilePath = preg_replace('~\.html\.html|\.htm\.htm~', '.html', $fullFilePath);
                }

                if (File::exists($fullFilePath)) {
                    $content = File::get($fullFilePath);
                    $lastModified = File::lastModified($fullFilePath);
                    $lastModified = DateTime::createFromFormat('U', $lastModified);
                    $lastModified->setTimezone(new DateTimeZone(config('app.timezone')));
                    $diffTimeInMinutes = now()->diffInMinutes($lastModified);

                    if (config('renderer.time_rerender_file') <= $diffTimeInMinutes) {
                        // Re render new file
                        goto rerender_file;
                    }

                    return response($content)->header('Content-Type', 'text/html');
                }

                rerender_file:

                $isOk = true;
                $nodeExcuteCommand = 'node '.base_path('index.js').' '.$pageUrl.' '.$isMobile;

                $process = new Process($nodeExcuteCommand);
                $process->run();

                if (! $process->isSuccessful() || '' !== $process->getErrorOutput()) {
                    $isOk = false;
                    Log::error($process->getErrorOutput());
                }

                if ($isOk) {
                    $output = $process->getOutput();

                    return response($output)->header('Content-Type', 'text/html');
                }
            }
        }

        return $next($request);
    }
}
