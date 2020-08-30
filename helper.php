<?php

/**
 * DokuWiki Plugin fksdownloader (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michal Koutný <michal@fykos.cz>
 */

// must be run within Dokuwiki
use dokuwiki\Extension\Plugin;

if (!defined('DOKU_INC'))
    die();

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'FKSDownloaderSoap.php';

class helper_plugin_fksdownloader extends Plugin {

    const EXPIRATION_FRESH = 0;
    const EXPIRATION_NEVER = 0x7fffffff;

    private FKSDownloaderSoap $soap;

    public function downloadExport($expiration, string $qid, array $parameters, int $formatVersion = 1) {
        $filename = 'export.' . $formatVersion . '.' . self::getExportId($qid, $parameters);
        return $this->tryCache($filename, $expiration, function () use ($qid, $parameters, $formatVersion) {
            $request = $this->getSoap()->createExportRequest($qid, $parameters, $formatVersion);
            $xml = $this->getSoap()->callMethod('GetExport', $request);

            if (!$xml) {
                msg('fksdownloader: ' . sprintf($this->getLang('download_failed_export'), $qid), -1);
                return null;
            } else {
                return $xml;
            }
        });
    }

    public function downloadResultsDetail($expiration, $contest, $year, $series) {
        $filename = sprintf('result.detail.%s.%s.%s', $contest, $year, $series);
        return $this->tryCache($filename, $expiration, function () use ($contest, $year, $series) {
            $request = $this->getSoap()->createResultsDetailRequest($contest, $year, $series);
            return $this->downloadResults($request);
        });
    }

    public function downloadResultsCummulative($expiration, $contest, $year, $series) {
        $filename = sprintf('result.cumm.%s.%s.%s', $contest, $year, implode('', $series));
        return $this->tryCache($filename, $expiration, function () use ($contest, $year, $series) {
            $request = $this->getSoap()->createResultsCummulativeRequest($contest, $year, $series);
            return $this->downloadResults($request);
        });
    }

    public function downloadResultsSchoolCummulative($expiration, $contest, $year, $series) {
        $filename = sprintf('result.school-cumm.%s.%s.%s', $contest, $year, implode('', $series));
        return $this->tryCache($filename, $expiration, function () use ($contest, $year, $series) {
            $request = $this->getSoap()->createResultsSchoolCummulativeRequest($contest, $year, $series);
            return $this->downloadResults($request);
        });
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

    /**
     * @param mixed $request
     * @return string
     */
    private function downloadResults($request) {
        $xml = $this->getSoap()->callMethod('GetResults', $request);

        if (!$xml) {
            msg('fksdownloader: ' . sprintf($this->getLang('download_failed_results')), -1);
            return null;
        } else {
            return $xml;
        }
    }

    /**
     * @return FKSDownloaderSoap
     * @internal
     */
    private function getSoap(): FKSDownloaderSoap {
        if (!isset($this->soap)) {
            $this->soap = new FKSDownloaderSoap($this->getConf('wsdl'), $this->getConf('fksdb_login'), $this->getConf('fksdb_password'));
        }
        return $this->soap;
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
