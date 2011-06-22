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
  
  public function read( $id ) {
    if( !$this->_validateId( $id ) ) {
      throw new UnexpectedValueException( 'Invalid session id: ' . $id );
    } else {
      $data = $this->_read( $id );
      if( empty( $data ) ) {
        return array();
      } else {
        $data = $this->_unserialize( $data );
        if( !is_array( $data ) ) {
          trigger_error( 'Error unserializing session data for ID: ' . $id );
          return array();
        } else {
          return $data;
        }
      }
    }
  }
  
  public function write( $id, array $data ) {
    if( !$this->_validateId( $id ) ) {
      throw new UnexpectedValueException( 'Invalid session id: ' . $id );
    } else {
      $data = $this->_serialize( $data );
      if( !is_string( $data ) ) {
        trigger_error( 'Error serializing session data for ID: ' . $id );
      } elseif( !empty( $data ) ) {
        $this->_write( $id, $data );
      }
    }
  }
  
  public function create() {
    $newId = $this->_getFreeId();
    $this->read( $newId );
    return $newId;
  }
  
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
  
  abstract protected function _read( $id );
  
  abstract protected function _write( $id, $data );
  
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
    return serialize( $data );
  }
  
  /**
   * Unserialize a string created with _serialize()
   *
   * @param type $dataString 
   * @return array
   */
  protected function _unserialize( $dataString ) {
    return unserialize( $dataString );
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
}