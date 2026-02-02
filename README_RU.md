<h1 align="center">Laravel AI Translator</h1>

<p align="center">
Инструмент для автоматического перевода языковых файлов Laravel с помощью AI
</p>

<p align="center">
<a href="README.md">English Documentation</a>
</p>

> **Примечание:** Это форк [kargnas/laravel-ai-translator](https://github.com/kargnas/laravel-ai-translator) с дополнительными возможностями.

## Возможности форка

### Поддержка кастомного API URL (OpenRouter)

Используйте OpenAI-совместимые API, такие как OpenRouter:

```php
'ai' => [
    'provider' => 'openai',
    'model' => 'anthropic/claude-sonnet-4',
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => 'https://openrouter.ai/api/v1',
],
```

### Защита ключей от перезаписи (Locked Keys)

Защитите определённые переводы от перезаписи при операциях `clean`:

1. **Пометьте ключи** в файлах перевода комментарием `// @locked`:
   ```php
   return [
       'welcome' => 'Добро пожаловать!', // @locked
       'greeting' => 'Привет, :name!',
   ];
   ```

2. **Экспортируйте защищённые ключи** в JSON файл:
   ```bash
   php artisan ai-translator:export-locked
   ```

3. **Заблокируйте все вендорские переводы** (Filament и др.):
   ```bash
   php artisan ai-translator:export-locked --lock-vendor
   ```

Защищённые ключи хранятся в `config/ai-translator-locked.json` и не загружаются Laravel автоматически.

#### Опции команды export-locked

| Опция | Описание |
|-------|----------|
| `--dry-run` | Показать что будет экспортировано без записи файла |
| `--lock-vendor` | Заблокировать все вендорские переводы |

### Поддержка вендорских переводов

**Перевод вендорских пакетов:**

```bash
# Перевести все вендорские пакеты
php artisan ai-translator:translate --vendor

# Или включить в конфиге постоянно
'translate_vendor' => true,
```

**Заблокировать вендорские переводы** (защита от перезаписи при clean):

```bash
php artisan ai-translator:export-locked --lock-vendor
```

Опция `--lock-vendor` сканирует `lang/vendor/{package}/{locale}/*.php` и блокирует все ключи.

---

## Установка

### Из оригинального репозитория

```bash
composer require kargnas/laravel-ai-translator
```

### Из этого форка

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ixbtcom/laravel-ai-translator.git"
        }
    ],
    "require": {
        "kargnas/laravel-ai-translator": "dev-master"
    }
}
```

## Настройка

1. Добавьте API ключ в `.env`:

   ```env
   # Для Anthropic Claude (рекомендуется)
   ANTHROPIC_API_KEY=your-api-key

   # Для OpenAI
   OPENAI_API_KEY=your-api-key

   # Для OpenRouter
   OPENROUTER_API_KEY=your-api-key

   # Для Google Gemini
   GEMINI_API_KEY=your-api-key
   ```

2. Опубликуйте конфигурацию:

   ```bash
   php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\ServiceProvider"
   ```

3. Настройте `config/ai-translator.php`:

   ```php
   return [
       'source_directory' => 'lang',
       'source_locale' => 'en',

       'ai' => [
           'provider' => 'anthropic',
           'model' => 'claude-sonnet-4-20250514',
           'api_key' => env('ANTHROPIC_API_KEY'),
           // 'base_url' => 'https://openrouter.ai/api/v1', // для OpenRouter
       ],

       // Путь к файлу с защищёнными ключами
       // 'locked_keys_file' => config_path('ai-translator-locked.json'),

       // Пропустить локали
       // 'skip_locales' => ['vendor'],

       // Дополнительные правила перевода
       'additional_rules' => [
           'default' => [
               "- Используйте дружелюбный и понятный тон, как у Discord.",
           ],
           'ru' => [
               "- Используйте вежливую форму обращения на 'вы'.",
               "- Избегайте канцеляризмов и сложных конструкций.",
           ],
       ],
   ];
   ```

## Использование

### Перевод PHP файлов

```bash
# Перевести все локали
php artisan ai-translator:translate

# Перевести параллельно (быстрее)
php artisan ai-translator:translate-parallel

# Перевести конкретные локали
php artisan ai-translator:translate --locale=ru,de

# Включая вендорские пакеты (Filament, etc.)
php artisan ai-translator:translate --vendor
```

### Перевод JSON файлов

```bash
php artisan ai-translator:translate-json
```

### Очистка переводов (для повторного перевода)

```bash
# Показать что будет удалено
php artisan ai-translator:clean --dry-run

# Очистить все переводы (кроме source)
php artisan ai-translator:clean

# Очистить конкретный файл
php artisan ai-translator:clean auth

# Без запроса подтверждения
php artisan ai-translator:clean --force
```

Защищённые ключи (`@locked` или из `locked_keys_file`) не будут удалены.

### Поиск неиспользуемых переводов

```bash
# Найти неиспользуемые ключи
php artisan ai-translator:find-unused

# С детальной информацией
php artisan ai-translator:find-unused --show-files

# Автоматически удалить
php artisan ai-translator:find-unused --force
```

### Экспорт защищённых ключей

```bash
# Сканировать @locked маркеры
php artisan ai-translator:export-locked

# Включая вендорские переводы
php artisan ai-translator:export-locked --lock-vendor

# Предварительный просмотр
php artisan ai-translator:export-locked --lock-vendor --dry-run
```

## Поддерживаемые провайдеры

| Провайдер | Модель | Рекомендация |
|-----------|--------|--------------|
| `anthropic` | `claude-sonnet-4-20250514` | Лучшее качество |
| `anthropic` | `claude-3-7-sonnet-latest` | Отличное качество |
| `anthropic` | `claude-3-haiku-20240307` | Быстро и дёшево |
| `openai` | `gpt-4o` | Хорошее качество |
| `openai` | `gpt-4o-mini` | Для тестов |
| `gemini` | `gemini-2.5-pro-preview-05-06` | Большой контекст |

### OpenRouter

Через OpenRouter можно использовать любую модель:

```php
'ai' => [
    'provider' => 'openai',
    'model' => 'anthropic/claude-sonnet-4', // или любая другая модель
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => 'https://openrouter.ai/api/v1',
],
```

## Структура файлов

### PHP файлы

```
lang/
├── en/
│   ├── auth.php
│   ├── validation.php
│   └── messages.php
├── ru/
│   └── ... (создаются автоматически)
└── vendor/
    └── filament/
        ├── en/
        │   └── actions.php
        └── ru/
            └── actions.php
```

### JSON файлы

```
lang/
├── en.json
├── ru.json
└── de.json
```

## Маркер @locked

Добавьте `// @locked` после значения, чтобы защитить ключ:

```php
return [
    // Этот ключ будет защищён
    'brand_name' => 'MyApp', // @locked

    // Или для вложенных ключей
    'errors' => [
        'custom_error' => 'Специальная ошибка', // @locked
    ],
];
```

После добавления маркеров выполните:

```bash
php artisan ai-translator:export-locked
```

Файл `config/ai-translator-locked.json` будет создан/обновлён.

## Лицензия

MIT License. Подробнее в [LICENSE.md](LICENSE.md).

## Благодарности

- Оригинальный пакет: [kargnas/laravel-ai-translator](https://github.com/kargnas/laravel-ai-translator)
- Автор оригинала: [Sangrak Choi](https://kargn.as)
