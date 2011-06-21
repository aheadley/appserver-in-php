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
   * The session name. PHP default is PHPSESSID
   * 
   * @var string
   */
  private $_name = null;

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

  public function offsetExists( $offset ) {
    $this->_ensureStarted();
    return array_key_exists( $offset, $this->_vars );
  }
  
  public function offsetGet( $offset ) {
    $this->_ensureStarted();
    if( !array_key_exists( $offset, $this->_vars ) ) {
      $trace = debug_backtrace();
      trigger_error( sprintf( 'Undefined offset: %s in %s at line %d', $offset,
        $trace[1]['file'], $trace[1]['line'] ) );
      return null;
    } else {
      return $this->_vars[$offset];
    }
    return $this->__get( $offset );
  }
  
  public function offsetSet( $offset, $value ) {
    $this->_ensureStarted();
    $this->_vars[$offset] = $value;
  }
  
  public function offsetUnset( $offset ) {
    $this->_ensureStarted();
    unset( $this->_vars[$offset] );
  }
  
  /**
   * Test method to work around Magento dumbness.
   *
   * @param string $key
   * @return mixed
   */
  public function &getRef( $key ) {
    if( isset( $this->_vars[$key] ) ) {
      return $this->_vars[$key];
    } else {
      return null;
    }
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
  
  public function getName() {
    return $this->_name;
  }
  
  public function setName( $name ) {
    $this->_ensureNotStarted();
    $this->_name = $name;
    $this->_options['cookie_name'] = $name;
  }
  
  /**
   * Set the session options.
   *
   * @param array $options 
   */
  public function setOptions( array $options ) {
    $this->_ensureNotStarted();
    $this->_options = array_merge( $this->_getDefaultOptions(),
      $this->_options, $options );
  }
  
  /**
   * Set the backend for this session engine instance (and open it).
   *
   * @param Storage $storageEngine 
   * @throws LogicException
   */
  public function setSaveHandler( Storage $storageEngine ) {
    $this->_ensureNotStarted();
    $this->_storage = $storageEngine;
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
      if( $this->getId() !== '' ) {
        $this->_storage->open( $this->getId() );
        $this->_vars = $this->_storage->read();
      } elseif( $this->_cookieIsSet() ) {
        $this->setId( $this->_storage->open( $this->_getIdFromCookie() ) );
        $this->_vars = $this->_storage->read();
      } else {
        $this->setId( $this->_storage->create() );
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
    $this->_storage->write( $this->_vars );
    $this->_reset();
  }

  /**
   * Destroy the session and close the engine (reset).
   */
  public function destroy() {
    $this->_ensureStarted();
    $this->_storage->destroy();
    $this->_dropIdCookie();
    $this->_reset();
  }

  /**
   * Get the headers for the session id cookie.
   *
   * @return array
   */
  public function getCookieHeader() {
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
      $trace = debug_backtrace();
      throw new LogicException( sprintf( 'Session has not been started in %s on line %d',
        $trace[1]['file'], $trace[1]['line'] ) );
    }
  }
  
  /**
   * Opposite of _ensureStarted()
   * 
   * @throws LogicException
   */
  protected function _ensureNotStarted() {
    if( $this->isStarted() ) {
      $trace = debug_backtrace();
      throw new LogicException( sprintf( 'Session has already been started in %s on line %d',
        $trace[1]['file'], $trace[1]['line'] ) );
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
   * Check if a cookie name is valid.
   *
   * @param string $name
   * @return bool 
   */
  protected static function _validateCookieName( $name ) {
    return !(bool)strpbrk( $name, "=,; \t\r\n\013\014" );
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
    if( !self::_validateCookieName( $name ) ) {
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
    return new Storage\FileStorage();
  }
}
