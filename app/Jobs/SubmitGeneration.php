<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Integrations\Pixelforge\PixelforgeException;
use App\Integrations\Pixelforge\SyncGeneration;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $uniqueFor = 600;

    public function __construct(public Generation $generation) {}

    /**
     * Connection errors and retryable API errors are retried; the Idempotency-Key makes
     * that safe even if the first attempt was accepted but the response was never received.
     */
    public function handle(PixelforgeClient $client, SyncGeneration $sync): void
    {
        if ($this->generation->status !== GenerationStatus::PENDING) {
            return;
        }

        try {

            $response = $client->createGeneration($this->generation);

        } catch (PixelforgeException $e) {

            $hasAttemptsLeft = $this->attempts() < $this->tries;

            if ($e->isRetryable() && ($hasAttemptsLeft || ! $e->isExplicitRejection())) {
                $this->release($e->retryAfter ?? 5);

                return;
            }

            $this->generation->failIfPending($e->errorCode, $e->getMessage());

            return;
        }

        $id = $this->generation->getKey();

        Generation::whereKey($id)
            ->update([
                'provider_reference' => $response['id'],
            ]);

        // A replayed idempotent request can return a generation that already finished, so the
        // response goes through the same path as a webhook: that is what writes the charge.
        $sync($response);
    }

    public function uniqueId(): string
    {
        return $this->generation->getKey();
    }

    /**
     * Running out of attempts on an unexplained error does not fail the generation: the request
     * may have been accepted anyway. generations:reconcile resubmits it (idempotently) and only
     * gives up after an hour. An explicit rejection (e.g. overloaded) fails it on the last attempt.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 5, 10, 30, 60];
    }
}
