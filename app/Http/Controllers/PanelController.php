<?php

namespace App\Http\Controllers;

use App\Models\Broker;
use App\Models\CapturedMessage;
use App\Rabbit\BrokerUnavailable;
use App\Rabbit\Catalog;
use App\Rabbit\MessageFormatter;
use App\Warren\Brokers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    /**
     * The messages of a special panel, in plain language. They come from what the tap captured, never from reading
     * the queue itself: peeking at a quorum queue counts as a delivery and can make the broker drop the message.
     */
    public function __invoke(Broker $broker, string $id, Request $request, Catalog $catalog, Brokers $brokers, MessageFormatter $formatter): JsonResponse
    {
        $panel = $catalog->panel($id);
        if ($panel === null) {
            return response()->json(['message' => trans('warren.panel.unknown')], 404);
        }

        $context = $brokers->for($broker);

        try {
            $snapshot = $context->topology->snapshot();
        } catch (BrokerUnavailable) {
            $snapshot = ['queues' => []];
        }
        $known = array_column($snapshot['queues'], null, 'name');
        $queues = $catalog->queuesOf($panel, array_keys($known));

        $messages = collect();
        $total = 0;
        if ($queues !== []) {
            $query = CapturedMessage::where('broker_id', $broker->id)->where(function ($q) use ($queues) {
                foreach ($queues as $name) {
                    $q->orWhereJsonContains('queues', $name);
                }
            });
            $total = (clone $query)->count();
            $messages = $query->orderByDesc('id')->limit(max(1, min(1000, (int) $request->query('limite', 300))))->get();
        }

        $items = $messages->map(function (CapturedMessage $m) use ($panel, $formatter, $queues) {
            $inPanel = array_values(array_intersect($m->queues, $queues));

            return $formatter->format($m, $panel, $inPanel[0] ?? null);
        })->values();

        return response()->json([
            'panel' => ['id' => $panel['id'], 'title' => $panel['title'], 'kind' => $panel['kind'], 'description' => $panel['description'], 'queues' => $queues],
            'queues' => array_map(fn (string $name) => [
                'name' => $name,
                'label' => $known[$name]['catalog']['name'] ?? $name,
                'ready' => $known[$name]['ready'],
                'unacked' => $known[$name]['unacked'],
                'consumers' => $known[$name]['consumers'],
                'type' => $known[$name]['type'],
                'ttlMs' => ((array) $known[$name]['arguments'])['x-message-ttl'] ?? null,
            ], $queues),
            'items' => $items,
            'total' => $total,
            'counts' => $items->countBy('status'),
            'tap' => $context->status->get(),
        ])->header('Cache-Control', 'no-store');
    }
}
