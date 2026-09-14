<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in(__DIR__);
uses()->group('providers')->in('Providers');
uses()->group('anthropic')->in('Providers/Anthropic');
uses()->group('deepseek')->in('Providers/DeepSeek');
uses()->group('gemini')->in('Providers/Gemini');
uses()->group('groq')->in('Providers/Groq');
uses()->group('mistral')->in('Providers/Mistral');
uses()->group('ollama')->in('Providers/Ollama');
uses()->group('openai')->in('Providers/OpenAI');
uses()->group('replicate')->in('Providers/Replicate');
uses()->group('xai')->in('Providers/XAI');
uses()->group('qwen')->in('Providers/Qwen');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/
