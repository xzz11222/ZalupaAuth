<?php
declare(strict_types=1);

namespace zalupaauth;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use zalupaauth\form\CustomForm;
use function count;
use function date;
use function in_array;
use function is_array;
use function password_hash;
use function password_verify;
use function str_replace;
use function strtolower;
use function trim;
use function time;
use function max;

final class Main extends PluginBase implements Listener{
    private Config $playersConfig;
    private Config $trustedConfig;
    private Config $authConfig;
    private Config $textConfig;
    private Config $securityConfig;

    /** @var array<string, bool> */
    private array $authenticated = [];
    /** @var array<string, Position> */
    private array $joinPositions = [];
    /** @var array<string, int> */
    private array $joinTimes = [];
    /** @var array<string, int> */
    private array $loginImmunityUntil = [];

    protected function onEnable() : void{
        @mkdir($this->getDataFolder());
        $this->playersConfig = new Config($this->getDataFolder() . "players.yml", Config::YAML);
        $this->trustedConfig = new Config($this->getDataFolder() . "trusted.yml", Config::YAML);
        $this->authConfig = new Config($this->getDataFolder() . "ConfigAuth.yaml", Config::YAML, [
            "plugin_name" => "Auth",
            "password_min_length" => 3,
            "password_max_length" => 20,
            "blocked_passwords" => ["123", "1234", "12345", "111111", "password", "qwerty", "admin", "zalupa"],
            "trust_session_hours" => 1,
            "trust_sessions_persist_after_reload" => true,
            "language" => "ru",
            "login_timeout_seconds" => 30,
            "max_login_attempts" => 3,
            "temp_ban_minutes" => 3
        ]);
        $this->textConfig = new Config($this->getDataFolder() . "ConfigText.yaml", Config::YAML, [
            "login_title" => "Auth",
            "register_title" => "Auth"
        ]);
        $this->securityConfig = new Config($this->getDataFolder() . "security.yml", Config::YAML, [
            "login_attempts" => [],
            "temp_bans" => []
        ]);
        if(!(bool) $this->authConfig->get("trust_sessions_persist_after_reload", true)){
            $this->trustedConfig->setAll([]);
            $this->trustedConfig->save();
        }
        $this->authConfig->save();
        $this->textConfig->save();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            foreach($this->getServer()->getOnlinePlayers() as $player){
                if($this->isAuthenticated($player)){
                    continue;
                }

                $name = strtolower($player->getName());
                if(isset($this->joinPositions[$name])){
                    $player->teleport(clone $this->joinPositions[$name]);
                }
            }
        }), 1);
    }

    protected function onDisable() : void{
        $this->playersConfig->save();
        $this->trustedConfig->save();
        $this->authConfig->save();
        $this->textConfig->save();
        $this->securityConfig->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if($command->getName() === "passreset"){
            return $this->handlePasswordReset($sender, $args);
        }
        if(!$sender instanceof Player){
            $sender->sendMessage(TextFormat::RED . $this->t("console_only"));
            return true;
        }
        return match($command->getName()){
            "register" => count($args) === 0 ? $this->openRegisterForm($sender) : $this->handleRegister($sender, $args),
            "login" => count($args) === 0 ? $this->openLoginForm($sender) : $this->handleLogin($sender, $args),
            default => false
        };
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        $this->authenticated[$name] = false;
        $this->joinTimes[$name] = time();
        $this->setFrozenState($player, true);
        $this->joinPositions[$name] = clone $player->getPosition();
        if($this->isTemporarilyBanned($player)){
            $this->kickBannedPlayer($player);
            return;
        }
        if($this->isRegistered($player) && $this->hasTrustedSession($player)){
            $this->authenticated[$name] = true;
            $this->setFrozenState($player, false);
            unset($this->joinPositions[$name]);
            $this->grantLoginImmunity($player);
            return;
        }
        if($this->isRegistered($player)){
            $player->sendMessage($this->t("login_prompt"));
            $this->openLoginForm($player);
        }else{
            $player->sendMessage($this->t("register_prompt"));
            $this->openRegisterForm($player);
        }
        $this->scheduleLoginTimeout($player);
    }

    public function onQuit(PlayerQuitEvent $event) : void{
        $name = strtolower($event->getPlayer()->getName());
        unset($this->authenticated[$name], $this->joinPositions[$name], $this->joinTimes[$name]);
        unset($this->loginImmunityUntil[$name]);
    }

    public function onMove(PlayerMoveEvent $event) : void{
        $player = $event->getPlayer();
        if($this->isAuthenticated($player)){
            return;
        }
        $name = strtolower($player->getName());
        if(isset($this->joinPositions[$name])){
            $event->setTo(clone $this->joinPositions[$name]);
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

        $name = strtolower($entity->getName());
        if($this->isAuthenticated($entity)){
            $until = (int) ($this->loginImmunityUntil[$name] ?? 0);
            if($until > time() && $event->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK){
                $event->cancel();
            }
            return;
        }

        $event->cancel();
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
        $event->getPlayer()->sendMessage($this->t("auth_required"));
    }

    public function onCommandPreprocess(CommandEvent $event) : void{
        $sender = $event->getSender();
        if(!$sender instanceof Player || $this->isAuthenticated($sender)){
            return;
        }
        $message = strtolower(trim($event->getCommand()));
        $commandName = strtok($message, " ");
        if(in_array($commandName, ["login", "l", "log", "register", "reg"], true)){
            return;
        }
        $event->cancel();
        $sender->sendMessage($this->t("only_auth_commands"));
    }

    private function handleRegister(Player $player, array $args) : bool{
        if($this->isRegistered($player)){
            $player->sendMessage($this->t("already_registered"));
            $this->openLoginForm($player);
            return true;
        }
        $password = $args[0] ?? "";
        $confirmPassword = $args[1] ?? "";
        if($password === "" || $confirmPassword === ""){
            $player->sendMessage($this->t("register_usage"));
            $this->openRegisterForm($player);
            return true;
        }
        if(!$this->isPasswordAllowed($password)){
            $player->sendMessage($this->t("password_invalid"));
            $this->openRegisterForm($player);
            return true;
        }
        if($password !== $confirmPassword){
            $player->sendMessage($this->t("password_mismatch"));
            $this->openRegisterForm($player);
            return true;
        }
        $this->playersConfig->set(strtolower($player->getName()), password_hash($password, PASSWORD_DEFAULT));
        $this->playersConfig->save();
        $this->authenticated[strtolower($player->getName())] = true;
        $this->setFrozenState($player, false);
        unset($this->joinPositions[strtolower($player->getName())]);
        $this->grantLoginImmunity($player);
        $this->clearLoginSecurity($player);
        $this->storeTrustedSession($player);
        $player->sendMessage($this->t("register_success"));
        return true;
    }

    private function handleLogin(Player $player, array $args) : bool{
        if(!$this->isRegistered($player)){
            $player->sendMessage($this->t("not_registered"));
            $this->openRegisterForm($player);
            return true;
        }
        if($this->isAuthenticated($player)){
            $player->sendMessage($this->t("already_logged_in"));
            return true;
        }
        $password = $args[0] ?? "";
        $hash = (string) $this->playersConfig->get(strtolower($player->getName()), "");
        if($password === "" || !password_verify($password, $hash)){
            $this->registerFailedLogin($player);
            if($this->isTemporarilyBanned($player)){
                $this->kickBannedPlayer($player);
                return true;
            }
            $player->sendMessage($this->t("wrong_password"));
            $this->openLoginForm($player);
            return true;
        }
        $this->completeLogin($player);
        return true;
    }

    private function handlePasswordReset(CommandSender $sender, array $args) : bool{
        $targetName = trim((string) ($args[0] ?? ""));
        $newPassword = (string) ($args[1] ?? "");
        if($targetName === "" || $newPassword === ""){
            $sender->sendMessage($this->t("passreset_usage"));
            return true;
        }
        if(!$this->isPasswordAllowed($newPassword)){
            $sender->sendMessage($this->t("new_password_invalid"));
            return true;
        }
        $targetKey = strtolower($targetName);
        if(!$this->playersConfig->exists($targetKey)){
            $sender->sendMessage(TextFormat::RED . $this->t("player_not_registered"));
            return true;
        }
        $this->playersConfig->set($targetKey, password_hash($newPassword, PASSWORD_DEFAULT));
        $this->playersConfig->save();
        $this->trustedConfig->remove($targetKey);
        $this->trustedConfig->save();
        $this->authenticated[$targetKey] = false;
        $onlineTarget = $this->getServer()->getPlayerExact($targetName);
        if($onlineTarget instanceof Player){
            $onlineTarget->sendMessage($this->t("password_reset_notice"));
            $this->scheduleLoginForm($onlineTarget);
        }
        $sender->sendMessage(TextFormat::GREEN . $this->t("password_reset_done", ["name" => $targetName]));
        return true;
    }

    private function isPasswordAllowed(string $password) : bool{
        $min = max(1, (int) $this->authConfig->get("password_min_length", 3));
        $max = max($min, (int) $this->authConfig->get("password_max_length", 20));
        $normalized = strtolower(trim($password));
        if(strlen($password) < $min || strlen($password) > $max){
            return false;
        }
        $blocked = $this->authConfig->get("blocked_passwords", []);
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

    private function openLoginForm(Player $player) : bool{
        if($this->isAuthenticated($player)){
            return true;
        }
        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->openLoginForm($player);
                return;
            }
            $password = trim((string) ($data[1] ?? ""));
            $this->handleLogin($player, [$password]);
        });
        $form->setTitle($this->t("login_title"));
        $form->addLabel($this->t("login_warning"));
        $form->addInput($this->t("login_input"), $this->t("login_input"));
        $player->sendForm($form);
        return true;
    }

    private function scheduleLoginForm(Player $player, int $delayTicks = 30) : void{
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            if($player->isConnected() && !$this->isAuthenticated($player)){
                $this->openLoginForm($player);
            }
        }), $delayTicks);
    }

    private function scheduleLoginTimeout(Player $player) : void{
        $timeoutSeconds = max(5, (int) $this->authConfig->get("login_timeout_seconds", 30));
        $playerName = strtolower($player->getName());
        $startedAt = $this->joinTimes[$playerName] ?? time();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $playerName, $startedAt) : void{
            if(!$player->isConnected() || $this->isAuthenticated($player)){
                return;
            }
            if(($this->joinTimes[$playerName] ?? 0) !== $startedAt){
                return;
            }
            $player->kick($this->t("timeout_kick"));
        }), $timeoutSeconds * 20);
    }

    private function registerFailedLogin(Player $player) : void{
        $name = strtolower($player->getName());
        $attempts = $this->getFailedLoginAttempts($name) + 1;
        $this->setFailedLoginAttempts($name, $attempts);
        $maxAttempts = max(1, (int) $this->authConfig->get("max_login_attempts", 3));
        if($attempts >= $maxAttempts){
            $banMinutes = max(1, (int) $this->authConfig->get("temp_ban_minutes", 3));
            $this->setTempBan($name, time() + ($banMinutes * 60));
            $this->setFailedLoginAttempts($name, 0);
        }
    }

    private function getFailedLoginAttempts(string $name) : int{
        $attempts = $this->securityConfig->get("login_attempts", []);
        return is_array($attempts) ? max(0, (int) ($attempts[strtolower($name)] ?? 0)) : 0;
    }

    private function setFailedLoginAttempts(string $name, int $value) : void{
        $attempts = $this->securityConfig->get("login_attempts", []);
        if(!is_array($attempts)){
            $attempts = [];
        }
        $attempts[strtolower($name)] = max(0, $value);
        $this->securityConfig->set("login_attempts", $attempts);
        $this->securityConfig->save();
    }

    private function setTempBan(string $name, int $until) : void{
        $bans = $this->securityConfig->get("temp_bans", []);
        if(!is_array($bans)){
            $bans = [];
        }
        $bans[strtolower($name)] = $until;
        $this->securityConfig->set("temp_bans", $bans);
        $this->securityConfig->save();
    }

    private function isTemporarilyBanned(Player $player) : bool{
        $name = strtolower($player->getName());
        $bans = $this->securityConfig->get("temp_bans", []);
        if(!is_array($bans)){
            return false;
        }
        $until = (int) ($bans[$name] ?? 0);
        if($until <= time()){
            if(isset($bans[$name])){
                unset($bans[$name]);
                $this->securityConfig->set("temp_bans", $bans);
                $this->securityConfig->save();
            }
            return false;
        }
        return true;
    }

    private function kickBannedPlayer(Player $player) : void{
        $bans = $this->securityConfig->get("temp_bans", []);
        $until = is_array($bans) ? (int) ($bans[strtolower($player->getName())] ?? 0) : 0;
        $player->kick(str_replace("{time}", date("d.m.Y H:i", $until), $this->t("tempban_kick")) . "\n" . str_replace("{time}", date("d.m.Y H:i", $until), $this->t("tempban_until")));
    }

    private function clearLoginSecurity(Player $player) : void{
        $name = strtolower($player->getName());
        $attempts = $this->securityConfig->get("login_attempts", []);
        if(is_array($attempts) && isset($attempts[$name])){
            unset($attempts[$name]);
            $this->securityConfig->set("login_attempts", $attempts);
        }
        $bans = $this->securityConfig->get("temp_bans", []);
        if(is_array($bans) && isset($bans[$name])){
            unset($bans[$name]);
            $this->securityConfig->set("temp_bans", $bans);
        }
        $this->securityConfig->save();
    }

    private function openRegisterForm(Player $player) : bool{
        if($this->isAuthenticated($player)){
            return true;
        }
        $form = new CustomForm(function(Player $player, ?array $data) : void{
            if($data === null){
                $this->openRegisterForm($player);
                return;
            }
            $password = trim((string) ($data[1] ?? ""));
            $confirmPassword = trim((string) ($data[2] ?? ""));
            $this->handleRegister($player, [$password, $confirmPassword]);
        });
        $form->setTitle($this->t("register_title"));
        $form->addLabel($this->t("register_warning"));
        $form->addInput($this->t("register_input"), $this->t("register_input"));
        $form->addInput($this->t("register_confirm"), $this->t("register_confirm"));
        $player->sendForm($form);
        return true;
    }

    private function isRegistered(Player $player) : bool{
        return $this->playersConfig->exists(strtolower($player->getName()));
    }

    private function isAuthenticated(Player $player) : bool{
        return ($this->authenticated[strtolower($player->getName())] ?? false) === true;
    }

    private function hasTrustedSession(Player $player) : bool{
        $trusted = $this->trustedConfig->get(strtolower($player->getName()));
        if(!is_array($trusted)){
            return false;
        }
        $expiresAt = (int) ($trusted["expiresAt"] ?? 0);
        if($expiresAt < time()){
            $this->trustedConfig->remove(strtolower($player->getName()));
            $this->trustedConfig->save();
            return false;
        }
        $savedIp = (string) ($trusted["ip"] ?? "");
        $savedXuid = (string) ($trusted["xuid"] ?? "");
        return $savedIp === $player->getNetworkSession()->getIp() && ($savedXuid === "" || $savedXuid === $player->getXuid());
    }

    private function storeTrustedSession(Player $player) : void{
        $this->trustedConfig->set(strtolower($player->getName()), [
            "ip" => $player->getNetworkSession()->getIp(),
            "xuid" => $player->getXuid(),
            "expiresAt" => time() + ((int) $this->authConfig->get("trust_session_hours", 1) * 3600)
        ]);
        $this->trustedConfig->save();
    }

    private function completeLogin(Player $player) : void{
        $this->authenticated[strtolower($player->getName())] = true;
        $this->setFrozenState($player, false);
        unset($this->joinPositions[strtolower($player->getName())]);
        $this->grantLoginImmunity($player);
        $this->storeTrustedSession($player);
        $player->sendMessage($this->t("login_success"));
    }

    private function setFrozenState(Player $player, bool $frozen) : void{
        if($frozen){
            $player->setMotion($player->getMotion()->multiply(0));
        }
    }

    private function grantLoginImmunity(Player $player) : void{
        $this->loginImmunityUntil[strtolower($player->getName())] = time() + 3;
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            if($player->isConnected()){
                $this->loginImmunityUntil[strtolower($player->getName())] = 0;
            }
        }), 44);
    }

    private function t(string $key) : string{
        $lang = strtolower((string) $this->authConfig->get("language", "ru"));
        $messages = [
            "ru" => [
                "console_only" => "§cКоманду можно использовать только в игре.",
                "login_prompt" => "§eВойди через §6/login <пароль>",
                "register_prompt" => "§aЗарегистрируйся через §6/register <пароль> <пароль>",
                "login_warning" => "Войди в аккаунт, чтобы продолжить.",
                "register_warning" => "Зарегистрируй аккаунт, чтобы продолжить.",
                "login_input" => "Ваш пароль",
                "register_input" => "Пароль",
                "register_confirm" => "Подтвердите пароль",
                "auth_required" => "§cСначала войди или зарегистрируйся.",
                "only_auth_commands" => "§cДоступны только §6/login §c/ §6l §c/ §6log §cи §6/register §c/ §6reg§c, пока ты не вошёл.",
                "already_registered" => "§cТы уже зарегистрирован. Используй §6/login <пароль>",
                "register_usage" => "§cИспользуй: §6/register <пароль> <пароль>",
                "password_invalid" => "§cПароль должен быть от 3 до 20 символов и не должен быть слишком простым.",
                "password_mismatch" => "§cПароли не совпадают.",
                "register_success" => "§aРегистрация завершена. Ты вошёл в аккаунт.",
                "not_registered" => "§cТы не зарегистрирован. Используй §6/register <пароль>",
                "already_logged_in" => "§aТы уже вошёл.",
                "wrong_password" => "§cНеверный пароль.",
                "passreset_usage" => "§eИспользуй: §6/passreset <name> <password>",
                "new_password_invalid" => "§cНовый пароль должен быть от 3 до 20 символов и не должен быть слишком простым.",
                "login_success" => "§aВход выполнен.",
                "player_not_registered" => "§cИгрок не зарегистрирован.",
                "password_reset_notice" => "§eТвой пароль был сброшен администрацией. Войди снова с новым паролем.",
                "password_reset_done" => "§aПароль игрока {name} успешно сброшен.",
            ],
            "en" => [
                "console_only" => "§cThis command can only be used in game.",
                "login_prompt" => "§eLog in with §6/login <password>",
                "register_prompt" => "§aRegister with §6/register <password> <password>",
                "login_warning" => "Log in to continue.",
                "register_warning" => "Register to continue.",
                "login_input" => "Your password",
                "register_input" => "Password",
                "register_confirm" => "Confirm password",
                "auth_required" => "§cPlease log in or register first.",
                "only_auth_commands" => "§cOnly §6/login §c/ §6l §c/ §6log §cand §6register §c/ §6reg§c are available until you log in.",
                "already_registered" => "§cYou are already registered. Use §6/login <password>",
                "register_usage" => "§cUse: §6/register <password> <password>",
                "password_invalid" => "§cPassword must be 3-20 characters and not too simple.",
                "password_mismatch" => "§cPasswords do not match.",
                "register_success" => "§aRegistration complete. You are logged in.",
                "not_registered" => "§cYou are not registered. Use §6/register <password>",
                "already_logged_in" => "§aYou are already logged in.",
                "wrong_password" => "§cWrong password.",
                "passreset_usage" => "§eUse: §6/passreset <name> <password>",
                "new_password_invalid" => "§cNew password must be 3-20 characters and not too simple.",
                "login_success" => "§aLogin successful.",
                "player_not_registered" => "§cPlayer is not registered.",
                "password_reset_notice" => "§eYour password was reset by an admin. Please log in again with the new password.",
                "password_reset_done" => "§aPassword for {name} was reset successfully.",
            ]
        ];
        return $messages[$lang][$key] ?? ($messages["ru"][$key] ?? "Auth");
    }
}
