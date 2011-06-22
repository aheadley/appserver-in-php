<?php

namespace AiP\Middleware\Session;

interface Storage {
  /**
   * Setup the storage engine.
   * 
   * @param array $options
   */
  public function __construct( array $options );
  
  /**
   * Create a new session id and open with it.
   * 
   * @return string
   */
  public function create();
  
  /**
   * Read the session data for an ID
   * 
   * @param string $id
   * @return string
   */
  public function read( $id );
  
  /**
   * Write the session data for an ID
   * 
   * @param string $id
   * @param array $data
   * @return bool
   */
  public function write( $id, array $data );
  
  /**
   * Delete the session data for an ID
   * 
   * @param string $id
   * @return bool
   */
  public function destroy( $id );
  
  /**
   * Collect garbage sessions (with a lifetime greater than $maxLifeTime).
   * 
   * @param int $maxLifeTime
   */
  public function gc( $maxLifeTime );
}