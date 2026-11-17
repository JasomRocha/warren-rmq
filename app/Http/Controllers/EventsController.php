<?php

namespace App\Http\Controllers;

use App\Models\Broker;
use App\Models\CapturedMessage;
use App\Rabbit\Catalog;
use App\Warren\Brokers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventsController extends Controller
{
    /** Messages changed since a revision, so the live view only downloads what is new. */
    public function __invoke(Broker $broker, Request $request, Brokers $brokers, Catalog $catalog): JsonResponse
    {
        $since = max(0, (int) $request->query('since', 0));
        $names = [];
        $mine = CapturedMessage::where('broker_id', $broker->id);

        return response()->json([
            'rev' => (int) (clone $mine)->max('rev'),
            'firstId' => (int) ((clone $mine)->min('id') ?? 0),
            'messages' => (clone $mine)->where('rev', '>', $since)->orderBy('id')->limit(5000)->get()->map(function (CapturedMessage $m) use ($catalog, &$names) {
                $instance = $m->instance;
                $label = $instance === null ? null : ($names[$instance] ??= $catalog->instanceLabel($instance));

                return $m->summary() + ['instanceName' => $label];
            })->values(),
            'tap' => $brokers->for($broker)->status->get(),
        ])->header('Cache-Control', 'no-store');
    }
}
