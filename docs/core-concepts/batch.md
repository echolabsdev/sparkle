# Batch Processing

The Batch API lets you submit large numbers of requests for asynchronous processing. Instead of sending each prompt one at a time and waiting for a response, you queue them all at once and collect the results later. This makes batch processing significantly cheaper and better suited for offline workloads like dataset generation, bulk classification, or nightly report jobs.

> [!IMPORTANT]
> For deeper background, see the official provider documentation:
> - [Anthropic](https://platform.claude.com/docs/en/build-with-claude/batch-processing)
> - [OpenAI](https://developers.openai.com/api/docs/guides/batch/)

## How It Works

1. **Create** a batch (submit your requests)
2. **Poll** the batch status until it reaches `Completed`
3. **Retrieve** the results

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Batch\BatchStatus;

// 1. Create
$job = Prism::batch()
    ->using(Provider::Anthropic)
    ->create(items: $items);

// 2. Poll
while ($job->status !== BatchStatus::Completed) {
    sleep(30);
    $job = Prism::batch()
        ->using(Provider::Anthropic)
        ->retrieve($job->id);
}

// 3. Get results
$results = Prism::batch()
    ->using(Provider::Anthropic)
    ->getResults($job->id);

foreach ($results as $result) {
    echo "{$result->customId}: {$result->text}\n";
}
```

## Creating a Batch

### Anthropic

Anthropic takes a list of `BatchRequestItem` objects directly. Each item wraps a normal `TextRequest` and a unique `customId` you'll use to match results back to the original input.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$items = collect($yourData)->map(fn ($row) => new BatchRequestItem(
    customId: "row-{$row->id}",
    request: new TextRequest(
        model: 'claude-sonnet-4-20250514',
        messages: [new UserMessage("Summarise: {$row->content}")],
    ),
))->all();

$job = Prism::batch()
    ->using(Provider::Anthropic)
    ->create(items: $items);

echo $job->id;     // "msgbatch_01..."
echo $job->status; // BatchStatus::Validating
```

### OpenAI

OpenAI accepts the same `items` array as Anthropic. Prism automatically builds the JSONL payload and uploads it via the [Files API](/core-concepts/files) before creating the batch — you don't need to handle the file upload yourself.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$items = collect($yourData)->map(fn ($row) => new BatchRequestItem(
    customId: "row-{$row->id}",
    request: new TextRequest(
        model: 'gpt-4o',
        messages: [new UserMessage("Summarise: {$row->content}")],
    ),
))->all();

$job = Prism::batch()
    ->using(Provider::OpenAI)
    ->create(items: $items);

echo $job->id;
```

#### Bringing your own file

If you've already uploaded a JSONL file via the [Files API](/core-concepts/files), pass its ID directly via `inputFileId` instead:

```php
$file = Prism::files()
    ->using(Provider::OpenAI)
    ->withProviderOptions(['purpose' => 'batch'])
    ->upload(content: $jsonlContent, filename: 'batch-input.jsonl');

$job = Prism::batch()
    ->using(Provider::OpenAI)
    ->create(inputFileId: $file->id);
```

> [!NOTE]
> Providing both `items` and `inputFileId` at the same time throws a `PrismException`. Use one or the other.

## Polling for Completion

The batch doesn't complete immediately. Poll the status until it reaches a terminal state:

```php
use Prism\Prism\Batch\BatchStatus;

do {
    sleep(30);

    $job = Prism::batch()
        ->using(Provider::Anthropic)
        ->retrieve($job->id);

} while (! in_array($job->status, [
    BatchStatus::Completed,
    BatchStatus::Failed,
    BatchStatus::Expired,
    BatchStatus::Cancelled,
]));
```

### BatchStatus values

| Status | Meaning |
|---|---|
| `Validating` | Requests are being validated |
| `InProgress` | Processing is underway |
| `Finalizing` | Results are being prepared |
| `Completed` | All requests have finished |
| `Failed` | The batch failed to process |
| `Cancelling` | Cancellation is in progress |
| `Cancelled` | The batch was cancelled |
| `Expired` | The batch expired before completing |

### Request counts

The `BatchJob` object includes a `requestCounts` property that breaks down progress:

```php
echo "Total:      {$job->requestCounts->total}";
echo "Processing: {$job->requestCounts->processing}";
echo "Succeeded:  {$job->requestCounts->succeeded}";
echo "Failed:     {$job->requestCounts->failed}";
echo "Expired:    {$job->requestCounts->expired}";
```

## Retrieving Results

Once the batch is `Completed`, call `getResults()` to get all the result items back as an array:

```php
use Prism\Prism\Batch\BatchResultStatus;

$results = Prism::batch()
    ->using(Provider::Anthropic)
    ->getResults($job->id);

foreach ($results as $result) {
    if ($result->status === BatchResultStatus::Succeeded) {
        echo "{$result->customId}: {$result->text}\n";
        echo "Tokens: {$result->usage->promptTokens} in / {$result->usage->completionTokens} out\n";
    } else {
        echo "{$result->customId} failed: {$result->errorMessage}\n";
    }
}
```

### BatchResultItem properties

| Property | Type | Description |
|---|---|---|
| `customId` | `string` | The ID you assigned when creating the item |
| `status` | `BatchResultStatus` | `Succeeded`, `Errored`, `Canceled`, or `Expired` |
| `text` | `?string` | The generated text (null if not succeeded) |
| `usage` | `?Usage` | Token counts |
| `messageId` | `?string` | Provider message ID |
| `model` | `?string` | Model used |
| `errorType` | `?string` | Error code if failed |
| `errorMessage` | `?string` | Human-readable error detail |

## Listing Batches

Browse previously created batches with optional pagination:

```php
$list = Prism::batch()
    ->using(Provider::OpenAI)
    ->list(limit: 20);

foreach ($list->batches as $job) {
    echo "{$job->id}: {$job->status->name}\n";
}

// Next page
if ($list->hasMore) {
    $nextPage = Prism::batch()
        ->using(Provider::OpenAI)
        ->list(limit: 20, afterId: $list->lastId);
}
```

## Cancelling a Batch

```php
$job = Prism::batch()
    ->using(Provider::Anthropic)
    ->cancel($job->id);

echo $job->status->name; // "Cancelling"
```

## Provider-Conditional Options

Use `whenProvider()` when your batch logic needs to handle both providers from the same code path:

```php
$job = Prism::batch()
    ->using($providerName)
    ->whenProvider('openai', fn ($r) => $r->withProviderOptions(['completion_window' => '24h']))
    ->create(items: $items, inputFileId: $inputFileId);
```

## Client Options

```php
$job = Prism::batch()
    ->using(Provider::Anthropic)
    ->withClientOptions(['timeout' => 60])
    ->withClientRetry(3, 500)
    ->create(items: $items);
```

## Error Handling

```php
use Prism\Prism\Exceptions\PrismException;
use Throwable;

try {
    $job = Prism::batch()
        ->using(Provider::Anthropic)
        ->create(items: $items);
} catch (PrismException $e) {
    Log::error('Batch creation failed', ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    Log::error('Unexpected error', ['error' => $e->getMessage()]);
}
```

> [!TIP]
> Individual result items can have their own failure status independent of the overall batch status. Always check `$result->status` on each item — a `Completed` batch can still contain `Errored` or `Expired` result items.
