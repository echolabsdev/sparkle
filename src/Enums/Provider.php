<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum Provider: string
{
    case Anthropic = 'anthropic';
    case DeepSeek = 'deepseek';
    case Ollama = 'ollama';
    case OpenAI = 'openai';
    case OpenRouter = 'openrouter';
    case Requesty = 'requesty';
    case Mistral = 'mistral';
    case Groq = 'groq';
    case XAI = 'xai';
    case Gemini = 'gemini';
    case VoyageAI = 'voyageai';
    case ElevenLabs = 'elevenlabs';
    case Replicate = 'replicate';
    case Qwen = 'qwen';
    case Azure = 'azure';
    case Perplexity = 'perplexity';
    case Vertex = 'vertex';
    case Z = 'z';
}
