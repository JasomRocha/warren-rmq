<?php

return [
    'required' => 'O campo :attribute é obrigatório.',
    'email' => 'Informe um :attribute válido.',
    'string' => 'O campo :attribute deve ser um texto.',
    'url' => 'Informe um :attribute válido, começando com http:// ou https://.',
    'regex' => 'O campo :attribute está em um formato inválido.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'between' => ['numeric' => 'O campo :attribute deve estar entre :min e :max.'],
    'max' => ['string' => 'O campo :attribute não pode passar de :max caracteres.'],
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'attributes' => ['email' => 'e-mail', 'password' => 'senha', 'name' => 'nome', 'url' => 'endereço da API', 'username' => 'usuário', 'vhost' => 'vhost', 'amqp_host' => 'endereço AMQP', 'amqp_port' => 'porta AMQP'],
];
