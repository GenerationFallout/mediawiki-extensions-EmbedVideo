<?php
/**
 * EmbedVideo
 * EmbedVideo OEmbed Class
 *
 * @license MIT
 * @package EmbedVideo
 * @link    https://www.mediawiki.org/wiki/Extension:EmbedVideo
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\EmbedVideo;

use JsonException;
use MediaWiki\MediaWikiServices;
use MWException;

class OEmbed {
	/**
	 * Data from oEmbed service.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Main Constructor
	 *
	 * @private
	 * @param  array	Data return from oEmbed service.
	 * @return void
	 */
	private function __construct( $data ) {
		$this->data = $data;
	}

	/**
	 * Create a new object from an oEmbed URL.
	 *
	 * @access public
	 * @param  string	Full oEmbed URL to process.
	 * @return false|OEmbed New OEmbed object or false on initialization failure.
	 */
	public static function newFromRequest( $url ) {
		$data = self::get( $url );

		if ( $data !== false ) {
			try {
				$data = json_decode( $data, true, 512, JSON_THROW_ON_ERROR );
			} catch ( JsonException $e ) {
				return false;
			}
		}

		if ( !$data || !is_array( $data ) ) {
			return false;
		}

		return new self( $data );
	}

	/**
	 * Return the HTML from the data, typically an iframe.
	 *
	 * @access public
	 * @return mixed String HTML or false on error.
	 */
	public function getHtml() {
		if ( !isset( $this->data['html'] ) ) {
			return false;
		}

		// Remove any extra HTML besides the iframe.
		$iframeStart = strpos( $this->data['html'], '<iframe' );
		$iframeEnd = strpos( $this->data['html'], '</iframe>' );
		if ( $iframeStart !== false ) {
			// Only strip if an iframe was found.
			$this->data['html'] = substr( $this->data['html'], $iframeStart, $iframeEnd + 9 );
		}

		return $this->data['html'];
	}

	/**
	 * Return the title from the data.
	 *
	 * @access public
	 * @return mixed String or false on error.
	 */
	public function getTitle() {
		return $this->data['title'] ?? false;
	}

	/**
	 * Return the author name from the data.
	 *
	 * @access public
	 * @return mixed String or false on error.
	 */
	public function getAuthorName() {
		return $this->data['author_name'] ?? false;
	}

	/**
	 * Return the author URL from the data.
	 *
	 * @access public
	 * @return mixed String or false on error.
	 */
	public function getAuthorUrl() {
		return $this->data['author_url'] ?? false;
	}

	/**
	 * Return the provider name from the data.
	 *
	 * @access public
	 * @return mixed String or false on error.
	 */
	public function getProviderName() {
		return $this->data['provider_name'] ?? false;
	}

	/**
	 * Return the provider URL from the data.
	 *
	 * @access public
	 * @return mixed String or false on error.
	 */
	public function getProviderUrl() {
		return $this->data['provider_url'] ?? false;
	}

	/**
	 * Return the width from the data.
	 *
	 * @access public
	 * @return false|int Integer or false on error.
	 */
	public function getWidth() {
		if ( isset( $this->data['width'] ) ) {
			return (int)$this->data['width'];
		}

		return false;
	}

	/**
	 * Return the height from the data.
	 *
	 * @access public
	 * @return false|int Integer or false on error.
	 */
	public function getHeight() {
		if ( isset( $this->data['height'] ) ) {
			return (int)$this->data['height'];
		}

		return false;
	}

	/**
	 * Return the thumbnail width from the data.
	 *
	 * @access public
	 * @return false|int Integer or false on error.
	 */
	public function getThumbnailWidth() {
		if ( isset( $this->data['thumbnail_width'] ) ) {
			return (int)$this->data['thumbnail_width'];
		}

		return false;
	}

	/**
	 * Return the thumbnail height from the data.
	 *
	 * @access public
	 * @return false|int Integer or false on error.
	 */
	public function getThumbnailHeight() {
		if ( isset( $this->data['thumbnail_height'] ) ) {
			return (int)$this->data['thumbnail_height'];
		}

		return false;
	}

	/**
	 * Perform a Curl GET request.
	 *
	 * @private
	 * @param  string	URL
	 * @return bool|string
	 */
	private static function get( $location ) {
		$timeout = 10;

		$req = MediaWikiServices::getInstance()->getHttpRequestFactory()->create( $location, [
			'timeout' => $timeout,
			'connectTimeout' => $timeout,
			'userAgent' => sprintf( 'EmbedVideo/1.0/%s', MediaWikiServices::getInstance()->getMainConfig()->get( 'Server' ) ),
			'followRedirects' => true,
			'maxRedirects' => 10,
		] );

		$req->setHeader( 'Date', gmdate( "D, d M Y H:i:s", time() ) . " GMT" );

		try {
			$status = $req->execute();

			if ( !$status->isOK() ) {
				return false;
			}

			return $req->getContent();
		} catch ( MWException $e ) {
			wfLogWarning( $e->getMessage() );

			return false;
		}
	}
}
