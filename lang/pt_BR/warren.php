<?php

return [
    'broker' => [
        'created' => 'Conexão :name cadastrada a partir do ambiente.',
        'unknown' => 'Esta conexão não existe.',
        'no_vhost' => 'O vhost “:vhost” não existe neste RabbitMQ.',
        'unauthorized' => 'Usuário ou senha não aceitos pelo RabbitMQ (resposta :status)',
        'status' => 'O RabbitMQ respondeu :status em /api:path',
        'unreachable' => 'Não foi possível falar com o RabbitMQ em :url (/api:path)',
        'password_again' => 'Informe a senha de novo: o endereço, o usuário ou o vhost mudou.',
        'address_invalid' => 'Endereço inválido.',
        'address_extra' => 'Use só o endereço da API de gerenciamento, sem usuário, senha, parâmetros nem âncora.',
        'address_blocked' => 'Este endereço não é permitido (link-local ou metadados de nuvem).',
    ],
    'message_gone' => 'Esta mensagem já saiu do histórico.',
    'panel' => ['unknown' => 'Este painel não existe no catálogo.'],
    'tap' => [
        'removed' => 'Conexão :name removida; escuta encerrada.',
        'disabled' => 'A escuta está desligada (WARREN_TAP_ENABLED=false).',
        'connected' => 'Escuta conectada, :count {count|vínculo de cópia|vínculos de cópia}',
        'stopped' => 'Escuta interrompida: :error',
    ],
    'user' => [
        'no_users' => 'O login está ligado, mas não há usuários. Crie um com: php artisan warren:user voce@exemplo.com',
        'ask_email' => 'E-mail',
        'ask_password' => 'Senha (mínimo de 8 caracteres)',
        'ask_repeat' => 'Repita a senha',
        'mismatch' => 'As senhas não coincidem.',
        'invalid_email' => 'E-mail inválido.',
        'short_password' => 'A senha precisa ter pelo menos 8 caracteres.',
        'created' => 'Usuário :email criado.',
        'updated' => 'Senha de :email atualizada.',
    ],
    'demo' => [
        'warning' => 'Isto cria exchanges e filas demo.* e publica mensagens de teste em :host:::port (vhost :vhost).',
        'confirm' => 'Continuar? Nunca aponte isto para um broker de produção.',
        'publishing' => 'Publicando :rate/s. Ctrl+C para parar.',
    ],
];
