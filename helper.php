<?php

/**
 * DokuWiki Plugin fksdownloader (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michal Koutný <michal@fykos.cz>
 */

// must be run within Dokuwiki
use dokuwiki\Extension\Plugin;
use Fykosak\FKSDBDownloaderCore\FKSDBDownloader;
use Fykosak\FKSDBDownloaderCore\Requests\ExportRequest;
use Fykosak\FKSDBDownloaderCore\Requests\Request;
use Fykosak\FKSDBDownloaderCore\Requests\Results\ResultsCumulativeRequest;
use Fykosak\FKSDBDownloaderCore\Requests\Results\ResultsDetailRequest;

if (!defined('DOKU_INC'))
    die();

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

class helper_plugin_fksdownloader extends Plugin {

    const EXPIRATION_FRESH = 0;
    const EXPIRATION_NEVER = 0x7fffffff;

    private FKSDBDownloader $downloader;

    public function downloadExport($expiration, string $qid, array $parameters, int $formatVersion = 2): string {
        return $this->tryCache2(new ExportRequest($qid, $parameters, $formatVersion), $expiration);
    }

    public function downloadResultsDetail($expiration, int $contest, int $year, int $series): string {
        return $this->tryCache2(new ResultsDetailRequest($contest, $year, $series), $expiration);
    }

    public function downloadResultsCummulative($expiration, int $contest, int $year, array $series): string {
        return $this->tryCache2(new ResultsCumulativeRequest($contest, $year, $series), $expiration);
    }

    public function downloadResultsSchoolCummulative($expiration, $contest, $year, $series) {
        msg('fksdownloader: ' . 'School results is deprecated', -1);
    }

    public function downloadWebServer($expiration, $path) {
        $filename = self::getWebServerFilename($path);

        return $this->tryCache($filename, $expiration, function () use ($path) {
            if ($this->getConf('http_login')) {
                $auth = $this->getConf('http_login') . ':' . $this->getConf('http_password') . '@';
            } else {
                $auth = '';
            }
            $host = $this->getConf('http_host');
            $scheme = $this->getConf('http_scheme');

            $src = "{$scheme}://$auth{$host}{$path}"; // TODO ? rawurlencode($path)

            $dst = tempnam($this->getConf('temp_dir'), 'fks');

            if (!@copy($src, $dst)) {
                $safeSrc = "{$scheme}://{$host}{$path}"; // TODO ? rawurlencode($path)
                $err = error_get_last();
                msg('fksdownloader: ' . sprintf($this->getLang('download_failed_http'), $safeSrc, $err['message']), -1);
                return null;
            }
            $content = file_get_contents($dst);
            unlink($dst);
            return $content;
        });
    }

    public static function getWebServerFilename(string $path): string {
        $namePath = str_replace('/', '_', $path);
        return sprintf('http.%s', $namePath);
    }

    private function getSoap(): FKSDBDownloader {
        if (!isset($this->downloader)) {
            try {
                $this->downloader = new FKSDBDownloader($this->getConf('wsdl'), $this->getConf('fksdb_login'), $this->getConf('fksdb_password'));
            } catch (SoapFault $e) {
                msg('fksdbexport: ' . $e->getMessage(), -1);
            }
            return $this->downloader;
        }
    }

    private function tryCache($filename, $expiration, $contentCallback) {
        $cached = $this->getFromCache($filename, $expiration);

        if (!$cached) {
            $content = call_user_func($contentCallback);
            if ($content) {
                $this->putToCache($filename, $content);
            }
            return $content;
        } else {
            return $cached;
        }
    }

    private function tryCache2(Request $request, $expiration): string {
        $cached = $this->getFromCache($request->getCacheKey(), $expiration);

        if (!$cached) {
            $content = $this->getSoap()->download($request);
            if ($content) {
                $this->putToCache($request->getCacheKey(), $content);
            }
            return $content;
        } else {
            return $cached;
        }
    }

    private function getFromCache($filename, $expiration) {
        $realFilename = $this->getCacheFilename($filename);
        if (file_exists($realFilename) && filemtime($realFilename) + $expiration >= time()) {
            return io_readFile($realFilename);
        } else {
            return null;
        }
    }

    private function putToCache($filename, $content): void {
        $realFilename = $this->getCacheFilename($filename);
        io_saveFile($realFilename, $content);
    }

    public function getCacheFilename(string $filename): string {
        $id = $this->getPluginName() . ':' . $filename;
        return metaFN($id, '.xml');
    }

    public static function getExportId(string $qid, $parameters): string {
        $hash = md5(serialize($parameters));
        return $qid . '_' . $hash;
    }

}
