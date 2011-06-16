<?php

namespace AiP\Middleware\Session;

class Engine implements \ArrayAccess {
  
  /**
   * Pattern used to validate session IDs
   * 
   * @var string
   */
  const PATTERN_ID = '~[A-Za-z0-9,-]+~';

  /**
   * Not sure what this is for yet.
   *
   * @var array
   */
  private $_cookies = array();
  
  /**
   * The session id cookie headers. Should be a two element array with the header
   * name as the first element and value as the second.
   *
   * @var array
   */
  private $_headers = array();
  
  /**
   * Storage engine options?
   *
   * @var array
   */
  private $_options = array();
  
  /**
   * Flag for session state.
   *
   * @var bool
   */
  private $_isStarted = false;
  
  /**
   * The current session ID.
   *
   * @var string
   */
  private $_id = null;
  
  /**
   * The actual session var storage.
   *
   * @var array
   */
  private $_vars = array();
  
  /**
   * The storage engine backing this session engine.
   *
   * @var Storage
   */
  private $_storage = null;

  /**
   * Setup this session engine with the current context.
   *
   * @param array $context 
   */
  public function __construct( array $context ) {
    if( isset( $context['env']['HTTP_COOKIE'] ) ) {
      $this->_parseCookies( $context['env']['HTTP_COOKIE'] );
    }
    $this->setOptions( array() );
  }

  /**
   * Get a session var. Triggers notice if var has not been set.
   *
   * @param int|string $offset
   * @return mixed
   */
  public function __get( $offset ) {
    $this->_ensureStarted();
    if( !array_key_exists( $offset, $this->_vars ) ) {
      trigger_error( sprintf( 'Undefined offset: %s in %s at line %d', $offset, __FILE__, __LINE__ ) );
      return null;
    } else {
      return $this->_vars[$offset];
    }
  }

  /**
   * Set a session var.
   *
   * @param int|string $offset
   * @param mixed $value
   */
  public function __set( $offset, $value ) {
    $this->_ensureStarted();
    $this->_vars[$offset] = $value;
  }

  /**
   * Check if a session var is set.
   *
   * @param int|string $offset
   * @return mixed
   */
  public function __isset( $offset ) {
    $this->_ensureStarted();
    return array_key_exists( $offset, $this->_vars );
  }

  /**
   * Unset a session var.
   *
   * @param int|string $offset 
   */
  public function __unset( $offset ) {
    $this->_ensureStarted();
    unset( $this->_vars[$offset] );
  }
  
  public function offsetExists( $offset ) {
    return $this->__isset( $offset );
  }
  
  public function offsetGet( $offset ) {
    return $this->__get( $offset );
  }
  
  public function offsetSet( $offset, $value ) {
    return $this->__set( $offset, $value );
  }
  
  public function offsetUnset( $offset ) {
    return $this->__unset( $offset );
  }
  
  /**
   * Check if the session has been started.
   *
   * @return bool
   */
  final public function isStarted() {
    return $this->_isStarted;
  }

  /**
   * Get the session ID.
   *
   * @return string
   */
  public function getId() {
    if( is_null( $this->_id ) ) {
      return '';
    } else {
      return $this->_id;
    }
  }
  
  /**
   * Set the session ID. Session must be started before calling.
   *
   * @param string $id
   */
  public function setId( $id ) {
    $this->_ensureNotStarted();
    if( $this->_validateId( $id ) ) {
      $this->_id = $id;
    } else {
      throw new UnexpectedValueException( 'Invalid session ID: ' . $id );
    }
  }
  
  /**
   * Set the session options.
   *
   * @param array $options 
   */
  public function setOptions( array $options ) {
    $this->_ensureNotStarted();
    $this->_options = array_merge( $this->_getDefaultOptions(), $options );
  }
  
  /**
   * Set the backend for this session engine instance (and open it).
   *
   * @param Storage $storageEngine 
   * @throws LogicException
   */
  public function setSaveHandler( Storage $storageEngine ) {
    $this->_ensureNotStarted();
    $this->_handler = $storageEngine;
    $this->_handler->open( $this->_options['storage_save_path'],
      $this->_options['cookie_name'] );
  }

  /**
   * Start the session, initializes everything the session engine needs to run.
   * Does nothing if the session has already been started.
   */
  public function start() {
    if( !$this->_isStarted ) {
      if( is_null( $this->_storage ) ) {
        // Save handler wasn't set, use the default.
        $this->setSaveHandler( $this->_getDefaultSaveHandler() );
      }
      if( $this->_cookieIsSet() ) {
        $this->setId( $this->_getIdFromCookie() );
        $this->_vars = unserialize( $this->_storage->read( $this->getId() ) );
      } else {
        $this->setId( $this->_storage->getFreeId() );
        $this->_createIdCookie();
      }
      $this->_isStarted = true;
    }
  }

  /**
   * Alias of writeClose()
   */
  public function commit() {
    $this->writeClose();
  }
  
  /**
   * Write the sessions out and close the engine (reset).
   */
  public function writeClose() {
    $this->_ensureStarted();
    $this->_storage->write( $this->getId(), serialize( $this->_vars ) );
    $this->_reset();
  }

  /**
   * Destroy the session and close the engine (reset).
   */
  public function destroy() {
    $this->_ensureStarted();
    $this->_storage->destroy( $this->getId() );
    $this->_dropIdCookie();
    $this->_reset();
  }

  /**
   * Get the headers for the session id cookie.
   *
   * @return array
   */
  public function getSessionCookieHeaders() {
    $this->_ensureStarted();
    return $this->_headers;
  }
  
  /**
   * Check if a session id is valid
   *
   * @param string $id
   * @return bool
   */
  protected function _validateId( $id ) {
    return (bool)preg_match( self::PATTERN_ID, $id );
  }
  
  /**
   * Check if a cookie name is valid.
   *
   * @param string $name
   * @return bool 
   */
  protected function _validateCookieName( $name ) {
    return !(bool)strpbrk( $name, "=,; \t\r\n\013\014" );
  }
  
  /**
   * Reset the session engine, used after closing a session.
   */
  private function _reset( $keepHandler = false ) {
    $this->_id = null;
    $this->_vars = array();
    if( !$keepHandler ) {
      if( !is_null( $this->_storage ) ) {
        $this->_storage->close();
      }
      $this->_storage = null;
    }
    $this->_headers = array();
    $this->_isStarted = false;
    $this->setOptions( array() );
  }

  /**
   * Convenience method to throw an exception if the session
   * hasn't been started yet.
   * 
   * @throws LogicException
   */
  protected function _ensureStarted() {
    if( !$this->isStarted() ) {
      throw new LogicException( 'Session has not been started' );
    }
  }
  
  /**
   * Opposite of _ensureStarted()
   * 
   * @throws LogicException
   */
  protected function _ensureNotStarted() {
    if( $this->isStarted() ) {
      throw new LogicException( 'Session has already been started' );
    }
  }
  
  /**
   * Parse the cookie header value into a name => value array
   *
   * @param string $cookiestr 
   */
  private function _parseCookies( $cookiestr ) {
    $pairs = explode( '; ', $cookiestr );
    $this->_cookies = array( );
    foreach( $pairs as $pair ) {
      list($name, $value) = explode( '=', $pair );
      $this->_cookies[$name] = urldecode( $value );
    }
  }

  /**
   * Check if the session id cookie was set.
   *
   * @return bool
   */
  private function _cookieIsSet() {
    return array_key_exists( $this->_options['cookie_name'], $this->_cookies );
  }

  /**
   * Get the session id from the session id cookie.
   *
   * @return string
   */
  private function _getIdFromCookie() {
    return $this->_cookies[$this->_options['cookie_name']];
  }

  /**
   * Create the session id cookie.
   */
  private function _createIdCookie() {
    $this->_setIdCookie( $this->getId(),
      $this->_options['cookie_lifetime'] === 0 ? 0 :
        $this->_options['cookie_lifetime'] + time() );
  }

  /**
   * Set the session id cookie to expire (be removed).
   */
  private function _dropIdCookie() {
    $this->_setIdCookie( '', time() - 3600 );
  }

  /**
   * Set the session id cookie data.
   *
   * @param string $value
   * @param int $expire 
   */
  private function _setIdCookie( $value, $expire ) {
    $this->_headers[] = 'Set-Cookie';
    $this->_headers[] = self::_cookieHeaderValue(
      $this->_options['cookie_name'], $value, $expire,
      $this->_options['cookie_path'],
      $this->_options['cookie_domain'], $this->_options['cookie_secure'], 
      $this->_options['cookie_httponly'] );
    $this->_cookies[$this->_options['cookie_name']] = $value;
  }

  /**
   * Create the session id cookie header value string.
   *
   * @param type $name
   * @param type $value
   * @param type $expire
   * @param type $path
   * @param type $domain
   * @param type $secure
   * @param type $httponly
   * @return string 
   */
  private static function _cookieHeaderValue( $name, $value, $expire, $path,
    $domain, $secure, $httponly ) {
    if( !$this->_validateCookieName(  $name ) ) {
      throw new UnexpectedValueException(
        "Cookie names can not contain any of the following:" .
          " '=,; \\t\\r\\n\\013\\014'" );
    }
    $headerValue = $name . '=';
    if( '' == $value ) {
      // deleting
      $headerValue .= 'deleted; expires=' . date( "D, d-M-Y H:i:s T",
          time() - 31536001 );
    } else {
      $headerValue .= urlencode( $value );
      if( $expire > 0 ) {
        $headerValue .= '; expires=' . date( "D, d-M-Y H:i:s T", $expire );
      }
    }
    if( !is_null( $path ) ) {
      $headerValue .= '; path=' . $path;
    }
    if( !is_null( $domain ) ) {
      $headerValue .= '; domain=' . $domain;
    }
    if( $secure === true ) {
      $headerValue .= '; secure';
    }
    if( $httponly === true ) {
      $headerValue .= '; httponly';
    }
    return $headerValue;
  }
  
  /**
   * Get the default session options.
   * 
   * @return array
   */
  final protected function _getDefaultOptions() {
    return array(
      // This is the name of the session ID cookie (regular PHP uses PHPSESSID).
      'cookie_name' => ini_get( 'session.name' ),
      'hash_algorithm' => 'sha1',
      'cookie_lifetime' => ini_get( 'session.cookie_lifetime' ),
      'cookie_path' => ini_get( 'session.cookie_path' ),
      'cookie_domain' => ini_get( 'session.cookie_domain' ),
      'cookie_secure' => ini_get( 'session.cookie_secure' ),
      'cookie_httponly' => ini_get( 'session.cookie_httponly' ),
    );
  }
  
  /**
   * Get an instance of the default session storage handler
   *
   * @return Storage\FileStorage 
   */
  final protected function _getDefaultSaveHandler() {
    return new Storage\FileStorage( array() );
  }
}