# Plan: Sistema de Notificaciones via Telegram

> **Stack objetivo:** Laravel 8 + PHP 7.3 + PostgreSQL multi-tenant + GuzzleHTTP 7
> **Enfoque:** HTTP directo a `api.telegram.org` (sin SDK de terceros por incompatibilidad de versiones con PHP 7.3)
> **Modo:** Bidireccional (push saliente + webhook entrante)
> **Bot:** Único, global, configurado por `.env`

---

## 1. Resumen ejecutivo

Construir una **infraestructura solida de notificaciones por Telegram** lista para produccion, desacoplada de los casos de uso concretos. La capa base provee:

- Servicio `TelegramService` para envio (texto plano, con rate-limit awareness).
- Job `SendTelegramMessageJob` con reintentos exponenciales y backoff.
- Webhook entrante en `/api/telegram/webhook` validado por `X-Telegram-Bot-Api-Secret-Token`.
- Tabla `user_telegram_chats` para mapear `users -> chat_id` con metadatos.
- Comando Artisan `telegram:register-chat` para que un usuario vincule su `chat_id` ejecutando `/start` y enviando su ID.
- Comando `telegram:test-notify` para pruebas manuales de envio a un usuario.
- Skill OpenCode que documente el flujo, los gotchas y ejemplos de invocacion.

Los **casos de uso reales** (notificar fallos de Contifico, cambios de Rappi/Uber, etc.) se conectaran despues consumiendo `TelegramService::sendMessage()`.

---

## 2. Decisiones tecnicas confirmadas

| Tema | Decision | Razon |
|------|----------|-------|
| Transporte HTTP | Guzzle 7 directo a `https://api.telegram.org/bot<TOKEN>/<method>` | SDK v3.x requiere PHP 8.0+; SDK v2.x sin soporte oficial |
| Suscripcion | `chat_id` unico por usuario, guardado en tabla aparte | Telegram exige que el usuario haya iniciado el bot antes de recibir mensajes |
| Webhook | `POST /api/telegram/webhook` con `X-Telegram-Bot-Api-Secret-Token` | URL publica + validacion criptografica; no expone sin secreto |
| Bot | Uno solo, global, configurado via `TELEGRAM_BOT_TOKEN` | Coherente con multi-tenant: las conexiones `*_mc` son para MasterControl, no para bots |
| Reintentos | Job con backoff `30s, 2min, 10min, 1h`, max 5 intentos | Telegram rate limit 429 + errores 5xx transitorios |
| Almacenamiento | Tabla `user_telegram_chats` (no columna en `users`) | Permite multiples canales por usuario a futuro, soft delete, auditoria |

---

## 3. Variables de entorno (`.env.example`)

```env
# === Telegram Bot ===
TELEGRAM_BOT_TOKEN=123456:ABC-DEF...                 # Token entregado por @BotFather
TELEGRAM_BOT_USERNAME=MiBotIntegradorBot             # Para deep links t.me/<username>
TELEGRAM_WEBHOOK_SECRET=                             # Cadena aleatoria >=16 chars (openssl rand -hex 32)
TELEGRAM_WEBHOOK_URL=                                # https://midominio.com/api/telegram/webhook
TELEGRAM_API_TIMEOUT=10                              # Segundos por request a la API
TELEGRAM_DEFAULT_PARSE_MODE=HTML                     # HTML | MarkdownV2 | null
TELEGRAM_QUEUE=telegram                              # Cola dedicada (usa conexion 'database')
```

Anadir tambien al `.env` real (no commitearlo):

```env
TELEGRAM_BOT_TOKEN=<token real>
TELEGRAM_WEBHOOK_SECRET=<openssl rand -hex 32>
TELEGRAM_WEBHOOK_URL=https://<host publico>/api/telegram/webhook
```

---

## 4. Migracion: `user_telegram_chats`

Archivo: `database/migrations/2026_06_23_000001_create_user_telegram_chats_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserTelegramChatsTable extends Migration
{
    public function up()
    {
        Schema::create('user_telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_user');
            $table->bigInteger('telegram_chat_id')->index();      // Hasta 64 bits (Telegram ids son positivos grandes)
            $table->string('telegram_username', 100)->nullable();
            $table->string('telegram_first_name', 150)->nullable();
            $table->string('telegram_last_name', 150)->nullable();
            $table->string('language_code', 10)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->boolean('is_active')->default(true);          // El usuario puede desactivar
            $table->timestamp('last_message_at')->nullable();     // Ultima interaccion
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('id_user')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            // Un usuario puede tener un solo chat activo
            $table->unique(['id_user', 'deleted_at'], 'user_telegram_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_telegram_chats');
    }
}
```

> **Nota:** El indice unico considera `deleted_at` para permitir re-vincular tras soft delete. El proyecto ya usa `SoftDeletes` (ver `User.php:14`).

---

## 5. Modelo `UserTelegramChat`

Archivo: `app/Models/UserTelegramChat.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserTelegramChat extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'user_telegram_chats';

    protected $fillable = [
        'id_user',
        'telegram_chat_id',
        'telegram_username',
        'telegram_first_name',
        'telegram_last_name',
        'language_code',
        'is_bot',
        'is_active',
        'last_message_at',
        'failed_attempts',
        'last_error',
    ];

    protected $casts = [
        'telegram_chat_id' => 'integer',
        'is_bot'           => 'boolean',
        'is_active'        => 'boolean',
        'failed_attempts'  => 'integer',
        'last_message_at'  => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeReachable($query)
    {
        return $query->active()->where('failed_attempts', '<', 10);
    }
}
```

**Extender `User.php`** con la relacion inversa:

```php
// app/Models/User.php (agregar al final de la clase)

public function telegramChats()
{
    return $this->hasMany(UserTelegramChat::class, 'id_user', 'id');
}

public function activeTelegramChat()
{
    return $this->hasOne(UserTelegramChat::class, 'id_user', 'id')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->latestOfMany('id');
}
```

---

## 6. Configuracion: `config/telegram.php`

Archivo: `config/telegram.php`

```php
<?php

return [

    'bot' => [
        'token'       => env('TELEGRAM_BOT_TOKEN'),
        'username'    => env('TELEGRAM_BOT_USERNAME'),
        'api_base'    => 'https://api.telegram.org/bot',
        'api_timeout' => (int) env('TELEGRAM_API_TIMEOUT', 10),
    ],

    'webhook' => [
        'url'    => env('TELEGRAM_WEBHOOK_URL'),
        'secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'messaging' => [
        'default_parse_mode' => env('TELEGRAM_DEFAULT_PARSE_MODE', 'HTML'),
        'disable_web_page_preview' => true,
        'max_message_length' => 4096,    // Limite Telegram
    ],

    'queue' => [
        'connection' => env('QUEUE_CONNECTION', 'database'),
        'name'       => env('TELEGRAM_QUEUE', 'telegram'),
        'max_attempts' => 5,
        'backoff'    => [30, 120, 600, 1800, 3600], // segundos
    ],

    'logging' => [
        'channel' => env('TELEGRAM_LOG_CHANNEL', 'stack'),
    ],
];
```

---

## 7. `TelegramService`: cliente HTTP

Archivo: `app/Services/TelegramService.php`

```php
<?php

namespace App\Services;

use App\Jobs\SendTelegramMessageJob;
use App\Models\UserTelegramChat;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramService
{
    private Client $http;
    private string $base;
    private int $timeout;

    public function __construct()
    {
        $this->timeout = (int) config('telegram.bot.api_timeout', 10);
        $this->base    = rtrim(config('telegram.bot.api_base'), '/')
                       . config('telegram.bot.token');

        $this->http = new Client([
            'base_uri'    => $this->base . '/',
            'timeout'     => $this->timeout,
            'http_errors' => false,         // Manejamos errores manualmente
            'headers'     => [
                'Accept'     => 'application/json',
                'User-Agent' => 'IntegratorMasterControl/1.0 Telegram-Client',
            ],
        ]);
    }

    /**
     * Envia un mensaje de texto a un chat_id especifico.
     * Retorna el array de respuesta de Telegram o lanza RuntimeException.
     */
    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        $payload = array_merge([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => config('telegram.messaging.default_parse_mode'),
            'disable_web_page_preview' => config('telegram.messaging.disable_web_page_preview', true),
        ], $options);

        return $this->call('sendMessage', $payload);
    }

    /**
     * Despacha un mensaje encolado (produccion-safe).
     * Este es el metodo que consumiran los casos de uso reales.
     */
    public function queueMessage(int $chatId, string $text, array $options = []): void
    {
        SendTelegramMessageJob::dispatch($chatId, $text, $options)
            ->onConnection(config('telegram.queue.connection'))
            ->onQueue(config('telegram.queue.name'));
    }

    /**
     * Envia a todos los chats activos de un usuario.
     */
    public function sendToUser(int $userId, string $text, bool $async = true): void
    {
        $chats = UserTelegramChat::query()
            ->where('id_user', $userId)
            ->reachable()
            ->get();

        foreach ($chats as $chat) {
            if ($async) {
                $this->queueMessage($chat->telegram_chat_id, $text);
            } else {
                try {
                    $this->sendMessage($chat->telegram_chat_id, $text);
                } catch (\Throwable $e) {
                    $this->markFailed($chat, $e->getMessage());
                }
            }
        }
    }

    /**
     * Llama a cualquier metodo del Bot API.
     */
    public function call(string $method, array $payload): array
    {
        $logChannel = config('telegram.logging.channel', 'stack');

        try {
            $response = $this->http->post($method, [
                'json'    => $payload,
                'timeout' => $this->timeout,
            ]);
        } catch (GuzzleException $e) {
            Log::channel($logChannel)->error('Telegram HTTP error', [
                'method'  => $method,
                'error'   => $e->getMessage(),
            ]);
            throw new RuntimeException("Telegram HTTP error: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true) ?? [];

        if ($status !== 200 || ($body['ok'] ?? false) !== true) {
            Log::channel($logChannel)->warning('Telegram API error', [
                'method'   => $method,
                'status'   => $status,
                'response' => $body,
            ]);
            throw new RuntimeException(
                "Telegram API error [{$status}]: " . ($body['description'] ?? 'unknown')
            );
        }

        return $body;
    }

    /**
     * Configura el webhook con el secret token.
     */
    public function setWebhook(string $url, ?string $secret = null): array
    {
        return $this->call('setWebhook', [
            'url'         => $url,
            'secret_token' => $secret ?? config('telegram.webhook.secret'),
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo', []);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', []);
    }

    private function markFailed(UserTelegramChat $chat, string $error): void
    {
        $chat->increment('failed_attempts');
        $chat->update(['last_error' => mb_substr($error, 0, 500)]);

        // Si supera 10 intentos, desactivar
        if ($chat->failed_attempts >= 10) {
            $chat->update(['is_active' => false]);
            Log::warning('Telegram chat desactivado por exceso de fallos', [
                'id_user' => $chat->id_user,
                'chat_id' => $chat->telegram_chat_id,
            ]);
        }
    }
}
```

---

## 8. Job: `SendTelegramMessageJob`

Archivo: `app/Jobs/SendTelegramMessageJob.php`

```php
<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $timeout = 30;

    private int $chatId;
    private string $text;
    private array $options;

    public function __construct(int $chatId, string $text, array $options = [])
    {
        $this->chatId  = $chatId;
        $this->text    = $text;
        $this->options = $options;
        $this->tries   = (int) config('telegram.queue.max_attempts', 5);
        $this->onQueue(config('telegram.queue.name', 'telegram'));
    }

    public function backoff(): array
    {
        return config('telegram.queue.backoff', [30, 120, 600, 1800, 3600]);
    }

    public function handle(TelegramService $telegram): void
    {
        Log::info('Telegram: intentando enviar mensaje', [
            'chat_id' => $this->chatId,
            'length'  => strlen($this->text),
            'attempt' => $this->attempts(),
        ]);

        $response = $telegram->sendMessage($this->chatId, $this->text, $this->options);

        Log::info('Telegram: mensaje enviado', [
            'chat_id'    => $this->chatId,
            'message_id' => $response['result']['message_id'] ?? null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Telegram: job fallo definitivamente', [
            'chat_id' => $this->chatId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
```

---

## 9. Webhook: `TelegramWebhookController`

Archivo: `app/Http/Controllers/TelegramWebhookController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserTelegramChat;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request, TelegramService $telegram)
    {
        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        $expected = config('telegram.webhook.secret');

        if (empty($expected) || !hash_equals($expected, (string) $secret)) {
            Log::warning('Telegram webhook: secret invalido', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['ok' => false], 403);
        }

        $update = $request->all();
        Log::info('Telegram webhook: update recibido', [
            'update_id' => $update['update_id'] ?? null,
        ]);

        // Procesar /start (vinculacion)
        if (isset($update['message']['text']) && str_starts_with($update['message']['text'], '/start')) {
            return $this->handleStart($update['message']);
        }

        // Otros handlers se agregan aqui (callbacks, comandos, etc.)
        return response()->json(['ok' => true]);
    }

    private function handleStart(array $message)
    {
        $chat    = $message['chat']    ?? [];
        $from    = $message['from']    ?? [];
        $chatId  = $chat['id']         ?? null;
        $parts   = explode(' ', trim($message['text'] ?? ''), 2);
        $payload = $parts[1] ?? null;   // /start <payload>

        if (!$chatId) {
            return response()->json(['ok' => false], 400);
        }

        $userId = $payload ? (int) $payload : null;
        $user   = $userId ? User::find($userId) : null;

        $record = UserTelegramChat::withTrashed()
            ->updateOrCreate(
                ['telegram_chat_id' => $chatId],
                [
                    'id_user'            => $user?->id,
                    'telegram_username'  => $from['username']        ?? null,
                    'telegram_first_name'=> $from['first_name']      ?? null,
                    'telegram_last_name' => $from['last_name']       ?? null,
                    'language_code'      => $from['language_code']   ?? null,
                    'is_bot'             => $from['is_bot']          ?? false,
                    'is_active'          => true,
                    'failed_attempts'    => 0,
                    'last_message_at'    => now(),
                ]
            );

        if ($record->trashed()) {
            $record->restore();
        }

        Log::info('Telegram: chat vinculado', [
            'id_user' => $record->id_user,
            'chat_id' => $chatId,
        ]);

        return response()->json(['ok' => true]);
    }
}
```

### Ruta en `routes/api.php`

```php
Route::middleware('api')->post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
```

> **Importante:** Esta ruta **no** debe pasar por `jwt.verify`. Es publica pero protegida por el `X-Telegram-Bot-Api-Secret-Token`.

---

## 10. Comandos Artisan

### 10.1 `telegram:set-webhook`

Archivo: `app/Console/Commands/TelegramSetWebhook.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook
                            {--show : Solo muestra la info actual}';
    protected $description = 'Configura (o consulta) el webhook de Telegram';

    public function handle(TelegramService $telegram): int
    {
        if ($this->option('show')) {
            $this->line(json_encode($telegram->getWebhookInfo(), JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $url    = config('telegram.webhook.url');
        $secret = config('telegram.webhook.secret');

        if (empty($url) || empty($secret)) {
            $this->error('Faltan TELEGRAM_WEBHOOK_URL o TELEGRAM_WEBHOOK_SECRET en .env');
            return self::FAILURE;
        }

        $result = $telegram->setWebhook($url, $secret);
        $this->line(json_encode($result, JSON_PRETTY_PRINT));
        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
```

### 10.2 `telegram:register-chat`

Vincula un `chat_id` capturado por otro medio (no recomendado para produccion; util en QA).

Archivo: `app/Console/Commands/TelegramRegisterChat.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\UserTelegramChat;
use Illuminate\Console\Command;

class TelegramRegisterChat extends Command
{
    protected $signature = 'telegram:register-chat
                            {userId : ID del usuario en la tabla users}
                            {chatId : chat_id entregado por Telegram}';
    protected $description = 'Vincula manualmente un chat_id a un usuario';

    public function handle(): int
    {
        $record = UserTelegramChat::updateOrCreate(
            ['telegram_chat_id' => (int) $this->argument('chatId')],
            [
                'id_user'         => (int) $this->argument('userId'),
                'is_active'       => true,
                'failed_attempts' => 0,
                'last_message_at' => now(),
            ]
        );

        $this->info("Vinculado. id={$record->id}");
        return self::SUCCESS;
    }
}
```

### 10.3 `telegram:test-notify`

Archivo: `app/Console/Commands/TelegramTestNotify.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramTestNotify extends Command
{
    protected $signature = 'telegram:test-notify
                            {userId : ID del usuario a notificar}
                            {--text=: Notificacion de prueba desde IntegratorMasterControl}';
    protected $description = 'Envia un mensaje de prueba a todos los chats de un usuario';

    public function handle(TelegramService $telegram): int
    {
        $userId = (int) $this->argument('userId');
        $text   = $this->option('text') ?: 'Notificacion de prueba desde IntegratorMasterControl';

        $telegram->sendToUser($userId, $text, async: false);
        $this->info("Enviado a usuario {$userId}");
        return self::SUCCESS;
    }
}
```

Uso:
```bash
php artisan telegram:test-notify 1
php artisan telegram:test-notify 1 --text="Hola desde produccion"
```

---

## 11. Service Provider (auto-discovery)

Como el proyecto usa composer auto-discovery y se siguen convenciones de `app/Providers/`, no se requiere registro manual. Sin embargo, **se debe publicar la config**:

```bash
php artisan vendor:publish --tag=telegram-config   # Solo si se usara SDK; aqui no aplica
```

La clase `config/telegram.php` ya esta en su lugar; se carga automaticamente desde `bootstrap/cache/config.php` al ejecutar `php artisan config:cache`.

---

## 12. Flujo end-to-end de un caso de uso

Ejemplo: "Notificar a un usuario cuando una orden Rappi falla".

```php
// En RappiWebhookcontroller.php (o un Observer/Event Listener)
use App\Services\TelegramService;

class RappiWebhookcontroller extends Controller
{
    public function newOrder(Request $request, TelegramService $telegram)
    {
        // ...logica existente...
        if ($ordenFallida) {
            $telegram->queueMessage(
                chatId: $adminChatId,
                text: "Orden Rappi #{$orderId} fallo en {$conexion}\nError: {$error}"
            );
        }
    }
}
```

El job se ejecuta con backoff automatico, no bloquea el webhook de Rappi, y se registra en el log.

---

## 13. Plan de despliegue (produccion)

### 13.1 Pasos de instalacion

```bash
# 1. Instalar (no requiere composer - solo Guzzle que ya esta)
composer install --no-dev --optimize-autoloader

# 2. Ejecutar migracion
php artisan migrate

# 3. Limpiar y cachear config
php artisan config:clear
php artisan config:cache

# 4. Configurar el webhook (solo una vez por entorno)
php artisan telegram:set-webhook
php artisan telegram:set-webhook --show      # Verificar

# 5. Verificar el worker de la cola 'telegram'
php artisan queue:work --queue=telegram --tries=5 --timeout=30
```

### 13.2 Produccion hardening

- **HTTPS obligatorio:** Telegram rechaza webhooks sin TLS. Usar certificado valido (no self-signed).
- **Firewall:** Permitir solo IPs de Telegram en el endpoint `/api/telegram/webhook` (rangos publicados en su API docs).
- **Rate limit:** Telegram permite ~30 msg/s globalmente por bot. El job encolado ya regula naturalmente; no enviar desde el hilo del request.
- **Secret token:** Rotar periodicamente y tras incidentes. El header `X-Telegram-Bot-Api-Secret-Token` se valida con `hash_equals` (timing-safe).
- **Logs:** Asegurar que `telegram` tenga su canal en `config/logging.php` separado del principal.
- **Monitoreo:** Crear job de heartbeat que cada 6h ejecute `getWebhookInfo` y alerte si `pending_update_count > 100`.

### 13.3 Verificacion post-deploy

```bash
# Smoke test
php artisan telegram:test-notify 1
# Verificar entrega en Telegram del usuario

# Verificar webhook
php artisan telegram:set-webhook --show
# pending_update_count debe ser 0

# Probar /start: el usuario envia /start <userId> al bot
# El log debe mostrar "Telegram: chat vinculado"
```

---

## 14. Estructura final de archivos

```
IntegratorMasterControl/
|-- app/
|   |-- Console/Commands/
|   |   |-- TelegramSetWebhook.php        (nuevo)
|   |   |-- TelegramRegisterChat.php      (nuevo)
|   |   '-- TelegramTestNotify.php        (nuevo)
|   |-- Http/Controllers/
|   |   '-- TelegramWebhookController.php (nuevo)
|   |-- Jobs/
|   |   '-- SendTelegramMessageJob.php    (nuevo)
|   |-- Models/
|   |   |-- User.php                      (modificado: +relacion)
|   |   '-- UserTelegramChat.php          (nuevo)
|   '-- Services/
|       '-- TelegramService.php           (nuevo)
|-- config/
|   '-- telegram.php                      (nuevo)
|-- database/migrations/
|   '-- 2026_06_23_000001_create_user_telegram_chats_table.php  (nuevo)
|-- routes/
|   '-- api.php                           (modificado: +ruta webhook)
'-- .opencode/skills/telegram-notifications/
    '-- SKILL.md                          (nuevo)
```

---

## 15. Skill OpenCode: `telegram-notifications`

Archivo: `.opencode/skills/telegram-notifications/SKILL.md`

Documenta:
- Cuando usar `TelegramService` vs `SendTelegramMessageJob` (sync vs async).
- Como obtener el `chat_id` de un usuario en pruebas.
- Gotchas:
  - El bot **no puede** iniciar la conversacion; el usuario debe hacer `/start` primero.
  - Limite 4096 caracteres por mensaje; partir en chunks si se excede.
  - HTML/MarkdownV2 requieren escape de caracteres especiales.
  - Errores 429 incluyen `retry_after` (segundos) - el job ya respeta el backoff, pero considerar parsear `parameters.retry_after` en una v2.
  - `chat_id` puede ser negativo para grupos y hasta 13 digitos para usuarios.
  - El webhook entrante puede llegar desordenado; usar `update_id` para deduplicar si se requiere idempotencia.
- Ejemplos de uso desde otros controladores.

---

## 16. Resumen de entregables

| # | Archivo | Tipo |
|---|---------|------|
| 1 | `config/telegram.php` | Config |
| 2 | `database/migrations/2026_06_23_000001_create_user_telegram_chats_table.php` | Migracion |
| 3 | `app/Models/UserTelegramChat.php` | Modelo |
| 4 | `app/Models/User.php` | Modificacion (relacion) |
| 5 | `app/Services/TelegramService.php` | Servicio |
| 6 | `app/Jobs/SendTelegramMessageJob.php` | Job |
| 7 | `app/Http/Controllers/TelegramWebhookController.php` | Controller |
| 8 | `app/Console/Commands/TelegramSetWebhook.php` | Comando |
| 9 | `app/Console/Commands/TelegramRegisterChat.php` | Comando |
| 10 | `app/Console/Commands/TelegramTestNotify.php` | Comando |
| 11 | `routes/api.php` | Modificacion (1 linea) |
| 12 | `.env.example` | Modificacion (vars) |
| 13 | `.opencode/skills/telegram-notifications/SKILL.md` | Skill |

---

## 17. Riesgos y mitigaciones

| Riesgo | Mitigacion |
|--------|------------|
| Telegram rate-limit 429 en rafagas | Cola dedicada con backoff; nunca enviar sincronico desde request critico |
| Perdida de mensajes al redeploy | El driver `database` persiste los jobs en la tabla `jobs`; `queue:work` retoma al reiniciar |
| Webhook filtrado / DDoS | Validacion `secret_token` + IP allowlist + rate limiting con `throttle:60,1` en la ruta |
| Token comprometido | Rotar con `@BotFather` -> `/revoke`; el cambio surte efecto inmediato |
| Bot bloqueado por usuario | Job recibe error `403 Forbidden` -> `markFailed()` desactiva tras 10 intentos |
| Charset/encoding en mensajes | Forzar `mb_*` al loguear; truncar con `mb_substr`; UTF-8 por default en Laravel |

---

## 18. Proximos pasos tras la aprobacion

1. Confirmar el archivo `.env` con el token real y el `secret` generado.
2. Crear la skill OpenCode (formato en `.opencode/skills/telegram-notifications/SKILL.md`).
3. Implementar los 13 entregables en orden: migracion -> modelo -> config -> service -> job -> controller -> comandos -> ruta -> skill.
4. Pruebas: `php artisan telegram:set-webhook`, enviar `/start <userId>` desde Telegram, `php artisan telegram:test-notify 1`.
5. Smoke test en staging antes de produccion.

---

**Listo para implementar al recibir aprobacion.**
