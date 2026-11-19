# portfolioSite

Ben Lindsay's public portfolio website, served at [benlindsay.net](https://benlindsay.net) with GitHub Pages.

## Structure

```
index.html              Home page: hero, about, selected work, skills, contact
case-studies/           One HTML page per case study
css/styles.css          All styling, including light and dark themes
js/main.js              Mobile menu, theme switch, footer year
assets/                 Images and resume.pdf
CNAME                   Custom domain for GitHub Pages
```

Plain HTML, CSS and JavaScript, with no build step. Edit a file, commit to `main`, and GitHub Pages publishes it within a minute or two.

## Adding a case study

1. Copy `case-studies/project-one.html` to a new file, such as `case-studies/my-project.html`.
2. Replace the bracketed placeholder text.
3. Point a card's "Read case study" link in `index.html` to the new file.
