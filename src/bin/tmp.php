<?php
$openAiClient = object();
$context = collect();

// User question
$context->append(['role' => 'user', 'content' => 'Give me a quote from the Office.',]);

// AI response
$context->append([
    'role' => 'assistant',
    'content' => $openAiClient->askQuestion($context->last()),
]);

// User follow-up question
$context->append(['role' => 'user', 'content' => 'Give me another one.',]);

// AI response
$context->append([
    'role' => 'assistant',
    'content' => $openAiClient->askQuestion($context->last()),
]);



