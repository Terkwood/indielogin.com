<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

class Authenticate {

  use Provider\GitHub;
  use Provider\Twitter;
  use Provider\IndieAuth;

  public function start(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $params = $request->getQueryParams();

    // Check that the application provided all the necessary parameters

    $errors = [];

    $client_id = false;
    $redirect_uri = false;
    $state = false;

    if(!isset($params['client_id'])) {
      $errors[] = 'The request is missing the client_id parameter';
    } else if(!\p3k\url\is_url($params['client_id'])) {
      $errors[] = 'The client_id parameter provided is not a URL';
    } else {
      $client_id = $params['client_id'];
    }

    if(isset(Config::$allowedClientIDs)) {
      if($client_id && !in_array($client_id, Config::$allowedClientIDs)) {
        $errors[] = 'This client_id is not enabled for use on this website';
      }
    }

    if(!isset($params['redirect_uri'])) {
      $errors[] = 'The request is missing the redirect_uri parameter';
    } else if(!\p3k\url\is_url($params['redirect_uri'])) {
      $errors[] = 'The redirect_uri parameter provided is not a complete URL';
    } else {
      $redirect_uri = $params['redirect_uri'];

      // check that the redirect uri is on the same domain as the client id
      if($client_id) {
        if(parse_url($client_id, PHP_URL_HOST) != parse_url($redirect_uri, PHP_URL_HOST)) {
          $errors[] = 'The client_id and redirect_uri must be on the same domain';
        }
      }
    }

    if(!isset($params['state'])) {
      $errors[] = 'The request is missing the state parameter';
    } else {
      $state = $params['state'];
    }

    $login_request = [
      'client_id' => $client_id,
      'redirect_uri' => $redirect_uri,
      'state' => $state,
    ];


    if(count($errors)) {
      $response->getBody()->write(view('auth/dev-error', [
        'title' => Config::$name.' Error',
        'errors' => $errors
      ]));
      return $response;
    }

    if(!isset($params['me'])) {
      if(isset($params['action']) && $params['action'] == 'logout')
        unset($_SESSION['me']);

      if(isset($_SESSION['me']))
        $_SESSION['expected_me'] = $_SESSION['me'];

      // If the developer isn't expecting a particular user, use the session user if present
      $response->getBody()->write(view('auth/form', [
        'title' => 'Sign In using '.Config::$name,
        'me' => $_SESSION['me'] ?? '',
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'state' => $state,
      ]));
      return $response;
    } else {

      // If the user-entered 'me' is the same as the one in the session, skip authentication and show a prompt
      if(isset($_SESSION['me']) && $_SESSION['me'] == $params['me']) {
        $switch_account = '/auth?'.http_build_query([
          'action' => 'logout',
          'client_id' => $client_id,
          'redirect_uri' => $redirect_uri,
          'state' => $state,
        ]);

        $code = bin2hex(random_bytes(32));
        redis()->setex('indielogin:select:'.$code, 120, json_encode($login_request));
        $_SESSION['expected_me'] = $_SESSION['me'];

        $response->getBody()->write(view('auth/prompt', [
          'title' => 'Sign In using '.Config::$name,
          'me' => $_SESSION['me'],
          'code' => $code,
          'client_id' => $client_id,
          'redirect_uri' => $redirect_uri,
          'state' => $state,
          'switch_account' => $switch_account
        ]));
        return $response;
      }

      // Otherwise, drop the session 'me' and make the user authenticate again
      unset($_SESSION['me']);

      // Verify the "me" parameter is a URL
      if(!\p3k\url\is_url($params['me'])) {
        return $this->_userError($response, 'You entered something that doesn\'t look like a URL. Please go back and try again.');
      }

      // Fetch the user's home page now
      $profile = fetch_profile($params['me']);

      $errors = [];

      // Show an error to the user if there was a problem
      if($profile['code'] != 200 ) {
        return $this->_userError($response, 'There was a problem connecting to your website', [
          'me' => $params['me'],
          'response' => $profile['exception'],
        ]);
      }

      // Store the canonical URL of the user
      $_SESSION['expected_me'] = $profile['me'];
      $login_request['me'] = $profile['me'];

      $rels = $profile['rels'];

      // If there is an IndieAuth authorization_endpoint, redirect there now
      if(count($rels['authorization_endpoint'])) {
        $authorization_endpoint = $rels['authorization_endpoint'][0];

        // Check that it's a full URL and was not a relative URL.
        if(!\p3k\url\is_url($authorization_endpoint)) {
          return $this->_userError($response, 'We found an authorization_endpoint but it does not look like a URL');
        }

        $login_request['authorization_endpoint'] = $authorization_endpoint;

        return $this->_startAuthenticate($response, $login_request, [
          'provider' => 'indieauth',
          'authorization_endpoint' => $authorization_endpoint,
        ]);
      }

      if(count($rels['authn'])) {
        // Find which of the rels are supported providers
        $supported = $this->_getSupportedProviders($rels['authn'], ($rels['pgpkey'] ?? []));

        // If there are none, then error out now since the user explicitly said not to trust rel=mes
        if(count($supported) == 0) {
          return $this->_userError($response,
            'None of the rel=authn URLs found on your page were recognized as a supported provider', [
              'found' => $rels['authn']
            ]
          );
        }

        // If there is one rel=authn, redirect now
        if(count($supported) == 1) {
          return $this->_startAuthenticate($response, $login_request, $supported[0]);
        }

        // If there is more than one rel=authn, show the chooser
        return $this->_showProviderChooser($response, $login_request, $supported);
      }

      if(count($rels['me'])) {
        $supported = $this->_getSupportedProviders($rels['me']);

        // If there are no supported rel=me, then show an error
        if(count($supported) == 0) {
          return $this->_userError($response,
            'None of the rel=me URLs found on your page were recognized as a supported provider', [
              'found' => $rels['me']
            ]
          );
        }

        // If there is one rel=me, redirect now
        if(count($supported) == 1) {
          return $this->_startAuthenticate($response, $login_request, $supported[0]);
        }

        // If there is more than one rel=me, show the chooser
        return $this->_showProviderChooser($response, $login_request, $supported);
      }

      // Show an error
      return $this->_userError($response, 'We couldn\'t find any rel=me links on your website.');
    }
  }

  public function select(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $params = $request->getQueryParams();

    if(!isset($params['code'])) {
      die('bad request');
    }

    $details = redis()->get('indielogin:select:'.$params['code']);

    if(!$details) {
      die('expired, start over');
    }

    $details = json_decode($details, true);

    return $this->_startAuthenticate($response, $details['login_request'], $details['provider']);
  }

  public function post_select(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $params = $request->getParsedBody();

    if(!isset($params['code'])) {
      die('bad request');
    }

    $details = redis()->get('indielogin:select:'.$params['code']);

    if(!$details) {
      die('expired, start over');
    }

    $details = json_decode($details, true);
    $_SESSION['login_request'] = $details;
    return $this->_finishAuthenticate($response);
  }

  public function verify(ServerRequestInterface $request, ResponseInterface $response) {
    $params = $request->getParsedBody();

    if(!isset($params['code'])) {
      die('missing code');
    }

    if(!isset($params['client_id'])) {
      die('missing client_id');
    }

    if(!isset($params['redirect_uri'])) {
      die('missing redirect_uri');
    }

    $login = redis()->get('indielogin:code:'.$params['code']);

    if(!$login) {
      die('code expired');
    }

    $login = json_decode($login, true);

    // Verify client_id and redirect_uri match
    if($params['client_id'] != $login['client_id']) {
      die('client_id mismatch');
    }

    if($params['redirect_uri'] != $login['redirect_uri']) {
      die('redirect_uri mismatch');
    }

    $response->getBody()->write(json_encode([
      'me' => $login['me']
    ]));
    return $response->withHeader('Content-type', 'application/json');
  }

  private function _showProviderChooser(&$response, $login_request, $providers) {
    $choices = [];

    // Generate a temporary code for each provider
    foreach($providers as $provider) {
      $code = bin2hex(random_bytes(32));
      $details = [
        'login_request' => $login_request,
        'provider' => $provider,
      ];
      $choices[] = [
        'code' => $code,
        'provider' => $provider,
      ];
      redis()->setex('indielogin:select:'.$code, 120, json_encode($details));
    }

    // Show the select form
    $response->getBody()->write(view('auth/select', [
      'title' => 'Authenticate',
      'me' => $login_request['me'],
      'client_id' => $login_request['client_id'],
      'redirect_uri' => $login_request['redirect_uri'],
      'choices' => $choices,
    ]));
    return $response;
  }

  private function _startAuthenticate(&$response, $login_request, $details) {
    $_SESSION['login_request'] = $login_request;

    $method = '_start_'.$details['provider'];
    return $this->{$method}($response, $login_request, $details);
  }

  private function _finishAuthenticate(&$response) {
    // Generate a temporary authorization code to store the user details
    $code = bin2hex(random_bytes(32));

    $params = [
      'code' => $code,
      'state' => $_SESSION['login_request']['state'],
    ];

    $redirect = \p3k\url\add_query_params_to_url($_SESSION['login_request']['redirect_uri'], $params);

    $_SESSION['login_request']['me'] = $_SESSION['expected_me'];

    redis()->setex('indielogin:code:'.$code, 60, json_encode($_SESSION['login_request']));

    unset($_SESSION['login_request']);
    $_SESSION['me'] = $_SESSION['expected_me'];
    unset($_SESSION['expected_me']);

    return $response->withHeader('Location', $redirect)->withStatus(302);
  }

  private function _userError(&$response, $error, $opts=[]) {
    $response->getBody()->write(view('auth/user-error', [
      'title' => 'Error',
      'error' => $error,
      'opts' => $opts
    ]));
    return $response;
  }

  private function _getSupportedProviders($rels, $pgps=[]) {
    $supported = [];

    foreach($rels as $url) {
      if(preg_match('~^https:?//(?:www\.)?(github|twitter)\.com/([a-z0-9_]+$)~', $url, $match)) {
        $supported[] = [
          'provider' => $match[1],
          'username' => $match[2],
          'display' => $match[1].'.com/'.$match[2],
        ];
      } elseif(preg_match('~^mailto:(.+)$~', $url, $match)) {
        $supported[] = [
          'provider' => 'email',
          'email' => $match[1],
          'display' => $match[1],
        ];
      } else {
        if(in_array($url, $pgps)) {
          $supported[] = [
            'provider' => 'pgp',
            'key' => $url,
            'display' => $url,
          ];
        }
      }
    }

    return $supported;
  }


}
