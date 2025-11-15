# ai-agent-demo
These examples interact with OpenAI. 
Simple conversation with the LLM and also interaction in Agent mode. 

## How to run
Install the dependencies

```
composer require openai-php/client guzzlehttp/guzzle
´´´

Set your env files
```
cp .env.example .env
```

## Examples
### Conversation with a LLM
```
php src/bin/llm.php
```

### Interact in Agent mode
```
php src/bin/agent.php
```
