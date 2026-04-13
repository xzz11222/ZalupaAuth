<?php
declare(strict_types=1);

namespace sessionauth;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\Location;
use sessionauth\form\CustomForm;
use sessionauth\repository\PlayerRepository;
use sessionauth\service\AccessControlService;
use sessionauth\service\AuditTrail;
use sessionauth\service\AuthService;
use sessionauth\service\CaptchaService;
use sessionauth\service\LoginAttemptService;
use sessionauth\service\MigrationService;
use sessionauth\service\SessionService;
use sessionauth\storage\DatabaseManager;
use function count;
use function date;
use function in_array;
use function is_array;
use function max;
use function strtolower;
use function trim;
use function time;

final class Main extends PluginBase implements Listener{
	private Config $authConfig;
	private Config $textConfig;
	private DatabaseManager $database;
	private PlayerRepository $repository;
	private AuditTrail $audit;
	private AccessControlService $accessControl;
	private CaptchaService $captchaService;
	private SessionService $sessionService;
	private LoginAttemptService $attemptService;
	private AuthService $authService;
	private MigrationService $migrationService;

	/** @var array<string, bool> */
	private array $authenticated = [];
	/** @var array<string, Location> */
	private array $joinLocations = [];
	/** @var array<string, int> */
	private array $joinTimes = [];

	protected function onEnable() : void{
		@mkdir($this->getDataFolder(), 0777, true);
		$this->bootstrapRuntime();
		$this->localizeCommandMetadata();
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	protected function onDisable() : void{
		if(isset($this->database)){
			$this->database->close();
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		$name = strtolower($command->getName());
		if($name === "passreset"){
			return $this->handlePasswordReset($sender, $args);
		}
		if($name === "authinfo"){
			return $this->handleAuthInfo($sender, $args);
		}
		if($name === "authreload"){
			return $this->handleAuthReload($sender);
		}
		if($name === "authhelp" || $name === "authh"){
			return $this->handleAuthHelp($sender);
		}
		if(!$sender instanceof Player){
			$sender->sendMessage(TextFormat::RED . $this->message("console_only"));
			return true;
		}
		return match($name){
			"register" => count($args) === 0 ? $this->openAuthForm($sender, "register") : $this->handleRegisterCommand($sender, $args),
			"login" => count($args) === 0 ? $this->openAuthForm($sender, "login") : $this->handleLoginCommand($sender, $args),
			"changepass" => count($args) === 0 ? $this->openChangePassForm($sender) : $this->handleChangePassCommand($sender, $args),
			"logout" => $this->handleLogoutCommand($sender),
			"reauth" => $this->handleReauthCommand($sender),
			default => false
		};
	}

	public function onJoin(PlayerJoinEvent $event) : void{
		$player = $event->getPlayer();
		$name = strtolower($player->getName());
		$this->authenticated[$name] = false;
		$this->joinTimes[$name] = time();
		$this->joinLocations[$name] = clone $player->getLocation();

		$state = $this->authService->prepareJoin($player);
		if($state["state"] === "blocked"){
			$this->kickWithMessage($player, "access_blocked", ["reason" => (string) ($state["reason"] ?? "blocked")]);
			$this->audit->record("ACCESS", $player->getName() . " join blocked: " . (string) ($state["reason"] ?? "blocked"));
			return;
		}
		if($state["state"] === "temp_banned"){
			$this->kickTempBannedPlayer($player, (int) $state["temp_ban_until"]);
			return;
		}
		if($state["state"] === "auto_login"){
			$this->markAuthenticated($player);
			$this->sendMessage($player, "auto_login");
			return;
		}

		$this->setFrozenState($player, true);
		$this->sendMessage($player, $state["state"] === "register" ? "register_prompt" : "login_prompt");
		$this->scheduleAuthForm($player, $state["state"] === "register" ? "register" : "login", 20);
		$this->scheduleLoginTimeout($player);
	}

	public function onQuit(PlayerQuitEvent $event) : void{
		$name = strtolower($event->getPlayer()->getName());
		unset($this->authenticated[$name], $this->joinLocations[$name], $this->joinTimes[$name]);
	}

	public function onMove(PlayerMoveEvent $event) : void{
		$player = $event->getPlayer();
		if($this->isAuthenticated($player)){
			return;
		}
		$name = strtolower($player->getName());
		if(isset($this->joinLocations[$name])){
			$event->setTo(clone $this->joinLocations[$name]);
			return;
		}
		if($event->getFrom()->distanceSquared($event->getTo()) > 0.0){
			$event->cancel();
		}
	}

	public function onDamage(EntityDamageEvent $event) : void{
		$entity = $event->getEntity();
		if(!$entity instanceof Player){
			return;
		}
		if(!$this->isAuthenticated($entity)){
			$event->cancel();
		}
	}

	public function onInteract(PlayerInteractEvent $event) : void{
		if(!$this->isAuthenticated($event->getPlayer())){
			$event->cancel();
		}
	}

	public function onItemUse(PlayerItemUseEvent $event) : void{
		if(!$this->isAuthenticated($event->getPlayer())){
			$event->cancel();
		}
	}

	public function onChat(PlayerChatEvent $event) : void{
		if($this->isAuthenticated($event->getPlayer())){
			return;
		}
		$event->cancel();
		$event->getPlayer()->sendMessage($this->message("auth_required"));
	}

	public function onCommandPreprocess(CommandEvent $event) : void{
		$sender = $event->getSender();
		if(!$sender instanceof Player || $this->isAuthenticated($sender)){
			return;
		}
		$commandName = strtolower(trim((string) strtok(trim($event->getCommand()), " ")));
		if(in_array($commandName, ["login", "l", "log", "register", "reg"], true)){
			return;
		}
		$event->cancel();
		$sender->sendMessage($this->message("only_auth_commands"));
	}

	private function handleLoginCommand(Player $player, array $args) : bool{
		$password = trim((string) ($args[0] ?? ""));
		if($password === ""){
			return $this->openAuthForm($player, "login");
		}
		return $this->handleLoginResult($player, $this->authService->submitLogin($player, $password, null, null));
	}

	private function handleRegisterCommand(Player $player, array $args) : bool{
		$password = trim((string) ($args[0] ?? ""));
		$confirm = trim((string) ($args[1] ?? ""));
		if($password === "" || $confirm === ""){
			return $this->openAuthForm($player, "register");
		}
		return $this->handleRegisterResult($player, $this->authService->submitRegister($player, $password, $confirm, null, null));
	}

	private function handleChangePassCommand(Player $player, array $args) : bool{
		if(count($args) < 3){
			return $this->openChangePassForm($player);
		}
		return $this->handleChangePassResult($player, $this->authService->changePassword($player, (string) $args[0], (string) $args[1]));
	}

	private function handleLogoutCommand(Player $player) : bool{
		if(!$this->isAuthenticated($player)){
			$player->sendMessage($this->message("not_logged_in"));
			return true;
		}
		$this->markUnauthenticated($player);
		$this->sessionService->clearTrustedSession($player->getName());
		$this->audit->record("LOGOUT", $player->getName());
		$this->sendMessage($player, "logout_success");
		$this->scheduleAuthForm($player, "login");
		return true;
	}

	private function handleReauthCommand(Player $player) : bool{
		if(!$this->isAuthenticated($player)){
			$player->sendMessage($this->message("auth_required"));
			return true;
		}
		$this->markUnauthenticated($player);
		$this->audit->record("REAUTH", $player->getName());
		$this->sendMessage($player, "reauth_prompt");
		$this->scheduleAuthForm($player, "login", 15, true);
		return true;
	}

	private function handlePasswordReset(CommandSender $sender, array $args) : bool{
		$targetName = trim((string) ($args[0] ?? ""));
		$newPassword = trim((string) ($args[1] ?? ""));
		if($targetName === "" || $newPassword === ""){
			$sender->sendMessage($this->message("passreset_usage"));
			return true;
		}
		$result = $this->authService->resetPassword($targetName, $newPassword);
		if($result["status"] === "not_registered"){
			$sender->sendMessage(TextFormat::RED . $this->message("player_not_registered"));
			return true;
		}
		if($result["status"] === "password_invalid"){
			$sender->sendMessage(TextFormat::RED . $this->message("new_password_invalid"));
			return true;
		}
		$sender->sendMessage(TextFormat::GREEN . $this->message("password_reset_done", ["name" => $targetName]));
		$this->audit->record("PASSRESET", $targetName . " by " . $sender->getName());
		$online = $this->getServer()->getPlayerExact($targetName);
		if($online instanceof Player){
			$this->markUnauthenticated($online);
			$this->sendMessage($online, "password_reset_notice");
			$this->scheduleAuthForm($online, "login");
		}
		return true;
	}

	private function handleAuthInfo(CommandSender $sender, array $args) : bool{
		$targetName = trim((string) ($args[0] ?? ""));
		if($targetName === ""){
			if($sender instanceof Player){
				$targetName = $sender->getName();
			}else{
				$sender->sendMessage($this->message("authinfo_usage"));
				return true;
			}
		}

		$record = $this->repository->findByName($targetName);
		if($record === null){
			$sender->sendMessage($this->message("player_not_registered"));
			return true;
		}

		foreach($this->buildAuthInfoLines($targetName, $record) as $line){
			$sender->sendMessage($line);
		}
		$this->audit->record("AUTHINFO", $targetName . " inspected by " . $sender->getName());
		return true;
	}

	private function handleAuthReload(CommandSender $sender) : bool{
		$this->bootstrapRuntime();
		$sender->sendMessage(TextFormat::GREEN . $this->message("authreload_success"));
		$this->audit->record("RELOAD", "config reload by " . $sender->getName());
		return true;
	}

	private function handleAuthHelp(CommandSender $sender) : bool{
		foreach($this->buildAuthHelpLines() as $line){
			$sender->sendMessage($line);
		}
		return true;
	}

	private function openChangePassForm(Player $player) : bool{
		if(!$this->isAuthenticated($player)){
			$player->sendMessage($this->message("auth_required"));
			return true;
		}
		$form = new CustomForm(function(Player $player, ?array $data) : void{
			if($data === null){
				$this->scheduleChangePassForm($player);
				return;
			}
			$oldPassword = trim((string) ($data[1] ?? ""));
			$newPassword = trim((string) ($data[2] ?? ""));
			$confirm = trim((string) ($data[3] ?? ""));
			if($newPassword !== $confirm){
				$player->sendMessage($this->message("password_mismatch"));
				$this->scheduleChangePassForm($player);
				return;
			}
			$this->handleChangePassResult($player, $this->authService->changePassword($player, $oldPassword, $newPassword));
		});
		$form->setTitle($this->message("changepass_title"));
		$form->addLabel($this->message("changepass_warning"));
		$form->addInput($this->message("changepass_old"), $this->message("changepass_old"));
		$form->addInput($this->message("changepass_new"), $this->message("changepass_new"));
		$form->addInput($this->message("changepass_confirm"), $this->message("changepass_confirm"));
		$player->sendForm($form);
		return true;
	}

	private function scheduleAuthForm(Player $player, string $mode, int $delayTicks = 15, bool $ignoreTrustedSession = false) : void{
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $mode, $ignoreTrustedSession) : void{
			if($player->isConnected() && !$this->isAuthenticated($player)){
				$this->openAuthForm($player, $mode, $ignoreTrustedSession);
			}
		}), max(1, $delayTicks));
	}

	private function openAuthForm(Player $player, string $mode, bool $ignoreTrustedSession = false) : bool{
		if($this->isAuthenticated($player)){
			return true;
		}

		$state = $mode === "register"
			? $this->authService->getRegisterPromptState($player)
			: $this->authService->getLoginPromptState($player, $ignoreTrustedSession);

		if($state["state"] === "blocked"){
			$this->kickWithMessage($player, "access_blocked", ["reason" => (string) ($state["reason"] ?? "blocked")]);
			return true;
		}
		if($state["state"] === "temp_banned"){
			$this->kickTempBannedPlayer($player, (int) $state["temp_ban_until"]);
			return true;
		}

		$captcha = is_array($state["challenge"] ?? null) ? $state["challenge"] : null;
		$form = new CustomForm(function(Player $player, ?array $data) use ($mode, $captcha, $ignoreTrustedSession) : void{
			if($data === null){
				$this->scheduleAuthForm($player, $mode, 15, $ignoreTrustedSession);
				return;
			}

			if($mode === "login"){
				$password = trim((string) ($data[1] ?? ""));
				$captchaResponse = $this->extractCaptchaResponse($captcha, $data, 2);
				$this->handleLoginResult($player, $this->authService->submitLogin($player, $password, $captcha, $captchaResponse));
				return;
			}

			$password = trim((string) ($data[1] ?? ""));
			$confirm = trim((string) ($data[2] ?? ""));
			$captchaResponse = $this->extractCaptchaResponse($captcha, $data, 3);
			$this->handleRegisterResult($player, $this->authService->submitRegister($player, $password, $confirm, $captcha, $captchaResponse));
		});

		$form->setTitle($this->message($mode === "register" ? "register_title" : "login_title"));
		$form->addLabel($this->message($mode === "register" ? "register_warning" : "login_warning"));
		$form->addInput($this->message($mode === "register" ? "register_input" : "login_input"), $this->message($mode === "register" ? "register_input" : "login_input"));
		if($mode === "register"){
			$form->addInput($this->message("register_confirm"), $this->message("register_confirm"));
		}
		if(is_array($captcha)){
			$this->addCaptchaField($form, $captcha);
		}
		$player->sendForm($form);
		return true;
	}

	private function addCaptchaField(CustomForm $form, array $challenge) : void{
		if(($challenge["mode"] ?? "math") === "button"){
			$form->addDropdown((string) ($challenge["question"] ?? $this->message("captcha_text")), is_array($challenge["options"]) ? array_values($challenge["options"]) : []);
			return;
		}
		$form->addInput((string) ($challenge["question"] ?? $this->message("captcha_text")), $this->message("captcha_input"));
	}

	private function extractCaptchaResponse(?array $challenge, array $data, int $index) : ?string{
		if(!is_array($challenge)){
			return null;
		}
		if(($challenge["mode"] ?? "math") === "button"){
			$selected = (int) ($data[$index] ?? 0);
			return (string) ($challenge["options"][$selected] ?? "");
		}
		return trim((string) ($data[$index] ?? ""));
	}

	private function handleLoginResult(Player $player, array $result) : bool{
		$status = (string) ($result["status"] ?? "error");
		if($status === "success"){
			return $this->finishAuth($player, "login_success");
		}
		if($status === "not_registered"){
			$this->sendMessage($player, "not_registered");
			$this->scheduleAuthForm($player, "register");
			return true;
		}
		if($status === "temp_banned"){
			$this->kickTempBannedPlayer($player, (int) ($result["until"] ?? 0));
			return true;
		}
		if($status === "ip_cooldown"){
			return $this->sendCooldownMessage($player, (int) ($result["until"] ?? 0), "ip_cooldown");
		}
		if($status === "registration_cooldown"){
			return $this->sendCooldownMessage($player, (int) ($result["until"] ?? 0), "registration_cooldown");
		}
		if($status === "form_cooldown"){
			return $this->sendMessage($player, "form_cooldown");
		}
		if($status === "progressive_delay"){
			return $this->sendRetryAfter($player, (int) ($result["retry_after"] ?? 1));
		}
		if($status === "blocked"){
			$this->kickWithMessage($player, "access_blocked", ["reason" => (string) ($result["reason"] ?? "blocked")]);
			return true;
		}
		if($status === "captcha_required"){
			$this->sendMessage($player, "captcha_wrong");
			$this->scheduleAuthForm($player, "login");
			return true;
		}
		if(isset($result["temp_ban_until"]) && (int) $result["temp_ban_until"] > time()){
			$this->kickTempBannedPlayer($player, (int) $result["temp_ban_until"]);
			return true;
		}
		$this->sendMessage($player, "wrong_password");
		$this->scheduleAuthForm($player, "login");
		return true;
	}

	private function handleRegisterResult(Player $player, array $result) : bool{
		$status = (string) ($result["status"] ?? "error");
		if($status === "success"){
			return $this->finishAuth($player, "register_success");
		}
		if($status === "already_registered"){
			$this->sendMessage($player, "already_registered");
			$this->scheduleAuthForm($player, "login");
			return true;
		}
		if($status === "password_invalid"){
			$this->sendMessage($player, "password_invalid");
			$this->scheduleAuthForm($player, "register");
			return true;
		}
		if($status === "password_mismatch"){
			$this->sendMessage($player, "password_mismatch");
			$this->scheduleAuthForm($player, "register");
			return true;
		}
		if($status === "ip_cooldown"){
			return $this->sendCooldownMessage($player, (int) ($result["until"] ?? 0), "ip_cooldown");
		}
		if($status === "registration_cooldown"){
			return $this->sendCooldownMessage($player, (int) ($result["until"] ?? 0), "registration_cooldown");
		}
		if($status === "form_cooldown"){
			return $this->sendMessage($player, "form_cooldown");
		}
		if($status === "blocked"){
			$this->kickWithMessage($player, "access_blocked", ["reason" => (string) ($result["reason"] ?? "blocked")]);
			return true;
		}
		if($status === "captcha_required"){
			$this->sendMessage($player, "captcha_wrong");
			$this->scheduleAuthForm($player, "register");
			return true;
		}
		$this->sendMessage($player, "password_invalid");
		return true;
	}

	private function handleChangePassResult(Player $player, array $result) : bool{
		$status = (string) ($result["status"] ?? "error");
		if($status === "success"){
			$this->sendMessage($player, "changepass_success");
			return true;
		}
		if($status === "password_invalid"){
			$this->sendMessage($player, "password_invalid");
			$this->scheduleChangePassForm($player);
			return true;
		}
		if($status === "wrong_password"){
			$this->sendMessage($player, "changepass_wrong_old");
			$this->scheduleChangePassForm($player);
			return true;
		}
		if($status === "ip_cooldown"){
			return $this->sendCooldownMessage($player, (int) ($result["until"] ?? 0), "ip_cooldown");
		}
		$this->sendMessage($player, "changepass_wrong_old");
		return true;
	}

	private function finishAuth(Player $player, string $messageKey) : bool{
		$this->markAuthenticated($player);
		$this->sendMessage($player, $messageKey);
		return true;
	}

	private function scheduleChangePassForm(Player $player) : void{
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
			if($player->isConnected() && $this->isAuthenticated($player)){
				$this->openChangePassForm($player);
			}
		}), 15);
	}

	private function scheduleLoginTimeout(Player $player) : void{
		$timeoutSeconds = max(10, (int) $this->getAuthConfigValue("auth.login_timeout_seconds", 30));
		$playerName = strtolower($player->getName());
		$startedAt = $this->joinTimes[$playerName] ?? time();
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $playerName, $startedAt) : void{
			if(!$player->isConnected() || $this->isAuthenticated($player)){
				return;
			}
			if(($this->joinTimes[$playerName] ?? 0) !== $startedAt){
				return;
			}
			$player->kick($this->message("timeout_kick"));
		}), $timeoutSeconds * 20);
	}

	private function markAuthenticated(Player $player) : void{
		$name = strtolower($player->getName());
		$this->authenticated[$name] = true;
		unset($this->joinLocations[$name]);
		$this->setFrozenState($player, false);
	}

	private function markUnauthenticated(Player $player) : void{
		$name = strtolower($player->getName());
		$this->authenticated[$name] = false;
		$this->joinLocations[$name] = clone $player->getLocation();
		$this->setFrozenState($player, true);
	}

	private function setFrozenState(Player $player, bool $frozen) : void{
		if($frozen){
			$player->setMotion($player->getMotion()->multiply(0));
		}
	}

	private function isAuthenticated(Player $player) : bool{
		return ($this->authenticated[strtolower($player->getName())] ?? false) === true;
	}

	private function sendMessage(Player $player, string $key, array $replace = []) : bool{
		$player->sendMessage($this->message($key, $replace));
		return true;
	}

	private function sendCooldownMessage(Player $player, int $until, string $messageKey) : bool{
		$this->sendMessage($player, $messageKey, ["time" => date("d.m.Y H:i:s", $until)]);
		return true;
	}

	private function sendRetryAfter(Player $player, int $seconds) : bool{
		$this->sendMessage($player, "progressive_delay", ["seconds" => (string) max(1, $seconds)]);
		return true;
	}

	private function kickTempBannedPlayer(Player $player, int $until) : void{
		$player->kick(
			$this->message("tempban_kick", ["remaining" => $this->attemptService->getRemainingTempBanText($until), "until" => date("d.m.Y H:i:s", $until)]) .
			"\n" .
			$this->message("tempban_until", ["time" => date("d.m.Y H:i:s", $until)])
		);
	}

	private function kickWithMessage(Player $player, string $messageKey, array $replace = []) : void{
		$player->kick($this->message($messageKey, $replace));
	}

	private function message(string $key, array $replace = []) : string{
		$language = $this->getLanguage();
		$messages = $this->getTextMessages();
		$value = $messages[$language][$key] ?? $messages["ru"][$key] ?? $messages["en"][$key] ?? $key;
		foreach($replace as $needle => $replacement){
			$value = str_replace("{" . $needle . "}", (string) $replacement, $value);
		}
		return $value;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function getTextMessages() : array{
		$defaults = $this->getDefaultTextConfig();
		if(!isset($this->textConfig)){
			return $defaults["messages"];
		}

		$messages = $this->textConfig->getNested("messages", []);
		if(!is_array($messages)){
			$messages = [];
		}

		$normalized = [
			"ru" => is_array($messages["ru"] ?? null) ? array_merge($defaults["messages"]["ru"], $messages["ru"]) : $defaults["messages"]["ru"],
			"en" => is_array($messages["en"] ?? null) ? array_merge($defaults["messages"]["en"], $messages["en"]) : $defaults["messages"]["en"]
		];

		foreach(["ru", "en"] as $language){
			foreach($defaults["messages"][$language] as $key => $value){
				if(!isset($normalized[$language][$key])){
					$normalized[$language][$key] = $value;
				}
			}
		}

		return $normalized;
	}

	private function getLanguage() : string{
		$fallback = isset($this->authConfig) ? (string) $this->authConfig->getNested("plugin.language", "ru") : "ru";
		$language = strtolower((string) (isset($this->textConfig) ? $this->textConfig->getNested("language", $fallback) : $fallback));
		return in_array($language, ["ru", "en"], true) ? $language : "ru";
	}

	private function getAuthConfigValue(string $path, mixed $default = null) : mixed{
		return isset($this->authConfig) ? $this->authConfig->getNested($path, $default) : $default;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getAuthConfigArray(string $path, array $default = []) : array{
		$value = $this->getAuthConfigValue($path, $default);
		return is_array($value) ? $value : $default;
	}

	private function getAuthConfigString(string $path, string $default = "") : string{
		$value = $this->getAuthConfigValue($path, $default);
		return is_scalar($value) ? (string) $value : $default;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return list<string>
	 */
	private function buildAuthInfoLines(string $targetName, array $record) : array{
		$lines = [];
		$lines[] = TextFormat::GOLD . "Информация SessionAuth: " . TextFormat::WHITE . $targetName;
		$lines[] = TextFormat::GRAY . "Регистрация: " . TextFormat::WHITE . $this->formatTime($record["registered_at"] ?? null);
		$lines[] = TextFormat::GRAY . "Последний вход: " . TextFormat::WHITE . $this->formatTime($record["last_login_at"] ?? null);
		$lines[] = TextFormat::GRAY . "Последний IP: " . TextFormat::WHITE . ((string) ($record["last_ip"] ?? "-"));
		$lines[] = TextFormat::GRAY . "XUID: " . TextFormat::WHITE . (((string) ($record["xuid"] ?? "")) !== "" ? (string) $record["xuid"] : "-");
		$lines[] = TextFormat::GRAY . "Неудачных попыток: " . TextFormat::WHITE . (string) ((int) ($record["failed_attempts"] ?? 0));
		$lines[] = TextFormat::GRAY . "Последняя ошибка: " . TextFormat::WHITE . $this->formatTime($record["last_failed_at"] ?? null);
		$lines[] = TextFormat::GRAY . "Бан до: " . TextFormat::WHITE . $this->formatTime($record["temp_ban_until"] ?? null);
		$lines[] = TextFormat::GRAY . "Капча нужна: " . TextFormat::WHITE . (((int) ($record["captcha_required"] ?? 0)) === 1 ? "да" : "нет");
		$lines[] = TextFormat::GRAY . "Trusted session до: " . TextFormat::WHITE . $this->formatTime($record["trusted_session_expires_at"] ?? null);
		return $lines;
	}

	private function formatTime(mixed $value) : string{
		$time = (int) ($value ?? 0);
		return $time > 0 ? date("d.m.Y H:i:s", $time) : "-";
	}

	private function bootstrapRuntime() : void{
		$dataFolder = $this->getDataFolder();
		@mkdir($dataFolder, 0777, true);

		$authPath = $dataFolder . "ConfigAuth.yaml";
		$textPath = $dataFolder . "ConfigText.yaml";

		$this->authConfig = new Config($authPath, Config::YAML, $this->getDefaultAuthConfig());
		if(!is_file($authPath)){
			$this->authConfig->save();
		}

		$this->textConfig = new Config($textPath, Config::YAML, $this->getDefaultTextConfig());
		if(!is_file($textPath)){
			$this->textConfig->save();
		}
		$this->sanitizeTextConfig($this->textConfig);

		if(isset($this->database)){
			$this->database->close();
		}

		$dbFile = $this->getAuthConfigString("database.file", "players.db");
		$dbPath = $dataFolder . ltrim($dbFile, "/\\");
		$this->database = new DatabaseManager($dbPath);
		$this->database->initializeSchema();
		$this->repository = new PlayerRepository($this->database);

		$logFile = $this->getAuthConfigString("logging.file", "logs/sessionauth.log");
		$logPath = $dataFolder . ltrim($logFile, "/\\");
		$this->audit = new AuditTrail($logPath);

		$this->accessControl = new AccessControlService(
			$this->database,
			$this->audit,
			$this->getAuthConfigArray("access_control", [])
		);

		$authOptions = array_merge(
			$this->getAuthConfigArray("auth", []),
			[
				"captcha_enabled" => (bool) $this->getAuthConfigValue("captcha.enabled", true),
				"captcha_trigger_after_failed_attempts" => (int) $this->getAuthConfigValue("captcha.trigger_after_failed_attempts", 3),
				"captcha_trigger_on_new_ip" => (bool) $this->getAuthConfigValue("captcha.trigger_on_new_ip", true)
			]
		);

		$this->captchaService = new CaptchaService(
			$this->getAuthConfigArray("captcha", []),
			$this->getLanguage()
		);

		$this->sessionService = new SessionService(
			$this->repository,
			$this->getLogger(),
			$this->audit,
			$this->getAuthConfigArray("trusted_session", [])
		);

		$this->attemptService = new LoginAttemptService(
			$this->repository,
			$this->getLogger(),
			$this->audit,
			$authOptions,
			array_merge(
				$this->getAuthConfigArray("ip_protection", []),
				["form_max_submits_per_10_seconds" => (int) $this->getAuthConfigValue("ip_protection.form_max_submits_per_10_seconds", 4)]
			)
		);

		$this->authService = new AuthService(
			$this->repository,
			$this->sessionService,
			$this->attemptService,
			$this->captchaService,
			$this->getLogger(),
			$this->accessControl,
			$this->audit,
			$authOptions
		);

		$this->migrationService = new MigrationService(
			$this,
			$this->repository,
			$this->getLogger(),
			$this->audit,
			$dataFolder
		);
		$this->migrationService->run();
		$this->accessControl->reload($this->getAuthConfigArray("access_control", []));
	}

	private function localizeCommandMetadata() : void{
		$language = $this->getLanguage();
		$meta = [
			"register" => [$language === "ru" ? "Регистрация аккаунта" : "Register an account", "/register <password> <password>"],
			"login" => [$language === "ru" ? "Вход в аккаунт" : "Log into an account", "/login <password>"],
			"passreset" => [$language === "ru" ? "Сбросить пароль игрока" : "Reset a player's password", "/passreset <name> <password>"],
			"changepass" => [$language === "ru" ? "Сменить свой пароль" : "Change your password", "/changepass <old> <new> <new>"],
			"logout" => [$language === "ru" ? "Выйти из аккаунта" : "Log out from your account", "/logout"],
			"reauth" => [$language === "ru" ? "Повторно пройти вход" : "Re-authenticate your account", "/reauth"],
			"authinfo" => [$language === "ru" ? "Показать состояние auth игрока" : "Show auth state for a player", "/authinfo <name>"],
			"authreload" => [$language === "ru" ? "Перезагрузить конфиг SessionAuth" : "Reload SessionAuth config", "/authreload"],
			"authhelp" => [$language === "ru" ? "Показать помощь SessionAuth" : "Show SessionAuth help", "/authhelp"]
		];

		foreach($meta as $name => [$description, $usage]){
			$command = $this->getServer()->getCommandMap()->getCommand($name);
			if($command !== null){
				$command->setDescription($description);
				$command->setUsage($usage);
			}
		}
	}

	private function sanitizeTextConfig(Config $config) : void{
		$all = $config->getAll();
		if(!is_array($all)){
			return;
		}
		$changed = $this->sanitizeArray($all);
		if($changed !== $all){
			$config->setAll($changed);
			$config->save();
		}
	}

	/**
	 * @param array<string|int, mixed> $value
	 * @return array<string|int, mixed>
	 */
	private function sanitizeArray(array $value) : array{
		foreach($value as $key => $item){
			if(is_array($item)){
				$value[$key] = $this->sanitizeArray($item);
				continue;
			}
			if(is_string($item)){
				$value[$key] = str_replace("В§", "§", $item);
			}
		}
		return $value;
	}

	/**
	 * @return list<string>
	 */
	private function buildAuthHelpLines() : array{
		$ru = $this->getLanguage() === "ru";
		return $ru ? [
			TextFormat::GOLD . "Помощь SessionAuth",
			TextFormat::GRAY . "Команды:",
			TextFormat::YELLOW . "/register" . TextFormat::WHITE . " - регистрация",
			TextFormat::GREEN . "/login" . TextFormat::WHITE . " - вход",
			TextFormat::AQUA . "/changepass" . TextFormat::WHITE . " - смена пароля",
			TextFormat::RED . "/logout" . TextFormat::WHITE . " - выход",
			TextFormat::LIGHT_PURPLE . "/reauth" . TextFormat::WHITE . " - повторный вход",
			TextFormat::GRAY . "/authinfo" . TextFormat::WHITE . " - статус игрока",
			TextFormat::GRAY . "/authreload" . TextFormat::WHITE . " - перезагрузка конфига",
			TextFormat::RED . "/passreset" . TextFormat::WHITE . " - сброс пароля, только op",
			TextFormat::GRAY . "/authhelp, /authh" . TextFormat::WHITE . " - эта помощь"
		] : [
			TextFormat::GOLD . "SessionAuth Help",
			TextFormat::GRAY . "Commands:",
			TextFormat::YELLOW . "/register" . TextFormat::WHITE . " - register",
			TextFormat::GREEN . "/login" . TextFormat::WHITE . " - login",
			TextFormat::AQUA . "/changepass" . TextFormat::WHITE . " - change password",
			TextFormat::RED . "/logout" . TextFormat::WHITE . " - log out",
			TextFormat::LIGHT_PURPLE . "/reauth" . TextFormat::WHITE . " - re-authenticate",
			TextFormat::GRAY . "/authinfo" . TextFormat::WHITE . " - player status",
			TextFormat::GRAY . "/authreload" . TextFormat::WHITE . " - reload config",
			TextFormat::RED . "/passreset" . TextFormat::WHITE . " - reset password, op only",
			TextFormat::GRAY . "/authhelp, /authh" . TextFormat::WHITE . " - this help"
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getDefaultAuthConfig() : array{
		return [
			"plugin" => [
				"name" => "SessionAuth",
				"language" => "ru"
			],
			"database" => [
				"type" => "sqlite",
				"file" => "players.db"
			],
			"logging" => [
				"file" => "logs/sessionauth.log"
			],
			"auth" => [
				"max_login_attempts" => 5,
				"temp_ban_minutes" => 5,
				"progressive_delay" => true,
				"reset_attempts_after_minutes" => 20,
				"login_timeout_seconds" => 30,
				"password_min_length" => 3,
				"password_max_length" => 20,
				"blocked_passwords" => []
			],
			"captcha" => [
				"enabled" => true,
				"mode" => "math",
				"only_on_suspicious" => true,
				"trigger_after_failed_attempts" => 3,
				"trigger_on_new_ip" => true,
				"digits" => 4
			],
			"ip_protection" => [
				"enabled" => true,
				"max_attempts_per_minute" => 8,
				"cooldown_minutes" => 5,
				"form_max_submits_per_10_seconds" => 4
			],
			"trusted_session" => [
				"enabled" => true,
				"expire_days" => 7,
				"require_ip_match" => true
			],
			"access_control" => [
				"enabled" => true,
				"allow_private_ips" => true,
				"allow_ips" => [],
				"block_ips" => [],
				"allow_countries" => [],
				"block_countries" => [],
				"block_on_lookup_failure" => false,
				"max_registrations_per_window" => 3,
				"registration_window_minutes" => 60,
				"registration_cooldown_minutes" => 30,
				"geoip_lookup_url" => "",
				"geoip_cache_minutes" => 60,
				"geoip_timeout_ms" => 900
			]
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getDefaultTextConfig() : array{
		return [
			"language" => "ru",
			"messages" => [
				"ru" => [
					"console_only" => "Эту команду можно использовать только из консоли.",
					"auth_required" => "Сначала войди в аккаунт.",
					"only_auth_commands" => "Сначала войди, затем используй команды регистрации и входа.",
					"not_logged_in" => "Ты еще не вошёл.",
					"logout_success" => "Ты вышел из аккаунта.",
					"reauth_prompt" => "Повторно введи пароль.",
					"passreset_usage" => "Использование: /passreset <ник> <пароль>",
					"player_not_registered" => "Игрок не зарегистрирован.",
					"new_password_invalid" => "Новый пароль не подходит.",
					"password_reset_done" => "Пароль игрока {name} сброшен.",
					"password_reset_notice" => "Твой пароль был изменён администратором. Войди снова.",
					"authinfo_usage" => "Использование: /authinfo <ник>",
					"authreload_success" => "Конфиг SessionAuth перезагружен.",
					"changepass_title" => "Смена пароля",
					"changepass_warning" => "Смена пароля доступна только после входа.",
					"changepass_old" => "Старый пароль",
					"changepass_new" => "Новый пароль",
					"changepass_confirm" => "Повтори новый пароль",
					"register_prompt" => TextFormat::YELLOW . "Регистрация",
					"login_prompt" => TextFormat::GREEN . "Вход",
					"register_title" => TextFormat::GOLD . "Регистрация",
					"login_title" => TextFormat::AQUA . "Вход",
					"register_warning" => TextFormat::GRAY . "Создай пароль, чтобы продолжить.",
					"login_warning" => TextFormat::GRAY . "Введи пароль, чтобы продолжить.",
					"register_input" => TextFormat::WHITE . "Пароль",
					"login_input" => TextFormat::WHITE . "Пароль",
					"register_confirm" => TextFormat::WHITE . "Повтори пароль",
					"captcha_text" => TextFormat::LIGHT_PURPLE . "Капча",
					"captcha_input" => TextFormat::WHITE . "Ответ",
					"login_success" => TextFormat::GREEN . "Вход выполнен.",
					"register_success" => TextFormat::GREEN . "Регистрация выполнена.",
					"already_registered" => TextFormat::RED . "Этот ник уже зарегистрирован.",
					"password_invalid" => TextFormat::RED . "Пароль слишком короткий или запрещён.",
					"password_mismatch" => TextFormat::RED . "Пароли не совпадают.",
					"wrong_password" => TextFormat::RED . "Неверный пароль.",
					"tempban_kick" => TextFormat::RED . "Временная блокировка. Осталось: {remaining}",
					"tempban_until" => TextFormat::GRAY . "Блокировка до: {time}",
					"ip_cooldown" => TextFormat::RED . "Слишком много попыток с этого IP. Попробуй позже: {time}",
					"registration_cooldown" => TextFormat::RED . "Регистрация с этого IP временно недоступна до {time}",
					"form_cooldown" => TextFormat::RED . "Слишком много отправок формы. Подожди немного.",
					"access_blocked" => TextFormat::RED . "Доступ ограничен: {reason}",
					"progressive_delay" => TextFormat::YELLOW . "Подожди {seconds} сек. перед следующей попыткой.",
					"timeout_kick" => TextFormat::RED . "Время ожидания входа истекло.",
					"auto_login" => TextFormat::GREEN . "Автовход выполнен по trusted session.",
					"captcha_wrong" => TextFormat::RED . "Неверная капча.",
					"changepass_success" => TextFormat::GREEN . "Пароль изменён.",
					"changepass_wrong_old" => TextFormat::RED . "Старый пароль неверный.",
					"not_registered" => TextFormat::RED . "Сначала нужно зарегистрироваться."
				],
				"en" => [
					"console_only" => "This command can only be used from console.",
					"auth_required" => "Please log in first.",
					"only_auth_commands" => "Please log in first, then use login/register commands.",
					"not_logged_in" => "You are not logged in yet.",
					"logout_success" => "You have been logged out.",
					"reauth_prompt" => "Please enter your password again.",
					"passreset_usage" => "Usage: /passreset <name> <password>",
					"player_not_registered" => "Player is not registered.",
					"new_password_invalid" => "New password is not valid.",
					"password_reset_done" => "Password for {name} has been reset.",
					"password_reset_notice" => "Your password was changed by an admin. Please log in again.",
					"authinfo_usage" => "Usage: /authinfo <name>",
					"authreload_success" => "SessionAuth config reloaded.",
					"changepass_title" => "Change password",
					"changepass_warning" => "You can change your password only after logging in.",
					"changepass_old" => "Old password",
					"changepass_new" => "New password",
					"changepass_confirm" => "Repeat new password",
					"register_prompt" => TextFormat::YELLOW . "Registration",
					"login_prompt" => TextFormat::GREEN . "Login",
					"register_title" => TextFormat::GOLD . "Registration",
					"login_title" => TextFormat::AQUA . "Login",
					"register_warning" => TextFormat::GRAY . "Create a password to continue.",
					"login_warning" => TextFormat::GRAY . "Enter your password to continue.",
					"register_input" => TextFormat::WHITE . "Password",
					"login_input" => TextFormat::WHITE . "Password",
					"register_confirm" => TextFormat::WHITE . "Repeat password",
					"captcha_text" => TextFormat::LIGHT_PURPLE . "Captcha",
					"captcha_input" => TextFormat::WHITE . "Answer",
					"login_success" => TextFormat::GREEN . "Login successful.",
					"register_success" => TextFormat::GREEN . "Registration successful.",
					"already_registered" => TextFormat::RED . "This name is already registered.",
					"password_invalid" => TextFormat::RED . "Password is too short or blocked.",
					"password_mismatch" => TextFormat::RED . "Passwords do not match.",
					"wrong_password" => TextFormat::RED . "Wrong password.",
					"tempban_kick" => TextFormat::RED . "Temporary ban. Remaining: {remaining}",
					"tempban_until" => TextFormat::GRAY . "Ban until: {time}",
					"ip_cooldown" => TextFormat::RED . "Too many attempts from this IP. Try again later: {time}",
					"registration_cooldown" => TextFormat::RED . "Registration from this IP is temporarily disabled until {time}",
					"form_cooldown" => TextFormat::RED . "Too many form submits. Please wait.",
					"access_blocked" => TextFormat::RED . "Access restricted: {reason}",
					"progressive_delay" => TextFormat::YELLOW . "Wait {seconds} sec before the next attempt.",
					"timeout_kick" => TextFormat::RED . "Login timeout reached.",
					"auto_login" => TextFormat::GREEN . "Auto-login completed via trusted session.",
					"captcha_wrong" => TextFormat::RED . "Wrong captcha.",
					"changepass_success" => TextFormat::GREEN . "Password changed.",
					"changepass_wrong_old" => TextFormat::RED . "Old password is incorrect.",
					"not_registered" => TextFormat::RED . "You need to register first."
				]
			]
		];
	}
}
