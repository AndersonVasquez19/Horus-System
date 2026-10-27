/** @type {import('tailwindcss').Config} */
export default {
  content: ['./resources/views/**/*.blade.php', './resources/js/**/*.js'],
  theme: {
    extend: {
      colors: {
        gold: {
          DEFAULT: '#C9A961',
          dark: '#B8956A',
          light: '#E8DCC4',
        },
        ink: '#0A2540',
      },
      fontFamily: {
        sans: ['Poppins', 'sans-serif'],
      },
      boxShadow: {
        gold: '0 12px 40px rgba(201, 169, 97, 0.2)',
      },
    },
  },
  plugins: [],
};
