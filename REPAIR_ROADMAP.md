# TBot Gateway — Repair & Stabilization Roadmap

## Goal
Turn TBot Gateway into a safe, stable and scalable WordPress SaaS plugin for Telegram AI bots with credits, plans, payments, memory, analytics and interoperable AI providers.

## Milestone 1 — Core Stable
- Fix admin submenu registration.
- Make `tbot_user_state` the source of truth for user state.
- Repair `/start`, `/balance`, `/topup`, `/perfil`, `/help`, `/delete_data`.
- Connect referral flow end-to-end.
- Connect dormant feature modules: image, QR, short links, file converter, leaderboard, streak recovery.
- Add predictable error handling and user-facing fallback messages.

## Milestone 2 — Security Hardening
- Make Telegram webhook secret mandatory in production.
- Add settings sanitization and validation for every option.
- Add SecretManager for API keys and tokens.
- Block SSRF in URL-fetching tools.
- Escape Mini App user data and validate Telegram initData/auth_date.
- Restrict Docker services to localhost/private network.
- Remove secrets from logs.

## Milestone 3 — Payments & Credits
- Add idempotent `tbot_payment_transactions` table.
- Upgrade credit transactions into a real ledger with before/after balances.
- Harden Stripe webhook processing.
- Harden Telegram Stars processing.
- Activate plans through UserState, not scattered user_meta.
- Add refund/reversal handling.

## Milestone 4 — AI Provider Layer
- Add internal model catalog by task and plan.
- Prefer LiteLLM as the main AI gateway.
- Keep direct providers only as optional fallback.
- Track token cost, credit cost and provider errors.
- Add refund/no-charge logic when AI generation fails.
- Harden tool calling.

## Milestone 5 — Async & Scale
- Move AI processing out of the webhook request.
- Add job queue and worker: Action Scheduler, WP-CLI worker, or Redis queue.
- Add retries, dead-letter queue and rate limits by plan.
- Keep Telegram webhook responses fast.
- Add provider circuit breaker and timeout policy.

## Milestone 6 — Productization
- Complete Mini App dashboard: credits, plans, top-ups, referrals, history and settings.
- Add admin financial analytics: revenue, credits issued/used, model cost, gross margin.
- Add system health checks for Redis, Qdrant, ClickHouse, LiteLLM and Telegram.
- Add install guide, production checklist and test checklist.

## Execution rule
Do not add more channels or fancy features until this chain is reliable:

`/start → user created → credits assigned → AI response → credits deducted → payment processed → plan activated → memory saved → admin sees metrics`.
