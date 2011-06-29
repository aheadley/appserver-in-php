<?php

namespace AiP\Middleware\HTTPParser;

class Cookies implements \ArrayAccess {

  private $_headers = array( );
  private $_cookies = array( );

  public function __construct( $cookiestr = null ) {
    if( null === $cookiestr ) {
      return;
    }

    $pairs = explode( '; ', $cookiestr );

    foreach( $pairs as $pair ) {
      list($name, $value) = explode( '=', $pair );
      $this->_cookies[$name] = urldecode( $value );
    }
  }

  public function setcookie( $name, $value, $expire = 0, $path = null,
    $domain = null, $secure = false, $httponly = false ) {
    $this->_addHeader( 'Set-Cookie',
      self::_cookieHeaderValue( $name, $value, $expire, $path, $domain, $secure,
        $httponly, false ) );
    $this->_cookies[$name] = $value;
  }

  public function setrawcookie( $name, $value, $expire = 0, $path = null,
    $domain = null, $secure = false, $httponly = false ) {
    $this->_addHeader( 'Set-Cookie',
      self::_cookieHeaderValue( $name, $value, $expire, $path, $domain, $secure,
        $httponly, true ) );
    $this->_cookies[$name] = $value;
  }

  public function __toArray() {
    return $this->_cookies;
  }

  public function offsetExists( $offset ) {
    return array_key_exists( $offset, $this->_cookies );
  }

  public function offsetGet( $offset ) {
    if( !$this->offsetExists( $offset ) ) throw new OutOfBoundsException();

    return $this->_cookies[$offset];
  }

  public function offsetSet( $offset, $value ) {
    throw new LogicException();
  }

  public function offsetUnset( $offset ) {
    throw new LogicException();
  }

  /**
   * Get the set-cookie headers
   *
   * @return array
   */
  public function getHeaders() {
    return $this->_headers;
  }

  /**
   * Add some headers as a name/value pair
   *
   * @param string $name
   * @param string $value
   */
  private function _addHeader( $name, $value ) {
    $this->_headers[] = $name;
    $this->_headers[] = $value;
  }

  /**
   * Check if a cookie name or value is valid.
   *
   * @param string $str
   * @return bool true if the string doesn't contain any invalid characters
   */
  protected static function _validateCookieString( $str ) {
    return !(bool)strpbrk( $name, "=,; \t\r\n\013\014" );
  }

  /**
   * Create the a cookie header value string. This one almost directly copies
   * php_setcookie() function from php-core
   *
   * @param type $name
   * @param type $value
   * @param type $expire
   * @param type $path
   * @param type $domain
   * @param type $secure
   * @param type $httponly
   * @return string 
   */
  private static function _cookieHeaderValue( $name, $value, $expire, $path,
    $domain, $secure, $httponly, $raw ) {
    if( !self::_validateCookieString( $name ) ) {
      throw new UnexpectedValueException( "Cookie names can not contain any of the following: '=,; \\t\\r\\n\\013\\014'" );
    }

    if( true === $raw && !self::_validateCookieString( $value ) ) {
      throw new UnexpectedValueException( "Cookie values can not contain any of the following: ',; \\t\\r\\n\\013\\014'" );
    }

    $string = $name . '=';

    if( '' == $value ) {
      // deleting
      $string .= 'deleted; expires=' . date( "D, d-M-Y H:i:s T",
          time() - 31536001 );
    } else {
      if( true === $raw ) {
        $string .= $value;
      } else {
        $string .= urlencode( $value );
      }

      if( $expire > 0 ) {
        $string .= '; expires=' . date( "D, d-M-Y H:i:s T", $expire );
      }
    }

    if( null !== $path ) $string .= '; path=' . $path;

    if( null !== $domain ) $string .= '; domain=' . $domain;

    if( true === $secure ) $string .= '; secure';

    if( true === $httponly ) $string .= '; httponly';

    return $string;
  }
}