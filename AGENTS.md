<!-- LOVABLE:BEGIN -->
> [!IMPORTANT]
> This project is connected to [Lovable](https://lovable.dev). Avoid rewriting
> published git history — force pushing, or rebasing/amending/squashing commits
> that are already pushed — as it rewrites history on Lovable's side and the
> user will likely lose their project history.
>
> Commits you push to the connected branch sync back to Lovable and show up in
> the editor, so keep the branch in a working state.
<!-- LOVABLE:END -->

- The cloned KHWWC app (src/SawaApp.tsx) renders client-only via src/routes/index.tsx and $.tsx; its react-router-style imports go through src/lib/router-compat.tsx (TanStack-backed shim) — keeps the original source nearly untouched.
- Styling uses the legacy tailwind.config.js via @config in src/styles.css — preserves the original design classes.
- App appearance is owned by the root AppearanceProvider and semantic CSS tokens; existing legacy color classes are mapped to those tokens so every cloned page shares the theme without changing API contracts.
- Store the morning screen's last displayed Nairobi calendar day only in device storage; this is a presentation preference, not business data.
- Dashboard presentation consumes existing API response fields only; never estimate financial totals, growth or account details from unrelated values because live records must remain trustworthy.
- Member payment destinations are shared in a browser-safe module; the welfare page reuses the existing monthly contribution payment contract while penalties and fund drives retain their existing selection pages.
