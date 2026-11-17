<?php

namespace App\Http\Controllers;

use App\Models\Broker;
use App\Models\CapturedMessage;
use App\Models\MetricSample;
use App\Rabbit\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** The data behind the dashboard charts: how the totals moved, and who has been sending. */
class MetricsController extends Controller
{
    private const RANGES = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '3d' => 259200];

    private const MAX_POINTS = 240;

    public function __invoke(Broker $broker, Request $request, Catalog $catalog): JsonResponse
    {
        $range = array_key_exists((string) $request->query('range'), self::RANGES) ? (string) $request->query('range') : '1h';
        $span = self::RANGES[$range];
        $to = time();
        $from = $to - $span;

        $samples = MetricSample::where('broker_id', $broker->id)->where('ts', '>=', $from)
            // readings from before the work-queue rule have no queue count and still hold result queues as backlog
            ->where(fn ($q) => $q->where('queues', '>', 0)->orWhere(fn ($q) => $q->where('ready', 0)->where('unacked', 0)))
            ->orderBy('ts')->get();
        $messages = CapturedMessage::where('broker_id', $broker->id)->where('ts', '>=', $from * 1000)->get(['ts', 'instance']);

        return response()->json([
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'samples' => $this->downsample($samples, $span),
            'areas' => $catalog->categories(),
            'instances' => $this->topInstances($messages, $catalog),
            'published' => $this->published($messages, $from, $to, $span),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Averages the samples into at most MAX_POINTS buckets, so three days of data is as light to draw as one hour.
     *
     * @param  Collection<int, MetricSample>  $samples
     * @return list<array<string, mixed>>
     */
    private function downsample(Collection $samples, int $span): array
    {
        $bucket = max(1, (int) ceil($span / self::MAX_POINTS));
        $out = [];

        foreach ($samples->groupBy(fn (MetricSample $s) => intdiv($s->ts, $bucket)) as $group) {
            $n = $group->count();
            $areas = [];
            foreach ($group as $s) {
                foreach ((array) $s->areas as $area => $ready) {
                    $areas[$area] = ($areas[$area] ?? 0) + $ready / $n;
                }
            }
            $out[] = [
                't' => (int) round($group->avg('ts')),
                'ready' => (int) round($group->avg('ready')),
                'unacked' => (int) round($group->avg('unacked')),
                'consumers' => (int) round($group->avg('consumers')),
                'rateIn' => round($group->avg('rate_in'), 2),
                'rateDeliver' => round($group->avg('rate_deliver'), 2),
                'rateAck' => round($group->avg('rate_ack'), 2),
                'rateRedeliver' => round((float) $group->avg('rate_redeliver'), 2),
                'capacity' => $group->whereNotNull('capacity')->isEmpty() ? null : round((float) $group->whereNotNull('capacity')->avg('capacity'), 3),
                'queues' => (int) round((float) $group->avg('queues')),
                'areas' => array_map(fn ($v) => (int) round($v), $areas),
            ];
        }

        return $out;
    }

    /** @param  Collection<int, CapturedMessage>  $messages */
    private function topInstances(Collection $messages, Catalog $catalog): array
    {
        return $messages->groupBy(fn ($m) => $m->instance ?? '')->map(fn ($group, $key) => [
            'key' => (string) $key,
            'name' => $key === '' ? null : $catalog->instanceLabel((string) $key),
            'count' => $group->count(),
        ])->sortByDesc('count')->take(10)->values()->all();
    }

    /**
     * Messages the tap saw published, per time slice.
     *
     * @param  Collection<int, CapturedMessage>  $messages
     * @return list<array{t: int, count: int}>
     */
    private function published(Collection $messages, int $from, int $to, int $span): array
    {
        $slice = max(60, (int) ceil($span / 120));
        $counts = $messages->countBy(fn ($m) => intdiv(intdiv($m->ts, 1000), $slice));

        $out = [];
        for ($t = intdiv($from, $slice); $t <= intdiv($to, $slice); $t++) {
            $out[] = ['t' => $t * $slice, 'count' => (int) ($counts[$t] ?? 0)];
        }

        return $out;
    }
}
