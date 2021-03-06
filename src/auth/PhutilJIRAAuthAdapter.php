<?php

/**
 * Authentication adapter for JIRA OAuth1.
 */
final class PhutilJIRAAuthAdapter extends PhutilOAuth1AuthAdapter {

  // TODO: JIRA tokens expire (after 5 years) and we could surface and store
  // that.

  private $jiraBaseURI;
  private $adapterDomain;
  private $currentSession;
  private $userInfo;

  public function setJIRABaseURI($jira_base_uri) {
    $this->jiraBaseURI = $jira_base_uri;
    return $this;
  }

  public function getJIRABaseURI() {
    return $this->jiraBaseURI;
  }

  public function getAccountID() {
    // Make sure the handshake is finished; this method is used for its
    // side effect by Auth providers.
    $this->getHandshakeData();

    return idx($this->getUserInfo(), 'key');
  }

  public function getAccountName() {
    return idx($this->getUserInfo(), 'name');
  }

  public function getAccountImageURI() {
    $avatars = idx($this->getUserInfo(), 'avatarUrls');
    if ($avatars) {
      return idx($avatars, '48x48');
    }
    return null;
  }

  public function getAccountRealName() {
    return idx($this->getUserInfo(), 'displayName');
  }

  public function getAccountEmail() {
    return idx($this->getUserInfo(), 'emailAddress');
  }

  public function getAdapterType() {
    return 'jira';
  }

  public function getAdapterDomain() {
    return $this->adapterDomain;
  }

  public function setAdapterDomain($domain) {
    $this->adapterDomain = $domain;
    return $this;
  }

  protected function getSignatureMethod() {
    return 'RSA-SHA1';
  }

  protected function getRequestTokenURI() {
    return $this->getJIRAURI('plugins/servlet/oauth/request-token');
  }

  protected function getAuthorizeTokenURI() {
    return $this->getJIRAURI('plugins/servlet/oauth/authorize');
  }

  protected function getValidateTokenURI() {
    return $this->getJIRAURI('plugins/servlet/oauth/access-token');
  }

  private function getJIRAURI($path) {
    return rtrim($this->jiraBaseURI, '/').'/'.ltrim($path, '/');
  }

  private function getUserInfo() {
    if ($this->userInfo === null) {
      $this->currentSession = $this->newJIRAFuture('rest/auth/1/session', 'GET')
        ->resolveJSON();

      // The session call gives us the username, but not the user key or other
      // information. Make a second call to get additional information.

      $params = array(
        'username' => $this->currentSession['name'],
      );

      $this->userInfo = $this->newJIRAFuture('rest/api/2/user', 'GET', $params)
        ->resolveJSON();
    }

    return $this->userInfo;
  }

  public static function newJIRAKeypair() {
    $config = array(
      'digest_alg' => 'sha512',
      'private_key_bits' => 4096,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    );

    $res = openssl_pkey_new($config);
    if (!$res) {
      throw new Exception('openssl_pkey_new() failed!');
    }

    $private_key = null;
    $ok = openssl_pkey_export($res, $private_key);
    if (!$ok) {
      throw new Exception('openssl_pkey_export() failed!');
    }

    $public_key = openssl_pkey_get_details($res);
    if (!$ok || empty($public_key['key'])) {
      throw new Exception('openssl_pkey_get_details() failed!');
    }
    $public_key = $public_key['key'];

    return array($public_key, $private_key);
  }


  /**
   * JIRA indicates that the user has clicked the "Deny" button by passing a
   * well known `oauth_verifier` value ("denied"), which we check for here.
   */
  protected function willFinishOAuthHandshake() {
    $jira_magic_word = 'denied';
    if ($this->getVerifier() == $jira_magic_word) {
      throw new PhutilAuthUserAbortedException();
    }
  }

  public function newJIRAFuture($path, $method, $params = array()) {
    $uri = new PhutilURI($this->getJIRAURI($path));
    if ($method == 'GET') {
      $uri->setQueryParams($params);
      $params = array();
    } else {
      // For other types of requests, JIRA expects the request body to be
      // JSON encoded.
      $params = json_encode($params);
    }

    // JIRA returns a 415 error if we don't provide a Content-Type header.

    return $this->newOAuth1Future($uri, $params)
      ->setMethod($method)
      ->addHeader('Content-Type', 'application/json');
  }

}
