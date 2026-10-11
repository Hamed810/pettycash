# UI acceptance matrix (T123 — human-only)

Manual Persian RTL, installed Firefox (115+ ESR), accessibility, and
representative-user first-attempt matrix. SC-008: not measured (post-V1).
FR-073 error-rate figure: per `quickstart.md` §8.2 with its 1% ceiling — not
asserted here. Browsers: Chrome 120+, Edge 120+ per FR-072 + installed
Firefox 115+ ESR. Themes: default, dark, high-contrast. Viewports: 1440×900
+ 390×844 (no horizontal scroll at 390px; status color/icon WCAG AA;
text ≥12pt Persian font; keyboard-only primary journeys with visible focus).

| # | Journey | Browser | Viewport | Theme | RTL | Focus visible | 1st-attempt | Result |
|---|---------|---------|----------|-------|-----|---------------|-------------|--------|
| 1 | Purchaser capture → submit | Firefox ESR | 1440×900 | default | n/a | yes |  | TODO |
| 2 | Purchaser capture → submit | Firefox ESR | 390×844 | default | RTL | yes |  | TODO |
| 3 | Manager review → decision | Chrome 120+ | 1440×900 | default | RTL | yes |  | TODO |
| 4 | Senior review → return | Chrome 120+ | 390×844 | dark | RTL | yes |  | TODO |
| 5 | Finance settlement | Edge 120+ | 1440×900 | default | RTL | yes |  | TODO |
| 6 | Correction → resubmit | Firefox ESR | 390×844 | high-contrast | RTL | yes |  | TODO |
| 7 | Reports → export | Chrome 120+ | 1440×900 | default | RTL | yes |  | TODO |
| 8 | Evidence upload → links | Edge 120+ | 390×844 | dark | RTL | yes |  | TODO |

Keyboard-only pass (no pointer): journeys 1, 3, 5 — record PASS/FAIL + focus
indicator present at every stop (computed outline-style ≠ none, width > 0).

Representative users (≥90% first-attempt): record n users, n pass, rate.
