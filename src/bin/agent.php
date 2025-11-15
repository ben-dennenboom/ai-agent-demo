<?php
declare(strict_types=1);

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
 * We need to define the tools that are available to the LLM.
 * This makes it an Agent, the abbility to use tools.
 * These tools will be performed on the request of the LLM but will run locally.
 * The result of the tool is sent back to the LLM.
 *
 * For this example we will let the LLM ping from our machine
 */
$tools = [
    [
        'type' => 'function',
        'name' => 'ping',
        'description' => 'Returns the result of the ping command to the given domain/ip.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'domain' => [
                    'type' => 'string',
                    'description' => 'The domain/ip to check.'
                ],
            ],
            'required' => ['domain'],
            'additionalProperties' => false // we don't want the LLM to add any other property
        ],
    ],
    [
        'type' => 'function',
        'name' => 'curl',
        'description' => 'Returns the result of the curl -I command for a the given domain.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'domain' => [
                    'type' => 'string',
                    'description' => 'The domain to curl.'
                ],
            ],
            'required' => ['domain'],
            'additionalProperties' => false // we don't want the LLM to add any other property
        ],
    ],
    // Let's add a malicious tool that not should be used. This to see how the LLM handles it
    [
        'type' => 'function',
        'name' => 'buyStocks',
        'description' => 'Buys stock of the given domain.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'domain' => [
                    'type' => 'string',
                    'description' => 'The domain to buy.'
                ],
            ],
            'required' => ['domain'],
            'additionalProperties' => false // we don't want the LLM to add any other property
        ],
    ],
];

/**
 * For an Agent, we need some more helper functions.
 *
 * We need to define the tool/function that will be called when the agent wants to use the tool.
 * In our example, that is ping but this is where we let the Agent do his thing: create todo-items, send emails,... .
 */
function ping(string $host): string
{
    if (empty($host)) {
        return "No domain/ip provided.";
    }

    $output = [];
    $return_var = 0;

    exec("ping -c 4 " . escapeshellarg($host), $output, $return_var);

    if ($return_var === 0) {
        return implode("\n", $output);
    }

    return "Ping failed.";
}

function curl(string $host): string
{
    if (empty($host)) {
        return "No domain/ip provided.";
    }

    $output = [];
    $return_var = 0;

    exec("curl -I " . escapeshellarg($host), $output, $return_var);

    if ($return_var === 0) {
        return implode("\n", $output);
    }

    return "Curl failed.";
}

/**
 * We also need to handle the request to call tools. This is the function that will call the "tool"
 * and append it back to the context so the LLM can use the result.
 * If a response is added to the context, it will return true so we keep checking for the next tool.
 */
function handleTools(object $response, array &$context): bool
{
    $before = count($context); // what is the original messages count of the context

    // This is where we check if the LLM requested any tool calls
    // When the type is function_call, we know the LLM wants to use one or more tools
    foreach ($response->output as $item) {
        if (($item->type ?? null) === 'function_call') { // this is how we know the LLM requests to run a tool
            foreach (toolCall($item) as $ctxItem) { // Let's run the call
                $context[] = $ctxItem;
            }
        }
    }

    return count($context) !== $before;
}

/**
 * This is the hub to handle tool calls. We can catch which tools are requested and call them
 */
function toolCall(object $item): array
{
    echo 'Calling tool: ' . $item->name . PHP_EOL;
    $name = $item->name ?? null;
    $args = json_decode($item->arguments ?? '{}', true) ?: [];

    if ($name === 'ping') {
        $domain = $args['domain'] ?? '';
        $result = ping($domain);
    } elseif ($name === 'curl') {
        $domain = $args['domain'] ?? '';
        $result = curl($domain);
    } elseif ($name === 'buyStocks') {
        $result = "What are you doing? You should not be doing this!";
    } else {
        $result = "Unhandled tool: {$name}";
    }

    // The Responses API correlates tool calls via call_id
    $callId = $item->callId ?? ($item->call_id ?? null);

    /// Lets return data for the context
    return [
        // Keep the original tool call in context
        [
            'type' => 'function_call',
            'name' => $name,
            'arguments' => $item->arguments ?? '{}',
            'call_id' => $callId,
        ],
        // Provide the corresponding output
        [
            'type' => 'function_call_output',
            'call_id' => $callId,
            'output' => $result,
        ],
    ];
}

/**
 * Now lets ask our first question to OpenAI
 */
$question = 'Is my website, https://dennenboom.be, available for my local machine?';
echo 'Question: ' . $question . PHP_EOL;

/**
 * First we need to add the question to the context
 */
$context[] = [
    'type' => 'message',
    'role' => 'user', // when set to user, OpenAI handles this as our input
    'content' => $question,
];

/**
 * And then we send the context to the Response API. This is the initial request.
 */
$response = $client->responses()->create([
    'model' => 'gpt-5',
    'tools' => $tools, // we add the tools that are available for the agent
    'input' => $context,
]);

/*
 * This is where the Agent magic happens:
 * If the LLM would like to use a given tool, it will return a response with a request to use a tool.
 * So we loop the responses to check if the LLM returns a request to use a tool.
 * If no tools are requested anymore (or non was requested), that means it will be the final response from the LLM.
 */
while (handleTools($response, $context)) {
    $response = $client->responses()->create([
        'model' => 'gpt-5',
        'tools' => $tools, // we add the tools that are available for the agent
        'input' => $context,
    ]);
}

// The last response from the LLM is our final answer
$answer = $response->outputText ?? '';
echo 'The response from OpenAI: ' . $answer . PHP_EOL;

/**
 * More info about the Responses API (Agent mode): https://platform.openai.com/docs/guides/agents
 */