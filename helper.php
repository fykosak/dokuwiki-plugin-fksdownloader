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

class helper_plugin_fksdownloader extends DokuWiki_Plugin {

    /**
     * @var fksdownloader_soap 
     */
    private $soap;

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

    public function downloadExport($qid, $parameters) {
        $request = $this->soap->createExportRequest($qid, $parameters);
        $xml = $this->soap->callMethod('GetExport', $request);

        if (!$xml) {
            msg('fksdownloader: ' . sprintf($this->getLang('download_failed_export'), $qid), -1);
            return null;
        } else {
            return $xml;
        }
    }

    public function downloadResultsDetail($contest, $year, $series) {
        $request = $this->soap->createResultsDetailRequest($contest, $year, $series);
        return $this->downloadResults($request);
    }

    public function downloadResultsCummulative($contest, $year, $series) {
        $request = $this->soap->createResultsCummulativeRequest($contest, $year, $series);
        return $this->downloadResults($request);
    }

    public function downloadWebServer($path) {
        if ($this->getConf('http_user')) {
            $auth = $this->getConf('http_user') . ':' . $this->getConf('http_password');
        } else {
            $auth = '';
        }
        $host = $this->getConf('http_host');

        $src = "http://$auth@{$host}{$path}"; // TODO ? rawurlencode($path)

        $dst = tempnam($this->getConf('temp_dir'), 'fks');

        if (!@copy($src, $dst)) {
            msg('fksdownloader: ' . sprintf($this->getLang('download_failed_http'), $path), -1);
            return null;
        }
        $content = file_get_contents($dst);
        unlink($dst);
        return $content;
    }

    private function downloadResults($request) {
        $xml = $this->soap->callMethod('GetResults', $request);

        if (!$xml) {
            msg('fksdownloader: ' . sprintf($this->getLang('download_failed_results')), -1);
            return null;
        } else {
            return $xml;
        }
    }

}

// vim:ts=4:sw=4:et:
