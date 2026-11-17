<?php

return [
    'required' => 'The :attribute field is required.',
    'email' => 'The :attribute must be a valid e-mail address.',
    'string' => 'The :attribute must be a string.',
    'url' => 'The :attribute must be a valid address starting with http:// or https://.',
    'regex' => 'The :attribute format is invalid.',
    'integer' => 'The :attribute must be an integer.',
    'between' => ['numeric' => 'The :attribute must be between :min and :max.'],
    'max' => ['string' => 'The :attribute may not be greater than :max characters.'],
    'boolean' => 'The :attribute field must be true or false.',
    'attributes' => ['email' => 'e-mail', 'password' => 'password', 'name' => 'name', 'url' => 'API address', 'username' => 'user', 'vhost' => 'vhost', 'amqp_host' => 'AMQP host', 'amqp_port' => 'AMQP port'],
];
