<?php
declare(strict_types=1);

namespace sessionauth\service;

use pocketmine\player\Player;
use sessionauth\repository\PlayerRepository;

final class LoginAttemptService{
	/**
	 * @var array<string, array{count: int, window_start: int}>
	 */
	private array $formSubmits = [];

	public function __construct(
		private PlayerRepository $repository,
		private $logger,
		private AuditTrail $audit,
		private array $authConfig,
		private array $ipConfig
	){}

	public function getTempBanUntil(array $record) : ?int{
		$until = (int) ($record["temp_ban_until"] ?? 0);
		return $until > time() ? $until : null;
	}

	public function getProgressiveDelaySeconds(array $record) : int{
		if(!(bool) ($this->authConfig["progressive_delay"] ?? true)){
			return 0;
		}
		if($this->shouldResetAttempts($record)){
			return 0;
		}
		$failedAttempts = max(0, (int) ($record["failed_attempts"] ?? 0));
		if($failedAttempts <= 0){
			return 0;
		}
		return min($failedAttempts, 3);
	}

	public function getRemainingDelaySeconds(array $record) : int{
		if($this->shouldResetAttempts($record)){
			return 0;
		}
		$delay = $this->getProgressiveDelaySeconds($record);
		if($delay <= 0){
			return 0;
		}
		$lastFailedAt = (int) ($record["last_failed_at"] ?? 0);
		if($lastFailedAt <= 0){
			return 0;
		}
		$elapsed = time() - $lastFailedAt;
		return max(0, $delay - $elapsed);
	}

	public function recordFormSubmit(Player $player) : bool{
		$limit = max(1, (int) ($this->ipConfig["form_max_submits_per_10_seconds"] ?? 4));
		$name = strtolower($player->getName());
		$now = time();
		$state = $this->formSubmits[$name] ?? ["count" => 0, "window_start" => $now];
		if(($now - $state["window_start"]) > 10){
			$state = ["count" => 0, "window_start" => $now];
		}
		$state["count"]++;
		$this->formSubmits[$name] = $state;
		return $state["count"] <= $limit;
	}

	public function clearFormSubmitState(string $username) : void{
		unset($this->formSubmits[strtolower($username)]);
	}

	public function recordIpAttempt(string $ip) : array{
		$enabled = (bool) ($this->ipConfig["enabled"] ?? true);
		if(!$enabled){
			return ["allowed" => true, "cooldown_until" => null];
		}
		$maxAttempts = max(1, (int) ($this->ipConfig["max_attempts_per_minute"] ?? 8));
		$cooldownMinutes = max(1, (int) ($this->ipConfig["cooldown_minutes"] ?? 5));
		$now = time();
		$current = $this->repository->getIpAttempt($ip);
		$attempts = 0;
		$lastAttemptAt = $now;
		$cooldownUntil = null;
		if($current !== null){
			$cooldownUntil = isset($current["cooldown_until"]) ? (int) $current["cooldown_until"] : null;
			if($cooldownUntil !== null && $cooldownUntil > $now){
				return ["allowed" => false, "cooldown_until" => $cooldownUntil];
			}
			$lastAttemptAt = (int) ($current["last_attempt_at"] ?? $now);
			$attempts = (int) ($current["attempts"] ?? 0);
			if(($now - $lastAttemptAt) > 60){
				$attempts = 0;
			}
		}
		$attempts++;
		if($attempts > $maxAttempts){
			$cooldownUntil = $now + ($cooldownMinutes * 60);
			$this->repository->upsertIpAttempt($ip, $attempts, $now, $cooldownUntil);
			$this->audit->record("IP-COOLDOWN", $ip . " until " . date("Y-m-d H:i:s", $cooldownUntil));
			return ["allowed" => false, "cooldown_until" => $cooldownUntil];
		}
		$this->repository->upsertIpAttempt($ip, $attempts, $now, null);
		return ["allowed" => true, "cooldown_until" => null];
	}

	public function getIpCooldownUntil(string $ip) : ?int{
		if(!(bool) ($this->ipConfig["enabled"] ?? true)){
			return null;
		}
		$row = $this->repository->getIpAttempt($ip);
		if($row === null){
			return null;
		}
		$until = (int) ($row["cooldown_until"] ?? 0);
		return $until > time() ? $until : null;
	}

	public function recordFailure(string $username, ?array $record, ?int $forcedTempBanUntil = null) : array{
		$name = strtolower($username);
		$now = time();
		$resetAttempts = $this->shouldResetAttempts($record);
		$failedAttempts = $resetAttempts ? 1 : max(0, (int) ($record["failed_attempts"] ?? 0)) + 1;
		$maxAttempts = max(1, (int) ($this->authConfig["max_login_attempts"] ?? 5));
		$tempBanMinutes = max(1, (int) ($this->authConfig["temp_ban_minutes"] ?? 5));
		$captchaTrigger = max(1, (int) ($this->authConfig["captcha_trigger_after_failed_attempts"] ?? 3));
		$tempBanUntil = $forcedTempBanUntil;
		$captchaRequired = $resetAttempts ? 0 : (int) ($record["captcha_required"] ?? 0);
		if($failedAttempts >= $captchaTrigger){
			$captchaRequired = 1;
		}
		if($failedAttempts >= $maxAttempts){
			$tempBanUntil = $now + ($tempBanMinutes * 60);
			$failedAttempts = 0;
			$captchaRequired = 1;
			$this->logger->warning("[SessionAuth] Temp ban applied to " . $name . " until " . date("Y-m-d H:i:s", $tempBanUntil));
			$this->audit->record("TEMPBAN", $name . " until " . date("Y-m-d H:i:s", $tempBanUntil));
		}
		$this->repository->updateFailedAttempt($name, $failedAttempts, $now, $tempBanUntil, $captchaRequired);
		return [
			"failed_attempts" => $failedAttempts,
			"last_failed_at" => $now,
			"temp_ban_until" => $tempBanUntil,
			"captcha_required" => $captchaRequired
		];
	}

	public function clearAfterSuccess(string $username, ?string $ip = null) : void{
		$this->repository->clearSecurityState($username);
		if($ip !== null){
			$this->repository->clearIpAttempt($ip);
		}
		$this->clearFormSubmitState($username);
	}

	public function shouldRequireCaptcha(array $record, bool $newIp, bool $ipCooldown) : bool{
		$enabled = (bool) (($this->authConfig["captcha_enabled"] ?? true));
		if(!$enabled){
			return false;
		}
		if((int) ($record["captcha_required"] ?? 0) === 1){
			return true;
		}
		$triggerFailed = max(1, (int) ($this->authConfig["captcha_trigger_after_failed_attempts"] ?? 3));
		if((int) ($record["failed_attempts"] ?? 0) >= $triggerFailed){
			return true;
		}
		if($newIp && (bool) ($this->authConfig["captcha_trigger_on_new_ip"] ?? true)){
			return true;
		}
		return $ipCooldown;
	}

	private function shouldResetAttempts(array $record) : bool{
		$minutes = max(1, (int) ($this->authConfig["reset_attempts_after_minutes"] ?? 20));
		$lastFailedAt = (int) ($record["last_failed_at"] ?? 0);
		if($lastFailedAt <= 0){
			return false;
		}
		return (time() - $lastFailedAt) >= ($minutes * 60);
	}

	public function getRemainingTempBanText(?int $until) : string{
		if($until === null){
			return "";
		}
		$remaining = max(1, $until - time());
		$minutes = (int) floor($remaining / 60);
		$seconds = $remaining % 60;
		return sprintf("%dm %02ds", $minutes, $seconds);
	}
}
