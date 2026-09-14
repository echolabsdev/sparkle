# Human-in-the-Loop

Some tools are too consequential to run automatically — deleting files, sending
money, emailing customers. Prism gives you two ways to keep a human (or your
own application logic) between the model and the action:

- **Client-executed tools** — the model can call the tool, but *your
  application* executes it.
- **Approval-required tools** — execution pauses until an explicit approval
  decision is supplied. **Deny-by-default**: a tool call that never receives an
  approval is refused, never executed.

Both work across all providers for text and structured requests; streaming
events are emitted for Anthropic and OpenAI.

## Client-executed tools

Mark a tool with `clientExecuted()` instead of giving it a handler:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;

$searchTool = Tool::as('search_crm')
    ->for('Search the CRM for a customer')
    ->withStringParameter('query', 'The search query')
    ->clientExecuted();

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5')
    ->withTools([$searchTool])
    ->withMaxSteps(3)
    ->withPrompt('Find the customer named Acme Corp')
    ->asText();
```

When the model calls `search_crm`, the request loop **stops** and the response
comes back with `FinishReason::ToolCalls`. Execute the tool yourself, then
resume by appending your results to the conversation:

```php
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolResult;

$pendingCall = $response->toolCalls[0];

$results = new ToolResultMessage([
    new ToolResult(
        toolCallId: $pendingCall->id,
        toolName: $pendingCall->name,
        args: $pendingCall->arguments(),
        result: MyCrm::search($pendingCall->arguments()['query']),
    ),
]);

$final = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5')
    ->withTools([$searchTool])
    ->withMessages([...$response->messages, $results])
    ->asText();
```

## Approval-required tools

Keep the handler, but gate it behind `requiresApproval()`:

```php
$deleteTool = Tool::as('delete_file')
    ->for('Delete a file')
    ->withStringParameter('path', 'File path to delete')
    ->using(fn (string $path): string => Storage::delete($path) ? "Deleted {$path}" : "Failed")
    ->requiresApproval();
```

Approval can also be conditional on the arguments:

```php
$transferTool = Tool::as('transfer')
    ->for('Transfer money')
    ->withNumberParameter('amount', 'Amount in dollars')
    ->using(fn (int $amount): string => Bank::transfer($amount))
    ->requiresApproval(fn (array $args): bool => ($args['amount'] ?? 0) > 100);
```

When approval is needed, the loop stops and the pending requests surface on the
assistant message and the step:

```php
$step = $response->steps->last();

foreach ($step->toolApprovalRequests as $request) {
    // $request->approvalId — correlate the decision
    // $request->toolCallId — which tool call it belongs to
}
```

Resume with the decisions — approved calls execute, denied calls produce a
denial result the model sees:

```php
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

$decisions = new ToolResultMessage([], [
    new ToolApprovalResponse($request->approvalId, approved: true),
    // or: new ToolApprovalResponse($id, approved: false, reason: 'Too risky'),
]);

$final = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5')
    ->withTools([$deleteTool])
    ->withMessages([...$response->messages, $decisions])
    ->asText();
```

::: warning
Approval responses are trusted input from **your application** — Prism
verifies correlation ids, not who clicked the button. Authenticate and
authorize the approving user in your app before sending an approval.
:::

## Streaming

For Anthropic and OpenAI streams, a pending approval emits a
`ToolApprovalRequestEvent` (`tool_approval_request`) carrying the
`approvalId`, the tool call, and its arguments — then the stream ends with
`FinishReason::ToolCalls`. The event is broadcastable
(`ToolApprovalRequestBroadcast`), so an approval prompt can be pushed to a UI
over websockets in real time.

```php
foreach ($stream as $event) {
    if ($event->type() === StreamEventType::ToolApprovalRequest) {
        // Surface the approval prompt; resume with a ToolApprovalResponse
    }
}
```
