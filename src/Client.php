<?php

// http://wiki.omnicasa.com/display/ManuelFR/Omnicasa+APIV2+%3A+Documentation

namespace Maneuver\Omnicasa;  

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Symfony\Component\Cache\Simple\FilesystemCache;

class Client {

  private $_username, $_password;
  private $_language;
  private $_url;

  private $_logger = null;
  private $_caching = true;
  private $_cachingTimeout = 3600;

  private $_langs = ['nl' => 1, 'fr' => 2, 'en' => 3];
  public $defaultLanguage = 1;

  public $settings, $general;

  public function __construct($username, $password, $language = 'nl', $version = 1.12) {
    $this->_username = $username;
    $this->_password = $password;

    $this->_url = sprintf('http://newapi.omnicasa.com/%s/OmnicasaService.svc/', (string)$version);

    $this->_language = isset($_langs[$language]) ? $_langs[$language] : $this->defaultLanguage;

    $this->settings = new Settings($this);
    $this->general = new General($this);
  }

  public function enableLogging($logfile = 'omnicasa.log') {
    $this->_logger = new Logger('omnicasa');
    $this->_logger->pushHandler(new RotatingFileHandler($logfile));
  }

  public function setCachingTimeout($timeout) {
    $this->_cachingTimeout = $timeout;
  }
  
  public function setCaching($caching) {
    $this->_caching = $caching;
  }

  public function setUrl($url) {
    $this->_url = $url;
  }

  private function getUncachedEndpoints() {
    return [
      'CheckPersonLoginJson', 
      'GetPersonJson', 
      'GetPersonListJson', 
      'GetAutomaticHistoriesJson', 
      'GetVisitStatisticOfPropertyJson', 
      'GetCalendarHistoriesJson',
      'GetMediaObjectStatisticsGraphListJson',
      'ContactOnMeJson',
      'ContactOnMeProjectJson',
      'DemandRegisterJson',
      'UnsubscribeDemandPersonJson',
      'GetDemandPersonJson'
    ];
  }


  public function makeRequest($endpoint, $data = []) {
    
    $params = array_merge([
      'CustomerName' => $this->_username,
      'CustomerPassword' => $this->_password,
      'LanguageId' => $this->_language,
    ], $data);

    // var_dump($params);exit;

    $endpoint .= 'Json';
    $params = json_encode($params);
    $pretty_url = $this->_url . $endpoint .'?json=' . $params;
    $params = urlencode($params);
    $url = $this->_url . $endpoint .'?json=' . $params;


    $data = null;
    $cache = new FilesystemCache('', $this->_cachingTimeout, 'omnicasa_cache');
    $cacheKey = md5($url);

    if ($this->_caching && !in_array($endpoint, $this->getUncachedEndpoints())) {
      if ($cache->has($cacheKey)) {
        return $cache->get($cacheKey);
      }
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    curl_close($ch);

    if ($this->_logger) {
      $this->_logger->info($pretty_url);
    }
    $result = json_decode($output); 
    $key = $endpoint . 'Result';
    if (!empty($result->$key)) {
      $result = $result->$key;

      if ($result->Code > 0 && !$result->Success) {
        // $this->showError($result->Message);
      }

      $data = $result->Value;

      if (isset($data->Items)) {
        $data = $data->Items;
      }
    } else if (isset($result->Value) && is_object($result->Value)) {
      $data = $result->Value;
    }

    if ($this->_caching) {
      $cache->set($cacheKey, $data);
    }

    return $data;
  }

  protected function showError($message) {
    throw new \Exception($message);
  }
}