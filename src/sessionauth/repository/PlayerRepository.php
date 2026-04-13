<?php
declare(strict_types=1);

namespace sessionauth\repository;

use sessionauth\storage\DatabaseManager;

final class PlayerRepository{
	public function __construct(
		private DatabaseManager $db
	){}

	public function normalizeName(string $name) : string{
		return strtolower(trim($name));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByName(string $name) : ?array{
		return $this->db->fetchOne("SELECT * FROM players WHERE username = ?", [$this->normalizeName($name)]);
	}

	public function exists(string $name) : bool{
		return $this->findByName($name) !== null;
	}

	public function createPlayer(string $username, string $passwordHash, ?string $xuid, ?string $ip, int $registeredAt) : void{
		$this->db->run(
			"INSERT INTO players (username, password_hash, xuid, last_ip, registered_at) VALUES (?, ?, ?, ?, ?)",
			[$this->normalizeName($username), $passwordHash, $xuid, $ip, $registeredAt]
		);
	}

	public function updateLoginSuccess(string $username, ?string $ip, ?string $xuid, int $lastLoginAt) : void{
		$this->db->run(
			"UPDATE players SET last_ip = ?, xuid = COALESCE(?, xuid), last_login_at = ?, failed_attempts = 0, last_failed_at = NULL, temp_ban_until = NULL, captcha_required = 0 WHERE username = ?",
			[$ip, $xuid, $lastLoginAt, $this->normalizeName($username)]
		);
	}

	public function updatePasswordHash(string $username, string $passwordHash) : void{
		$this->db->run(
			"UPDATE players SET password_hash = ? WHERE username = ?",
			[$passwordHash, $this->normalizeName($username)]
		);
	}

	public function updateFailedAttempt(string $username, int $failedAttempts, ?int $lastFailedAt, ?int $tempBanUntil, int $captchaRequired) : void{
		$this->db->run(
			"UPDATE players SET failed_attempts = ?, last_failed_at = ?, temp_ban_until = ?, captcha_required = ? WHERE username = ?",
			[$failedAttempts, $lastFailedAt, $tempBanUntil, $captchaRequired, $this->normalizeName($username)]
		);
	}

	public function clearSecurityState(string $username) : void{
		$this->db->run(
			"UPDATE players SET failed_attempts = 0, last_failed_at = NULL, temp_ban_until = NULL, captcha_required = 0 WHERE username = ?",
			[$this->normalizeName($username)]
		);
	}

	public function setCaptchaRequired(string $username, bool $required) : void{
		$this->db->run(
			"UPDATE players SET captcha_required = ? WHERE username = ?",
			[$required ? 1 : 0, $this->normalizeName($username)]
		);
	}

	public function setTempBan(string $username, int $until) : void{
		$this->db->run(
			"UPDATE players SET temp_ban_until = ? WHERE username = ?",
			[$until, $this->normalizeName($username)]
		);
	}

	public function clearTempBan(string $username) : void{
		$this->db->run(
			"UPDATE players SET temp_ban_until = NULL WHERE username = ?",
			[$this->normalizeName($username)]
		);
	}

	public function setTrustedSession(string $username, string $token, ?string $ip, int $expiresAt) : void{
		$this->db->run(
			"UPDATE players SET trusted_session_token = ?, trusted_session_expires_at = ?, last_ip = COALESCE(?, last_ip) WHERE username = ?",
			[$token, $expiresAt, $ip, $this->normalizeName($username)]
		);
	}

	public function clearTrustedSession(string $username) : void{
		$this->db->run(
			"UPDATE players SET trusted_session_token = NULL, trusted_session_expires_at = NULL WHERE username = ?",
			[$this->normalizeName($username)]
		);
	}

	public function setLoginAttempts(string $username, int $attempts) : void{
		$this->db->run(
			"UPDATE players SET failed_attempts = ? WHERE username = ?",
			[$attempts, $this->normalizeName($username)]
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getIpAttempt(string $ip) : ?array{
		return $this->db->fetchOne("SELECT * FROM ip_attempts WHERE ip = ?", [$ip]);
	}

	public function upsertIpAttempt(string $ip, int $attempts, int $lastAttemptAt, ?int $cooldownUntil) : void{
		$this->db->run(
			"INSERT INTO ip_attempts (ip, attempts, last_attempt_at, cooldown_until) VALUES (?, ?, ?, ?) ON CONFLICT(ip) DO UPDATE SET attempts = excluded.attempts, last_attempt_at = excluded.last_attempt_at, cooldown_until = excluded.cooldown_until",
			[$ip, $attempts, $lastAttemptAt, $cooldownUntil]
		);
	}

	public function clearIpAttempt(string $ip) : void{
		$this->db->run("DELETE FROM ip_attempts WHERE ip = ?", [$ip]);
	}

	public function getMeta(string $key) : ?string{
		$row = $this->db->fetchOne("SELECT value FROM meta WHERE key = ?", [$key]);
		return $row !== null ? (string) $row["value"] : null;
	}

	public function setMeta(string $key, string $value) : void{
		$this->db->run(
			"INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
			[$key, $value]
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getAllPlayers() : array{
		return $this->db->fetchAll("SELECT * FROM players ORDER BY username ASC");
	}

	public function countPlayers() : int{
		$row = $this->db->fetchOne("SELECT COUNT(*) AS count FROM players");
		return $row === null ? 0 : (int) $row["count"];
	}
}
