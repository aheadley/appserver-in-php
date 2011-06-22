<?php

namespace AiP\Middleware\Session\Storage;

class FileStorage extends AbstractStorage {
  
  protected $_handle    = null;
  
  public function open( $sessionId ) {
    $this->_ensureClosed( true );
    if( $this->_validateId( $sessionId ) ) {
      $this->_id = $sessionId;
      $this->_lock();
      if( file_exists( $this->getSessionFilename() ) ) {
        $this->_data = $this->_unserialize( file_get_contents(
          $this->getSessionFilename() ) );
        if( !is_array( $this->_data ) ) {
          $this->_data = array();
        }
      } else {
        $this->_data = array();
      }
      return $this->_id;
    } else {
      throw new UnexpectedValueException( 'Invalid session id: ' . $sessionId );
    }
  }
  
  public function isOpen() {
    return !is_null( $this->_id );
  }
  
  public function close() {
    if( $this->isOpen() ) {
      $this->_flush();
      $this->_unlock();
      $this->_id = null;
    }
  }
  
  public function read() {
    $this->_ensureOpen( true );
    return $this->_data;
  }
  
  public function write( array $sessionData ) {
    $this->_ensureOpen( true );
    $this->_data = $sessionData;
    $this->_flush();
  }
  
  public function destroy() {
    if( $this->isOpen() ) {
      $this->_unlock();
      unlink( $this->getSessionFilename() );
      $this->_id = null;
    }
  }
  
  public function gc( $maxLifeTime ) {
    array_map(
      function( $path ) {
        if( filemtime( $path ) > $maxLifeTime ) {
          unlink( $path );
        }
      },
      glob( $this->_op['save_path'] . DIRECTORY_SEPARATOR . sprintf(
        $this->_options['filename_pattern'], '*' ) )
    );
  }
  
  /**
   * Get the full path to the file containing the session data.
   *
   * @return string
   */
  public function getSessionFilename() {
    if( is_null( $this->_id ) ) {
      trigger_error( 'Session not started' );
      return null;
    } else {
      return $this->_options['save_path'] . DIRECTORY_SEPARATOR . sprintf(
        $this->_options['filename_pattern'], $this->_id );
    }
  }
  
  /**
   * Write out the session data to disk.
   */
  protected function _flush() {
    fwrite( $this->_handle, $this->_serialize( $this->_data ) );
    fflush( $this->_handle );
  }
  
  /**
   * Lock the session data file.
   */
  protected function _lock() {
    if( $this->_handle = fopen( $this->getSessionFilename(), 'c+b' ) ) {
      flock( $this->_handle, LOCK_EX );
    } else {
      throw new RuntimeException( 'Unable to open session file: ' .
        $this->getSessionFilename() );
    }
  }
  
  /**
   * Unlock the session data file.
   */
  protected function _unlock() {
    fclose( $this->_handle );
    $this->_handle = null;
  }
  
  protected function _getDefaultOptions() {
    if( !realpath( ini_get( 'session.save_path' ) ) ) {
      $savePath = '/tmp';
    } else {
      $savePath = realpath( ini_get( 'session.save_path' ) );
    }
    return array(
      'save_path'         => $savePath,
      'filename_pattern'  => 'sess_%s',
      'maxlifetime'       => ini_get( 'session.gc_maxlifetime' )
    );
  }
  
  protected function _isIdFree( $id ) {
    return !file_exists( $this->_options['save_path'] . DIRECTORY_SEPARATOR .
      sprintf( $this->_options['filename_pattern'], $id ) );
  }
}
