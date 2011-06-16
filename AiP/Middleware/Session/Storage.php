<?php

namespace AiP\Middleware\Session;

interface Storage {
  /**
   * Setup the storage engine.
   * 
   * @param array $options
   */
  public function __construct(array $options);
  
  /**
   * Open the engine (prepare for session writing).
   * 
   * @param string $savePath
   * @param string $sessionName
   * @return bool
   */
  public function open( $savePath, $sessionName );
  
  /**
   * Close the engine (no more session writing?)
   * 
   * @return bool
   */
  public function close();
  
  /**
   * Read the session data for an ID
   * 
   * @param string $sessionId
   * @return string
   */
  public function read( $sessionId );
  
  /**
   * Write the session data for an ID
   * 
   * @param string $sessionId
   * @param string $sessionData
   * @return bool
   */
  public function write( $sessionId, $sessionData );
  
  /**
   * Delete the session data for an ID
   * 
   * @param string $sessionId
   * @return bool
   */
  public function destroy( $sessionId );
  
  /**
   * Collect garbage sessions (with a lifetime greater than $maxLifeTime).
   * 
   * @param int $maxLifeTime
   */
  public function gc( $maxLifeTime );
  
  /**
   * Check if a session id is available.
   * 
   * @param string $id
   * @return bool
   */
  public function isIdFree( $id );
  
  /**
   * Get a free session id for use in a new session.
   * 
   * @return string
   */
  public function getFreeId();
  
  /**
   * Check if we've been open()'d
   * 
   * @return bool
   */
  public function isOpen();
}
