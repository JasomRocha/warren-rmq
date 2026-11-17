<?php

namespace App\Http\Controllers;

use App\Models\Broker;
use App\Rabbit\BrokerUnavailable;
use App\Warren\Brokers;
use Illuminate\Http\JsonResponse;

class TopologyController extends Controller
{
    public function __invoke(Broker $broker, Brokers $brokers): JsonResponse
    {
        try {
            return response()->json($brokers->for($broker)->topology->snapshot())->header('Cache-Control', 'no-store');
        } catch (BrokerUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
