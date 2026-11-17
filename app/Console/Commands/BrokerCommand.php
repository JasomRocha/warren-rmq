<?php

namespace App\Console\Commands;

use App\Models\Broker;
use Illuminate\Console\Command;

class BrokerCommand extends Command
{
    protected $signature = 'warren:broker {--from-env : Cria a primeira conexão a partir das variáveis RABBITMQ_* se ainda não existir nenhuma}';

    protected $description = 'Cadastra a conexão inicial com o RabbitMQ a partir do ambiente';

    public function handle(): int
    {
        $created = Broker::seedFromEnvironment();

        if ($created !== null) {
            $this->info(trans('warren.broker.created', ['name' => $created->name]));
        }

        return self::SUCCESS;
    }
}
