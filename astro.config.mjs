import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Боевой домен. Используется для канонических ссылок, sitemap и og:url.
// Сайт собирается как чистая статика для российского хостинга (Beget).
// Форма обрабатывается PHP-скриптом public/api/lead.php прямо на хостинге.
export default defineConfig({
  site: 'https://labazan.ru',
  output: 'static',
  integrations: [sitemap()],
  build: {
    // Встраиваем стили прямо в HTML — сайт не зависит от папки _astro при заливке.
    inlineStylesheets: 'always',
  },
});
