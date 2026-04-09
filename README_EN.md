# ZalupaAuth

`ZalupaAuth` is an authentication plugin for PocketMine-MP.

## Features

- password-based registration and login;
- trusted sessions after a successful login;
- kick if a player does not enter a password within a configured time;
- temporary ban after repeated failed login attempts;
- password length limits and blocked password list;
- `ru/en` message localization;
- configurable form text via `ConfigText.yaml`.

## Compatibility

- API: `5.0.0`
- PocketMine-MP: `5.x`

## Commands

- `/register <password> <password>` - register an account
- `/login <password>` - log into an account
- `/passreset <name> <password>` - reset a player's password

Aliases:

- `/reg` -> `/register`
- `/l`, `/log` -> `/login`

## Permissions

- `zalupaauth.command.register` - `/register`
- `zalupaauth.command.login` - `/login`
- `zalupaauth.command.passreset` - `/passreset`

## Configuration

The plugin uses two main files in `plugin_data/ZalupaAuth/`:

- `ConfigAuth.yaml` - logic and security;
- `ConfigText.yaml` - form and screen text.

### `ConfigAuth.yaml`

- `plugin_name` - plugin display name used in messages and UI;
- `trust_session_hours` - trust session duration in hours;
- `trust_sessions_persist_after_reload` - keep trust sessions after restart `on/off`;
- `language` - message language: `ru` or `en`;
- `password_min_length` - minimum password length;
- `password_max_length` - maximum password length;
- `blocked_passwords` - blocked passwords list;
- `login_timeout_seconds` - how many seconds before a login timeout kick;
- `max_login_attempts` - how many wrong password attempts are allowed;
- `temp_ban_minutes` - temporary ban duration after the limit is reached;
- `help_text` - helper descriptions for config entries.

### `ConfigText.yaml`

- `login_title` - login form title;
- `register_title` - registration form title;
- `login_warning` - warning label in the form;
- `login_input` - login input placeholder;
- `register_input` - first registration input placeholder;
- `register_confirm` - password confirmation placeholder;
- `timeout_kick` - timeout kick message;
- `tempban_kick` - temp ban kick message;
- `tempban_until` - ban time template, for example `Until: {time}`.

## Example `ConfigAuth.yaml`

```yaml
plugin_name: Auth
trust_session_hours: 1
trust_sessions_persist_after_reload: true
language: ru
password_min_length: 3
password_max_length: 20
blocked_passwords:
  - "123"
  - "1234"
  - "12345"
  - "111111"
  - "password"
  - "qwerty"
  - "admin"
  - "zalupa"
login_timeout_seconds: 30
max_login_attempts: 3
temp_ban_minutes: 3
```

## Example `ConfigText.yaml`

```yaml
login_title: Login
register_title: Register
login_warning: Warning: your password is visible while typing.
login_input: Enter password
register_input: Password
register_confirm: Repeat password
timeout_kick: You did not enter your password in time.
tempban_kick: You are temporarily banned for too many login attempts.
tempban_until: Until: {time}
```

## Trusted Sessions

After a successful login, the player gets a trusted session for the duration set in `trust_session_hours`.
If `trust_sessions_persist_after_reload: true`, sessions survive restarts.
If set to `false`, trusted sessions are cleared when the plugin starts.

## Password Rules

The plugin checks:

- minimum length;
- maximum length;
- blocked password list;
- password confirmation during registration.

## Localization

Set `language` in `ConfigAuth.yaml` to:

- `ru` - Russian;
- `en` - English.

If language is not specified, `ru` is used.
