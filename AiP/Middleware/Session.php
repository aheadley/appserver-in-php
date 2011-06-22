<?php

namespace AiP\Middleware;

class Session {
  
  /**
   * The array key in the context that we get stored in.
   * 
   * @var string
   */
  const CONTEXT_KEY = '_SESSION';

  /**
   * The running app.
   *
   * @var object
   */
  private $_app     = null;

  public function __construct( $app ) {
    if( !is_callable( $app ) ) {
      throw new InvalidApplicationException( 'invalid app supplied' );
    } else {
      $this->_app = $app;
    }
  }

  public function __invoke( $context ) {
    if( isset( $context[self::CONTEXT_KEY] ) ) {
      throw new Session\LogicException( 'Context key is already occupied in context' );
    }
    $session = $context[self::CONTEXT_KEY] = new Session\Engine( $context );
    $result = call_user_func( $this->_app, $context );
    try {
      $result[1] = array_merge( $result[1], $session->getCookieHeader() );
      $session->writeClose();
    } catch( \AiP\Middleware\Session\LogicException $e ) {/* don't care */}
    return $result;
  }
}