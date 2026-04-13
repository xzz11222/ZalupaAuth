# SessionAuth

`SessionAuth` is an auth/login/session plugin for PocketMine-MP 5.

## Version 2.1.0

- SQLite storage instead of `players.yml`
- trusted sessions with auto-login
- anti-bruteforce protection with limits, cooldowns, temp bans, and progressive delay
- captcha only on suspicious activity
- `/passreset` is operator-only
- added `/changepass`, `/logout`, `/reauth`, `/authinfo`, `/authreload`
- logic split into services

## Data files

- `plugin_data/SessionAuth/players.db` - SQLite database
- `plugin_data/SessionAuth/ConfigAuth.yaml` - plugin settings
- `plugin_data/SessionAuth/ConfigText.yaml` - Russian and English texts
- `plugin_data/SessionAuth/logs/sessionauth.log` - audit and service logs
- `plugin_data/ZalupaAuth/players.yml` - legacy YAML for migration

## Migration

On first launch, the plugin tries to migrate data from:

- `plugin_data/ZalupaAuth/players.yml`
- `plugin_data/ZalupaAuth/trusted.yml`
- `plugin_data/ZalupaAuth/security.yml`

After a successful migration, `players.yml` is renamed to `players.yml.bak`.

## Commands

- `/register <password> <password>`
- `/login <password>`
- `/changepass <old> <new> <new>`
- `/logout`
- `/reauth`
- `/authinfo <name>`
- `/authhelp` or `/authh`
- `/authreload` - operator only
- `/passreset <name> <password>` - operator only

## Permissions

- `sessionauth.command.register`
- `sessionauth.command.login`
- `sessionauth.command.changepass`
- `sessionauth.command.logout`
- `sessionauth.command.reauth`
- `sessionauth.command.authinfo`
- `sessionauth.command.authreload`
- `sessionauth.command.passreset`

`/passreset` is `op` by default.

## Config

`ConfigAuth.yaml` is created automatically with these sections:

- `plugin`
- `database`
- `logging`
- `auth`
- `captcha`
- `ip_protection`
- `trusted_session`
- `access_control`

`ConfigText.yaml` stores messages in `ru` and `en`. For a Russian server, edit `ru`.

## Behavior

- normal players see only the login form;
- captcha appears only when suspicious conditions are triggered;
- trusted sessions remove unnecessary logins;
- temp bans and IP cooldowns protect against brute force;
- `/authinfo` shows account state;
- `/authreload` reloads configs without a restart.
