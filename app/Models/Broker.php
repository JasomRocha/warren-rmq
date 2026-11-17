<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A RabbitMQ that Warren follows: where its management API is, which user to use, and whether to listen to it.
 */
class Broker extends Model
{
    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'amqp_ssl' => 'boolean',
            'tap_enabled' => 'boolean',
            'amqp_port' => 'integer',
        ];
    }

    /** @return array{url: string, username: string, password: string, vhost: string, timeout: int, scope: string} */
    public function managementConfig(): array
    {
        return [
            'url' => $this->url,
            'username' => $this->username,
            'password' => (string) $this->password,
            'vhost' => $this->vhost,
            'timeout' => (int) config('warren.broker.timeout'),
            'scope' => (string) $this->id,
        ];
    }

    /** @return array{host: string, port: int, username: string, password: string, vhost: string, ssl: bool} */
    public function amqpConfig(): array
    {
        return [
            'host' => $this->amqp_host ?: (string) parse_url($this->url, PHP_URL_HOST),
            'port' => (int) ($this->amqp_port ?: 5672),
            'username' => $this->username,
            'password' => (string) $this->password,
            'vhost' => $this->vhost,
            'ssl' => (bool) $this->amqp_ssl,
        ];
    }

    /** The shape the UI gets: everything except the password. */
    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'username' => $this->username,
            'hasPassword' => $this->password !== '' && $this->password !== null,
            'vhost' => $this->vhost,
            'amqpHost' => $this->amqp_host,
            'amqpPort' => $this->amqp_port,
            'amqpSsl' => $this->amqp_ssl,
            'tapEnabled' => $this->tap_enabled,
        ];
    }

    /**
     * First start of an install that is configured by environment: the RABBITMQ_* variables become the first
     * connection, so existing setups keep working and new ones can add more from the screen.
     */
    public static function seedFromEnvironment(): ?self
    {
        if (self::query()->exists() || env('RABBITMQ_URL') === null || env('RABBITMQ_URL') === '') {
            return null;
        }

        $broker = config('warren.broker');
        $amqp = config('warren.amqp');

        return self::create([
            'name' => (string) (env('WARREN_CONNECTION_NAME') ?: (parse_url($broker['url'], PHP_URL_HOST) ?: 'RabbitMQ')),
            'url' => $broker['url'],
            'username' => $broker['username'],
            'password' => $broker['password'],
            'vhost' => $broker['vhost'],
            'amqp_host' => env('RABBITMQ_AMQP_HOST') ?: null,
            'amqp_port' => $amqp['port'],
            'amqp_ssl' => $amqp['ssl'],
            'tap_enabled' => $amqp['enabled'],
        ]);
    }
}
