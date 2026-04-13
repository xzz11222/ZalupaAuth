<?php
declare(strict_types=1);

namespace sessionauth\storage;

use RuntimeException;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

final class DatabaseManager{
	private SQLite3 $db;

	public function __construct(string $path){
		$this->db = new SQLite3($path);
		$this->db->busyTimeout(5000);
		$this->db->exec("PRAGMA journal_mode = WAL;");
		$this->db->exec("PRAGMA synchronous = NORMAL;");
	}

	public function close() : void{
		$this->db->close();
	}

	public function initializeSchema() : void{
		$this->exec("
			CREATE TABLE IF NOT EXISTS meta (
				key TEXT PRIMARY KEY,
				value TEXT NOT NULL
			)
		");
		$this->exec("
			CREATE TABLE IF NOT EXISTS players (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				username TEXT NOT NULL UNIQUE,
				password_hash TEXT NOT NULL,
				xuid TEXT DEFAULT NULL,
				last_ip TEXT DEFAULT NULL,
				registered_at INTEGER NOT NULL,
				last_login_at INTEGER DEFAULT NULL,
				trusted_session_token TEXT DEFAULT NULL,
				trusted_session_expires_at INTEGER DEFAULT NULL,
				failed_attempts INTEGER DEFAULT 0,
				last_failed_at INTEGER DEFAULT NULL,
				temp_ban_until INTEGER DEFAULT NULL,
				captcha_required INTEGER DEFAULT 0
			)
		");
		$this->exec("
			CREATE TABLE IF NOT EXISTS ip_attempts (
				ip TEXT PRIMARY KEY,
				attempts INTEGER DEFAULT 0,
				last_attempt_at INTEGER NOT NULL,
				cooldown_until INTEGER DEFAULT NULL
			)
		");
		$this->exec("
			CREATE TABLE IF NOT EXISTS registration_ip_attempts (
				ip TEXT PRIMARY KEY,
				registrations INTEGER DEFAULT 0,
				window_start INTEGER NOT NULL,
				cooldown_until INTEGER DEFAULT NULL
			)
		");
		$this->exec("
			CREATE TABLE IF NOT EXISTS ip_geo_cache (
				ip TEXT PRIMARY KEY,
				country_code TEXT DEFAULT NULL,
				fetched_at INTEGER NOT NULL
			)
		");
		$this->exec("
			CREATE TABLE IF NOT EXISTS sessions (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				username TEXT NOT NULL,
				session_token TEXT NOT NULL,
				ip TEXT DEFAULT NULL,
				created_at INTEGER NOT NULL,
				expires_at INTEGER NOT NULL
			)
		");
		$this->exec("CREATE INDEX IF NOT EXISTS idx_players_last_login_at ON players(last_login_at)");
		$this->exec("CREATE INDEX IF NOT EXISTS idx_players_temp_ban_until ON players(temp_ban_until)");
		$this->exec("CREATE INDEX IF NOT EXISTS idx_sessions_username ON sessions(username)");
		$this->exec("CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at)");
		$this->exec("CREATE INDEX IF NOT EXISTS idx_ip_geo_cache_country_code ON ip_geo_cache(country_code)");
	}

	public function exec(string $sql) : void{
		if(!$this->db->exec($sql)){
			throw new RuntimeException("SQLite exec failed: " . $this->db->lastErrorMsg());
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function fetchOne(string $sql, array $params = []) : ?array{
		$stmt = $this->prepare($sql, $params);
		$result = $stmt->execute();
		if(!$result instanceof SQLite3Result){
			$stmt->close();
			throw new RuntimeException("SQLite query failed: " . $this->db->lastErrorMsg());
		}
		$row = $result->fetchArray(SQLITE3_ASSOC);
		$result->finalize();
		$stmt->close();
		return $row === false ? null : $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchAll(string $sql, array $params = []) : array{
		$stmt = $this->prepare($sql, $params);
		$result = $stmt->execute();
		if(!$result instanceof SQLite3Result){
			$stmt->close();
			throw new RuntimeException("SQLite query failed: " . $this->db->lastErrorMsg());
		}
		$rows = [];
		while(($row = $result->fetchArray(SQLITE3_ASSOC)) !== false){
			$rows[] = $row;
		}
		$result->finalize();
		$stmt->close();
		return $rows;
	}

	public function run(string $sql, array $params = []) : void{
		$stmt = $this->prepare($sql, $params);
		$result = $stmt->execute();
		if($result instanceof SQLite3Result){
			$result->finalize();
		}elseif($result === false){
			$stmt->close();
			throw new RuntimeException("SQLite statement failed: " . $this->db->lastErrorMsg());
		}
		$stmt->close();
	}

	public function clearIpAttempt(string $ip) : void{
		$this->run("DELETE FROM ip_attempts WHERE ip = ?", [$ip]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getRegistrationAttempt(string $ip) : ?array{
		return $this->fetchOne("SELECT * FROM registration_ip_attempts WHERE ip = ?", [$ip]);
	}

	public function upsertRegistrationAttempt(string $ip, int $registrations, int $windowStart, ?int $cooldownUntil) : void{
		$this->run(
			"INSERT INTO registration_ip_attempts (ip, registrations, window_start, cooldown_until) VALUES (?, ?, ?, ?) ON CONFLICT(ip) DO UPDATE SET registrations = excluded.registrations, window_start = excluded.window_start, cooldown_until = excluded.cooldown_until",
			[$ip, $registrations, $windowStart, $cooldownUntil]
		);
	}

	public function clearRegistrationAttempt(string $ip) : void{
		$this->run("DELETE FROM registration_ip_attempts WHERE ip = ?", [$ip]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getGeoCache(string $ip) : ?array{
		return $this->fetchOne("SELECT * FROM ip_geo_cache WHERE ip = ?", [$ip]);
	}

	public function setGeoCache(string $ip, ?string $countryCode, int $fetchedAt) : void{
		$this->run(
			"INSERT INTO ip_geo_cache (ip, country_code, fetched_at) VALUES (?, ?, ?) ON CONFLICT(ip) DO UPDATE SET country_code = excluded.country_code, fetched_at = excluded.fetched_at",
			[$ip, $countryCode, $fetchedAt]
		);
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function transaction(callable $callback){
		$this->exec("BEGIN IMMEDIATE TRANSACTION");
		try{
			$result = $callback();
			$this->exec("COMMIT");
			return $result;
		}catch(\Throwable $e){
			$this->exec("ROLLBACK");
			throw $e;
		}
	}

	/**
	 * @param array<int, mixed> $params
	 */
	private function prepare(string $sql, array $params) : SQLite3Stmt{
		$stmt = $this->db->prepare($sql);
		if(!$stmt instanceof SQLite3Stmt){
			throw new RuntimeException("SQLite prepare failed: " . $this->db->lastErrorMsg());
		}

		foreach(array_values($params) as $index => $value){
			$this->bind($stmt, $index + 1, $value);
		}

		return $stmt;
	}

	private function bind(SQLite3Stmt $stmt, int $index, mixed $value) : void{
		if($value === null){
			$stmt->bindValue($index, null, SQLITE3_NULL);
			return;
		}
		if(is_int($value)){
			$stmt->bindValue($index, $value, SQLITE3_INTEGER);
			return;
		}
		if(is_float($value)){
			$stmt->bindValue($index, $value, SQLITE3_FLOAT);
			return;
		}
		if(is_bool($value)){
			$stmt->bindValue($index, $value ? 1 : 0, SQLITE3_INTEGER);
			return;
		}

		$stmt->bindValue($index, (string) $value, SQLITE3_TEXT);
	}
}
