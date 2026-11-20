# benlindsaynet

My portfolio site, live at [benlindsay.net](https://benlindsay.net) and hosted on hosting.com.

Plain HTML, CSS and JavaScript with no build step, plus a small PHP backend for the
AI-powered Product Manager Simulator.

## Structure

The website lives in `public/`. Only that folder is copied to the live site.

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

Pushing to `main` deploys automatically through Enhance's GitHub deploy. Each deploy
is cloned into `~/deploys/<commit>`, then this build script (set in Enhance under
GitHub → Configure → Build script) copies `public/` into `~/public_html`:

```
set -e
DEST="$(cd ../.. && pwd)/public_html"
if [ -d "$DEST" ] && [ -d public ]; then
  cp -R public/. "$DEST"/
  echo "Copied public/ to $DEST"
else
  echo "public_html or public/ not found; nothing copied"
  exit 1
fi
```

The copy adds and updates files but never deletes, so a file removed from the repo
must also be deleted from `public_html` by hand.

The game engine runs from `~/pmsim` (next to `public_html` and `deploys/`), together
with the Anthropic PHP library (`composer install` in that folder creates `vendor/`)
and `config.php`, which holds the API key and admin password and is never committed.
Deploys never touch `~/pmsim`; update it by hand from `private/pmsim/` when the engine changes.

## Adding a case study

1. Copy `templates/case-study-template.html` into `public/case-studies/`.
2. Replace the bracketed placeholder text.
3. Point a card's "Read case study" link in `public/index.html` to the new file.
