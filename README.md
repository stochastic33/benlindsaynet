# benlindsaynet

My portfolio site, live at [benlindsay.net](https://benlindsay.net) and hosted on hosting.com.

Plain HTML, CSS and JavaScript with no build step, plus a small PHP backend for the
AI-powered Product Manager Simulator.

## Structure

```
index.html                  Home: hero, about, selected work, capabilities, knowledge base, contact
play.html                   Product Manager Simulator
case-studies/               One page per case study
css/styles.css              Site styles (dark default, light theme)
css/sim.css                 Simulator styles
js/main.js                  Menu, theme switch, animations, hidden admin entrance
js/orb.js                   3D data sphere in the hero
js/videos.js                YouTube book summaries shown in the Knowledge base (edit to add videos)
js/knowledge.js             Renders the Knowledge base cards
js/pm-sim.js                Simulator front end
api/pm-sim.php              Simulator API endpoint
api/pmsim-admin.php         Hidden admin page entry (video library)
api/pmsim-check.php         Setup checker
private/pmsim/              Game engine source (deployed OUTSIDE public_html)
templates/                  Starting point for new case studies
```

## Deploying

- Everything except `private/` and `templates/` goes in `public_html`.
- `private/pmsim/` goes in a `pmsim` folder next to `public_html`, together with the
  Anthropic PHP library (`composer install` in that folder creates `vendor/`).
- `pmsim/config.php` holds the API key and admin password. It is never committed.

## Adding a case study

1. Copy `templates/case-study-template.html` into `case-studies/`.
2. Replace the bracketed placeholder text.
3. Point a card's "Read case study" link in `index.html` to the new file.
