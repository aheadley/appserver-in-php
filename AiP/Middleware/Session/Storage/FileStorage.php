<?php

namespace AiP\Middleware\Session\Storage;

class FileStorage extends AbstractStorage {
  
  protected function _read( $id ) {
    $filename = $this->_getFilename( $id );
    if( file_exists( $filename ) ) {
      $handle = fopen( $filename, 'rb' );
      flock( $handle, LOCK_SH );
      $data = file_get_contents( $filename );
      flock( $handle, LOCK_UN );
      fclose( $handle );
    } else {
      $data = '';
    }
    return $data;
  }
  
  protected function _write( $id, $data ) {
    $filename = $this->_getFilename( $id );
    if( !file_exists( $filename ) && !touch( $filename ) ) {
      throw new RuntimeException( 'Unable to create session data file: ' .
        $filename );
    }
    $handle = fopen( $filename, 'wb' );
    flock( $handle, LOCK_EX );
    file_put_contents( $filename, (string)$data );
    flock( $handle, LOCK_UN );
    fclose( $handle );
  }
  
  public function destroy( $id ) {
    if( !unlink( $this->_getFilename( $id ) ) ) {
      trigger_error( 'Unable to destroy session data for ID: ' . $id );
    }
  }
  
  public function gc( $maxLifeTime ) {
    array_map(
      function( $path ) {
        if( filemtime( $path ) > $maxLifeTime ) {
          unlink( $path );
        }
      },
      glob( $this->_options['save_path'] . DIRECTORY_SEPARATOR . sprintf(
        $this->_options['filename_pattern'], '*' ) )
    );
  }
  
  /**
   * Get the full path to the file containing the session data.
   *
   * @return string
   */
  protected function _getFilename( $id ) {
    if( !$this->_validateId( $id ) ) {
      throw new UnexpectedValueException( 'Invalid session ID: ' . $id );
    } else {
      return $this->_options['save_path'] . DIRECTORY_SEPARATOR . sprintf(
        $this->_options['filename_pattern'], $id );
    }
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
    return !file_exists( $this->_getFilename( $id ) );
  }
}
