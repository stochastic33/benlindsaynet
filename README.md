# benlindsaynet

My portfolio site, live at [benlindsay.net](https://benlindsay.net) and hosted on hosting.com.

Plain HTML, CSS and JavaScript with no build step, plus a small PHP backend for the
AI-powered Product Manager Simulator.

## Structure

The website lives in `public/`. Enhance's GitHub deploy uses `public` as the
publish directory, so only that folder is served to visitors.

```
public/                       The website (Enhance publish directory)
  index.html                  Home: hero, about, work, capabilities, knowledge base, contact
  play.html                   Product Manager Simulator
  case-studies/               One page per case study
  css/styles.css, sim.css     Site and simulator styles
  js/main.js                  Menu, theme switch, animations, hidden admin entrance
  js/orb.js                   3D data sphere in the hero
  js/videos.js                YouTube book summaries (edit to add videos)
  js/knowledge.js             Renders the Knowledge base cards
  js/pm-sim.js                Simulator front end
  img/                        Link preview images
  api/                        Simulator API, hidden admin entry, setup checker
private/pmsim/                Game engine source (runs from ~/pmsim on the server)
templates/                    Starting point for new case studies
```

## Deploying

- Pushing to `main` deploys automatically. In Enhance, the GitHub deploy's publish
  directory is set to `public`, which becomes the website's document root.
- The engine runs from a `pmsim` folder in the website's home folder (next to `deploys/`),
  together with the Anthropic PHP library (`composer install` in that folder creates `vendor/`).
- `pmsim/config.php` holds the API key and admin password. It is never committed.

## Adding a case study

1. Copy `templates/case-study-template.html` into `public/case-studies/`.
2. Replace the bracketed placeholder text.
3. Point a card's "Read case study" link in `public/index.html` to the new file.
