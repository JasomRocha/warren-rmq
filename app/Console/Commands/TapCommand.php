<?php

namespace App\Console\Commands;

use App\Warren\Supervisor;
use Illuminate\Console\Command;

class TapCommand extends Command
{
    protected $signature = 'warren:tap {--seconds= : Parar depois desta quantidade de segundos (padrão: roda até ser interrompido)}';

    protected $description = 'Escuta cada conexão cadastrada, registra as mensagens publicadas e coleta as métricas do dashboard';

    public function handle(Supervisor $supervisor): int
    {
        if (! config('warren.amqp.enabled')) {
            $this->warn(trans('warren.tap.disabled'));
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, fn () => $supervisor->stop());
            }
        }

        $seconds = $this->option('seconds');
        $supervisor->run($seconds === null ? null : (int) $seconds, fn (string $line) => $this->line($line));

        return self::SUCCESS;
    }
}
