<?php

/**
 * DokuWiki Plugin fksdownloader (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michal Koutný <michal@fykos.cz>
 */
// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'soap.php';

class helper_plugin_fksdownloader extends DokuWiki_Plugin {

    const EXPIRATION_FRESH = 0;
    const EXPIRATION_NEVER = 0x7fffffff;

    /**
     * @var fksdownloader_soap 
     */
    public $soap;

    public function __construct() {
        $this->soap = new fksdownloader_soap($this->getConf('wsdl'), $this->getConf('fksdb_login'), $this->getConf('fksdb_password'));
    }

    /**
     * Return info about supported methods in this Helper Plugin
     *
     * @return array of public methods
     */
    public function getMethods() {
        return array(
            array(
                'name' => 'downloadExport',
                'desc' => 'Downloads predefined export via web service API.',
                'params' => array(
                    'qid' => 'string',
                    'parameters' => 'array'
                ),
                'return' => array('xml' => 'string')
            ),
            array(
                'name' => 'downloadResultsDetail',
                'desc' => 'Downloads detailed (series) results via web service API.',
                'params' => array(
                    'contest' => 'string',
                    'year' => 'integer',
                    'series' => 'integer'
                ),
                'return' => array('xml' => 'string')
            ),
            array(
                'name' => 'downloadResultsCummulative',
                'desc' => 'Downloads cummulative (of specified series) results via web service API.',
                'params' => array(
                    'contest' => 'string',
                    'year' => 'integer',
                    'series' => 'array'
                ),
                'return' => array('xml' => 'string')
            ),
            array(
                'name' => 'downloadWebServer',
                'desc' => 'Downloads a file from configured web server.',
                'params' => array(
                    'path' => 'string',
                ),
                'return' => array('content' => 'string')
            ),
        );
    }

    public function downloadExport($expiration, $qid, $parameters) {
        $filename = 'export.' . self::getExportId($qid, $parameters);
        $that = $this;
        return $this->tryCache($filename, $expiration, function() use($qid, $parameters, $that) {
                            $request = $that->soap->createExportRequest($qid, $parameters);
                            $xml = $that->soap->callMethod('GetExport', $request);

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
        $that = $this;
        return $this->tryCache($filename, $expiration, function() use($contest, $year, $series, $that) {
                            $request = $that->soap->createResultsDetailRequest($contest, $year, $series);
                            return $that->downloadResults($request);
                        });
    }

    public function downloadResultsCummulative($expiration, $contest, $year, $series) {
        $filename = sprintf('result.cumm.%s.%s.%s', $contest, $year, implode('', $series));
        $that = $this;
        return $this->tryCache($filename, $expiration, function() use($contest, $year, $series, $that) {
                            $request = $that->soap->createResultsCummulativeRequest($contest, $year, $series);
                            return $that->downloadResults($request);
                        });
    }

    public function downloadWebServer($expiration, $path) {
        $filename = self::getWebServerFilename($path);
        $that = $this;
        return $this->tryCache($filename, $expiration, function() use($path, $that) {
                            if ($that->getConf('http_login')) {
                                $auth = $that->getConf('http_login') . ':' . $that->getConf('http_password') . '@';
                            } else {
                                $auth = '';
                            }
                            $host = $that->getConf('http_host');

                            $src = "http://$auth{$host}{$path}"; // TODO ? rawurlencode($path)

                            $dst = tempnam($that->getConf('temp_dir'), 'fks');

                            if (!@copy($src, $dst)) {
                                $safeSrc = "http://{$host}{$path}"; // TODO ? rawurlencode($path)
                                msg('fksdownloader: ' . sprintf($that->getLang('download_failed_http'), $safeSrc), -1);
                                return null;
                            }
                            $content = file_get_contents($dst);
                            unlink($dst);
                            return $content;
                        });
    }

    public static function getWebServerFilename($path) {
        $namePath = str_replace('/', '_', $path);
        return sprintf('http.%s', $namePath);
    }

    /**
     * @internal
     * @param mixed $request
     * @return string
     */
    public function downloadResults($request) {
        $xml = $this->soap->callMethod('GetResults', $request);

        if (!$xml) {
            msg('fksdownloader: ' . sprintf($this->getLang('download_failed_results')), -1);
            return null;
        } else {
            return $xml;
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

    private function getFromCache($filename, $expiration) {
        $realFilename = $this->getCacheFilename($filename);
        if (file_exists($realFilename) && filemtime($realFilename) + $expiration >= time()) {
            return io_readFile($realFilename);
        } else {
            return null;
        }
    }

    private function putToCache($filename, $content) {
        $realFilename = $this->getCacheFilename($filename);
        io_saveFile($realFilename, $content);
    }

    public function getCacheFilename($filename) {
        $id = $this->getPluginName() . ':' . $filename;
        return metaFN($id, '.xml');
    }

    public static function getExportId($qid, $parameters) {
        $hash = md5(serialize($parameters));
        return $qid . '_' . $hash;
    }

}

// vim:ts=4:sw=4:et:
