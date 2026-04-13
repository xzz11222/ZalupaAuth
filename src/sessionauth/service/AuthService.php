<?php
declare(strict_types=1);

namespace sessionauth\service;

use pocketmine\player\Player;
use sessionauth\repository\PlayerRepository;

final class AuthService{
	public function __construct(
		private PlayerRepository $repository,
		private SessionService $sessionService,
		private LoginAttemptService $attemptService,
		private CaptchaService $captchaService,
		private $logger,
		private AccessControlService $accessControl,
		private AuditTrail $audit,
		private array $authConfig
	){}

	public function getRecord(Player $player) : ?array{
		return $this->repository->findByName($player->getName());
	}

	public function isRegistered(Player $player) : bool{
		return $this->repository->exists($player->getName());
	}

	public function prepareJoin(Player $player, bool $ignoreTrustedSession = false) : array{
		$access = $this->accessControl->checkJoin($player);
		if(!$access["allowed"]){
			$this->audit->record("ACCESS", $player->getName() . " blocked on join: " . (string) $access["reason"]);
			return [
				"state" => "blocked",
				"record" => null,
				"captcha_required" => false,
				"challenge" => null,
				"temp_ban_until" => null,
				"reason" => (string) $access["reason"],
				"country_code" => $access["country_code"] ?? null
			];
		}

		$record = $this->getRecord($player);
		if($record === null){
			$ipCooldown = $this->attemptService->getIpCooldownUntil($player->getNetworkSession()->getIp()) !== null;
			$captchaRequired = $this->attemptService->shouldRequireCaptcha(["captcha_required" => 0, "failed_attempts" => 0], false, $ipCooldown);
			return [
				"state" => "register",
				"record" => null,
				"captcha_required" => $captchaRequired,
				"challenge" => $captchaRequired ? $this->captchaService->createChallenge() : null,
				"temp_ban_until" => null
			];
		}

		$tempBanUntil = $this->attemptService->getTempBanUntil($record);
		if($tempBanUntil !== null){
			return [
				"state" => "temp_banned",
				"record" => $record,
				"captcha_required" => false,
				"challenge" => null,
				"temp_ban_until" => $tempBanUntil
			];
		}

		if(!$ignoreTrustedSession && $this->sessionService->isTrustedLogin($player, $record)){
			$this->logger->info("[SessionAuth] Auto-login for " . $player->getName());
			$this->audit->record("AUTOLOGIN", $player->getName() . " trusted session");
			return [
				"state" => "auto_login",
				"record" => $record,
				"captcha_required" => false,
				"challenge" => null,
				"temp_ban_until" => null
			];
		}

		$newIp = $this->sessionService->isNewIp($player, $record);
		$ipCooldown = $this->attemptService->getIpCooldownUntil($player->getNetworkSession()->getIp()) !== null;
		$captchaRequired = $this->attemptService->shouldRequireCaptcha($record, $newIp, $ipCooldown);
		return [
			"state" => "login",
			"record" => $record,
			"captcha_required" => $captchaRequired,
			"challenge" => $captchaRequired ? $this->captchaService->createChallenge() : null,
			"temp_ban_until" => null,
			"new_ip" => $newIp,
			"ip_cooldown" => $ipCooldown
		];
	}

	public function getLoginPromptState(Player $player, bool $ignoreTrustedSession = false) : array{
		return $this->prepareJoin($player, $ignoreTrustedSession);
	}

	public function getRegisterPromptState(Player $player) : array{
		$record = $this->getRecord($player);
		if($record !== null){
			return $this->prepareJoin($player);
		}
		$newIp = false;
		$ipCooldown = $this->attemptService->getIpCooldownUntil($player->getNetworkSession()->getIp()) !== null;
		$captchaRequired = $this->attemptService->shouldRequireCaptcha(["captcha_required" => 0, "failed_attempts" => 0], $newIp, $ipCooldown);
		return [
			"state" => "register",
			"record" => null,
			"captcha_required" => $captchaRequired,
			"challenge" => $captchaRequired ? $this->captchaService->createChallenge() : null,
			"temp_ban_until" => null,
			"new_ip" => $newIp,
			"ip_cooldown" => $ipCooldown
		];
	}

	public function submitLogin(Player $player, string $password, ?array $challenge, ?string $captchaResponse) : array{
		$record = $this->getRecord($player);
		if($record === null){
			return ["status" => "not_registered"];
		}

		$tempBanUntil = $this->attemptService->getTempBanUntil($record);
		if($tempBanUntil !== null){
			return ["status" => "temp_banned", "until" => $tempBanUntil];
		}

		$formLimitOk = $this->attemptService->recordFormSubmit($player);
		if(!$formLimitOk){
			return ["status" => "form_cooldown"];
		}

		$access = $this->accessControl->checkJoin($player);
		if(!$access["allowed"]){
			return ["status" => "blocked", "reason" => $access["reason"], "country_code" => $access["country_code"] ?? null];
		}

		$ipResult = $this->attemptService->recordIpAttempt($player->getNetworkSession()->getIp());
		if(!$ipResult["allowed"]){
			return ["status" => "ip_cooldown", "until" => $ipResult["cooldown_until"]];
		}

		$remainingDelay = $this->attemptService->getRemainingDelaySeconds($record);
		if($remainingDelay > 0){
			return ["status" => "progressive_delay", "retry_after" => $remainingDelay];
		}

		$newIp = $this->sessionService->isNewIp($player, $record);
		$ipCooldown = $this->attemptService->getIpCooldownUntil($player->getNetworkSession()->getIp()) !== null;
		$captchaRequired = $this->attemptService->shouldRequireCaptcha($record, $newIp, $ipCooldown);
		if($captchaRequired){
			if($challenge === null || $captchaResponse === null || !$this->captchaService->validate($challenge, $captchaResponse)){
				$this->repository->setCaptchaRequired($player->getName(), true);
				$this->audit->record("CAPTCHA", $player->getName() . " failed login captcha");
				return [
					"status" => "captcha_required",
					"challenge" => $this->captchaService->createChallenge()
				];
			}
		}

		$hash = (string) ($record["password_hash"] ?? "");
		if($hash === "" || !password_verify($password, $hash)){
			$failure = $this->attemptService->recordFailure($player->getName(), $record);
			$nextCaptcha = $this->attemptService->shouldRequireCaptcha(
				array_merge($record, $failure),
				$newIp,
				$ipCooldown
			);
			$this->audit->record("LOGIN-FAIL", $player->getName() . " wrong password");
			return [
				"status" => "wrong_password",
				"retry_after" => $this->attemptService->getProgressiveDelaySeconds(array_merge($record, $failure)),
				"captcha_required" => $nextCaptcha,
				"challenge" => $nextCaptcha ? $this->captchaService->createChallenge() : null,
				"temp_ban_until" => $failure["temp_ban_until"] ?? null
			];
		}

		$this->attemptService->clearAfterSuccess($player->getName(), $player->getNetworkSession()->getIp());
		$this->sessionService->storeTrustedSession($player, $record);
		$this->repository->updateLoginSuccess($player->getName(), $player->getNetworkSession()->getIp(), $player->getXuid() !== "" ? $player->getXuid() : null, time());
		$this->logger->info("[SessionAuth] Login successful for " . $player->getName());
		$this->audit->record("LOGIN", $player->getName() . " " . $player->getNetworkSession()->getIp());
		return ["status" => "success"];
	}

	public function submitRegister(Player $player, string $password, string $confirmPassword, ?array $challenge, ?string $captchaResponse) : array{
		if($this->repository->exists($player->getName())){
			return ["status" => "already_registered"];
		}

		$formLimitOk = $this->attemptService->recordFormSubmit($player);
		if(!$formLimitOk){
			return ["status" => "form_cooldown"];
		}

		$access = $this->accessControl->checkJoin($player);
		if(!$access["allowed"]){
			return ["status" => "blocked", "reason" => $access["reason"], "country_code" => $access["country_code"] ?? null];
		}

		$registrationCheck = $this->accessControl->canRegister($player->getNetworkSession()->getIp());
		if(!$registrationCheck["allowed"]){
			return ["status" => "registration_cooldown", "until" => $registrationCheck["cooldown_until"]];
		}

		$ipResult = $this->attemptService->recordIpAttempt($player->getNetworkSession()->getIp());
		if(!$ipResult["allowed"]){
			return ["status" => "ip_cooldown", "until" => $ipResult["cooldown_until"]];
		}

		$emptyRecord = ["failed_attempts" => 0, "captcha_required" => 0];
		$newIp = false;
		$captchaRequired = $this->attemptService->shouldRequireCaptcha($emptyRecord, $newIp, $this->attemptService->getIpCooldownUntil($player->getNetworkSession()->getIp()) !== null);
		if($captchaRequired){
			if($challenge === null || $captchaResponse === null || !$this->captchaService->validate($challenge, $captchaResponse)){
				$this->audit->record("CAPTCHA", $player->getName() . " failed registration captcha");
				return [
					"status" => "captcha_required",
					"challenge" => $this->captchaService->createChallenge()
				];
			}
		}

		if(!$this->isPasswordAllowed($password)){
			return ["status" => "password_invalid"];
		}
		if($password !== $confirmPassword){
			return ["status" => "password_mismatch"];
		}

		$this->repository->createPlayer(
			$player->getName(),
			password_hash($password, PASSWORD_DEFAULT),
			$player->getXuid() !== "" ? $player->getXuid() : null,
			$player->getNetworkSession()->getIp(),
			time()
		);
		$this->repository->updateLoginSuccess($player->getName(), $player->getNetworkSession()->getIp(), $player->getXuid() !== "" ? $player->getXuid() : null, time());
		$this->sessionService->storeTrustedSession($player);
		$this->attemptService->clearAfterSuccess($player->getName(), $player->getNetworkSession()->getIp());
		$this->accessControl->recordRegistrationSuccess($player->getNetworkSession()->getIp());
		$this->logger->info("[SessionAuth] Registration successful for " . $player->getName());
		$this->audit->record("REGISTER", $player->getName() . " from " . $player->getNetworkSession()->getIp());
		return ["status" => "success"];
	}

	public function changePassword(Player $player, string $oldPassword, string $newPassword) : array{
		$record = $this->getRecord($player);
		if($record === null){
			return ["status" => "not_registered"];
		}

		$ipResult = $this->attemptService->recordIpAttempt($player->getNetworkSession()->getIp());
		if(!$ipResult["allowed"]){
			return ["status" => "ip_cooldown", "until" => $ipResult["cooldown_until"]];
		}

		if(!$this->isPasswordAllowed($newPassword)){
			return ["status" => "password_invalid"];
		}

		$hash = (string) ($record["password_hash"] ?? "");
		if($hash === "" || !password_verify($oldPassword, $hash)){
			$failure = $this->attemptService->recordFailure($player->getName(), $record);
			$this->audit->record("CHANGEPASS-FAIL", $player->getName() . " wrong old password");
			return [
				"status" => "wrong_password",
				"retry_after" => $this->attemptService->getProgressiveDelaySeconds(array_merge($record, $failure)),
				"temp_ban_until" => $failure["temp_ban_until"] ?? null
			];
		}

		$this->repository->updatePasswordHash($player->getName(), password_hash($newPassword, PASSWORD_DEFAULT));
		$this->repository->clearSecurityState($player->getName());
		$this->sessionService->storeTrustedSession($player, $record);
		$this->attemptService->clearAfterSuccess($player->getName(), $player->getNetworkSession()->getIp());
		$this->logger->info("[SessionAuth] Password changed for " . $player->getName());
		$this->audit->record("CHANGEPASS", $player->getName() . " changed password");
		return ["status" => "success"];
	}

	public function resetPassword(string $targetName, string $newPassword) : array{
		$record = $this->repository->findByName($targetName);
		if($record === null){
			return ["status" => "not_registered"];
		}
		if(!$this->isPasswordAllowed($newPassword)){
			return ["status" => "password_invalid"];
		}

		$this->repository->updatePasswordHash($targetName, password_hash($newPassword, PASSWORD_DEFAULT));
		$this->repository->clearSecurityState($targetName);
		$this->sessionService->clearTrustedSession($targetName);
		$this->audit->record("PASSRESET", $targetName . " reset by admin");
		return ["status" => "success"];
	}

	private function isPasswordAllowed(string $password) : bool{
		$min = max(3, (int) ($this->authConfig["password_min_length"] ?? 3));
		$max = max($min, (int) ($this->authConfig["password_max_length"] ?? 20));
		$normalized = strtolower(trim($password));
		if(strlen($password) < $min || strlen($password) > $max){
			return false;
		}
		$blocked = $this->authConfig["blocked_passwords"] ?? [];
		if(!is_array($blocked)){
			$blocked = [];
		}
		foreach($blocked as $bad){
			if($normalized === strtolower(trim((string) $bad))){
				return false;
			}
		}
		return true;
	}
}
