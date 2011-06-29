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
   * Container for the SID cookie.
   *
   * @var \AiP\Middleware\HTTPParser\Cookies
   */
  private $_cookieJar = null;
  
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
  private $_data = array();
  
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
  
  private $_context = array();

  /**
   * Setup this session engine with the current context.
   *
   * @param array $context 
   */
  public function __construct( array $context ) {
    $this->_reset();
    $this->_context = $context;
    if( (bool)ini_get( 'session.auto_start' ) ) {
      $this->start();
    }
  }

  public function offsetExists( $offset ) {
    $this->_ensureStarted();
    return array_key_exists( $offset, $this->_data );
  }
  
  public function offsetGet( $offset ) {
    $this->_ensureStarted();
    if( !array_key_exists( $offset, $this->_data ) ) {
      $trace = debug_backtrace();
      trigger_error( sprintf( 'Undefined offset: %s in %s at line %d', $offset,
        $trace[1]['file'], $trace[1]['line'] ) );
      return null;
    } else {
      return $this->_data[$offset];
    }
    return $this->__get( $offset );
  }
  
  public function offsetSet( $offset, $value ) {
    $this->_ensureStarted();
    $this->_data[$offset] = $value;
  }
  
  public function offsetUnset( $offset ) {
    $this->_ensureStarted();
    unset( $this->_data[$offset] );
  }
  
  /**
   * Test method to work around Magento dumbness.
   *
   * @param string $key
   * @return mixed
   */
  public function &getRef( $key ) {
    if( isset( $this->_data[$key] ) ) {
      return $this->_data[$key];
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
   * Get the session ID. Returns an empty string if the id isn't set (consistent
   * with PHP builtin methods).
   *
   * @return string
   */
  public function getId() {
    return is_null( $this->_id ) ? '' : $this->_id;
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
  
  public function regenerateId( $deleteOld = false ) {
    $this->_ensureStarted();
    if( $deleteOld ) {
      $this->_storage->destroy();
    } else {
      $this->_storage->write( $this->_data );
      $this->_storage->close();
    }
    $this->_id = $this->_storage->create();
    $this->_storage->write( $this->_data );
    $this->_createIdCookie();
  }
  
  /**
   * Get the name of the session. This is the token that identifies the SID,
   * default is PHPSESSID
   *
   * @return string
   */
  public function getName() {
    return $this->_name;
  }
  
  /**
   * Set the SID identifying token.
   *
   * @param string $name 
   */
  public function setName( $name ) {
    $this->_ensureNotStarted();
    $this->_name = $name;
  }
  
  /**
   * Set the SID cookie options.
   *
   * @param array $options 
   */
  public function setOptions( array $options ) {
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
      $this->_parseContext( $this->_context );
      if( $this->getId() !== '' ) {
        $this->_storage->open( $this->getId() );
        $this->_data = $this->_storage->read();
      } else {
        $this->setId( $this->_storage->create() );
        //this is assuming we use cookies for the SID which is usually true but
        // doesn't have to be, should be fixed at some point to handle SID in GET
        var_dump( 'SESSION CREATED NEW' );
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
    $this->_createIdCookie();
    $this->_storage->write( $this->_data );
    $this->_storage->close();
  }

  /**
   * Destroy the session and close the engine (reset).
   */
  public function destroy() {
    $this->_ensureStarted();
    $this->_storage->destroy();
    $this->_dropIdCookie();
  }

  /**
   * Get the headers for the session id cookie.
   *
   * @return array
   */
  public function getCookieHeader() {
    return $this->_cookieJar->getHeaders();
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
  private function _reset() {
    $this->_name = ini_get( 'session.name' );
    $this->_id = null;
    $this->_data = array();
    if( !is_null( $this->_storage ) ) {
      $this->_storage->close();
    }
    $this->_storage = null;
    $this->_options = $this->_getDefaultOptions();
    $this->_cookieJar = null;
    $this->_isStarted = false;
  }

  /**
   * Convenience method to throw an exception if the session
   * hasn't been started yet.
   * 
   * @throws LogicException
   */
  final protected function _ensureStarted() {
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
  final protected function _ensureNotStarted() {
    if( $this->isStarted() ) {
      $trace = debug_backtrace();
      throw new LogicException( sprintf( 'Session has already been started in %s on line %d',
        $trace[1]['file'], $trace[1]['line'] ) );
    }
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
    $this->_cookieJar->setcookie( $this->getName(), $value, $expire,
      $this->_options['cookie_path'],
      $this->_options['cookie_domain'],
      $this->_options['cookie_secure'],
      $this->_options['cookie_httponly'] );
  }
  

  /**
   * Get an instance of the default session storage handler
   *
   * @return Storage\FileStorage 
   */
  protected function _getDefaultSaveHandler() {
    return new Storage\FileStorage();
  }
  
  /**
   * Parse the context to read in the SID if it was given to us.
   *
   * @param array $context 
   */
  protected function _parseContext( array $context ) {
    $this->_cookieJar = $context['_COOKIE'];
    if( (bool)ini_get( 'session.use_cookies' ) &&
        isset( $context['_COOKIE'] ) &&
        isset( $context['_COOKIE'][$this->getName()] ) ) {
      $this->setId( $context['_COOKIE'][$this->getName()] );
    }
    if( !(bool)ini_get( 'session.use_only_cookies' ) &&
        isset( $context['_GET'][$this->getName()] ) ) {
      $id = $context['_GET'][$this->getName()];
      //this could be different from the session id in the cookie, not sure
      // how this is normally handled
      if( $this->getId() != '' &&
          $this->getId() != $id ) {
        //the ids are different, this is probably bad
        throw new UnexpectedValueException( 'Cookie SID differs from GET SID' );
      } else {
        $this->setId( $id );
      }
    }
  }
  
  /**
   * Get the default session options.
   * 
   * @return array
   */
  final protected function _getDefaultOptions() {
    return array(
      'cookie_path' => ini_get( 'session.cookie_path' ),
      'cookie_lifetime' => ini_get( 'session.cookie_lifetime' ),
      'cookie_domain' => ini_get( 'session.cookie_domain' ),
      'cookie_secure' => ini_get( 'session.cookie_secure' ),
      'cookie_httponly' => ini_get( 'session.cookie_httponly' ),
    );
  }
}
