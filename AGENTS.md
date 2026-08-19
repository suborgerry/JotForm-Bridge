# AGENTS.md — Jotform Bridge

## Проект

Разрабатываем standalone WordPress-плагин:

**Jotform Bridge**

Назначение плагина — использовать Jotform как headless backend для WordPress-форм.

Jotform отвечает за:

* структуру форм;
* submissions;
* email notifications;
* integrations;
* хранение данных.

WordPress отвечает за:

* frontend HTML;
* custom templates;
* UX;
* отправку данных через собственный REST endpoint;
* server-side validation;
* mapping данных в Jotform.

Основной сценарий — полностью кастомная HTML-разметка формы.

В дальнейшем также поддерживается автоматическая генерация формы из Jotform schema.

---

# Минимальные системные требования

Поддерживаем:

* WordPress >= 6.4
* PHP >= 8.0

Plugin header должен содержать:

```text
Requires at least: 6.4
Requires PHP: 8.0
```

Не использовать PHP features, появившиеся после PHP 8.0, если они делают PHP 8.0 несовместимым.

Например, не делать обязательными:

* enums;
* readonly properties;
* intersection types;
* PHP 8.1+ syntax.

Можно использовать возможности PHP 8.0:

* typed properties;
* union types;
* constructor property promotion;
* match;
* nullsafe operator.

---

# Универсальность

Плагин должен быть полностью standalone.

Он НЕ должен зависеть от:

* Sage;
* Blade;
* Acorn;
* ACF;
* Elementor;
* WooCommerce;
* Gutenberg;
* jQuery;
* React;
* Vue;
* Laravel;
* конкретной WordPress-темы;
* конкретного сайта;
* Node.js в production;
* frontend build system сайта.

Sage и Blade в текущей версии вообще не поддерживаем и не учитываем.

Custom templates версии 1 — обычные PHP templates.

---

# Composer

Composer разрешен для разработки и PSR-4 autoloading.

Однако конечный plugin package должен работать после обычной установки WordPress-плагина.

Пользователь не должен выполнять:

```bash
composer install
```

после установки ZIP.

Если используется Composer autoload, production package должен содержать необходимые runtime autoload files.

Не добавлять сторонние Composer dependencies без реальной необходимости.

Предпочитать WordPress Core API.

---

# Структура репозитория

Код плагина живет в отдельном каталоге в корне репозитория:

```text
jotform-bridge/
```

Все остальное в корне — dev-обвязка, которая в релиз не попадает.

Ориентировочная структура:

```text
.
├── AGENTS.md
├── README.md
├── prompts/
├── composer.json          # dev-зависимости и PSR-4 autoload
├── phpunit.xml.dist
├── tests/
│   ├── Unit/
│   └── Fixtures/
└── jotform-bridge/        # ← это и есть плагин
    ├── jotform-bridge.php # main plugin file с header
    ├── uninstall.php      # если нужен
    ├── src/
    ├── assets/
    │   └── frontend.js
    ├── languages/
    ├── readme.txt         # WordPress.org style, этап 6
    └── vendor/            # только production autoload, если используется
```

Правила:

* main plugin file называется `jotform-bridge.php`;
* plugin slug: `jotform-bridge`;
* text domain: `jotform-bridge`;
* namespace root: `JotformBridge\`;
* PSR-4 маппинг: `JotformBridge\` → `jotform-bridge/src/`;
* префикс для options/transients/hooks: `jotform_bridge_`;
* префикс для CSS-классов: `jfb-`.

Не размещать PHP-код плагина в корне репозитория.

Не размещать тесты и fixtures внутри `jotform-bridge/`.

---

# Упаковка релиза

Релизный ZIP — это содержимое каталога `jotform-bridge/` и ничего больше.

В ZIP не должно попадать:

```text
AGENTS.md
prompts/
tests/
composer.json
phpunit.xml.dist
.codex/
node_modules/
dev-зависимости внутри vendor/
```

После распаковки в `wp-content/plugins/` плагин обязан работать без:

```bash
composer install
npm install
npm run build
```

Если для autoload используется Composer, в `jotform-bridge/vendor/` должен лежать
production autoloader, сгенерированный с `--no-dev`.

Если сторонних runtime-зависимостей нет — предпочтительнее собственный простой
PSR-4 autoloader внутри `src/`, без каталога `vendor/` в релизе вообще.

---

# Git workflow

Один этап — один осмысленный набор коммитов.

* работать в ветке вида `stage-1-plugin-core`, `stage-2-schema-engine` и т. д.;
* не коммитить в `main` напрямую;
* не коммитить секреты, `vendor/` dev-зависимостей, локальные WordPress-файлы;
* в конце этапа сделать коммит с внятным описанием того, что реализовано;
* не делать merge/push и не открывать PR без явной просьбы пользователя.

---

# Основная архитектурная идея

Не привязывать frontend к Jotform Form ID или Question ID.

Публичный код работает через локальную сущность:

**Integration**

Например:

```text
contact
consultation
career
```

Integration связывает:

```text
local slug
    ↓
Jotform Form
    ↓
rendering mode
    ↓
optional custom template
```

Theme code не должен содержать Jotform Form ID.

---

# Integration

Integration должна иметь минимум следующие данные:

```text
Name
Slug
Jotform Form ID
Rendering Mode
Template Slug
Active
Success Action
Redirect Page ID
Redirect Delay
```

Success actions:

```text
message
redirect
```

`message` — поведение по умолчанию: показать success message в слоте template.

`redirect` — после успешного submission выполнить redirect на выбранную страницу.

Redirect target выбирается отдельно для каждой Integration.

Rendering modes:

```text
custom
auto
```

Одна Jotform Form может использоваться несколькими integrations.

Например:

```text
Jotform Form:
Request Consultation

Integrations:
consultation
consultation-popup
consultation-footer
```

Это намеренное требование.

---

# Хранение данных

Не создавать custom database tables без необходимости.

Использовать WordPress options.

Предпочтительно разделить:

```text
jotform_bridge_settings
jotform_bridge_integrations
```

или аналогичные namespaced options.

Все данные:

* sanitize при сохранении;
* validate перед использованием.

---

# Jotform API

Создать отдельный:

```text
JotformClient
```

Он является единственным низкоуровневым слоем взаимодействия с Jotform REST API.

Другие части приложения не должны самостоятельно строить произвольные HTTP-запросы к Jotform.

Использовать:

```php
wp_remote_get()
wp_remote_post()
```

или соответствующие функции WordPress HTTP API.

Не использовать прямой cURL без объективной причины.

JotformClient должен обрабатывать:

* WP_Error;
* timeout;
* non-2xx response;
* malformed response;
* API error;
* unavailable service.

Не молчать при ошибках.

---

# Jotform API documentation

Не угадывать формат Jotform REST API.

Перед реализацией конкретного API interaction проверить актуальную официальную документацию Jotform.

Особенно важно проверить:

* authentication;
* получение forms;
* получение questions;
* submission endpoint;
* submission payload;
* composite fields;
* checkbox/radio/select formats.

Jotform MCP НЕ является заменой REST API documentation.

Production plugin использует Jotform REST API.

---

# API Key

Jotform API key никогда не должен попадать на frontend и никогда не должен
попадать в базу данных.

Единственный источник — constant в `wp-config.php`:

```php
define('JOTFORM_API_KEY', '...');
```

WordPress option как источник ключа не используется: в Admin нет поля для
ввода ключа, `save()` ключ не пишет, а ключ, оставшийся в option от более ранней
версии, удаляется при upgrade/activation и никогда не читается.

Если constant не определена:

* API-вызовы не выполняются;
* на screens плагина и в списке плагинов показывается admin notice на английском
  с той строкой, которую нужно добавить в `wp-config.php`;
* на settings screen строка API Key показывает ту же инструкцию.

API key:

* никогда не выводить полностью обратно (только последние 4 символа);
* не добавлять в frontend HTML;
* не добавлять в JavaScript;
* не возвращать через REST API;
* не писать в debug logs.

---

# Секреты и тестовое окружение

## Где брать credentials

Агент **не** хранит и **не** запрашивает API key в переписке.

Порядок:

1. локальный `.env` в корне репозитория (в `.gitignore`, в репозиторий не попадает);
2. переменная окружения `JOTFORM_API_KEY`;
3. если ни того, ни другого нет — не выдумывать ключ и не просить его прислать
   сообщением, а выполнить проверки на fixtures/mocks и явно написать в отчете,
   что live connection не проверялось.

Шаблон `.env.example` (без значений) держать в репозитории.

Никогда не записывать реальный ключ в:

* `AGENTS.md`;
* `README.md`;
* `prompts/`;
* тесты и fixtures;
* любой коммит.

## Тестовая Jotform form

```text
Test form ID:      262215084646053  (форма "Test API")
Разрешение на live write: <не выдано>
```

Ключ и test form ID лежат в локальном `.env` (в репозиторий не попадают).
Аккаунт находится в EU-регионе: `api.jotform.com` отвечает `responseCode 301`
с указанием использовать `eu-api.jotform.com`, поэтому в настройках плагина
для локальных проверок выбирается регион EU.

Live write по-прежнему не разрешен. Пока разрешение не выдано, действует
безопасный режим:

* read-only обращения к Jotform допустимы;
* **никаких** submissions в реальный аккаунт;
* никаких изменений и удалений форм;
* end-to-end проверка выполняется до upstream boundary с mock-ом Jotform API;
* в отчете этапа явно указывается, что live upstream write не выполнялся.

Этапы 4, 5 и 6 упоминают live submission как условную возможность. Условие
считается невыполненным, пока в этом блоке рядом с test form ID не появится
явное разрешение на live write.

Production-форму не использовать ни при каких обстоятельствах.

---

# Jotform region

Все API URL должны формироваться централизованно.

Не hardcode API base URL в разных классах.

Поддержать настройку региона/base URL через JotformClient configuration.

Архитектура должна позволять поддерживать Standard/EU и другие Jotform environments.

---

# Schema

Jotform question schema нельзя использовать непосредственно во frontend.

Нужен слой:

```text
Jotform Questions
       ↓
FieldNormalizer
       ↓
Normalized Schema
```

Normalized Schema является внутренним контрактом приложения.

---

# Semantic fields

Custom template не должен знать Jotform qid.

Нельзя требовать:

```html
<input name="q7">
```

или:

```html
<input data-jotform-field="7">
```

Использовать semantic identifiers.

Например:

```html
<input data-jotform-field="email">
```

Composite fields:

```html
<input data-jotform-field="name.first">
<input data-jotform-field="name.last">
```

Address:

```html
<input data-jotform-field="address.addr_line1">
<input data-jotform-field="address.addr_line2">
<input data-jotform-field="address.city">
<input data-jotform-field="address.state">
<input data-jotform-field="address.postal">
<input data-jotform-field="address.country">
```

**Зафиксировано на Этапе 2** из Jotform API, а не придумано:

* Full Name — ключи `sublabels`: `prefix`, `first`, `middle`, `last`, `suffix`;
  `first` и `last` есть всегда, остальные — только при `prefix|middle|suffix = Yes`.
* Address — answer/prefill-ключи `addr_line1`, `addr_line2`, `city`, `state`,
  `postal`, `country`; какие из них присутствуют, определяет свойство `subfields`
  (токены `st1|st2|city|state|zip|country`).

Родительская часть пути — это semantic key самого поля (из Jotform `name`),
поэтому `address` в примерах выше — иллюстрация, а не фиксированное имя.

Не подгонять нормализацию под примеры из документации проекта.

Разработчик template не должен знать внутренние Jotform Question IDs.

---

# Semantic key generation

Использовать наиболее стабильный machine-readable identifier, предоставляемый Jotform schema.

Не полагаться только на visible label.

Labels могут:

* изменяться;
* повторяться;
* содержать пробелы;
* содержать special characters.

Raw Jotform qid должен сохраняться внутри normalized schema как authoritative mapping.

Если semantic key collision невозможно разрешить однозначно:

* не угадывать;
* пометить schema как problematic;
* показать понятную ошибку administrator.

---

# FieldNormalizer

FieldNormalizer преобразует Jotform-specific data во внутренний формат.

Концептуальный пример:

```php
[
    'key'      => 'email',
    'qid'      => '7',
    'type'     => 'email',
    'required' => true,
]
```

Composite:

```php
[
    'key'      => 'name',
    'qid'      => '3',
    'type'     => 'name',
    'required' => true,
    'children' => [
        'first',
        'last',
    ],
]
```

Поддерживаемые основные типы:

* textbox;
* textarea;
* email;
* phone;
* full name;
* address;
* dropdown/select;
* radio;
* checkbox;
* number;
* date, если возможно без неправильных предположений.

Unsupported fields:

* не должны silently corrupt data;
* должны отмечаться как unsupported;
* должны быть видны administrator.

---

# Custom Templates

Custom templates располагаются в активной WordPress theme.

Базовый каталог:

```text
/forms/
```

Пример:

```text
wp-content/themes/example/
└── forms/
    ├── contact.php
    └── consultation.php
```

Поддерживать:

* active theme;
* child theme;
* parent theme.

Child theme имеет приоритет при конфликте template slug.

Предоставить filter:

```text
jotform_bridge_template_paths
```

чтобы разработчики могли добавлять дополнительные template directories.

---

# Template metadata

Template обнаруживается по file header.

Пример:

```php
<?php
/*
Jotform Template Name: Contact Form
Jotform Template Slug: contact
*/
?>
```

Обязательные metadata:

```text
Jotform Template Name
Jotform Template Slug
```

Jotform Form ID внутри template запрещен.

Неправильно:

```text
Jotform Form ID: 123456789
```

Template идентифицирует только frontend template.

Связь:

```text
Template
↔
Jotform Form
```

хранится в Integration configuration.

---

# TemplateScanner

TemplateScanner должен:

* сканировать только разрешенные directories;
* находить PHP templates;
* читать header без исполнения файла;
* проверять metadata;
* формировать registry;
* отклонять duplicate slugs с понятной диагностикой;
* учитывать child theme priority;
* предотвращать directory traversal.

Нельзя сканировать theme files на каждом frontend request.

Registry должен кешироваться.

Добавить возможность:

```text
Rescan Templates
```

---

# TemplateRegistry

Registry entry концептуально:

```php
[
    'slug' => 'contact',
    'name' => 'Contact Form',
    'file' => '/trusted/path/forms/contact.php',
]
```

Можно render только file, обнаруженный TemplateScanner и находящийся внутри разрешенного template path.

Никогда не render произвольный path, полученный из:

* $_GET;
* $_POST;
* REST request;
* admin field.

---

# Template Validation

TemplateValidator сравнивает:

```text
data-jotform-field
```

из custom template с Normalized Schema Jotform form.

Static semantic identifiers в templates должны быть literal strings.

Например:

```html
data-jotform-field="email"
```

TemplateValidator должен определять:

* required fields present;
* required fields missing;
* optional fields missing;
* unknown template fields;
* unsupported schema fields.

Статусы:

```text
Compatible
Compatible with warnings
Invalid
```

Пример diagnostics:

```text
Email       email        ✓
First Name  name.first   ✓
Last Name   name.last    ✓
Company     company      Missing optional
Phone       phone        Missing required
```

Required field missing:

```text
ERROR
```

Optional field missing:

```text
WARNING
```

---

# Schema refresh

Jotform form может измениться вне WordPress.

Schema не считать вечной.

Кешировать normalized schema и fingerprint/hash.

Добавить:

```text
Refresh Schema
```

После refresh:

1. получить current Jotform schema;
2. normalize;
3. обновить cache;
4. пересчитать template compatibility.

Не обращаться к Jotform API на каждом frontend page view.

---

# Rendering

Поддерживаем два renderer:

```text
CustomTemplateRenderer
AutoRenderer
```

Custom Template — основной сценарий.

AutoRenderer реализуется после рабочего custom-template flow.

Renderer должен использовать:

```text
Integration
+
Normalized Schema
```

Не обращаться напрямую к Jotform API во время обычного rendering при наличии cached schema.

---

# PHP rendering API

Предоставить public helper:

```php
jotform_form('contact')
```

Предпочтительно helper возвращает HTML.

Использование:

```php
echo jotform_form('contact');
```

Также предоставить shortcode:

```text
[jotform_form id="contact"]
```

Shortcode и PHP helper используют один и тот же rendering service.

Business logic не дублировать.

---

# Custom template context

Custom PHP template может получить безопасный context, необходимый для rendering.

Например:

```text
integration
schema
endpoint
```

Template не должен hardcode integration-specific Jotform ID.

Form markup может использовать runtime integration slug.

Например:

```php
<form
    data-jotform-bridge
    data-jotform-integration="<?php echo esc_attr($integration['slug']); ?>"
>
```

---

# Frontend JavaScript

Использовать vanilla JavaScript.

Не использовать jQuery.

JS должен работать без build pipeline.

Production plugin содержит готовый:

```text
assets/frontend.js
```

Основной form marker:

```html
<form
    data-jotform-bridge
    data-jotform-integration="contact"
>
```

Field marker:

```html
data-jotform-field="email"
```

JS должен:

* intercept submit;
* собирать semantic fields;
* правильно обрабатывать radio;
* checkbox;
* select;
* multi-value fields;
* composite fields;
* блокировать repeated/double submit;
* отправлять JSON;
* показывать validation errors;
* восстанавливать submit state после ошибки.

Dispatch events:

```text
jotformbridge:before-submit
jotformbridge:success
jotformbridge:error
```

Не навязывать popup/animation.

Redirect выполняется только тогда, когда он явно настроен в Integration, и
только по данным, пришедшим в success response. См. `Success redirect`.

Theme должна иметь возможность построить собственный UX и отменить
настроенный redirect через `preventDefault()` на `jotformbridge:success`.

---

# REST API

Namespace:

```text
jotform-bridge/v1
```

Submission route:

```text
POST /wp-json/jotform-bridge/v1/submit/{integration}
```

Например:

```text
POST /wp-json/jotform-bridge/v1/submit/contact
```

Использовать WordPress REST API:

```php
register_rest_route()
WP_REST_Request
WP_REST_Response
WP_Error
```

Frontend отправляет semantic data.

Концептуально:

```json
{
    "fields": {
        "name.first": "John",
        "name.last": "Smith",
        "email": "john@example.com"
    }
}
```

Frontend не должен отправлять authoritative:

* Jotform Form ID;
* API key;
* qid.

Backend самостоятельно определяет:

```text
integration
→ configured Jotform form
→ normalized schema
→ Jotform qids
```

---

# Submission Validation

Все значения проверять server-side.

Frontend validation — только UX.

Backend проверяет:

* integration exists;
* integration active;
* schema available;
* allowed fields;
* required fields;
* field type;
* allowed options;
* request size.

Примеры WordPress sanitization:

```php
sanitize_text_field()
sanitize_textarea_field()
sanitize_email()
```

Email дополнительно проверять на valid format.

Select/radio values проверять по допустимым options, если schema их предоставляет.

Checkbox/multi-value fields проверять по разрешенным options.

Не доверять field type из frontend request.

---

# SubmissionMapper

Создать отдельный SubmissionMapper.

Он отвечает только за преобразование:

```text
semantic fields
→
Jotform submission payload
```

REST Controller не должен содержать mapping business logic.

Особенно внимательно обрабатывать composite fields:

```text
name.first
name.last
address.city
...
```

Перед реализацией mapper обязательно проверить актуальный формат Jotform REST API.

Не угадывать payload.

---

# REST responses

Success:

```json
{
    "success": true,
    "message": "Form submitted successfully."
}
```

Если Integration настроена на redirect, success response дополнительно содержит:

```json
{
    "success": true,
    "message": "Form submitted successfully.",
    "redirect": {
        "url": "https://example.com/thanks/",
        "delay": 0
    }
}
```

Ключ `redirect` отсутствует, если redirect не настроен.

Validation failure:

```text
HTTP 422
```

```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "email": "Invalid email."
    }
}
```

Upstream/Jotform error:

appropriate `5xx`.

Не возвращать:

* credentials;
* API keys;
* internal filesystem paths;
* raw exception stack;
* sensitive upstream details.

---

# Spam protection

Публичный endpoint нельзя считать защищенным WordPress nonce.

Не использовать nonce как единственную anti-spam protection anonymous form.

В первой версии не обязательно реализовывать конкретный captcha provider.

Но submission pipeline должен иметь extension point для будущего:

* Cloudflare Turnstile;
* reCAPTCHA;
* custom anti-spam provider.

Можно использовать interface или четкий WordPress hook/filter.

---

# Success redirect

Каждая Integration может быть настроена на redirect после успешного submission.

Redirect настраивается отдельно для каждой Integration, а не глобально.

Две integrations одной Jotform Form могут иметь разные redirect targets.

## Конфигурация

```text
Success Action:   message | redirect
Redirect Page ID: WordPress page ID
Redirect Delay:   секунды, 0 по умолчанию
```

Выбор страницы в Admin UI — существующая WordPress page/post, выбираемая
из списка (`wp_dropdown_pages()` или аналогичный контролируемый выбор).

Не свободный текстовый URL input в первой версии.

## Authority

Redirect target определяет backend.

Frontend никогда не передает redirect URL в submission request.

Backend резолвит `Redirect Page ID` в URL непосредственно в момент ответа.

Причины:

* URL страницы может измениться после сохранения Integration;
* frontend-provided URL — open redirect vector.

## Валидация

Redirect URL обязан быть внутренним для сайта.

Проверять через `wp_validate_redirect()` или эквивалентную проверку host против
`home_url()`.

Если page отсутствует, в trash, или не published:

* redirect не выполняется;
* поведение деградирует до обычного success message;
* admin получает предупреждение о невалидном redirect target на странице
  Integration (аналогично Template compatibility).

Success submission никогда не должен падать из-за сломанного redirect target.

## Frontend поведение

При наличии `redirect` в success response JS выполняет переход после dispatch
`jotformbridge:success`.

Порядок обязателен:

1. success state формы;
2. dispatch `jotformbridge:success`;
3. redirect.

Событие `jotformbridge:success` должно быть cancelable в части redirect: если
theme вызывает `preventDefault()`, redirect не выполняется, и theme строит
собственный UX.

`detail` события содержит `redirect`, чтобы theme могла принять решение.

Redirect выполняется через `window.location.assign()`.

При `delay > 0` — после соответствующей задержки, чтобы success message успел
быть прочитан.

Форма остается в disabled state во время задержки: повторный submit после
успешной отправки недопустим.

## Ограничения

* Redirect применяется только к успешному submission.
* Validation errors и upstream errors никогда не вызывают redirect.
* Не добавлять submission data в query string redirect URL.
* Не передавать persistent identifiers через URL.

---

# AutoRenderer

AutoRenderer строит semantic accessible HTML из Normalized Schema.

Использовать:

```html
<label>
<input>
<textarea>
<select>
<fieldset>
<legend>
```

Добавлять:

* correct input types;
* required;
* accessible IDs;
* error hooks;
* predictable CSS classes.

Базовые classes:

```text
.jfb-form
.jfb-field
.jfb-field--email
.jfb-error
.jfb-submit
```

Не создавать визуальный form builder.

Не пытаться копировать дизайн Jotform.

Не добавлять тяжелый frontend CSS framework.

---

# Caching

Кешировать:

* account forms list;
* raw/relevant form schema;
* normalized schema;
* template registry.

Использовать стандартные WordPress mechanisms:

* transients;
* options;
* object cache where appropriate.

Обеспечить explicit invalidation.

Обычный page request не должен обращаться к Jotform API без необходимости.

Страница без Jotform Bridge form не должна инициировать Jotform API calls.

---

# Debug logging

Debug logging:

```text
disabled by default
```

Можно включить через plugin settings.

Разрешено логировать:

* API failure metadata;
* HTTP status;
* schema refresh failures;
* template validation problems;
* submission technical errors.

Не логировать:

* API key;
* passwords;
* полный sensitive submission content без необходимости.

---

# Admin UI

Создать top-level admin menu:

```text
Jotform Bridge
├── Integrations
└── Settings
```

## Settings

Минимально:

```text
API Key (read-only: статус constant или инструкция, как ее задать)
Region
Connection Status
Debug Logging
```

Actions:

```text
Save
Test Connection
Refresh Forms
```

## Integrations

Integration UI:

```text
Name
Slug
Jotform Form
Rendering Mode
Template
Active
Success Action
Redirect Page
Redirect Delay
```

`Redirect Page` и `Redirect Delay` показываются только при
`Success Action = redirect`.

Также показывать:

```text
Schema status
Template compatibility
Redirect target status
```

Actions:

```text
Refresh Schema
Rescan Templates
```

Не создавать visual form builder.

---

# Developer diagnostics

На странице Integration показывать normalized schema table.

Пример columns:

```text
Label
Semantic Key
QID
Type
Required
Template Status
```

Не заставлять разработчика открывать raw JSON.

Raw response можно добавить только в debug/developer mode при необходимости.

---

# Security

Всегда соблюдать:

* capability checks в Admin;
* admin nonces для mutating admin actions;
* sanitization on input;
* validation;
* contextual escaping;
* no arbitrary file include;
* no path traversal;
* no API keys on frontend;
* no client-trusted Jotform IDs.

Admin settings должны быть доступны только пользователю с подходящей capability, например:

```text
manage_options
```

Публичный submission endpoint валидирует всё самостоятельно.

---

# Escaping

Использовать contextual escaping:

```php
esc_html()
esc_attr()
esc_url()
```

и другие WordPress APIs.

Не пропускать user-editable admin values как raw HTML.

Custom theme template является trusted developer-controlled PHP file и может формировать собственный HTML.

---

# Code architecture

Предпочитаем современный OO PHP.

Примерные ответственности:

```text
Api/
    JotformClient

Forms/
    FieldNormalizer
    FormSchema
    FormRepository

Integrations/
    IntegrationRepository

Templates/
    TemplateScanner
    TemplateRegistry
    TemplateValidator

Rendering/
    CustomTemplateRenderer
    AutoRenderer
    FormRenderer

Submission/
    SubmissionValidator
    SubmissionMapper

Rest/
    SubmissionController

Admin/
    SettingsPage
    IntegrationsPage
```

Это ориентир, а не требование создавать бессмысленные empty classes.

Не делать:

* giant god class;
* giant functions.php-style plugin file;
* business logic внутри admin views;
* arbitrary static globals;
* direct `$_POST` внутри domain services.

---

# WordPress hooks

Добавлять extension points только там, где есть реальная польза.

Потенциальные hooks:

```text
jotform_bridge_template_paths
jotform_bridge_normalized_schema
jotform_bridge_submission_fields
jotform_bridge_before_submit
jotform_bridge_after_submit
jotform_bridge_auto_field_html
jotform_bridge_spam_check
```

Не добавлять hooks ради количества.

---

# MCP

Проект может запускаться в двух средах, и набор инструментов в них разный.

## Codex

Project-scoped MCP servers настроены через:

```text
.codex/config.toml
```

Доступны:

```text
wordpress-playground
playwright
jotform
```

## Claude Code

Имена `wordpress-playground`, `playwright`, `jotform` здесь **не** существуют.
Использовать соответствия:

```text
Playwright MCP          → встроенный браузер (mcp__Claude_Browser__*)
                          или mcp__chrome-devtools__*
WordPress Playground MCP→ прямого аналога нет, см. ниже
Jotform MCP             → требует OAuth-авторизации коннектора;
                          без нее недоступен
```

Замена WordPress Playground:

* локальная установка WordPress, если она есть;
* `wp-env` / `wp-now` через Bash, если Docker/Node доступны;
* `@wp-playground/cli` через `npx`, если сеть доступна;
* если ничего из этого нет — ограничиться unit-тестами и явно указать
  в отчете, что WordPress runtime verification не выполнялась.

Jotform MCP в Claude Code требует авторизации коннектора пользователем.
Агент не должен запрашивать токены, коды или callback URL. Если коннектор
не авторизован — сказать об этом и продолжить без него.

## Общее правило

Инструменты используются для реальной verification, а не потому, что они доступны.

Если инструмент недоступен — см. секцию `MCP failure policy`. Никогда не выдавать
неисполненную проверку за исполненную и не подменять фактическую проверку
чтением собственного кода.

---

# WordPress Playground MCP

Использовать для WordPress runtime verification:

* plugin activation;
* fatal errors;
* hooks;
* REST routes;
* options;
* transients;
* PHP execution;
* shortcode;
* rendering;
* lifecycle;
* runtime smoke tests.

Если изменение зависит от WordPress runtime, не ограничиваться чтением PHP-кода.

По возможности реально проверить его через WordPress Playground.

---

# Playwright MCP

Использовать для browser/UI verification:

* wp-admin;
* Settings;
* Integrations;
* selects;
* buttons;
* validation messages;
* frontend form;
* JS behavior;
* REST submission;
* success/error state;
* double submit prevention.

Если этап затрагивает UI или frontend behavior, выполнить smoke test через Playwright.

---

# Jotform MCP

Использовать прежде всего как read/verification tool.

Полезно для:

* получения списка test forms;
* проверки существования формы;
* проверки submissions;
* end-to-end verification.

Не использовать Jotform MCP как замену официальной REST API documentation.

По умолчанию НЕ:

* удалять формы;
* изменять production forms;
* удалять submissions;
* менять реальные production data.

Для write-тестов использовать только явно test/development form.

Если такой формы нет, не выполнять live write operation без явного разрешения.

---

# End-to-end verification

Идеальный E2E flow:

```text
Playwright
    ↓
WordPress form
    ↓
WordPress REST API
    ↓
Jotform Bridge
    ↓
Jotform REST API
    ↓
Jotform
    ↓
Jotform MCP verification
```

Если live Jotform write нельзя безопасно выполнить:

* проверить mapper unit/integration tests;
* проверить REST validation;
* использовать fixtures/mocks;
* явно указать, что live upstream write не проверялся.

---

# MCP failure policy

Если конкретный MCP недоступен:

* не ломать implementation;
* выполнить доступную альтернативную проверку;
* в финальном отчете явно указать, что именно не было проверено.

Никогда не утверждать, что E2E test прошел, если он не выполнялся.

---

# Tests

Критичная domain logic должна быть testable отдельно от WordPress UI.

Приоритет tests:

1. FieldNormalizer
2. semantic key generation
3. collision detection
4. TemplateScanner
5. TemplateValidator
6. SubmissionValidator
7. SubmissionMapper

Использовать fixtures с realistic Jotform API responses.

Unit tests не должны требовать live Jotform API.

## Стек

Зафиксировано:

```text
PHPUnit ^9.6
brain/monkey ^2.6
mockery/mockery (транзитивно через brain/monkey)
```

PHPUnit 9.x выбран потому, что PHPUnit 10+ требует PHP 8.1+, а проект должен
поддерживать PHP 8.0.

Brain Monkey нужен, чтобы мокать WordPress-функции (`get_option`, `wp_remote_get`,
`sanitize_text_field`, `apply_filters` и т. д.) без загрузки WordPress.

Все это — **dev-зависимости**. В релизный ZIP они не попадают.

## Расположение

```text
composer.json
phpunit.xml.dist
tests/
├── bootstrap.php
├── TestCase.php          # базовый класс с setUp/tearDown Brain Monkey
├── Unit/
│   ├── Forms/
│   ├── Templates/
│   ├── Submission/
│   └── Api/
└── Fixtures/
    └── Jotform/          # sanitized JSON-ответы Jotform API
```

Composer PSR-4:

```text
JotformBridge\      → jotform-bridge/src/
JotformBridge\Tests\ → tests/
```

## Запуск

```bash
composer install
composer test          # алиас для vendor/bin/phpunit
```

Команда `composer test` должна работать начиная с Этапа 1, даже если тестов
на тот момент почти нет. Не откладывать настройку до Этапа 2.

## Правила написания

Тесты домена не должны:

* загружать WordPress;
* обращаться к сети;
* писать в файловую систему вне временного каталога;
* зависеть от порядка выполнения.

Тесты для `TemplateScanner` могут создавать временные файлы через
`sys_get_temp_dir()` и обязаны убирать их за собой.

Fixtures Jotform API должны быть sanitized: без реальных API-ключей, email,
имен и телефонов.

Если структура ответа Jotform для какого-то типа поля неизвестна — сначала
свериться с официальной документацией или прочитать реальную schema read-only,
и только потом создавать fixture. Выдуманный fixture хуже отсутствующего теста,
потому что он закрепляет неверное предположение.

## Lint

Если настраивается статический анализ, использовать WordPress Coding Standards
через `squizlabs/php_codesniffer` + `wp-coding-standards/wpcs` как dev-зависимость.

Это желательно, но не блокирует этапы. Тесты приоритетнее линтера.

---

# Workflow для каждого этапа

Перед изменением:

1. Прочитать этот `AGENTS.md`.
2. Изучить уже существующий код.
3. Не переписывать работающую архитектуру без объективной необходимости.
4. Определить минимальный scope текущего этапа.
5. Реализовать его.
6. Запустить доступные tests/lint.
7. Выполнить соответствующий MCP verification.
8. Исправить найденные проблемы.
9. Только после этого считать этап завершенным.

---

# Запрет на преждевременную реализацию

Если текущий prompt описывает конкретный этап:

* не реализовывать будущие этапы полностью;
* можно подготовить минимальный extension point;
* нельзя добавлять большой код "на будущее".

Цель — небольшие проверяемые increments.

---

# Отчет после каждого этапа

После работы предоставить:

```text
Что реализовано
Какие файлы изменены
Какие архитектурные решения приняты
Какие tests выполнены
Какие MCP проверки выполнены
Что не удалось проверить
Что остается для следующего этапа
```

Не писать просто:

```text
Done
Implementation complete
```

без verification details.

---

# Главные invariants проекта

Эти правила нельзя нарушать без явного изменения требований:

1. WordPress 6.4+.
2. PHP 8.0+.
3. Никакого Sage/Blade в текущей версии.
4. Plugin standalone.
5. Custom template не знает Jotform Form ID.
6. Template не знает Jotform qid.
7. Связь Form ↔ Template хранится в Integration.
8. Frontend использует semantic `data-jotform-field`.
9. Backend является authoritative source mapping.
10. API key существует только server-side и только в constant `wp-config.php`;
    в базе данных его нет.
11. Jotform REST API не вызывается на каждом page view.
12. Все submissions валидируются server-side.
13. Произвольный filesystem path никогда не renderится.
14. Одна Jotform Form может иметь несколько integrations/templates.
15. Custom Template — основной сценарий.
16. AutoRenderer строится поверх уже готовой Normalized Schema.
17. Перед использованием Jotform payload format проверяется официальная документация.
18. MCP используется для реальной verification, когда это возможно.
19. Redirect target приходит из Integration, резолвится backend и обязан быть
    внутренним URL; frontend никогда не диктует, куда выполняется redirect.
