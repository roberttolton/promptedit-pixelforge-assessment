<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Integrations\Pixelforge\PixelforgeException;
use App\Integrations\Pixelforge\SyncGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

#[Signature('generations:reconcile')]
#[Description('Recover Generations whose submission or webhooks were lost and therefore stuck as PENDING')]
class ReconcileGenerations extends Command
{
    private const int QUIET_MINUTES = 2;

    private const int GIVE_UP_AFTER_MINUTES = 60;

    private const int POLL_BATCH_SIZE = 20;

    public function handle(PixelforgeClient $client, SyncGeneration $sync): int
    {
        $resubmitted = $this->resubmitStuckPending();
        $polled = $this->pollActive($client, $sync);

        $this->components->info("Resubmitted {$resubmitted} stuck pending, polled {$polled} active. Only rows idle for ".self::QUIET_MINUTES.'+ minutes are touched.');

        return self::SUCCESS;
    }

    /**
     * Resubmitting is safe: the Idempotency-Key is the Generation's UUID, so if Pixelforge
     * already accepted it, that generation is returned and nothing is created or billed twice.
     */
    private function resubmitStuckPending(): int
    {
        $count = 0;

        Generation::where('status', GenerationStatus::PENDING)
            ->where('updated_at', '<', now()->subMinutes(self::QUIET_MINUTES))
            ->each(function (Generation $generation) use (&$count): void {
                $count++;

                if ($generation->created_at->lt(now()->subMinutes(self::GIVE_UP_AFTER_MINUTES))) {
                    $generation->failIfPending('submission_failed', 'We could not reach the image service. Nothing was charged.');

                    return;
                }

                SubmitGeneration::dispatch($generation);
            });

        return $count;
    }

    private function pollActive(PixelforgeClient $client, SyncGeneration $sync): int
    {
        $polled = 0;

        $generations = Generation::whereIn('status', [GenerationStatus::QUEUED, GenerationStatus::PROCESSING])
            ->whereNotNull('provider_reference')
            ->where('updated_at', '<', now()->subMinutes(self::QUIET_MINUTES))
            ->oldest('updated_at')
            ->limit(self::POLL_BATCH_SIZE)
            ->get();

        foreach ($generations as $generation) {
            $polled++;

            try {
                $sync($client->getGeneration($generation->provider_reference));
            } catch (PixelforgeException $e) {
                if ($e->errorCode === 'rate_limit_exceeded') {
                    return $polled;
                }

                Log::warning('Could not poll Pixelforge generation.', ['generation_id' => $generation->id, 'error' => $e->getMessage()]);
            } catch (ConnectionException) {
                return $polled;
            }

            // Move still-unfinished rows to the back of the queue so one slow job can't starve the rest.
            $generation->touch();
        }

        return $polled;
    }
}
