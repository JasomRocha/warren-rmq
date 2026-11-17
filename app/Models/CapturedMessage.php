<?php

namespace App\Models;

use App\Rabbit\Redactor;
use Illuminate\Database\Eloquent\Model;

/**
 * A copy of a message the tap saw being published, plus where it was routed and delivered.
 */
class CapturedMessage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'queues' => 'array',
            'deliveries' => 'array',
            'properties' => 'array',
            'headers' => 'array',
            'body_truncated' => 'boolean',
        ];
    }

    /** The light shape the live view polls (no headers, no body). */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'rev' => $this->rev,
            'ts' => $this->ts,
            'origin' => $this->origin,
            'exchange' => $this->exchange,
            'routingKey' => $this->routing_key,
            'instance' => $this->instance,
            'priority' => $this->priority,
            'job' => $this->job,
            'uuid' => $this->uuid,
            'size' => $this->size,
            'queues' => $this->queues,
            'deliveries' => $this->deliveries,
        ];
    }

    public function detail(): array
    {
        return $this->summary() + [
            'properties' => (object) $this->properties,
            'headers' => (object) Redactor::maskData($this->headers),
            'body' => $this->maskedBody(),
            'bodyTruncated' => $this->body_truncated,
        ];
    }

    /** The body with secrets hidden: sensitive JSON keys masked, tokens and JWTs in free text replaced. */
    private function maskedBody(): string
    {
        $data = json_decode($this->body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return json_encode(Redactor::maskData($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: Redactor::mask($this->body);
        }

        return Redactor::mask($this->body);
    }
}
