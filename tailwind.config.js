// Builds resources/dist/log-explorer-standalone.css for the standalone page
// (`npm ci && npm run build:css`). Re-run after changing Tailwind classes in
// the views or in log-explorer.js. The standalone page used to load the
// Tailwind Play CDN at runtime; that CDN sends no CORS headers, so it cannot
// be integrity-checked, and the compiled file ships with the package instead.
module.exports = {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
        './resources/dist/log-explorer.js',
    ],
    plugins: [require('@tailwindcss/forms')],
};
