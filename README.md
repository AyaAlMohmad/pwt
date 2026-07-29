# PWT API Backend — Claude Code Setup Pack

This pack contains everything Claude Code needs to build the PWT Digital Platform Laravel API backend from scratch.

## What's in this pack

```
pwt-claude-code/
├── CLAUDE.md                              ← Permanent project memory (auto-loaded by Claude Code)
├── prompt-claude-code-plan-mode.md        ← The kickoff prompt to paste into Plan Mode
└── .claude/
    └── agents/
        ├── api-architect.md               ← Designs OpenAPI contracts (run FIRST per module)
        ├── laravel-builder.md             ← Implements migrations / models / controllers / services
        ├── test-engineer.md               ← Writes Pest tests, achieves 80%+ coverage
        └── api-verifier.md                ← Final pass: Swagger + Postman MCP + Newman + report
```

## How to use

1. **Create a new project folder** for the Laravel backend (e.g. `C:\xampp\htdocs\pwt-api`).

2. **Copy the three source files** into that folder:
   - `PWTDigitalPlatform_Scope.pdf`
   - `comments.xlsx`
   - `Database for devlpt.xlsx`

3. **Copy the contents of this pack** into the same folder. The structure should look like:
   ```
   pwt-api/
   ├── CLAUDE.md
   ├── prompt-claude-code-plan-mode.md
   ├── PWTDigitalPlatform_Scope.pdf
   ├── comments.xlsx
   ├── Database for devlpt.xlsx
   └── .claude/
       └── agents/
           ├── api-architect.md
           ├── laravel-builder.md
           ├── test-engineer.md
           └── api-verifier.md
   ```

4. **Create the MySQL database** (XAMPP):
   - Open phpMyAdmin → New → name: `pwt` → Create.
   - Or run: `mysql -uroot -e "CREATE DATABASE pwt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`

5. **Launch Claude Code** inside the project folder:
   ```bash
   cd /path/to/pwt-api
   claude
   ```
   Claude Code will automatically pick up `CLAUDE.md` and the four sub-agents.

6. **Enable Plan Mode** (press `Shift+Tab` until "plan mode" appears in the status bar).

7. **Open** `prompt-claude-code-plan-mode.md` and paste its **entire content** as your first message. (You can paste it directly, no need to attach as a file.)

8. **Wait for the plan**. Claude Code will produce a multi-phase build plan and reply with:
   > "Plan ready for approval — see breakdown above. Awaiting your green light to begin Phase 0."

9. **Review the plan**. Reject / amend if needed. Once you reply with approval (e.g. *"approved, begin"*), Claude Code will exit Plan Mode and start building module-by-module, dispatching the four sub-agents.

## What you'll get at the end

- A working Laravel 11 API at `http://127.0.0.1:8000`
- Swagger UI at `http://127.0.0.1:8000/api/documentation` with every endpoint annotated
- ~80+ REST endpoints under `/api/v1` (customer) and `/api/v1/admin` (admin)
- MySQL `pwt` database with 30+ tables and 227 real seed machines + ~1,800 historical maintenance visits + demo users
- Pest test suite with ≥ 80% coverage
- A Postman collection in your **"PWT API"** Postman workspace (synced via the Postman MCP)
- A Newman end-to-end run report
- A delivery report at `docs/verification/delivery-report.md`

## Prerequisites checklist

- [ ] PHP 8.2+ on PATH (XAMPP's PHP works — add `C:\xampp\php` to PATH)
- [ ] Composer 2.x on PATH
- [ ] MySQL running (XAMPP control panel → start MySQL)
- [ ] Node.js + npm (for Newman in the final verification step)
- [ ] Claude Code installed (latest)
- [ ] Postman account with the Postman MCP server connected to your Claude account (already done per your environment)

## Tips

- **Don't run multiple Plan-Mode prompts in the same session.** Once you approve the plan, let Claude Code execute it. Open a new session only if you want a fresh plan.
- **Re-read `CLAUDE.md`** at any point by asking Claude Code to dump it — it's the source of truth for conventions.
- **If a sub-agent disagrees with a previous one**, the orchestrator escalates to you for the decision. Don't be surprised by a clarification question.
- **PayFort credentials**: when you eventually have them, drop them in `.env` (`PAYFORT_MERCHANT_ID`, `PAYFORT_ACCESS_CODE`, `PAYFORT_SHA_REQUEST_PHRASE`, `PAYFORT_SHA_RESPONSE_PHRASE`, `PAYFORT_WEBHOOK_SECRET`) and switch `PAYMENT_DRIVER=payfort`. The stub will be swapped without code changes.
- **Adding more languages later**? `spatie/laravel-translatable` already supports it — just add to `APP_SUPPORTED_LOCALES`.

## What if Claude Code skips a step?

- Check `CLAUDE.md` is in the project root and visible in the file tree.
- Check `.claude/agents/` is in the project root, not in a subfolder.
- In Plan Mode, explicitly ask: *"Re-read CLAUDE.md and the three source files, then continue."*

Happy building. 🚀
