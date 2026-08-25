/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./views/**/*.php', './public/**/*.php'],
  // Dynamically built as `bg-${accent}-600` etc. in views/partials/shell.php
  // (accent is either "indigo" for WHM or "sky" for cPanel) - these can't be
  // discovered by content-scanning since the class name is assembled at
  // render time, so they're safelisted explicitly here.
  safelist: [
    'from-indigo-500', 'to-indigo-700', 'text-indigo-400', 'bg-indigo-950', 'border-indigo-800', 'bg-indigo-600',
    'from-sky-500', 'to-sky-700', 'text-sky-400', 'bg-sky-950', 'border-sky-800', 'bg-sky-600',
    // whm/server_config/php_versions.php status badges: text-${color}-700 / bg-${color}-500
    'text-emerald-700', 'bg-emerald-500', 'text-amber-700', 'bg-amber-500',
    'text-red-700', 'bg-red-500', 'text-slate-700', 'bg-slate-500',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
