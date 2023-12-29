<?php

/**
 * DokuWiki Plugin fksdownloader (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michal Koutný <michal@fykos.cz>
 */

use dokuwiki\Extension\Plugin;
use Fykosak\FKSDBDownloaderCore\FKSDBDownloader;
use Fykosak\FKSDBDownloaderCore\Requests\Event\ParticipantsListRequest;
use Fykosak\FKSDBDownloaderCore\Requests\EventListRequest;
use Fykosak\FKSDBDownloaderCore\Requests\ExportRequest;
use Fykosak\FKSDBDownloaderCore\Requests\OrganizersRequest;
use Fykosak\FKSDBDownloaderCore\Requests\Request;
use Fykosak\FKSDBDownloaderCore\Requests\Results\ResultsCumulativeRequest;
use Fykosak\FKSDBDownloaderCore\Requests\Results\ResultsDetailRequest;

if (!defined('DOKU_INC'))
    die();

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

class helper_plugin_fksdownloader extends Plugin {

    public const EXPIRATION_FRESH = 0;
    public const EXPIRATION_NEVER = 0x7fffffff;

    private FKSDBDownloader $downloader;

    public function downloadExport(int $expiration, string $qid, array $parameters, int $formatVersion = 2): string {
        return $this->downloadFKSDB(new ExportRequest($qid, $parameters, $formatVersion), $expiration);
    }

    public function downloadResultsDetail(int $expiration, int $contestId, int $year, int $series): string {
        return $this->downloadFKSDB(new ResultsDetailRequest($contestId, $year, $series), $expiration);
    }

    public function downloadResultsCummulative(int $expiration, int $contestId, int $year, array $series): string {
        return $this->downloadFKSDB(new ResultsCumulativeRequest($contestId, $year, $series), $expiration);
    }

    public function downloadOrganisers(int $expiration, int $contestId, ?int $year): string {
        return $this->downloadFKSDB(new OrganizersRequest($contestId, $year), $expiration);
    }

    public function downloadEventsList(int $expiration, array $eventTypeIds): string {
        return $this->downloadFKSDB(new EventListRequest($eventTypeIds), $expiration);
    }

    public function downloadEventParticipants(int $expiration, int $eventId, array $statuses = []): string {
        return $this->downloadFKSDB(new ParticipantsListRequest($eventId, $statuses), $expiration);
    }

    public function downloadWebServer(int $expiration, string $path): ?string {
        $filename = self::getWebServerFilename($path);

        return $this->tryCache($filename, $expiration, function () use ($path): ?string {
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
        }
        return $this->downloader;
    }

    private function tryCache(string $filename, int $expiration, callable $contentCallback): ?string {
        $cached = $this->getFromCache($filename, $expiration);

        if (!$cached) {
            $content = $contentCallback();
            if ($content) {
                $this->putToCache($filename, $content);
            }
            return $content;
        } else {
            return $cached;
        }
    }

    private function downloadFKSDB(Request $request, int $expiration): string {
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

    private function getFromCache(string $filename, int $expiration): ?string {
        $realFilename = $this->getCacheFilename($filename);
        if (file_exists($realFilename) && filemtime($realFilename) + $expiration >= time()) {
            return io_readFile($realFilename);
        } else {
            return null;
        }
    }

    private function putToCache(string $filename, string $content): void {
        $realFilename = $this->getCacheFilename($filename);
        io_saveFile($realFilename, $content);
    }

    public function getCacheFilename(string $filename): string {
        $id = $this->getPluginName() . ':' . $filename;
        return metaFN($id, '.xml');
    }
}
