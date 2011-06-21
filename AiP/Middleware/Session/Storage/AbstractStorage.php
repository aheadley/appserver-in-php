<?php

/**
 * Nexcess.net Magento Daemon
 *
 * <pre>
 * +----------------------------------------------------------------------+
 * | Nexcess.net Magento Daemon                                           |
 * +----------------------------------------------------------------------+
 * | Copyright (c) 2006-2011 Nexcess.net L.L.C., All Rights Reserved.     |
 * +----------------------------------------------------------------------+
 * | Redistribution and use in source form, with or without modification  |
 * | is NOT permitted without consent from the copyright holder.          |
 * |                                                                      |
 * | THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS "AS IS" AND |
 * | ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,    |
 * | THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A          |
 * | PARTICULAR PURPOSE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,    |
 * | EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,  |
 * | PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR   |
 * | PROFITS; OF BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY  |
 * | OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT         |
 * | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE    |
 * | USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH     |
 * | DAMAGE.                                                              |
 * +----------------------------------------------------------------------+
 * </pre>
 */

namespace AiP\Middleware\Session\Storage;

abstract class AbstractStorage implements \AiP\Middleware\Session\Storage {
  
  protected $_data        = array();
  protected $_id          = null;
  protected $_options     = null;
  
  /**
   * Initialize the storage engine with a combination of the default and
   * user-supplied options.
   *
   * @param array $options 
   */
  public function __construct( array $options = array() ) {
    $this->_options = array_merge( $this->_getDefaultOptions(), $options );
  }
  
  /**
   * Need to make sure the session data gets saved.
   */
  public function __destruct() {
    $this->close();
  }
  
  public function create() {
    return $this->open( $this->_getFreeId() );
  }
  
  abstract public function open( $sessionId );
  abstract public function isOpen();
  abstract public function close();
  abstract public function read();
  abstract public function write( $sessionData );
  abstract public function destroy();
  abstract public function gc( $maxLifeTime );
  
  /**
   * Get the default options of this storage method as an array.
   * 
   * @return array
   */
  abstract protected function _getDefaultOptions();
  
  /**
   * Check if an ID is available (not used).
   * 
   * @param string $id
   * @return bool
   */
  abstract protected function _isIdFree( $id );
  
  /**
   * Make sure a session id is valid
   *
   * @param string $id
   * @return bool
   */
  protected function _validateId( $id ) {
    return (bool)preg_match( \AiP\Middleware\Session\Engine::PATTERN_ID, $id );
  }
  
  /**
   * Serialize an array in the format that PHP normally uses for sessions (not
   * exactly the same as serialize()).
   *
   * @param array $data
   * @return string 
   */
  protected function _serialize( array $data ) {
    $oldSession = $_SESSION;
    $_SESSION = $data;
    $dataString = session_encode();
    $_SESSION = $oldSession;
    return $dataString;
  }
  
  /**
   * Unserialize a string created with _serialize()
   *
   * @param type $dataString 
   * @return array
   */
  protected function _unserialize( $dataString ) {
    $oldSession = $_SESSION;
    $_SESSION = array(); //make sure we have a clean slate
    if( !session_decode( $dataString ) ) {
      trigger_error( sprintf( 'Unable to unserialize string in %s on line %d',
        __FILE__, __LINE__ ) );
      return array();
    } else {
      $data = $_SESSION;
      $_SESSION = $oldSession;
      return $data;
    }
  }
  
  /**
   * Generate an ID, not checked for uniqueness but should probably be unique.
   * Combine with _isIdFree() to be sure.
   *
   * @return string
   */
  protected function _generateId() {
    return md5( uniqid( php_uname( 'n' ), true ) . microtime() .
      $_SERVER['REMOTE_ADDR'] . $_SERVER['REMOTE_PORT'] .
      $_SERVER['HTTP_USER_AGENT'] );
  }
  
  /**
   * Combine _generateId() and _isIdFree() until we get a free id.
   *
   * @return string
   */
  protected function _getFreeId() {
    while( $id = $this->_generateId() ) {
      if( $this->_isIdFree( $id ) ) {
        return $id;
      }
    }
  }
  
  /**
   * Check that the session has already been started, optionally throw
   * an exception if it hasn't, otherwise just trigger an error message.
   *
   * @param bool $throwException 
   * @throws LogicException
   */
  final protected function _ensureOpen( $throwException = false ) {
    if( !$this->isOpen() ) {
      if( $throwException ) {
        throw new LogicException( 'Session not started' );
      } else {
        trigger_error( 'Session not started' );
      }
    }
  }
  
  /**
   * Check that the session hasn't already been started, optionally throw
   * an exception if it has, otherwise just trigger an error message.
   *
   * @param bool $throwException 
   * @throws LogicException
   */
  final protected function _ensureClosed( $throwException = false ) {
    if( $this->isOpen() ) {
      if( $throwException ) {
        throw new LogicException( 'Session already started' );
      } else {
        trigger_error( 'Session already started' );
      }
    }
  }
}