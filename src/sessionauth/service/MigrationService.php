<?php
declare(strict_types=1);

namespace sessionauth\service;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use sessionauth\repository\PlayerRepository;

final class MigrationService{
	public function __construct(
		private PluginBase $plugin,
		private PlayerRepository $repository,
		private $logger,
		private AuditTrail $audit,
		private string $legacyFolder
	){}

	public function run() : void{
		if($this->repository->getMeta("legacy_yaml_migrated") === "1"){
			return;
		}

		$playersFile = rtrim($this->legacyFolder, "\\/") . DIRECTORY_SEPARATOR . "players.yml";
		$trustedFile = rtrim($this->legacyFolder, "\\/") . DIRECTORY_SEPARATOR . "trusted.yml";
		$securityFile = rtrim($this->legacyFolder, "\\/") . DIRECTORY_SEPARATOR . "security.yml";

		if(!is_file($playersFile)){
			$this->repository->setMeta("legacy_yaml_migrated", "1");
			return;
		}

		$players = new Config($playersFile, Config::YAML);
		$trusted = is_file($trustedFile) ? new Config($trustedFile, Config::YAML) : null;
		$security = is_file($securityFile) ? new Config($securityFile, Config::YAML) : null;
		$rawPlayers = $players->getAll();
		$migrated = 0;
		$skipped = 0;
		$errors = [];

		$this->logger->info("[SessionAuth] Starting legacy YAML migration from " . $playersFile);
		foreach($rawPlayers as $username => $value){
			$normalized = strtolower(trim((string) $username));
			if($normalized === ""){
				$skipped++;
				$errors[] = "Empty username entry skipped";
				continue;
			}

			try{
				$passwordHash = "";
				$xuid = null;
				$lastIp = null;
				if(is_string($value)){
					$passwordHash = $value;
				}elseif(is_array($value)){
					$passwordHash = (string) ($value["password_hash"] ?? $value["hash"] ?? $value["password"] ?? "");
					$xuid = isset($value["xuid"]) ? (string) $value["xuid"] : null;
					$lastIp = isset($value["last_ip"]) ? (string) $value["last_ip"] : null;
				}

				if($passwordHash === ""){
					$skipped++;
					$errors[] = $normalized . ": missing password hash";
					continue;
				}

				$registeredAt = time();
				$lastLoginAt = null;
				$tempBanUntil = null;
				$failedAttempts = 0;
				$lastFailedAt = null;
				$captchaRequired = 0;
				$trustedToken = null;
				$trustedExpires = null;

				if($trusted !== null){
					$trustedEntry = $trusted->get($normalized);
					if(is_array($trustedEntry)){
						$lastIp = (string) ($trustedEntry["ip"] ?? $lastIp ?? "");
						$xuid = (string) ($trustedEntry["xuid"] ?? $xuid ?? "");
						$trustedExpires = isset($trustedEntry["expiresAt"]) ? (int) $trustedEntry["expiresAt"] : null;
						$trustedToken = $trustedExpires !== null ? bin2hex(random_bytes(16)) : null;
					}
				}

				if($security !== null){
					$attempts = $security->get("login_attempts", []);
					$bans = $security->get("temp_bans", []);
					if(is_array($attempts) && isset($attempts[$normalized])){
						$failedAttempts = max(0, (int) $attempts[$normalized]);
					}
					if(is_array($bans) && isset($bans[$normalized])){
						$tempBanUntil = (int) $bans[$normalized];
					}
				}

				if($this->repository->exists($normalized)){
					$this->repository->updatePasswordHash($normalized, $passwordHash);
				}else{
					$this->repository->createPlayer($normalized, $passwordHash, $xuid, $lastIp, $registeredAt);
				}

				$this->repository->updateFailedAttempt($normalized, $failedAttempts, $lastFailedAt, $tempBanUntil, $captchaRequired);
				if($lastLoginAt !== null){
					$this->repository->updateLoginSuccess($normalized, $lastIp, $xuid, $lastLoginAt);
				}
				if($trustedToken !== null && $trustedExpires !== null){
					$this->repository->setTrustedSession($normalized, $trustedToken, $lastIp, $trustedExpires);
				}

				$migrated++;
			}catch(\Throwable $e){
				$skipped++;
				$errors[] = $normalized . ": " . $e->getMessage();
			}
		}

		$this->repository->setMeta("legacy_yaml_migrated", "1");
		$this->logger->info("[SessionAuth] Legacy migration finished. migrated={$migrated}, skipped={$skipped}");
		$this->audit->record("MIGRATE", "migrated={$migrated}, skipped={$skipped}");
		foreach($errors as $error){
			$this->logger->warning("[SessionAuth] Migration note: " . $error);
			$this->audit->record("MIGRATE-WARN", $error);
		}

		$backup = $playersFile . ".bak";
		if(!is_file($backup)){
			@rename($playersFile, $backup);
		}
	}
}
