/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './public/assets/app.jsx',
    './public/index.php',
    './public/index.html',
  ],
  theme: {
    extend: {
      screens: { xs: '375px' },
    },
  },
  plugins: [],
}
