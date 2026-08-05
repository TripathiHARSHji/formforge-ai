# DECISIONS.md

## Purpose
This document records my assumptions, Part D choices, trade-offs, and what I would build next with two more weeks.

## Assumptions
1. Parts A-C are already implemented to a working MVP level (form creation, submissions, AI-assisted generation/editing, and imports).
2. The product is currently a single-instance Laravel deployment with queue-based AI jobs.
3. The near-term goal is to increase production readiness and real user value rather than add broad but shallow feature count.
4. The target users are teams that need quick internal forms, public sharing, and reliable response collection.

## Part D Choices (3 Improvements)

### 1) Conditional Logic and Branching (Product)
User problem:
- Long forms ask irrelevant questions and reduce completion rate.
- Users need dynamic forms that adapt based on previous answers.

Implementation approach:
- Add rule blocks to schema (if/then conditions) for field visibility and required-state toggles.
- Evaluate rules client-side for instant UX and server-side during submission validation for integrity.
- Support basic operators first: equals, not_equals, contains, greater_than, less_than, in.

Why this choice:
- Highest direct impact on respondent experience and completion.
- Makes forms feel smart without requiring more pages or complexity.

Trade-offs accepted:
- More complex schema format and validation logic.
- Harder debugging when hidden fields depend on multiple upstream answers.

With more time:
- Add a visual rule builder and conflict detection.
- Add per-rule analytics to measure whether branches improve completion.

### 2) Completion and Drop-off Analytics (Product)
User problem:
- Form owners do not know where users abandon forms.
- Without funnel data, improving forms is guesswork.

Implementation approach:
- Track start, step/field progression, and completion events with session-level anonymous IDs.
- Provide dashboard cards: completion rate, median completion time, top drop-off fields.
- Add date range and form-version filters to distinguish schema changes over time.

Why this choice:
- Converts the product from "form collector" to "feedback optimization tool".
- Gives immediate, actionable insight to non-technical users.

Trade-offs accepted:
- Extra event storage and aggregation costs.
- Need clear privacy defaults and retention policy.

With more time:
- Build automated recommendations ("field X causes 40% exits").
- Add A/B comparison between form versions.

### 3) Public Submissions API + Webhooks (Engineering + Product)
User problem:
- Teams want submissions in CRM, Slack, Sheets, and internal tools in real time.
- Manual exports are slow and error-prone.

Implementation approach:
- Expose authenticated API endpoints for listing submissions and fetching by ID.
- Add outbound webhooks on submission create with signed payloads and retry/backoff.
- Include idempotency keys and delivery logs for troubleshooting.

Why this choice:
- Unlocks integrations and automation without requiring custom code in this app.
- Strong differentiator for production use beyond basic form builders.

Trade-offs accepted:
- Security surface increases (auth, signing, replay protection, abuse limits).
- Requires operational tooling for webhook failures and replays.

With more time:
- Add webhook event subscriptions, custom payload templates, and destination-level secret rotation.
- Publish API docs with examples and SDK snippets.

## Why These Three Together
- Conditional logic improves response quality.
- Analytics measures the impact and guides iteration.
- API/webhooks operationalize collected data into customer workflows.

Combined, they form a complete loop: better form UX -> measurable outcomes -> automated downstream action.

## What I Would Build Next With Two More Weeks
Week 1:
1. Ship conditional logic v1 with strict server validation parity.
2. Ship analytics event capture and baseline dashboard metrics.
3. Build migration-safe schema version tags for rule/analytics compatibility.

Week 2:
1. Ship submissions API v1 with token auth and pagination.
2. Ship webhook delivery system with retries, signatures, and logs.
3. Add rate limiting and spam controls (honeypot + per-IP throttling) to protect public forms.

## Additional Assumptions and Boundaries
1. Multi-tenant isolation is deferred for now; this scope assumes single-tenant or trusted internal deployment.
2. Advanced AI features (multilingual generation, template recommendations) are deferred until core analytics and integration foundations are stable.
3. Accessibility and CI hardening remain important and should proceed in parallel as quality tracks, but were not chosen as primary Part D differentiators for this submission.
