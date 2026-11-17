<?php

namespace App\Http\Controllers;

use App\Models\Broker;
use App\Models\CapturedMessage;
use App\Models\MetricSample;
use App\Rabbit\BrokerUnavailable;
use App\Rabbit\ManagementClient;
use App\Support\BrokerAddress;
use App\Warren\Brokers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The connections list and its credentials: add another RabbitMQ, change a password, test before saving.
 * Passwords are encrypted at rest and never sent back to the browser.
 */
class BrokerController extends Controller
{
    public function index(Brokers $brokers): JsonResponse
    {
        Broker::seedFromEnvironment();

        return response()->json(Broker::orderBy('name')->get()->map(function (Broker $b) use ($brokers) {
            $context = $brokers->for($b);

            return $b->toPublic() + ['health' => $context->topology->health(), 'tap' => $context->status->get()];
        })->values())->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Brokers $brokers): JsonResponse
    {
        $data = $request->validate($this->rules(creating: true));
        $broker = Broker::create($this->attributes($data));

        return response()->json($broker->toPublic() + ['health' => $brokers->for($broker)->topology->health()], 201);
    }

    public function update(Request $request, Broker $broker, Brokers $brokers): JsonResponse
    {
        $data = $request->validate($this->rules(creating: false));
        $attributes = $this->attributes($data);
        if (($data['password'] ?? '') === '') {
            // Blank keeps the saved password, but only for the same destination: otherwise anyone who can edit the
            // connection could point it at their own host and receive the password from the poller and the tap.
            if ($this->destinationChanged($broker, $attributes)) {
                throw ValidationException::withMessages(['password' => trans('warren.broker.password_again')]);
            }
            unset($attributes['password']);
        }
        $broker->update($attributes);
        $brokers->forget($broker->id);
        Cache::forget('warren.health.'.$broker->id);

        return response()->json($broker->fresh()->toPublic());
    }

    public function destroy(Broker $broker, Brokers $brokers): JsonResponse
    {
        CapturedMessage::where('broker_id', $broker->id)->delete();
        MetricSample::where('broker_id', $broker->id)->delete();
        $brokers->forget($broker->id);
        Cache::forget('warren.health.'.$broker->id);
        $broker->delete();

        return response()->json(null, 204);
    }

    /** Tries the credentials as typed, without saving anything. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'url' => ['bail', 'required', 'url', 'regex:#^https?://#i', $this->addressRule()],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'vhost' => ['nullable', 'string', 'max:255'],
            'amqp_host' => ['nullable', 'string', 'max:255', $this->hostRule()],
            'amqp_port' => ['nullable', 'integer', 'between:1,65535'],
        ]);

        $password = $data['password'] ?? '';
        $vhost = ($data['vhost'] ?? '') !== '' ? $data['vhost'] : '/';
        if ($password === '' && ! empty($data['id'])) {
            // the saved password only goes back to the address it was saved for
            $saved = Broker::find($data['id']);
            if ($saved && ! $this->destinationChanged($saved, ['url' => rtrim($data['url'], '/'), 'username' => $data['username'], 'vhost' => $vhost])) {
                $password = (string) $saved->password;
            }
        }
        $client = new ManagementClient([
            'url' => $data['url'], 'username' => $data['username'], 'password' => $password, 'vhost' => $vhost,
            'timeout' => 6, 'scope' => 'test',
        ]);

        try {
            $overview = $client->get('/overview');
            $whoami = $client->get('/whoami');
        } catch (BrokerUnavailable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        try {
            $client->get('/vhosts/'.$client->encodedVhost());
        } catch (BrokerUnavailable) {
            return response()->json(['ok' => false, 'message' => trans('warren.broker.no_vhost', ['vhost' => $vhost])]);
        }

        $tags = (array) ($whoami['tags'] ?? []);
        $host = ($data['amqp_host'] ?? '') !== '' ? $data['amqp_host'] : (string) parse_url($data['url'], PHP_URL_HOST);
        $port = (int) ($data['amqp_port'] ?? 5672);

        return response()->json([
            'ok' => true,
            'version' => $overview['rabbitmq_version'] ?? null,
            'cluster' => $overview['cluster_name'] ?? null,
            'tags' => $tags,
            // with only "management", Warren reads each queue separately: it works, but costs more calls
            'canMonitor' => (bool) array_intersect($tags, ['monitoring', 'administrator']),
            'amqp' => $this->amqpReachable($host, $port),
        ]);
    }

    /** @return array{ok: bool, host: string, port: int} */
    private function amqpReachable(string $host, int $port): array
    {
        if (! BrokerAddress::hostAllowed($host)) {
            return ['ok' => false, 'host' => $host, 'port' => $port];
        }
        $socket = @fsockopen($host, $port, $errno, $error, 3);
        if ($socket) {
            fclose($socket);
        }

        return ['ok' => (bool) $socket, 'host' => $host, 'port' => $port];
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'url' => ['bail', 'required', 'url', 'regex:#^https?://#i', 'max:255', $this->addressRule()],
            'username' => ['required', 'string', 'max:255'],
            'password' => [Rule::requiredIf($creating), 'nullable', 'string', 'max:255'],
            'vhost' => ['nullable', 'string', 'max:255'],
            'amqp_host' => ['nullable', 'string', 'max:255', $this->hostRule()],
            'amqp_port' => ['nullable', 'integer', 'between:1,65535'],
            'amqp_ssl' => ['boolean'],
            'tap_enabled' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'url' => rtrim($data['url'], '/'),
            'username' => $data['username'],
            'password' => $data['password'] ?? '',
            'vhost' => ($data['vhost'] ?? '') !== '' ? $data['vhost'] : '/',
            'amqp_host' => ($data['amqp_host'] ?? '') !== '' ? $data['amqp_host'] : null,
            'amqp_port' => (int) ($data['amqp_port'] ?? 5672),
            'amqp_ssl' => (bool) ($data['amqp_ssl'] ?? false),
            'tap_enabled' => (bool) ($data['tap_enabled'] ?? true),
        ];
    }

    /** The URL, user, vhost or AMQP endpoint differs from what is saved. */
    private function destinationChanged(Broker $broker, array $attributes): bool
    {
        foreach (['url', 'username', 'vhost', 'amqp_host', 'amqp_port', 'amqp_ssl'] as $field) {
            if (array_key_exists($field, $attributes) && (string) $attributes[$field] !== (string) $broker->getAttribute($field)) {
                return true;
            }
        }

        return false;
    }

    private function addressRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $problem = BrokerAddress::urlProblem((string) $value);
            if ($problem !== null) {
                $fail(trans('warren.broker.address_'.$problem));
            }
        };
    }

    private function hostRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && $value !== '' && ! BrokerAddress::hostAllowed((string) $value)) {
                $fail(trans('warren.broker.address_blocked'));
            }
        };
    }
}
