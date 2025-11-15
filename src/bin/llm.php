<?php

require __DIR__ . '/../../vendor/autoload.php';

// Load config and setup OpenAI client
// We will use the Responses API from OpenAI
$env = parse_ini_file(__DIR__ . '/../../.env');

$apiKey = $env['OPENAI_API_KEY'];
$client = OpenAI::client($apiKey);

// Conversation context (Responses API takes an array of "items")
// The context is not being saved by the API, we need to keep it in our context
$context = [];

/**
 * Now lets ask our first question to OpenAI
 */
$firstQuestion = 'Give a qoute from The Office US?';
echo 'First Question: ' . $firstQuestion . PHP_EOL;

/**
 * First we need to add the question to the context
 */
$context[] = [
    'type' => 'message',
    'role' => 'user', // when set to user, OpenAI handles this as our input
    'content' => $firstQuestion,
];

/**
 * And then we send the context to the Response API
 */
$response = $client->responses()->create([
    'model' => 'gpt-5', // the model you want to use
    'input' => $context,
]);

// The API response with an object
$firstAnswer = $response->outputText ?? '';
echo 'First answer from OpenAI: ' . $firstAnswer . PHP_EOL;

/**
 * The API is stateless so we need to keep track of the history ourselves.
 * We simply do this by appending the response to the context
 */
$context[] = [
    'type' => 'message',
    'role' => 'assistant', // when set to assistant, OpenAI handles this as their input
    'content' => $response->outputText ?? '',
];

// Let's ask another question
$secondQuestion = 'Give another one?';
echo 'Second question: ' . $secondQuestion . PHP_EOL;

/**
 * if you would reset the context,
 * the Responses API has no idea of what they need to give another one of
 */
// $context = [];

/**
 * Also add the second question to the same context
 */
$context[] = [
    'type' => 'message',
    'role' => 'user',
    'content' => $secondQuestion,
];

/**
 * Send the context to the Response API
 */
$response = $client->responses()->create([
    'model' => 'gpt-5', // the model you want to use
    'input' => $context,
]);
$secondAnswer = $response->outputText ?? '';
echo 'Second answer from OpenAI: ' . $secondAnswer . PHP_EOL;

/**
 * More info about the Responses API: https://platform.openai.com/docs/guides/text
 */