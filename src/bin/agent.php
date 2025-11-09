<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use OpenAI\Client as OpenAIClient;
use OpenAI;

// ---- Setup ---------------------------------------------------------------

$apiKey  = getenv('OPENAI_API_KEY');
$client  = OpenAI::client($apiKey);

// Conversation context (Responses API takes an array of "items")
$context = [];

// Define your function tool (here: `ping`)
$tools = [
    [
        'type' => 'function',
        'name' => 'ping',
        'description' => 'Returns a simple diagnostic string with the given payload.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Any text payload to echo back.'
                ],
            ],
            'required' => ['message'],
            'additionalProperties' => false
        ],
    ],
];

// ---- Your local tool implementation -------------------------------------

function ping(array $args): string
{
    // Keep it simple; adapt to your real tool logic
    $msg = $args['message'] ?? '';
    return "pong: " . $msg;
}

// ---- Helpers that mirror your Python functions --------------------------

/**
 * Equivalent to:
 *   def call(tools): return client.responses.create(model="gpt-5", tools=tools, input=context)
 */
function call_response(OpenAIClient $client, array $tools, array $context)
{
    return $client->responses()->create([
        'model' => 'gpt-5',
        'tools' => $tools,
        'input' => $context,
        // 'tool_choice' => 'auto', // optional
        // 'parallel_tool_calls' => true, // optional
    ]);
}

/**
 * Equivalent to:
 *   def tool_call(item): result = ping(**json.loads(item.arguments)); return [ item, {... function_call_output ...} ]
 */
function tool_call(object $item): array
{
    $name = $item->name ?? null;
    $args = json_decode($item->arguments ?? '{}', true) ?: [];

    if ($name === 'ping') {
        $result = ping($args);
    } else {
        $result = "Unhandled tool: {$name}";
    }

    // The Responses API correlates tool calls via call_id
    $callId = $item->callId ?? ($item->call_id ?? null);

    return [
        // Keep the original tool call in context
        [
            'type'      => 'function_call',
            'name'      => $name,
            'arguments' => $item->arguments ?? '{}',
            'call_id'   => $callId,
        ],
        // Provide the corresponding output
        [
            'type'    => 'function_call_output',
            'call_id' => $callId,
            'output'  => $result,
        ],
    ];
}

/**
 * Equivalent to:
 *   def handle_tools(tools, response): ...
 * Returns true if it appended anything to $context (so we should call the model again).
 */
function handle_tools(array $tools, object $response, array &$context): bool
{
    $before = count($context);

    // If the first output item is a "reasoning" block, keep it (optional)
    if (!empty($response->output) && isset($response->output[0]) && ($response->output[0]->type ?? null) === 'reasoning') {
        // Store raw reasoning block in context so the model can refer to it
        $context[] = [
            'type'    => 'reasoning',
            'content' => $response->output[0]->content ?? [],
        ];
    }

    // Resolve any function calls
    foreach ($response->output as $item) {
        if (($item->type ?? null) === 'function_call') {
            foreach (tool_call($item) as $ctxItem) {
                $context[] = $ctxItem;
            }
        }
    }

    return count($context) !== $before;
}

/**
 * Equivalent to:
 *   def process(line): ...
 */
function process(OpenAIClient $client, array $tools, array &$context, string $line): string
{
    // Add user message
    $context[] = [
        'type'    => 'message',
        'role'    => 'user',
        'content' => $line,
    ];

    // First call
    $response = call_response($client, $tools, $context);

    // Keep calling until no new tool calls are produced
    while (handle_tools($tools, $response, $context)) {
        $response = call_response($client, $tools, $context);
    }

    // Append final assistant message to the running context
    $context[] = [
        'type'    => 'message',
        'role'    => 'assistant',
        'content' => $response->outputText ?? '',
    ];

    return $response->outputText ?? '';
}

// ---- Example usage -------------------------------------------------------

$answer = process($client, $tools, $context, "Hi, call ping with message='hello from PHP'.");
echo $answer, PHP_EOL;
