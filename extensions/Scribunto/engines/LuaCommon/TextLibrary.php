<?php

// @codingStandardsIgnoreLine Squiz.Classes.ValidClassName.NotCamelCaps
class Scribunto_LuaTextLibrary extends Scribunto_LuaLibraryBase {
	// Matches Lua mw.text constants
	const JSON_PRESERVE_KEYS = 1;
	const JSON_TRY_FIXING = 2;
	const JSON_PRETTY = 4;

	function register() {
		global $wgUrlProtocols;

		$lib = array(
			'unstrip' => array( $this, 'textUnstrip' ),
			'unstripNoWiki' => array( $this, 'textUnstripNoWiki' ),
			'killMarkers' => array( $this, 'killMarkers' ),
			'getEntityTable' => array( $this, 'getEntityTable' ),
			'jsonEncode' => array( $this, 'jsonEncode' ),
			'jsonDecode' => array( $this, 'jsonDecode' ),
		);
		$opts = array(
			'comma' => wfMessage( 'comma-separator' )->inContentLanguage()->text(),
			'and' => wfMessage( 'and' )->inContentLanguage()->text() .
				wfMessage( 'word-separator' )->inContentLanguage()->text(),
			'ellipsis' => wfMessage( 'ellipsis' )->inContentLanguage()->text(),
			'nowiki_protocols' => array(),
		);

		foreach ( $wgUrlProtocols as $prot ) {
			if ( substr( $prot, -1 ) === ':' ) {
				// To convert the protocol into a case-insensitive Lua pattern,
				// we need to replace letters with a character class like [Xx]
				// and insert a '%' before various punctuation.
				$prot = preg_replace_callback( '/([a-zA-Z])|([()^$%.\[\]*+?-])/', function ( $m ) {
					if ( $m[1] ) {
						return '[' . strtoupper( $m[1] ) . strtolower( $m[1] ) . ']';
					} else {
						return '%' . $m[2];
					}
				}, substr( $prot, 0, -1 ) );
				$opts['nowiki_protocols']["($prot):"] = '%1&#58;';
			}
		}

		return $this->getEngine()->registerInterface( 'mw.text.lua', $lib, $opts );
	}

	function textUnstrip( $s ) {
		$this->checkType( 'unstrip', 1, $s, 'string' );
		$stripState = $this->getParser()->mStripState;
		return array( $stripState->killMarkers( $stripState->unstripNoWiki( $s ) ) );
	}

	function textUnstripNoWiki( $s ) {
		$this->checkType( 'unstripNoWiki', 1, $s, 'string' );
		return array( $this->getParser()->mStripState->unstripNoWiki( $s ) );
	}

	function killMarkers( $s ) {
		$this->checkType( 'killMarkers', 1, $s, 'string' );
		return array( $this->getParser()->mStripState->killMarkers( $s ) );
	}

	function getEntityTable() {
		$flags = ENT_QUOTES;
		// PHP 5.3 compat
		if ( defined( "ENT_HTML5" ) ) {
			$flags |= constant( "ENT_HTML5" );
		}
		$table = array_flip( get_html_translation_table( HTML_ENTITIES, $flags, "UTF-8" ) );
		return array( $table );
	}

	public function jsonEncode( $value, $flags ) {
		$this->checkTypeOptional( 'mw.text.jsonEncode', 2, $flags, 'number', 0 );
		$flags = (int)$flags;
		if ( !( $flags & self::JSON_PRESERVE_KEYS ) && is_array( $value ) ) {
			$value = self::reindexArrays( $value, true );
		}
		$ret = FormatJson::encode( $value, (bool)( $flags & self::JSON_PRETTY ), FormatJson::ALL_OK );
		if ( $ret === false ) {
			throw new Scribunto_LuaError( 'mw.text.jsonEncode: Unable to encode value' );
		}
		return array( $ret );
	}

	public function jsonDecode( $s, $flags ) {
		$this->checkType( 'mw.text.jsonDecode', 1, $s, 'string' );
		$this->checkTypeOptional( 'mw.text.jsonDecode', 2, $flags, 'number', 0 );
		$flags = (int)$flags;
		$opts = FormatJson::FORCE_ASSOC;
		if ( $flags & self::JSON_TRY_FIXING ) {
			$opts |= FormatJson::TRY_FIXING;
		}
		$status = FormatJson::parse( $s, $opts );
		if ( !$status->isOk() ) {
			throw new Scribunto_LuaError( 'mw.text.jsonDecode: ' . $status->getMessage()->text() );
		}
		$val = $status->getValue();
		if ( !( $flags & self::JSON_PRESERVE_KEYS ) && is_array( $val ) ) {
			$val = self::reindexArrays( $val, false );
		}
		return array( $val );
	}

	/** Recursively reindex array with integer keys to 0-based or 1-based
	 * @param array $arr
	 * @param bool $isEncoding
	 * @return array
	 */
	private static function reindexArrays( array $arr, $isEncoding ) {
		if ( $isEncoding ) {
			ksort( $arr, SORT_NUMERIC );
			$next = 1;
		} else {
			$next = 0;
		}
		$isSequence = true;
		foreach ( $arr as $k => &$v ) {
			if ( is_array( $v ) ) {
				$v = self::reindexArrays( $v, $isEncoding );
			}

			if ( $isSequence ) {
				if ( is_int( $k ) ) {
					$isSequence = $next++ === $k;
				} elseif ( $isEncoding && ctype_digit( $k ) ) {
					// json_decode currently doesn't return integer keys for {}
					$isSequence = $next++ === (int)$k;
				} else {
					$isSequence = false;
				}
			}
		}
		if ( $isSequence ) {
			if ( $isEncoding ) {
				return array_values( $arr );
			} else {
				return $arr ? array_combine( range( 1, count( $arr ) ), $arr ) : $arr;
			}
		}
		return $arr;
	}

}
