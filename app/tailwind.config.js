/** @type {import('tailwindcss').Config} */

// Every colour is a design token: a CSS variable holding "R G B" channels,
// defined for the light and the dark theme in public/assets/css/input.css.
// Views use the semantic names below (bg-surface, text-muted, bg-accent...)
// and never raw palette colours, so a theme change is one place.
const token = (name) => `rgb(var(--jp-${name}) / <alpha-value>)`;
const tone = (name) => ({
  DEFAULT: token(name),
  soft: token(`${name}-soft`),
  text: token(`${name}-text`),
});

module.exports = {
  // Class names must appear literally in one of these files (never build
  // them as "bg-{$x}-500"). src/Support/Ui.php holds the shared components.
  content: [
    './views/**/*.php',
    './public/**/*.php',
    './public/assets/js/**/*.js',
    './src/Support/Ui.php',
    './src/Support/Chart.php',
    './src/View.php',
    // The preview's chart gallery (absent on a server; Tailwind skips it).
    '../dev/preview/gallery/*.php',
  ],
  // theme.js resolves "system" and always sets data-theme on <html>.
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      colors: {
        canvas: token('canvas'),
        surface: {
          DEFAULT: token('surface'),
          2: token('surface-2'),
          3: token('surface-3'),
        },
        line: {
          DEFAULT: token('line'),
          strong: token('line-strong'),
        },
        fg: token('fg'),
        muted: token('muted'),
        subtle: token('subtle'),
        accent: {
          DEFAULT: token('accent'),
          hover: token('accent-hover'),
          fg: token('accent-fg'),
          soft: token('accent-soft'),
          text: token('accent-text'),
        },
        success: tone('success'),
        warning: tone('warning'),
        danger: tone('danger'),
        info: tone('info'),
      },
      fontFamily: {
        // Bundled (public/assets/fonts, SIL OFL); the system faces stand in until they load.
        sans: ['"IBM Plex Sans"', 'ui-sans-serif', 'system-ui', '-apple-system', '"Segoe UI"', 'Roboto', 'Arial', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'ui-monospace', 'SFMono-Regular', '"SF Mono"', 'Menlo', 'Consolas', 'monospace'],
      },
      boxShadow: {
        card: 'var(--jp-shadow-card)',
        pop: 'var(--jp-shadow-pop)',
      },
      maxWidth: {
        page: '84rem',
      },
    },
  },
  plugins: [],
};
