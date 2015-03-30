<?php
namespace Civi\Cxn\Rpc;

use Civi\Cxn\Rpc\Message\GarbledMessage;
use Civi\Cxn\Rpc\Message\StdMessage;
use Psr\Log\NullLogger;

class ApiClient extends Agent {
  /**
   * @var array
   */
  protected $appMeta;

  /**
   * @var string
   */
  protected $cxnId;

  /**
   * @var Http\HttpInterface
   */
  protected $http;

  /**
   * @param array $appMeta
   * @param CxnStore\CxnStoreInterface $cxnStore
   */
  public function __construct($appMeta, $cxnStore, $cxnId) {
    $this->appMeta = $appMeta;
    $this->cxnStore = $cxnStore;
    $this->cxnId = $cxnId;
    $this->http = new Http\PhpHttp();
    $this->log = new NullLogger();
  }

  public function call($entity, $action, $params) {
    $this->log->debug("Send API call: {entity}.{action} over {cxnId}", array(
      'entity' => $entity,
      'action' => $action,
      'cxnId' => $this->cxnId,
    ));
    $cxn = $this->cxnStore->getByCxnId($this->cxnId);
    $req = new StdMessage($cxn['cxnId'], $cxn['secret'],
      array($entity, $action, $params));
    list($respHeaders, $respCiphertext, $respCode) = $this->http->send('POST', $cxn['siteUrl'], $req->encode(), array(
      'Content-type' => Constants::MIME_TYPE,
    ));
    $respMessage = $this->decode(array(StdMessage::NAME, GarbledMessage::NAME), $respCiphertext);
    if ($respMessage instanceof GarbledMessage) {
      return array(
        $respCode,
        array(
          'is_error' => 1,
          'error_message' => 'Received garbled message',
          'original_message' => $respMessage->getData(),
        ),
      );
    }
    elseif ($respMessage instanceof StdMessage){
      if ($respMessage->getCxnId() != $cxn['cxnId']) {
        // Tsk, tsk, Mallory!
        throw new \RuntimeException('Received response from incorrect connection.');
      }
      return $respMessage->getData();
    }
    else {
      return $this->createError('Unrecognized message type.');
    }
  }

  /**
   * @return Http\HttpInterface
   */
  public function getHttp() {
    return $this->http;
  }

  /**
   * @param Http\HttpInterface $http
   */
  public function setHttp($http) {
    $this->http = $http;
  }

}
