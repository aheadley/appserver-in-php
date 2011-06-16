<?php

namespace AiP\Middleware\Session;

class Engine implements ArrayAccess {

  private $_cookies = array( );
  private $_headers = array( );
  private $_options;
  private $_isStarted = false;
  private $_isSaved = false;
  private $_id = null;
  private $_vars = array( );
  private $_storage = null;

  public function __construct( $context ) {
    if( isset( $context['env']['HTTP_COOKIE'] ) )
        $this->parseCookies( $context['env']['HTTP_COOKIE'] );
  }

  public function __get( $varname ) {
    if( false === $this->_isStarted )
        throw new LogicException( 'Session is not started' );

    if( !array_key_exists( $varname, $this->_vars ) )
        throw new OutOfBoundsException( 'there is no "' . $varname . '" var in session' );

    return $this->_vars[$varname];
  }

  public function __set( $varname, $value ) {
    if( false === $this->_isStarted )
        throw new LogicException( 'Session is not started' );

    $this->_vars[$varname] = $value;
  }

  public function __isset( $varname ) {
    if( false === $this->_isStarted )
        throw new LogicException( 'Session is not started' );

    return array_key_exists( $varname, $this->_vars );
  }

  public function __unset( $varname ) {
    if( false === $this->_isStarted )
        throw new LogicException( 'Session is not started' );

    unset( $this->_vars[$varname] );
  }
  
  public function offsetExists( $offset ) {
    return $this->__isset( $offset );
  }
  
  public function offsetGet( $offset ) {
    try {
      return $this->__get( $offset );
    } catch( OutOfBoundsException $e ) {
      return null;
    }
  }
  
  public function offsetSet( $offset, $value ) {
    return $this->__set( $offset, $value );
  }
  
  public function offsetUnset( $offset ) {
    return $this->__unset( $offset );
  }

  public function getId() {
    if( false === $this->_isStarted )
        throw new LogicException( 'Session is not started' );

    return $this->_id;
  }

  public function start( array $options = array( ) ) {
    if( true === $this->_isStarted )
        throw new LogicException( 'Session is already started' );

    $this->_options = array_merge(
      array(
      'cookie_name' => ini_get( 'session.name' ),
      'hash_algorithm' => 'sha1',
      'storage' => __NAMESPACE__ . '\Storage\FileStorage',
      'cookie_lifetime' => ini_get( 'session.cookie_lifetime' ),
      'cookie_path' => ini_get( 'session.cookie_path' ),
      'cookie_domain' => ini_get( 'session.cookie_domain' ),
      'cookie_secure' => ini_get( 'session.cookie_secure' ),
      'cookie_httponly' => ini_get( 'session.cookie_httponly' ),
      ), $options
    );

    $class = $this->_options['storage'];

    if( !in_array( __NAMESPACE__ . '\\Storage', class_implements( $class ) ) ) {
      throw new UnexpectedValueException( $storage . ' class does not implement Storage interface' );
    }

    $this->_storage = new $class( $this->_options );

    if( $this->cookieIsSet() ) {
      $this->fetchIdFromCookie();

      $this->_vars = $this->_storage->open( $this->_id );
    } else {
      $this->createSessionWithNewId();
      $this->createCookie();
    }

    $this->_isStarted = true;
  }

  public function save() {
    if( false === $this->_isStarted )
        throw new LogicException( 'Session is not started' );

    $this->_storage->save( $this->_vars );
    $this->_storage = null;

    $this->_vars = array( );
    $this->_isStarted = false;
  }

  public function destroy() {
    if( false === $this->_isStarted )
        throw new LogicException( 'Session is not started' );

    $this->_storage->destroy();
    $this->_storage = null;

    $this->dropCookie();
    $this->_id = null;

    $this->_vars = array( );
    $this->_isStarted = false;
  }

  private function createSessionWithNewId() {
    $callback = array( $this->_options['storage'], 'idIsFree' );

    while( true ) {
      $id = hash( $this->_options['hash_algorithm'], mt_rand() );

      try {
        $this->_storage->create( $id );
        break; // cool, we're first here
      } catch( IdIsTakenException $e ) {
        
      }
    }

    $this->_id = $id;
  }

  private function fetchIdFromCookie() {
    $this->_id = $this->getIdFromCookie();
  }

  // cookie stuff
  private function parseCookies( $cookiestr ) {
    $pairs = explode( '; ', $cookiestr );

    $this->_cookies = array( );

    foreach( $pairs as $pair ) {
      list($name, $value) = explode( '=', $pair );
      $this->_cookies[$name] = urldecode( $value );
    }
  }

  private function cookieIsSet() {
    $name = $this->_options['cookie_name'];
    return isset( $this->_cookies[$name] );
  }

  private function getIdFromCookie() {
    $name = $this->_options['cookie_name'];
    return $this->_cookies[$name];
  }

  private function createCookie() {
    $lifetime = $this->_options['cookie_lifetime'] === 0 ? 0 : $this->_options['cookie_lifetime'] + time();

    $this->setcookie( $this->_id, $lifetime );
  }

  private function dropCookie() {
    $this->setcookie( '', time() - 3600 );
  }

  // low-level stuff
  private function setcookie( $value, $expire ) {
    $name = $this->_options['cookie_name'];

    $this->_headers[] = 'Set-Cookie';
    $this->_headers[] = self::cookie_headervalue(
        $name, $value, $expire, $this->_options['cookie_path'],
        $this->_options['cookie_domain'], $this->_options['cookie_secure'],
        $this->_options['cookie_httponly']
    );

    $this->_cookies[$name] = $value;
  }

  private static function cookie_headervalue( $name, $value, $expire, $path,
    $domain, $secure, $httponly ) {
    if( false !== strpbrk( $name, "=,; \t\r\n\013\014" ) ) {
      throw new UnexpectedValueException( "Cookie names can not contain any of the following: '=,; \\t\\r\\n\\013\\014'" );
    }

    $string = $name . '=';

    if( '' == $value ) {
      // deleting
      $string .= 'deleted; expires=' . date( "D, d-M-Y H:i:s T",
          time() - 31536001 );
    } else {
      $string .= urlencode( $value );

      if( $expire > 0 ) {
        $string .= '; expires=' . date( "D, d-M-Y H:i:s T", $expire );
      }
    }

    if( null !== $path ) $string .= '; path=' . $path;

    if( null !== $domain ) $string .= '; domain=' . $domain;

    if( true === $secure ) $string .= '; secure';

    if( true === $httponly ) $string .= '; httponly';

    return $string;
  }

  public function _getHeaders() {
    return $this->_headers;
  }
}