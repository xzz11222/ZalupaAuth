# SessionAuth

`SessionAuth` - auth/login/session-плагин для PocketMine-MP 5.

## Версия 2.1.0

- SQLite вместо `players.yml`
- trusted session с авто-входом
- анти-брут: лимиты, cooldown, temp-ban и progressive delay
- капча включается только при подозрительной активности
- команда `/passreset` доступна только `op`
- добавлены `/changepass`, `/logout`, `/reauth`, `/authinfo`, `/authreload`
- логика разделена на сервисы

## Файлы данных

- `plugin_data/SessionAuth/players.db` - SQLite база
- `plugin_data/SessionAuth/ConfigAuth.yaml` - настройки плагина
- `plugin_data/SessionAuth/ConfigText.yaml` - русский и английский тексты
- `plugin_data/SessionAuth/logs/sessionauth.log` - аудит и служебные логи
- `plugin_data/ZalupaAuth/players.yml` - старый YAML, если нужна миграция

## Миграция

При первом запуске новой версии плагин пытается перенести данные из:

- `plugin_data/ZalupaAuth/players.yml`
- `plugin_data/ZalupaAuth/trusted.yml`
- `plugin_data/ZalupaAuth/security.yml`

После успешной миграции `players.yml` переименовывается в `players.yml.bak`.

## Команды

- `/register <password> <password>`
- `/login <password>`
- `/changepass <old> <new> <new>`
- `/logout`
- `/reauth`
- `/authinfo <name>`
- `/authhelp` или `/authh`
- `/authreload` - только `op`
- `/passreset <name> <password>` - только `op`

## Права

- `sessionauth.command.register`
- `sessionauth.command.login`
- `sessionauth.command.changepass`
- `sessionauth.command.logout`
- `sessionauth.command.reauth`
- `sessionauth.command.authinfo`
- `sessionauth.command.authreload`
- `sessionauth.command.passreset`

`/passreset` по умолчанию доступен только `op`.

## Конфиг

`ConfigAuth.yaml` создаётся автоматически с такими разделами:

- `plugin`
- `database`
- `logging`
- `auth`
- `captcha`
- `ip_protection`
- `trusted_session`
- `access_control`

`ConfigText.yaml` хранит тексты в секциях `ru` и `en`. Для русского сервера редактируй `ru`.

## Поведение

- обычный игрок видит только форму входа;
- капча появляется только если сработали подозрительные условия;
- trusted session убирает лишние входы;
- temp-ban и IP cooldown защищают от брута;
- `/authinfo` показывает состояние аккаунта;
- `/authreload` перечитывает конфиги без рестарта.
