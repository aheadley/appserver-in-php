<?php

namespace AiP\Middleware\Session\Storage;

class FileStorage extends AbstractStorage {
  
  const CHUNK_SIZE      = 8192;
  
  protected $_handle    = null;
  
  public function open( $sessionId ) {
    $this->_ensureClosed( true );
    if( $this->_validateId( $sessionId ) ) {
      $this->_id = $sessionId;
      $this->_lock();
      if( file_exists( $this->getSessionFilename() ) ) {
        $this->_data = $this->_unserialize( $this->_read() );
        if( !is_array( $this->_data ) ) {
          $this->_data = array();
        }
      } else {
        throw new RuntimeException( 'Error creating session data file' );
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
  
  protected function _read() {
    $data = '';
    while( !feof( $this->_handle ) ) {
      $data .= fread( $this->_handle, self::CHUNK_SIZE );
    }
    rewind( $this->_handle );
    return $data;
  }
  
  public function write( array $sessionData ) {
    $this->_ensureOpen( true );
    $this->_data = $sessionData;
    $this->_flush();
  }
  
  protected function _write( $data ) {
    if( rewind( $this->_handle ) &&
        ftruncate( $this->_handle, 0 ) &&
        fwrite( $this->_handle, $data ) ) {
      return null;
    } else {
      throw new RuntimeException( 'Error writing to session file' );
    }
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
    $this->_write( $this->_serialize( $this->_data ) );
    fflush( $this->_handle );
  }
  
  /**
   * Lock the session data file.
   */
  protected function _lock() {
    if( $this->_handle = fopen( $this->getSessionFilename(), 'c+b' ) ) {
      flock( $this->_handle, LOCK_SH );
    } else {
      throw new RuntimeException( 'Unable to open session file: ' .
        $this->getSessionFilename() );
    }
  }
  
  /**
   * Unlock the session data file.
   */
  protected function _unlock() {
    flock( $this->_handle, LOCK_UN );
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