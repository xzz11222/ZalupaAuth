# ZalupaAuth

`ZalupaAuth` - плагин авторизации для PocketMine-MP.

## Возможности

- регистрация и вход по паролю;
- доверенная сессия после успешного входа;
- кик, если игрок не ввел пароль за заданное время;
- временный бан после нескольких неудачных попыток входа;
- ограничение длины пароля и список запрещенных паролей;
- локализация сообщений `ru/en`;
- настройка текста форм через отдельный `ConfigText.yaml`.

## Совместимость

- API: `5.0.0`
- PocketMine-MP: `5.x`

## Команды

- `/register <password> <password>` - зарегистрировать аккаунт
- `/login <password>` - войти в аккаунт
- `/passreset <name> <password>` - сбросить пароль игроку

Алиасы:

- `/reg` -> `/register`
- `/l`, `/log` -> `/login`

## Права

- `zalupaauth.command.register` - `/register`
- `zalupaauth.command.login` - `/login`
- `zalupaauth.command.passreset` - `/passreset`

## Настройка

Плагин использует два основных файла в `plugin_data/ZalupaAuth/`:

- `ConfigAuth.yaml` - логика и безопасность;
- `ConfigText.yaml` - тексты форм и экранов.

### `ConfigAuth.yaml`

- `plugin_name` - имя плагина для сообщений и интерфейса;
- `trust_session_hours` - сколько часов хранится trust-сессия;
- `trust_sessions_persist_after_reload` - сохранять trust-сессии после перезагрузки `on/off`;
- `language` - язык сообщений: `ru` или `en`;
- `password_min_length` - минимальная длина пароля;
- `password_max_length` - максимальная длина пароля;
- `blocked_passwords` - список запрещенных паролей;
- `login_timeout_seconds` - через сколько секунд кикать за отсутствие входа;
- `max_login_attempts` - сколько раз можно ошибиться с паролем;
- `temp_ban_minutes` - на сколько минут банить после лимита попыток;
- `help_text` - пояснения к пунктам конфига.

### `ConfigText.yaml`

- `login_title` - заголовок формы входа;
- `register_title` - заголовок формы регистрации;
- `login_warning` - предупреждение в форме;
- `login_input` - плейсхолдер для поля входа;
- `register_input` - плейсхолдер первого поля регистрации;
- `register_confirm` - плейсхолдер подтверждения пароля;
- `timeout_kick` - текст кика за таймаут входа;
- `tempban_kick` - текст кика за временный бан;
- `tempban_until` - строка с временем бана, например `До: {time}`.

## Пример `ConfigAuth.yaml`

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

## Пример `ConfigText.yaml`

```yaml
login_title: Вход
register_title: Регистрация
login_warning: Внимание: пароль виден на экране при вводе.
login_input: Введи пароль
register_input: Пароль
register_confirm: Повтори пароль
timeout_kick: Вы не ввели пароль вовремя.
tempban_kick: Вы временно забанены за слишком большое число попыток входа.
tempban_until: До: {time}
```

## Как работает trust-сессия

После успешного входа игрок получает trust-сессию на срок из `trust_session_hours`.
Если включен режим `trust_sessions_persist_after_reload: true`, сессии сохраняются после перезагрузки.
Если поставить `false`, trust-сессии будут очищаться при старте плагина.

## Ограничения пароля

Плагин проверяет:

- минимальную длину;
- максимальную длину;
- список запрещенных паролей;
- совпадение пароля и подтверждения при регистрации.

## Локализация

В `ConfigAuth.yaml` можно выбрать:

- `ru` - русский;
- `en` - английский.

Если язык не указан, используется `ru`.
