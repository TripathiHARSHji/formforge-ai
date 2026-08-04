<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormImport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:docx,xlsx',
        ]);

        $path = $request->file('file')->store('imports');
        $type = $request->file('file')->extension() === 'docx' ? 'word' : 'excel';

        $form = Form::create([
            'title' => 'Imported form from ' . Str::upper($type),
            'description' => 'Imported from uploaded document',
            'schema' => [
                'title' => 'Imported form',
                'fields' => [
                    ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true],
                ],
            ],
            'public_uuid' => (string) Str::uuid(),
            'status' => 'draft',
        ]);

        $import = FormImport::create([
            'form_id' => $form->id,
            'source_type' => $type,
            'file_path' => $path,
            'status' => 'completed',
        ]);

        return redirect()->route('forms.show', $form)->with('status', 'Import completed.');
    }
}
