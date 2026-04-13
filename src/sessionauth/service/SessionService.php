<?php
declare(strict_types=1);

namespace sessionauth\service;

use pocketmine\player\Player;
use sessionauth\repository\PlayerRepository;

final class SessionService{
	public function __construct(
		private PlayerRepository $repository,
		private $logger,
		private AuditTrail $audit,
		private array $trustedConfig
	){}

	public function isEnabled() : bool{
		return (bool) ($this->trustedConfig["enabled"] ?? true);
	}

	public function isTrustedLogin(Player $player, array $record) : bool{
		if(!$this->isEnabled()){
			return false;
		}
		if($record === []){
			return false;
		}
		$expiresAt = (int) ($record["trusted_session_expires_at"] ?? 0);
		if($expiresAt <= time()){
			$this->repository->clearTrustedSession($player->getName());
			return false;
		}

		$currentIp = $player->getNetworkSession()->getIp();
		$currentXuid = (string) $player->getXuid();
		$savedIp = (string) ($record["last_ip"] ?? "");
		$savedXuid = (string) ($record["xuid"] ?? "");
		$requireIpMatch = (bool) ($this->trustedConfig["require_ip_match"] ?? true);

		if($requireIpMatch && $savedIp !== "" && $savedIp !== $currentIp){
			return false;
		}

		if($savedXuid !== "" && $currentXuid !== "" && $savedXuid !== $currentXuid){
			return false;
		}

		return true;
	}

	public function isNewIp(Player $player, array $record) : bool{
		$currentIp = $player->getNetworkSession()->getIp();
		$savedIp = (string) ($record["last_ip"] ?? "");
		return $savedIp !== "" && $savedIp !== $currentIp;
	}

	public function storeTrustedSession(Player $player, ?array $record = null) : void{
		if(!$this->isEnabled()){
			return;
		}
		$days = max(1, (int) ($this->trustedConfig["expire_days"] ?? 7));
		$expiresAt = time() + ($days * 86400);
		$token = bin2hex(random_bytes(24));
		$this->repository->setTrustedSession(
			$player->getName(),
			$token,
			$player->getNetworkSession()->getIp(),
			$expiresAt
		);
		$this->logger->info("[SessionAuth] Trusted session stored for " . $player->getName() . " until " . date("Y-m-d H:i:s", $expiresAt));
		$this->audit->record("SESSION", $player->getName() . " trusted until " . date("Y-m-d H:i:s", $expiresAt));
	}

	public function clearTrustedSession(string $username) : void{
		$this->repository->clearTrustedSession($username);
	}
}
