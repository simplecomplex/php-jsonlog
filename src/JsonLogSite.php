<?php
/**
 * Created by PhpStorm.
 * User: jacob
 * Date: 12/05/17
 * Time: 11:56
 */

namespace SimpleComplex\JsonLog;


class JsonLogSite {


  public $type = '';

  public $identifier = '';

  public $instanceOf = '';

  public $host = '';

  public $tags = array();


  public function __construct($jsonLogClass) {
    $this->type = $jsonLogClass::configGet($jsonLogClass::CONFIG_DOMAIN, 'type', $jsonLogClass::TYPE_DEFAULT);
  }


  public function getColumns() {



  }

  protected function host() {

  }
}
