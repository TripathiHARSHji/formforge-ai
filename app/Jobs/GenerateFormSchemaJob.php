<?php

namespace App\Jobs;

use App\Models\AiGenerationLog;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\AiFormSchemaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateFormSchemaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(private readonly int $logId)
    {
    }

    public function handle(AiFormSchemaService $service): void
    {
        $log = AiGenerationLog::find($this->logId);
        if (! $log) {
            return;
        }

        $form = $log->form_id ? Form::find($log->form_id) : null;

        $log->update(['status' => 'processing']);

        try {
            $result = $service->generate($log->prompt, $form?->schema);

            if ($form) {
                FormVersion::create([
                    'form_id' => $form->id,
                    'schema' => $form->schema ?? ['fields' => []],
                    'note' => 'AI edit: ' . mb_substr($log->prompt, 0, 120),
                ]);

                $form->update([
                    'title' => $result['schema']['title'] ?? $form->title,
                    'description' => $result['schema']['description'] ?? $form->description,
                    'schema' => $result['schema'],
                ]);
            }

            $log->update([
                'model' => $result['model'] ?? $log->model,
                'tokens_used' => $result['tokens_used'] ?? $log->tokens_used,
                'latency_ms' => $result['latency_ms'] ?? $log->latency_ms,
                'status' => 'completed',
                'metadata' => array_merge($log->metadata ?? [], $result['metadata'] ?? [], [
                    'attempts' => $result['attempts'] ?? 1,
                    'schema' => $result['schema'],
                ]),
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'metadata' => array_merge($log->metadata ?? [], [
                    'error' => $e->getMessage(),
                ]),
            ]);

            throw $e;
        }
    }
}
