<?php

namespace App\Http\Controllers;

use App\Models\AiGenerationLog;
use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiController extends Controller
{
    public function generate(Request $request)
    {
        $data = $request->validate([
            'prompt' => 'required|string',
            'form_id' => 'nullable|exists:forms,id',
        ]);

        $schema = $this->buildSchemaFromPrompt($data['prompt']);

        $form = null;
        if (! empty($data['form_id'])) {
            $form = Form::find($data['form_id']);
            if ($form) {
                $form->update(['schema' => $schema]);
            }
        }

        AiGenerationLog::create([
            'form_id' => $form?->id,
            'prompt' => $data['prompt'],
            'model' => 'demo-gpt',
            'tokens_used' => 120,
            'latency_ms' => 250,
            'status' => 'completed',
            'metadata' => ['source' => 'heuristic'],
        ]);

        return response()->json(['schema' => $schema, 'message' => 'Schema generated.']);
    }

    private function buildSchemaFromPrompt(string $prompt): array
    {
        $lower = Str::lower($prompt);
        $fields = [];

        if (Str::contains($lower, 'email')) {
            $fields[] = ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'key' => 'email', 'required' => true];
        }

        if (Str::contains($lower, 'phone')) {
            $fields[] = ['id' => 'phone', 'type' => 'phone', 'label' => 'Phone', 'key' => 'phone', 'required' => false];
        }

        if (Str::contains($lower, 'resume') || Str::contains($lower, 'upload')) {
            $fields[] = ['id' => 'resume', 'type' => 'file', 'label' => 'Resume', 'key' => 'resume', 'required' => false];
        }

        if (empty($fields)) {
            $fields[] = ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true];
        }

        return [
            'title' => Str::title(str_replace(['with', 'and', 'the'], '', $prompt)),
            'fields' => $fields,
        ];
    }
}
