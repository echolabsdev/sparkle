<template>
  <div>
    <div style="overflow-x: auto !important; display: block !important; width: 100% !important;">
      <table style="width: max-content !important; min-width: 850px !important;">
        <thead>
          <tr>
            <th scope="col">Provider</th>
            <th v-for="feature in features" :key="feature" scope="col">
              {{ feature }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="provider in providers" :key="provider.name">
            <th scope="row">{{ provider.name }}</th>
            <td v-for="feature in features" :key="feature">
              <div class="flex justify-center">
                <svg
                  v-if="provider[feature.toLowerCase()] === 'supported'"
                  class="w-6 h-6"
                  viewBox="0 0 24 24"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                    d="M20 6L9 17L4 12"
                    stroke="green"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
                <svg
                  v-else-if="provider[feature.toLowerCase()] === 'planned'"
                  class="w-6 h-6"
                  viewBox="0 0 24 24"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <circle
                    cx="12"
                    cy="12"
                    r="8"
                    stroke="orange"
                    stroke-width="2"
                  />
                </svg>
                <svg
                  v-else-if="provider[feature.toLowerCase()] === 'adapted'"
                  class="w-6 h-6"
                  viewBox="0 0 24 24"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                    d="M12 3v3m0 12v3M3 12h3m12 0h3"
                    stroke="green"
                    stroke-width="2"
                    stroke-linecap="round"
                  />
                  <circle cx="12" cy="12" r="4" stroke="green" stroke-width="2" />
                </svg>
                <svg
                  v-else
                  class="w-6 h-6"
                  viewBox="0 0 24 24"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                    d="M18 6L6 18M6 6l12 12"
                    stroke="red"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="flex gap-4 mt-4">
      <div class="flex items-center gap-2">
        <svg
          class="w-5 h-5"
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M20 6L9 17L4 12"
            stroke="green"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
        <span>Supported</span>
      </div>
      <div class="flex items-center gap-2">
        <svg
          class="w-5 h-5"
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <circle cx="12" cy="12" r="8" stroke="orange" stroke-width="2" />
        </svg>
        <span>Planned Support</span>
      </div>
      <div class="flex items-center gap-2">
        <svg
          class="w-5 h-5"
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M12 3v3m0 12v3M3 12h3m12 0h3"
            stroke="green"
            stroke-width="2"
            stroke-linecap="round"
          />
          <circle cx="12" cy="12" r="4" stroke="green" stroke-width="2" />
        </svg>
        <span>Adapted Support</span>
      </div>
      <div class="flex items-center gap-2">
        <svg
          class="w-5 h-5"
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M18 6L6 18M6 6l12 12"
            stroke="red"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
        <span>Unsupported</span>
      </div>
    </div>
  </div>
</template>

<script>
const Supported = "supported";
const Planned = "planned";
const Adapted = "adapted";
const Unsupported = "unsupported";

export default {
  name: "CompatibilityMatrix",
  data() {
    return {
      features: [
        "Text",
        "Streaming",
        "Structured",
        "Embeddings",
        "Image",
        "Speech-to-Text",
        "Text-to-Speech",
        "Tools",
        "Documents",
        "Moderation",
      ],
      providers: [
        {
          name: "Anthropic",
          text: Supported,
          streaming: Supported,
          structured: Adapted,
          embeddings: Unsupported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Supported,
          moderation: Unsupported,
        },
        {
          name: "Azure OpenAI",
          text: Planned,
          streaming: Planned,
          structured: Planned,
          embeddings: Planned,
          image: Planned,
          "speech-to-text": Planned,
          "text-to-speech": Planned,
          tools: Planned,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "Bedrock - Anthropic",
          text: Supported,
          streaming: Unsupported,
          structured: Supported,
          embeddings: Unsupported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "Bedrock - Cohere",
          text: Unsupported,
          streaming: Unsupported,
          structured: Unsupported,
          embeddings: Supported,
          image: Unsupported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Unsupported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "Bedrock - Converse",
          text: Supported,
          streaming: Unsupported,
          structured: Supported,
          embeddings: Unsupported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Supported,
          moderation: Unsupported,
        },
        {
          name: "DeepSeek",
          text: Supported,
          streaming: Unsupported,
          structured: Supported,
          embeddings: Unsupported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "ElevenLabs",
          text: Unsupported,
          streaming: Unsupported,
          structured: Unsupported,
          embeddings: Unsupported,
          image: Unsupported,
          "speech-to-text": Supported,
          "text-to-speech": Planned,
          tools: Unsupported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "Gemini",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Supported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Supported,
          moderation: Unsupported,
        },
        {
          name: "Groq",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Planned,
          image: Supported,
          "speech-to-text": Supported,
          "text-to-speech": Supported,
          tools: Supported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "Mistral",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Supported,
          image: Supported,
          "speech-to-text": Supported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Supported,
          moderation: Unsupported,
        },
        {
          name: "Ollama",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Supported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "OpenRouter",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Unsupported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Supported,
          moderation: Unsupported,
        },
        {
          name: "Requesty",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Unsupported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Supported,
          moderation: Unsupported,
        },
        {
          name: "OpenAI",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Supported,
          image: Supported,
          "speech-to-text": Supported,
          "text-to-speech": Supported,
          tools: Supported,
          documents: Supported,
          moderation: Supported,
        },
        {
          name: "Qwen",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Supported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "Replicate",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Supported,
          image: Supported,
          "speech-to-text": Supported,
          "text-to-speech": Supported,
          tools: Unsupported,
          documents: Unsupported,
        },
        {
          name: "VoyageAI",
          text: Unsupported,
          streaming: Unsupported,
          structured: Unsupported,
          embeddings: Supported,
          image: Unsupported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Unsupported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "xAI",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Unsupported,
          image: Supported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Supported,
          documents: Unsupported,
          moderation: Unsupported,
        },
        {
          name: "Perplexity",
          text: Supported,
          streaming: Supported,
          structured: Supported,
          embeddings: Unsupported,
          image: Unsupported,
          "speech-to-text": Unsupported,
          "text-to-speech": Unsupported,
          tools: Unsupported,
          documents: Unsupported,
        },
      ],
    };
  },
};
</script>

<style>
.provider-table-wrapper {
  overflow-x: auto;
  width: 100%;
}
.provider-table {
  min-width: 640px;
  width: 100%;
}
</style>
