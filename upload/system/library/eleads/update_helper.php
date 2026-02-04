<?php
class EleadsUpdateHelper {
	const TIMEOUT = 10;

	public static function getUpdateInfo() {
		$local_version = self::getLocalVersion();
		$latest_data = self::getLatestReleaseData();

		$latest_version = isset($latest_data['version']) ? $latest_data['version'] : null;
		$download_url = isset($latest_data['download_url']) ? $latest_data['download_url'] : null;
		$html_url = isset($latest_data['html_url']) ? $latest_data['html_url'] : null;
		$error = isset($latest_data['error']) ? $latest_data['error'] : null;

		$update_available = false;
		if (!empty($local_version) && !empty($latest_version)) {
			$update_available = version_compare($latest_version, $local_version, '>');
		}

		return array(
			'local_version' => $local_version,
			'latest_version' => $latest_version,
			'download_url' => $download_url,
			'html_url' => $html_url,
			'update_available' => $update_available,
			'error' => $error,
		);
	}

	public static function updateToLatest($root_path) {
		$latest_data = self::getLatestReleaseData();
		if (!empty($latest_data['error'])) {
			return array('ok' => false, 'message' => $latest_data['error']);
		}

		$download_url = isset($latest_data['download_url']) ? $latest_data['download_url'] : '';
		$expected_version = isset($latest_data['version']) ? (string)$latest_data['version'] : '';
		if ($download_url === '') {
			return array('ok' => false, 'message' => 'Update package not found');
		}

		return self::downloadAndReplace($download_url, $root_path, $expected_version);
	}

	private static function getLocalVersion() {
		$manifest = DIR_SYSTEM . 'library/eleads/manifest.json';
		if (!is_file($manifest)) {
			return '';
		}

		$data = json_decode((string)file_get_contents($manifest), true);
		if (!is_array($data)) {
			return '';
		}

		return isset($data['version']) ? (string)$data['version'] : '';
	}

	private static function getLatestReleaseData() {
		$release = self::requestJson(EleadsApiRoutes::GITHUB_LATEST_RELEASE);
		if (!empty($release['tag_name']) || !empty($release['name'])) {
			$tag = (string)(isset($release['tag_name']) ? $release['tag_name'] : $release['name']);
			$version = ltrim($tag, 'vV');

			$download_url = self::resolveDownloadUrlFromRelease($release, $tag);
			if ($download_url === '') {
				return array('error' => 'Release package for current OpenCart version not found');
			}

			return array(
				'version' => $version,
				'download_url' => $download_url,
				'html_url' => isset($release['html_url']) ? $release['html_url'] : null,
			);
		}

		$tags = self::requestJson(EleadsApiRoutes::GITHUB_TAGS);
		if (is_array($tags) && !empty($tags[0]['name'])) {
			$tag = (string)$tags[0]['name'];
			$version = ltrim($tag, 'vV');
			return array(
				'version' => $version,
				'download_url' => EleadsApiRoutes::githubZipballUrl($tag),
				'html_url' => EleadsApiRoutes::GITHUB_REPO,
			);
		}

		return array('error' => 'Unable to fetch latest version');
	}

	private static function resolveDownloadUrlFromRelease($release, $tag) {
		if (isset($release['assets']) && is_array($release['assets']) && !empty($release['assets'])) {
			$asset_name = self::getExpectedAssetName();
			$asset_url = self::findReleaseAssetUrl($release['assets'], $asset_name);
			if ($asset_url !== '') {
				return $asset_url;
			}

			return '';
		}

		if (!empty($release['zipball_url'])) {
			return (string)$release['zipball_url'];
		}

		return EleadsApiRoutes::githubZipballUrl($tag);
	}

	private static function getExpectedAssetName() {
		$major = self::getOpenCartMajorVersion();
		if ($major === 2) {
			return 'eleads-opencart-2.x.ocmod.zip';
		}
		if ($major === 4) {
			return 'eleads-opencart-4.x.ocmod.zip';
		}

		return 'eleads-opencart-3.x.ocmod.zip';
	}

	private static function getOpenCartMajorVersion() {
		if (!defined('VERSION')) {
			return 3;
		}

		$version = (string)VERSION;
		if ($version === '') {
			return 3;
		}

		$parts = explode('.', $version);
		if (!isset($parts[0]) || !is_numeric($parts[0])) {
			return 3;
		}

		return (int)$parts[0];
	}

	private static function findReleaseAssetUrl($assets, $asset_name) {
		foreach ($assets as $asset) {
			if (!is_array($asset)) {
				continue;
			}

			$name = isset($asset['name']) ? (string)$asset['name'] : '';
			$url = isset($asset['browser_download_url']) ? (string)$asset['browser_download_url'] : '';

			if ($name === $asset_name && $url !== '') {
				return $url;
			}
		}

		return '';
	}

	private static function requestJson($url) {
		$ch = curl_init();
		if ($ch === false) {
			return array();
		}

		$headers = array(
			'Accept: application/vnd.github+json',
			'User-Agent: ELeads-OpenCart',
		);

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);

		$response = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $code < 200 || $code >= 300) {
			return array();
		}

		$data = json_decode($response, true);
		return is_array($data) ? $data : array();
	}

	private static function downloadAndReplace($url, $root_path, $expected_version = '') {
		$tmp_base = sys_get_temp_dir() . '/eleads_update_' . uniqid('', true);
		$zip_path = $tmp_base . '.zip';
		$extract_dir = $tmp_base . '_extract';

		$fp = fopen($zip_path, 'wb');
		if ($fp === false) {
			return array('ok' => false, 'message' => 'Cannot create temp file');
		}

		$ch = curl_init();
		if ($ch === false) {
			fclose($fp);
			return array('ok' => false, 'message' => 'Cannot init curl');
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
		curl_setopt($ch, CURLOPT_USERAGENT, 'ELeads-OpenCart');

		$ok = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if ($ok === false || $code < 200 || $code >= 300) {
			return array('ok' => false, 'message' => 'Download failed');
		}

		$zip = new ZipArchive();
		if ($zip->open($zip_path) !== true) {
			return array('ok' => false, 'message' => 'Cannot open zip');
		}
		mkdir($extract_dir, 0777, true);
		$zip->extractTo($extract_dir);
		$zip->close();

		$package = self::findPackageRoot($extract_dir);
		if (empty($package['path']) || empty($package['mode'])) {
			self::cleanupTemp($zip_path, $extract_dir);
			return array('ok' => false, 'message' => 'Module package root not found in archive');
		}

		$target_root = self::resolveTargetRoot($root_path, $package['mode']);
		if ($target_root === '') {
			self::cleanupTemp($zip_path, $extract_dir);
			return array('ok' => false, 'message' => 'Invalid update target');
		}

		$copy_ok = self::copyRecursive($package['path'], $target_root);
		self::cleanupTemp($zip_path, $extract_dir);

		if (!$copy_ok) {
			return array('ok' => false, 'message' => 'Failed to update files');
		}

		if ($expected_version !== '' && !self::isInstalledVersion($root_path, $package['mode'], $expected_version)) {
			return array('ok' => false, 'message' => 'Updated files copied, but module version did not change');
		}

		return array('ok' => true, 'message' => 'Updated');
	}

	private static function findPackageRoot($base_dir) {
		$upload_root = self::findNamedDirectory($base_dir, 'upload');
		if ($upload_root !== '') {
			return array('path' => $upload_root . '/', 'mode' => 'upload');
		}

		if (self::getOpenCartMajorVersion() === 4) {
			$oc4_root = self::findOc4PackageRoot($base_dir);
			if ($oc4_root !== '') {
				return array('path' => $oc4_root . '/', 'mode' => 'oc4');
			}
		}

		return array('path' => '', 'mode' => '');
	}

	private static function findNamedDirectory($base_dir, $directory_name) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			if ($file->isDir() && $file->getFilename() === $directory_name) {
				return $file->getPathname();
			}
		}

		return '';
	}

	private static function findOc4PackageRoot($base_dir) {
		$dirs = array($base_dir);
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			if ($file->isDir()) {
				$dirs[] = $file->getPathname();
			}
		}

		foreach ($dirs as $dir) {
			if (is_dir($dir . '/admin') && is_dir($dir . '/catalog') && is_dir($dir . '/system')) {
				return $dir;
			}
		}

		return '';
	}

	private static function resolveTargetRoot($root_path, $mode) {
		$root_path = rtrim($root_path, '/\\') . '/';
		if ($mode === 'upload') {
			return $root_path;
		}

		if ($mode === 'oc4') {
			return $root_path . 'extension/eleads/';
		}

		return '';
	}

	private static function isInstalledVersion($root_path, $mode, $expected_version) {
		$root_path = rtrim($root_path, '/\\') . '/';
		$manifest = ($mode === 'oc4')
			? $root_path . 'extension/eleads/system/library/eleads/manifest.json'
			: $root_path . 'system/library/eleads/manifest.json';

		if (!is_file($manifest)) {
			return false;
		}

		$data = json_decode((string)file_get_contents($manifest), true);
		if (!is_array($data) || !isset($data['version'])) {
			return false;
		}

		return (string)$data['version'] === $expected_version;
	}

	private static function copyRecursive($src, $dst) {
		if (!is_dir($src)) {
			return false;
		}
		if (!is_dir($dst) && !mkdir($dst, 0777, true) && !is_dir($dst)) {
			return false;
		}

		$items = scandir($src);
		if ($items === false) {
			return false;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$from = $src . $item;
			$to = $dst . $item;
			if (is_dir($from)) {
				if (!self::copyRecursive($from . '/', $to . '/')) {
					return false;
				}
			} else {
				if (!copy($from, $to)) {
					return false;
				}
			}
		}

		return true;
	}

	private static function cleanupTemp($zip_path, $extract_dir) {
		if (is_file($zip_path)) {
			@unlink($zip_path);
		}
		if (is_dir($extract_dir)) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($extract_dir, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($iterator as $file) {
				if ($file->isDir()) {
					@rmdir($file->getPathname());
				} else {
					@unlink($file->getPathname());
				}
			}
			@rmdir($extract_dir);
		}
	}
}
