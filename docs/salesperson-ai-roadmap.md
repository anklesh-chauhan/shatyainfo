# Salesperson assistant roadmap

These features draft the next step for a field salesperson. They quote visits, tour plans, sample distributions, follow-ups, leads, and deals that are already stored. They do not submit a tour plan, approve a DCR, override an SGIP limit, or invent a product claim.

Build and ship them in this order.

| Order | Feature | Status | Where the salesperson uses it |
| --- | --- | --- | --- |
| 1 | Pre-call briefing | Available | Visit screen, and `GET /api/v1/visits/{visit}/briefing` |
| 2 | Voice note | Available | Visit screen “Voice note”, `POST /api/v1/visits/{visit}/voice-note`, then `POST /api/v1/visits/{visit}/voice-note/apply` |
| 3 | Today’s priority list | Available | Sales Assistant page, and `GET /api/v1/todays-priorities` |
| 4 | Next month tour-plan draft | Available | Sales Assistant page, and `GET /api/v1/tour-plan-draft` |
| 5 | Sample suggestion inside SGIP limits | Available | Visit briefing, and `GET /api/v1/visits/{visit}/sgip/suggestions` |
| 6 | Follow-up script and date | Available | Follow-up screen, and `GET /api/v1/follow-ups/{followUp}/script` |
| 7 | Day route order | Available | Sales Assistant page, and `GET /api/v1/day-route` |
| 8 | Lead and deal next action | Available | Lead and deal screens, `GET /api/v1/leads/{lead}/next-action`, `GET /api/v1/deals/{deal}/next-action` |

The field API also exposes `GET /api/v1/salesperson-ai/roadmap` with the same list.

## Rules for later changes

- A draft is saved only when the salesperson applies it.
- Sample suggestions stop at the quantity already allowed by `SgipLimit`. Submitted and approved distributions count toward that quantity.
- Call notes, scripts, and briefings repeat recorded remarks, outcomes, and interactions. They do not add dosing, claims, or clinical statements.
- Route order uses patch coordinates when they exist, and patch sequence when they do not. It does not change the approved tour plan.
- A language-model narrator can be added later only as a rewrite of these same facts.
