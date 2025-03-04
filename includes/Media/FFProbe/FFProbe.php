<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\EmbedVideo\Media\FFProbe;

use ConfigException;
use File;
use JsonException;
use MediaWiki\MediaWikiServices;

class FFProbe {
	/**
	 * MediaWiki File
	 *
	 * @var File
	 */
	private $file;

	/**
	 * Meta Data Cache
	 *
	 * @var array
	 */
	private $metadata;

	/**
	 * Main Constructor
	 *
	 * @access public
	 * @param  \FSFile MediaWiki File
	 * @return void
	 */
	public function __construct( $file ) {
		$this->file = $file;
	}

	/**
	 * Return the entire cache of meta data.
	 *
	 * @access public
	 * @return array Meta Data
	 */
	public function getMetaData(): array {
		if ( !is_array( $this->metadata ) ) {
			$this->invokeFFProbe();
		}
		return $this->metadata;
	}

	/**
	 * Get a selected stream.  Follows ffmpeg's stream selection style.
	 *
	 * @access public
	 * @param  string	Stream identifier
	 * Examples:
	 *		"v:0" - Select the first video stream
	 * 		"a:1" - Second audio stream
	 * 		"i:0" - First stream, whatever it is.
	 * 		"s:2" - Third subtitle
	 * 		"d:0" - First generic data stream
	 * 		"t:1" - Second attachment
	 * @return false|StreamInfo StreamInfo object or false if does not exist.
	 */
	public function getStream( $select ) {
		$this->getMetaData();

		$types = [
			'v'	=> 'video',
			'a'	=> 'audio',
			'i'	=> false,
			's'	=> 'subtitle',
			'd'	=> 'data',
			't'	=> 'attachment'
		];

		if ( !isset( $this->metadata['streams'] ) ) {
			return false;
		}

		[ $type, $index ] = explode( ":", $select );
		$index = (int)$index;

		$type = ( $types[$type] ?? false );

		$i = 0;
		foreach ( $this->metadata['streams'] as $stream ) {
			if ( $type !== false && isset( $stream['codec_type'] ) ) {
				if ( $index === $i && $stream['codec_type'] === $type ) {
					return new StreamInfo( $stream );
				}
			}
			if ( $type === false || $stream['codec_type'] === $type ) {
				$i++;
			}
		}
		return false;
	}

	/**
	 * Get the FormatInfo object.
	 *
	 * @access public
	 * @return false|FormatInfo FormatInfo object or false if does not exist.
	 */
	public function getFormat() {
		$this->getMetaData();

		if ( !isset( $this->metadata['format'] ) ) {
			return false;
		}

		return new FormatInfo( $this->metadata['format'] );
	}

	private function getFilePath() {
		return $this->file->getPath();
	}

	/**
	 * Invoke ffprobe on the command line.
	 *
	 * @private
	 * @return bool Success
	 */
	private function invokeFFProbe() {
		try {
			$ffprobeLocation = MediaWikiServices::getInstance()->getMainConfig()->get( 'FFProbeLocation' );
		} catch ( ConfigException $e ) {
			return false;
		}

		if ( !file_exists( $ffprobeLocation ) ) {
			$this->metadata = [];
			return false;
		}

		$json = shell_exec(
			escapeshellcmd(
				sprintf(
					'%s -v quiet -print_format json -show_format -show_streams %s',
					$ffprobeLocation,
					escapeshellarg( $this->getFilePath() )
				)
			)
		);

		try {
			$metadata = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			$this->metadata = [];
			return false;
		}

		if ( is_array( $metadata ) ) {
			$this->metadata = $metadata;
		} else {
			$this->metadata = [];
			return false;
		}

		return true;
	}
}
