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
   * Open the engine (prepare for session writing). Returns the session id it
   * opened.
   * 
   * @param string $sessionId
   * @return string
   */
  public function open( $sessionId );
  
  /**
   * Close the engine (no more session writing?)
   * 
   * @return bool
   */
  public function close();
  
  /**
   * Read the session data for an ID
   * 
   * @return string
   */
  public function read();
  
  /**
   * Write the session data for an ID
   * 
   * @param array $sessionData
   * @return bool
   */
  public function write( array $sessionData );
  
  /**
   * Delete the session data for an ID
   * 
   * @return bool
   */
  public function destroy();
  
  /**
   * Collect garbage sessions (with a lifetime greater than $maxLifeTime).
   * 
   * @param int $maxLifeTime
   */
  public function gc( $maxLifeTime );
  
  /**
   * Check if we've been open()'d
   * 
   * @return bool
   */
  public function isOpen();
}
