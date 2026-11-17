<?php

namespace App\Http\Controllers;

use App\Models\Broker;
use App\Models\CapturedMessage;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    public function __invoke(Broker $broker, int $id): JsonResponse
    {
        $message = CapturedMessage::where('broker_id', $broker->id)->find($id);

        return $message
            ? response()->json($message->detail())
            : response()->json(['message' => trans('warren.message_gone')], 404);
    }
}
