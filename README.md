# FormForge AI

## Live demo
- URL: https://formforge-ai-production.up.railway.app
- Demo credentials: none required for the demo flow

## What is included
- Manual form creation with schema-driven fields
- Public fill URL per form
- Server-side submission handling and CSV export
- Queued AI-assisted schema generation and AI editing for existing forms
- Document import workflow for Word and Excel uploads
- Forms index page with View, Edit, and AI Edit actions

## Setup
1. Copy `.env.example` to `.env` and configure database credentials.
2. Set `GEMINI_API_KEY` in `.env`.
3. Run `composer install`.
4. Run `php artisan migrate --seed`.
5. Run `php artisan queue:work` (required for non-blocking AI generation).
6. Run `php artisan serve`.

## Environment variables
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `APP_URL`
- `QUEUE_CONNECTION` (recommended: `database`)
- `GEMINI_API_KEY`
- `GEMINI_MODEL` (default: `gemini-2.5-flash`)
- After changing Gemini env values, run `php artisan optimize:clear` and `php artisan queue:restart`

## AI generation and editing flow
1. User enters an AI prompt in form builder (new form or existing form edit).
2. `POST /ai/generate` stores a `queued` log in `ai_generation_logs` and dispatches `GenerateFormSchemaJob`.
3. Frontend polls `GET /ai/logs/{id}` for status (`queued`, `processing`, `completed`, `failed`).
4. Job calls Gemini, validates and repairs output, retries up to 3 attempts, then applies fallback if needed.
5. Only schema-valid output is returned to UI and persisted (when editing existing forms).
6. `ai_generation_logs` stores model, tokens, latency, attempts, and retry/fallback metadata.

## Prompt strategy (documented contract)
### System prompt
- Force JSON-only output (no prose / markdown).
- Define strict schema contract and allowed field types.
- Require sensible labels/placeholders/validations.
- Require unique keys for all non-heading fields.

### User prompt mode
- Create mode: natural-language prompt only.
- Edit mode: natural-language instruction plus existing schema JSON so the model modifies current form instead of recreating blindly.

### Output contract
The model must return:
- `title`: string
- `description`: optional string
- `fields`: array of field objects

Field object shape:
- `id`: string
- `type`: one of `text`, `textarea`, `number`, `email`, `phone`, `date`, `file`, `rating`, `dropdown`, `radio`, `checkbox`, `heading`, `url`
- `label`: string
- `key`: required for non-heading
- `required`: boolean
- optional: `placeholder`, `helpText`, `defaultValue`, `options`, `maxRating`, `validation`

## Handling malformed JSON and hallucinations
- Parse direct JSON, fenced JSON, or first JSON object in mixed output.
- Attempt lightweight repair (e.g., remove trailing commas, normalize smart quotes).
- Normalize field types and map hallucinated aliases:
  - `tel` -> `phone`
  - `select` -> `dropdown`
  - `short_text` -> `text`
  - `long_text` -> `textarea`
  - `multiple_choice` -> `radio`
  - `multi_select` -> `checkbox`
  - unknown type -> `text`
- Enforce unique keys and minimum options for choice fields.
- Never persist broken schemas.

## Retry and fallback strategy
- Up to 3 model attempts.
- Each retry includes the previous validation error in prompt context.
- If all attempts fail:
  - edit mode fallback: normalized existing schema
  - create mode fallback: deterministic minimal valid schema inferred from prompt keywords

## Queued non-blocking behavior
- AI generation is never performed inside the request-response cycle.
- UI receives immediate `202 Accepted` and polls status.
- Queue worker performs LLM call and updates status/log metadata.

## Data model summary
- `forms`: core form definition and public UUID
- `form_versions`: version snapshots before manual/AI edits
- `form_submissions`: answer payloads per form submission
- `ai_generation_logs`: prompt, model, token, latency, status, metadata
- `form_imports`: import metadata and source files
