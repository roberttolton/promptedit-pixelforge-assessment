<?php

namespace App\Integrations\Pixelforge;

use App\Enums\GenerationSyncResult;
use App\Enums\WebhookOutcome;
use App\Models\Generation;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessWebhook
{
    public function __construct(private readonly SyncGeneration $sync) {}

    /**
     * Safe to call repeatedly for the same stored webhook as handled & ignored ones are skipped, and
     * ones that crashed are marked FAILED so providers can retry.
     */
    public function __invoke(Webhook $webhook): void
    {
        try {

            DB::transaction(function () use ($webhook): void {

                $webhook = Webhook::lockForUpdate()->findOrFail($webhook->id);

                if (in_array($webhook->outcome, [WebhookOutcome::HANDLED, WebhookOutcome::IGNORED], true)) {
                    return;
                }

                [$outcome, $reason, $generationId] = $this->handle($webhook);

                $webhook->update([
                    'outcome' => $outcome,
                    'failure_reason' => $reason,
                    'generation_id' => $generationId,
                    'processed_at' => now(),
                ]);

            });

        } catch (Throwable $e) {

            Webhook::whereKey($webhook->id)->update([
                'outcome' => WebhookOutcome::FAILED,
                'failure_reason' => Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{WebhookOutcome, string|null, string|null}
     */
    private function handle(Webhook $webhook): array
    {
        $type = WebhookEventType::tryFrom($webhook->type);
        $object = $webhook->payload['data']['object'] ?? null;

        if ($type === null || $type === WebhookEventType::PING || ! is_array($object)) {
            return [
                WebhookOutcome::IGNORED,
                'Not a generation event.',
                null,
            ];
        }

        return match (($this->sync)($object)) {

            GenerationSyncResult::APPLIED => [
                WebhookOutcome::HANDLED,
                null,
                $this->generationId($object),
            ],

            GenerationSyncResult::STALE => [
                WebhookOutcome::IGNORED,
                'Stale or out-of-order event.',
                $this->generationId($object),
            ],

            GenerationSyncResult::UNKNOWN_GENERATION => [
                WebhookOutcome::IGNORED,
                'Unknown generation.',
                null,
            ],

        };
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function generationId(array $object): ?string
    {
        return Generation::where('provider_reference', $object['id'])->value('id');
    }
}
