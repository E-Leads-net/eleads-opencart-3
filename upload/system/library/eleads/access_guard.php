<?php
class EleadsAccessGuard {
	public static function allowFeed($access_key, $request_key) {
		$access_key = (string)$access_key;
		if ($access_key === '') {
			return true;
		}
		return (string)$request_key === $access_key;
	}
}
