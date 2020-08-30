<?php

/**
 * Options for the fksdownloader plugin
 *
 * @author Michal Koutný <michal@fykos.cz>
 */
$meta['wsdl'] = ['string'];
$meta['fksdb_login'] = ['string'];
$meta['fksdb_password'] = ['password'];
$meta['http_host'] = ['string'];
$meta['http_scheme'] = ['multichoice', '_choices' => ['http', 'https']];
$meta['http_login'] = ['string'];
$meta['http_password'] = ['password'];
$meta['tmp_dir'] = ['string'];

