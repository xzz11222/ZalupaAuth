<?php
declare(strict_types=1);

namespace sessionauth\service;

use pocketmine\player\Player;
use sessionauth\storage\DatabaseManager;

final class AccessControlService{
	public function __construct(
		private DatabaseManager $database,
		private AuditTrail $audit,
		private array $config
	){}

	public function reload(array $config) : void{
		$this->config = $config;
	}

	public function checkJoin(Player $player) : array{
		$ip = $player->getNetworkSession()->getIp();
		return $this->checkIp($ip);
	}

	public function checkIp(string $ip) : array{
		$cfg = $this->config;
		if(!(bool) ($cfg["enabled"] ?? true)){
			return ["allowed" => true, "reason" => null, "country_code" => null];
		}

		if(!filter_var($ip, FILTER_VALIDATE_IP)){
			return ["allowed" => false, "reason" => "invalid_ip", "country_code" => null];
		}

		$allowPrivate = (bool) ($cfg["allow_private_ips"] ?? true);
		if(!$allowPrivate && $this->isPrivateIp($ip)){
			return ["allowed" => false, "reason" => "private_ip", "country_code" => null];
		}

		$blockIps = $this->normalizeList($cfg["block_ips"] ?? []);
		foreach($blockIps as $pattern){
			if($this->ipMatches($ip, $pattern)){
				return ["allowed" => false, "reason" => "blocked_ip", "country_code" => null];
			}
		}

		$allowIps = $this->normalizeList($cfg["allow_ips"] ?? []);
		if($allowIps !== []){
			$allowed = false;
			foreach($allowIps as $pattern){
				if($this->ipMatches($ip, $pattern)){
					$allowed = true;
					break;
				}
			}
			if(!$allowed){
				return ["allowed" => false, "reason" => "not_allowed_ip", "country_code" => null];
			}
		}

		$allowCountries = $this->normalizeCountries($cfg["allow_countries"] ?? []);
		$blockCountries = $this->normalizeCountries($cfg["block_countries"] ?? []);
		if($allowCountries === [] && $blockCountries === []){
			return ["allowed" => true, "reason" => null, "country_code" => null];
		}

		$countryCode = $this->resolveCountryCode($ip);
		if($countryCode === null){
			return [
				"allowed" => (bool) ($cfg["block_on_lookup_failure"] ?? false) ? false : true,
				"reason" => "country_lookup_failed",
				"country_code" => null
			];
		}

		if($blockCountries !== [] && in_array($countryCode, $blockCountries, true)){
			return ["allowed" => false, "reason" => "blocked_country", "country_code" => $countryCode];
		}

		if($allowCountries !== [] && !in_array($countryCode, $allowCountries, true)){
			return ["allowed" => false, "reason" => "not_allowed_country", "country_code" => $countryCode];
		}

		return ["allowed" => true, "reason" => null, "country_code" => $countryCode];
	}

	public function canRegister(string $ip) : array{
		$cfg = $this->config;
		$limit = max(1, (int) ($cfg["max_registrations_per_window"] ?? 3));
		$windowMinutes = max(1, (int) ($cfg["registration_window_minutes"] ?? 60));
		$cooldownMinutes = max(1, (int) ($cfg["registration_cooldown_minutes"] ?? 30));
		$now = time();

		$row = $this->database->getRegistrationAttempt($ip);
		$registrations = 0;
		$windowStart = $now;
		$cooldownUntil = null;
		if($row !== null){
			$windowStart = (int) ($row["window_start"] ?? $now);
			$registrations = (int) ($row["registrations"] ?? 0);
			$cooldownUntil = isset($row["cooldown_until"]) ? (int) $row["cooldown_until"] : null;
			if($cooldownUntil !== null && $cooldownUntil > $now){
				return ["allowed" => false, "reason" => "registration_cooldown", "cooldown_until" => $cooldownUntil];
			}
			if(($now - $windowStart) >= ($windowMinutes * 60)){
				$registrations = 0;
				$windowStart = $now;
			}
		}

		if($registrations >= $limit){
			$cooldownUntil = $now + ($cooldownMinutes * 60);
			$this->database->upsertRegistrationAttempt($ip, $registrations, $windowStart, $cooldownUntil);
			$this->audit->record("REG-SPAM", $ip . " exceeded registration limit");
			return ["allowed" => false, "reason" => "registration_cooldown", "cooldown_until" => $cooldownUntil];
		}

		return ["allowed" => true, "reason" => null, "cooldown_until" => null];
	}

	public function recordRegistrationSuccess(string $ip) : void{
		$cfg = $this->config;
		$limit = max(1, (int) ($cfg["max_registrations_per_window"] ?? 3));
		$windowMinutes = max(1, (int) ($cfg["registration_window_minutes"] ?? 60));
		$cooldownMinutes = max(1, (int) ($cfg["registration_cooldown_minutes"] ?? 30));
		$now = time();

		$row = $this->database->getRegistrationAttempt($ip);
		$registrations = 0;
		$windowStart = $now;
		if($row !== null){
			$windowStart = (int) ($row["window_start"] ?? $now);
			$registrations = (int) ($row["registrations"] ?? 0);
			$cooldownUntil = isset($row["cooldown_until"]) ? (int) $row["cooldown_until"] : null;
			if($cooldownUntil !== null && $cooldownUntil > $now){
				return;
			}
			if(($now - $windowStart) >= ($windowMinutes * 60)){
				$registrations = 0;
				$windowStart = $now;
			}
		}

		$registrations++;
		$cooldownUntil = null;
		if($registrations >= $limit){
			$cooldownUntil = $now + ($cooldownMinutes * 60);
			$this->audit->record("REG-SPAM", $ip . " registration cooldown until " . date("Y-m-d H:i:s", $cooldownUntil));
		}
		$this->database->upsertRegistrationAttempt($ip, $registrations, $windowStart, $cooldownUntil);
	}

	private function resolveCountryCode(string $ip) : ?string{
		$cached = $this->database->getGeoCache($ip);
		$cacheMinutes = max(5, (int) ($this->config["geoip_cache_minutes"] ?? 60));
		if($cached !== null){
			$fetchedAt = (int) ($cached["fetched_at"] ?? 0);
			$country = strtoupper((string) ($cached["country_code"] ?? ""));
			if($country !== "" && (time() - $fetchedAt) < ($cacheMinutes * 60)){
				return $country;
			}
		}

		$template = (string) ($this->config["geoip_lookup_url"] ?? "");
		if($template === ""){
			return null;
		}

		$url = str_replace("{ip}", rawurlencode($ip), $template);
		$timeoutMs = max(200, (int) ($this->config["geoip_timeout_ms"] ?? 900));
		$context = stream_context_create([
			"http" => [
				"timeout" => $timeoutMs / 1000,
				"ignore_errors" => true
			]
		]);
		$response = @file_get_contents($url, false, $context);
		if(!is_string($response) || trim($response) === ""){
			$this->database->setGeoCache($ip, null, time());
			return null;
		}

		$country = strtoupper(trim($response));
		if(strlen($country) !== 2){
			$this->database->setGeoCache($ip, null, time());
			return null;
		}

		$this->database->setGeoCache($ip, $country, time());
		return $country;
	}

	/**
	 * @param array<int, mixed> $list
	 * @return list<string>
	 */
	private function normalizeList(array $list) : array{
		$out = [];
		foreach($list as $item){
			$value = trim((string) $item);
			if($value !== ""){
				$out[] = $value;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @param array<int, mixed> $list
	 * @return list<string>
	 */
	private function normalizeCountries(array $list) : array{
		$out = [];
		foreach($list as $item){
			$value = strtoupper(trim((string) $item));
			if($value !== ""){
				$out[] = $value;
			}
		}
		return array_values(array_unique($out));
	}

	private function isPrivateIp(string $ip) : bool{
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
	}

	private function ipMatches(string $ip, string $pattern) : bool{
		$pattern = trim($pattern);
		if($pattern === ""){
			return false;
		}
		if(str_contains($pattern, "/")){
			return $this->cidrMatch($ip, $pattern);
		}
		return strcasecmp($ip, $pattern) === 0;
	}

	private function cidrMatch(string $ip, string $cidr) : bool{
		[$subnet, $mask] = array_pad(explode("/", $cidr, 2), 2, null);
		if($subnet === null || $mask === null){
			return false;
		}
		$ipBin = @inet_pton($ip);
		$subnetBin = @inet_pton($subnet);
		if($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)){
			return false;
		}

		$mask = (int) $mask;
		$bytes = intdiv($mask, 8);
		$bits = $mask % 8;
		if($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)){
			return false;
		}
		if($bits === 0){
			return true;
		}
		$maskByte = chr((0xFF << (8 - $bits)) & 0xFF);
		return ((ord($ipBin[$bytes]) & ord($maskByte)) === (ord($subnetBin[$bytes]) & ord($maskByte)));
	}
}
