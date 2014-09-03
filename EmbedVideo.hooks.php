<?php
/**
 * EmbedVideo
 * EmbedVideo Hooks
 *
 * @license		MIT
 * @package		EmbedVideo
 * @link		https://www.mediawiki.org/wiki/Extension:EmbedVideo
 *
 **/

class EmbedVideoHooks {
    /**
     * Hooks Initialized
     *
     * @var		boolean
     */
	static private $initialized = false;
	
    /**
     * Sets up this extension's parser functions.
     *
     * @access	public
     * @param	object	Parser object passed as a reference.
     * @return	boolean	true
     */
    static public function onParserFirstCallInit(Parser &$parser) {
		global $wgVersion;

		$parser->setFunctionHook("ev", "EmbedVideo::parseEV");
		$parser->setFunctionHook("evp", "EmbedVideo::parseEVP");

		return true;
	}
	
	/**
	 * Adapter to call the new style tag.
	 *
	 * @access	public
	 * @param	object	Parser
	 * @param	string	[Optional] Which online service has the video.
	 * @param	string	[Optional] Identifier of the chosen service
	 * @param	string	[Optional] Description to show
	 * @param	string	[Optional] Alignment of the video
	 * @param	string	[Optional] Width of video
	 * @return	string	Encoded representation of input params (to be processed later)
	 */
	static public function parseEV($parser, $service = null, $id = null, $desc = null, $align = null, $width = null) {
		return self::parserFunction_ev($parser, $service, $id, $width, $align, $desc);
	}
	
	/**
	 * Embeds video of the chosen service
	 *
	 * @access	public
	 * @param	object	Parser
	 * @param	string	[Optional] Which online service has the video.
	 * @param	string	[Optional] Identifier of the chosen service
	 * @param	string	[Optional] Width of video
	 * @param	string	[Optional] Description to show
	 * @param	string	[Optional] Alignment of the video
	 * @return	string	Encoded representation of input params (to be processed later)
	 */
	static public function parseEV($parser, $service = null, $id = null, $width = null, $align = null, $desc = null) {
		global $wgScriptPath;
		
		// Initialize things once
		if (!self::$initialized) {
			self::VerifyWidthMinAndMax();
			self::$initialized = true;
		}
		
		// Get the name of the host
		if ($service === null || $id === null) {
			return self::errMissingParams($service, $id);
		}
		
		$service = trim($service);
		$id      = trim($id);
		$desc    = $parser->recursiveTagParse($desc);
		
		$entry = self::getServiceEntry($service);
		if (!$entry) {
			return self::errBadService($service);
		}
		
		if (!self::sanitizeWidth($entry, $width)) {
			return self::errBadWidth($width);
		}
		$height = self::getHeight($entry, $width);
		
		$hasalign = ($align !== null || $align == 'auto');
		
		if ($hasalign) {
			$align = trim($align);
			if (!self::validateAlignment($align)) {
				return self::errBadAlignment($align);
			}
			$desc = self::getDescriptionMarkup($desc);
		}
		
		// If the service has an ID pattern specified, verify the id number
		if (!self::verifyID($entry, $id)) {
			return self::errBadID($service, $id);
		}
		$url = null;
		// If service is Yandex -> use own parser
		if ($service == 'yandex' || $service == 'yandexvideo') {
			$url = self::getYandex($id);
			$url = htmlspecialchars_decode($url);
		}
		// if the service has it's own custom extern declaration, use that instead
		if (array_key_exists('extern', $entry) && ($clause = $entry['extern']) != NULL) {
			if ($service == 'screen9') {
				$clause = self::parseScreen9Id($id, $width, $height);
				if ($clause == null) {
					return self::errBadScreen9Id();
				}
			} else {
				$clause = wfMsgReplaceArgs($clause, array(
					$wgScriptPath,
					$id,
					$width,
					$height,
					$url
				));
			}
			if ($hasalign) {
				$clause = self::generateAlignExternClause($clause, $align, $desc, $width, $height);
			}
			return array(
				$clause,
				'noparse' => true,
				'isHTML' => true
			);
		}
		
		// Build URL and output embedded flash object
		$url    = wfMsgReplaceArgs($entry['url'], array(
			$id,
			$width,
			$height
		));
		$clause = "";
		// If service is RuTube -> use own parser
		if ($service == 'rutube') {
			$url = self::getRuTube($id);
		}
		if ($hasalign) {
			$clause = self::generateAlignClause($url, $width, $height, $align, $desc);
		} else {
			$clause = self::generateNormalClause($url, $width, $height);
		}
		return array(
			$clause,
			'noparse' => true,
			'isHTML' => true
		);
	}
	
	/**
	 * Return the HTML necessary to embed the video normally.
	 *
	 * @access	private
	 * @param	string	URL
	 * @param	integer	Width
	 * @param	integer	Height
	 * @return string
	 */
	static private function generateNormalClause($url, $width, $height) {
		$clause = "<object width=\"{$width}\" height=\"{$height}\">" . "<param name=\"movie\" value=\"{$url}\"></param>" . "<param name=\"wmode\" value=\"transparent\"></param>" . "<embed src=\"{$url}\" type=\"application/x-shockwave-flash\"" . " wmode=\"transparent\" width=\"{$width}\" height=\"{$height}\">" . "</embed></object>";
		return $clause;
	}
	
	/**
	 * The HTML necessary to embed the video with a custom embedding clause,
	 * specified align and description text
	 *
	 * @access	private
	 * @param	string	Clause
	 * @param	string	Horizontal Alignment
	 * @param	string	Description
	 * @param	integer	Width
	 * @param	integer	Height
	 * @return string
	 */
	static private function generateAlignExternClause($clause, $align, $desc, $width, $height) {
		$alignClass = self::getAlignmentClass($align);
		$clause     = "<div class=\"thumb {$alignClass}\">" . "<div class=\"thumbinner\" style=\"width: {$width}px;\">" . $clause . "<div class=\"thumbcaption\">" . $desc . "</div></div></div>";
		return $clause;
	}
	
	/**
	 * Generate the HTML necessary to embed the video with the given alignment
	 * and text description
	 *
	 * @access	private
	 * @param	string $url
	 * @param	integer    $width
	 * @param	integer    $height
	 * @param	string $align
	 * @param	string $desc
	 *
	 * @return string
	 */
	static private function generateAlignClause($url, $width, $height, $align, $desc) {
		$alignClass = self::getAlignmentClass($align);
		$clause     = "<div class=\"thumb {$alignClass}\">" . "<div class=\"thumbinner\" style=\"width: {$width}px;\">" . "<object width=\"{$width}\" height=\"{$height}\">" . "<param name=\"movie\" value=\"{$url}\"></param>" . "<param name=\"wmode\" value=\"transparent\"></param>" . "<embed src=\"{$url}\" type=\"application/x-shockwave-flash\"" . " wmode=\"transparent\" width=\"{$width}\" height=\"{$height}\"></embed>" . "</object>" . "<div class=\"thumbcaption\">" . $desc . "</div></div></div>";
		return $clause;
	}
	
	/**
	 * Get the entry for the specified service, by name
	 *
	 * @param	string $service
	 *
	 * @return $string
	 */
	static private function getServiceEntry($service) {
		// Get the entry in the list of services
		global $wgEmbedVideoServiceList;
		return $wgEmbedVideoServiceList[$service];
	}
	
	/**
	 * Get the width. If there is no width specified, try to find a default
	 * width value for the service. If that isn't set, default to 425.
	 * If a width value is provided, verify that it is numerical and that it
	 * falls between the specified min and max size values. Return true if
	 * the width is suitable, false otherwise.
	 *
	 * @param	string $service
	 *
	 * @return mixed
	 */
	static private function sanitizeWidth($entry, &$width) {
		global $wgEmbedVideoMinWidth, $wgEmbedVideoMaxWidth;
		if ($width === null || $width == '*' || $width == '') {
			if (isset($entry['default_width'])) {
				$width = $entry['default_width'];
			} else {
				$width = 425;
			}
			return true;
		}
		if (!is_numeric($width)) {
			return false;
		}
		return $width >= $wgEmbedVideoMinWidth && $width <= $wgEmbedVideoMaxWidth;
	}
	
	/**
	 * Validate the align parameter.
	 *
	 * @param	string $align The align parameter
	 *
	 * @return {\code true} if the align parameter is valid, otherwise {\code false}.
	 */
	static private function validateAlignment($align) {
		return ($align == 'left' || $align == 'right' || $align == 'center' || $align == 'auto');
	}
	
	static private function getAlignmentClass($align) {
		if ($align == 'left' || $align == 'right') {
			return 't' . $align;
		}
		
		return $align;
	}
	
	/**
	 * Calculate the height from the given width. The default ratio is 450/350,
	 * but that may be overridden for some sites.
	 *
	 * @param	integer $entry
	 * @param	integer $width
	 *
	 * @return int
	 */
	static private function getHeight($entry, $width) {
		$ratio = 4 / 3;
		if (isset($entry['default_ratio'])) {
			$ratio = $entry['default_ratio'];
		}
		return round($width / $ratio);
	}
	
	/**
	 * If we have a textual description, get the markup necessary to display
	 * it on the page.
	 *
	 * @param	string $desc
	 *
	 * @return string
	 */
	static private function getDescriptionMarkup($desc) {
		if ($desc !== null) {
			return "<div class=\"thumbcaption\">$desc</div>";
		}
		return "";
	}
	
	/**
	 * Verify the id number of the video, if a pattern is provided.
	 *
	 * @param	string $entry
	 * @param	string $id
	 *
	 * @return bool
	 */
	static private function verifyID($entry, $id) {
		$idhtml = htmlspecialchars($id);
		//$idpattern = (isset($entry['id_pattern']) ? $entry['id_pattern'] : '%[^A-Za-z0-9_\\-]%');
		//if ($idhtml == null || preg_match($idpattern, $idhtml)) {
		return ($idhtml != null);
	}
	
	/**
	 * Get an error message for the case where the ID value is bad
	 *
	 * @param	string $service
	 * @param	string $id
	 *
	 * @return string
	 */
	static private function errBadID($service, $id) {
		$idhtml = htmlspecialchars($id);
		$msg    = wfMsgForContent('embedvideo-bad-id', $idhtml, @htmlspecialchars($service));
		return '<div class="errorbox">' . $msg . '</div>';
	}
	
	/**
	 * Get an error message if the width is bad
	 *
	 * @param	integer $width
	 *
	 * @return string
	 */
	static private function errBadWidth($width) {
		$msg = wfMsgForContent('embedvideo-illegal-width', @htmlspecialchars($width));
		return '<div class="errorbox">' . $msg . '</div>';
	}
	
	/**
	 * Get an error message if there are missing parameters
	 *
	 * @param	string $service
	 * @param	string $id
	 *
	 * @return string
	 */
	static private function errMissingParams($service, $id) {
		return '<div class="errorbox">' . wfMsg('embedvideo-missing-params') . '</div>';
	}
	
	/**
	 * Get an error message if the service name is bad
	 *
	 * @param	string $service
	 *
	 * @return string
	 */
	static private function errBadService($service) {
		$msg = wfMsg('embedvideo-unrecognized-service', @htmlspecialchars($service));
		return '<div class="errorbox">' . $msg . '</div>';
	}
	
	/**
	 * Get an error message for an invalid align parameter
	 *
	 * @param	string $align The given align parameter.
	 *
	 * @return string
	 */
	static private function errBadAlignment($align) {
		$msg = wfMsg('embedvideo-illegal-alignment', @htmlspecialchars($align));
		return '<div class="errorbox">' . $msg . '</div>';
	}
	
	/**
	 * Get an error message for an invalid screen 9 id.
	 *
	 * @return string
	 */
	static private function errBadScreen9Id() {
		$msg = wfMsg('embedvideo-illegal-screen9-id');
		return '<div class="errorbox">' . $msg . '</div>';
	}
	
	/**
	 * Verify that the min and max values for width are sane.
	 *
	 * @return void
	 */
	static private function VerifyWidthMinAndMax() {
		global $wgEmbedVideoMinWidth, $wgEmbedVideoMaxWidth;
		if (!is_numeric($wgEmbedVideoMinWidth) || $wgEmbedVideoMinWidth < 100) {
			$wgEmbedVideoMinWidth = 100;
		}
		if (!is_numeric($wgEmbedVideoMaxWidth) || $wgEmbedVideoMaxWidth > 1024) {
			$wgEmbedVideoMaxWidth = 1024;
		}
	}

	/**
	 * Get RuTube information
	 *
	 * @access	public
	 * @param	integer
	 * @return	string
	 */
	static private function getRuTube($id) {
		$id = intval($id);
		$return = self::curlGet("http://rutube.ru/oembed/track?&amp;url=http://rutube.ru/tracks/{$id}.html&amp;format=json");
		if ($return === false) {
			return false;
		}
		$json = curl_exec($ch);
		$start = strpos($return, 'value=\"') + 8;
		$end   = strpos($return, '\"></');
		$url   = substr($return, $start, $end - $start);
		return $url;
	}

	/**
	 * Get Yandex information
	 *
	 * @access	public
	 * @param	integer
	 * @return	string
	 */
	static private function getYandex($id) {
		$id = intval($id);
		$return = self::curlGet("http://video.yandex.ru/oembed.xml?url=http://video.yandex.ru/users/{$id}");
		if ($return === false) {
			return false;
		}
		$start = strpos($return, '<html>') + 6;
		$end   = strpos($return, '</html>');
		$url   = substr($return, $start, $end - $start);
		return $url;
	}

	/**
	 * Parse Screen9 Identification code.
	 *
	 * @access	public
	 * @param	integer
	 * @return	string
	 */
	static private function parseScreen9Id($id, $width, $height) {
		$parser = new Screen9IdParser();
		
		if (!$parser->parse($id)) {
			return null;
		}
		
		$parser->setWidth($width);
		
		$parser->setHeight($height);
		
		return $parser->toString();
	}

	/**
	 * Perform a Curl GET request.
	 *
	 * @access	private
	 * @param	string URL
	 * @return	mixed
	 */
	static private function curlGet($location) {
		global $wgServer;

		$ch = curl_init();

		$timeout = 10;
		$useragent = "EmbedVideo/1.0/".$wgServer;
		$dateTime = gmdate("D, d M Y H:i:s", time())." GMT";
		$headers = ['Date: '.$dateTime];

		$curlOptions = [
			CURLOPT_TIMEOUT			=> $timeout,
			CURLOPT_USERAGENT		=> $useragent,
			CURLOPT_URL				=> $location,
			CURLOPT_CONNECTTIMEOUT	=> $timeout,
			CURLOPT_FOLLOWLOCATION	=> true,
			CURLOPT_MAXREDIRS		=> 10,
			CURLOPT_COOKIEFILE		=> sys_get_temp_dir().DIRECTORY_SEPARATOR.'curlget',
			CURLOPT_COOKIEJAR		=> sys_get_temp_dir().DIRECTORY_SEPARATOR.'curlget',
			CURLOPT_RETURNTRANSFER	=> true,
			CURLOPT_HTTPHEADER		=> $headers
		];

		curl_setopt_array($ch, $curlOptions);

		$page = curl_exec($ch);

		$response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($responseCode == 503 || $responseCode == 404) {
			return false;
		}

		return $page;
	}
}
?>