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
        'name' => 'ReadHtmlFromALink',
        'description' => 'Fetches the HTML from an url and returns it.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'link' => [
                    'type' => 'string',
                    'description' => 'The link to page.'
                ],
            ],
            'required' => ['link'],
            'additionalProperties' => false // we don't want the LLM to add any other property
        ],
    ],
];

function ReadHtmlFromALink(string $link): string
{
    if (empty($link)) {
        return "No link provided.";
    }

    // curl -s $link
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $link);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // Simple parsing to extract post titles (this is a basic example, for production use a proper HTML parser)
    return $response ?? 'No content found.';
}

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

    if ($name === 'ReadHtmlFromALink') {
        $domain = $args['link'] ?? '';
        $result = ReadHtmlFromALink($domain);
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
$blogs = [
    //'https://www.reddit.com/r/SaaS',
    //'https://stitcher.io/',
    //'https://www.saastr.com/category/resource-type/blog-posts/',
    //'https://tomtunguz.com/',
    'https://calnewport.com/blog/',
    //'https://simonwillison.net/',
    //'https://blog.pragmaticengineer.com/',
    //'https://news.ycombinator.com/',
    'https://www.softkraft.co/blog/',
    'https://www.coffeedigital.nl/blog',
    'https://dx-solutions.be/blog/',

];

$content = [];
foreach ($blogs as $blog) {
    $content[] = 'The content of '. $blog . ": \n" .ReadHtmlFromALink($blog);
}

$question = 'Get the html from the last post from all the given blogs (the content has already been scraped). Do this by using the tool ReadHtmlFromALink to pass the url an fetch the html. Summarize each blog in a few sentences and use that summary to give 3 topics that are discussed across the posts. For echt topic, give 5 ideas to write a similar blog post about that topic. Make sure that the content can stand on its own. It should form an opinion, not list a couple of tips or steps or anything like that. The links: ' . implode(', ', $blogs) . '. The content of the blogs is: ' . implode("\n\n", $content);
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