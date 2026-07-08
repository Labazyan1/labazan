import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const blog = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/blog' }),
  schema: z.object({
    title: z.string(),
    // Короткий SEO-заголовок для <title>, если title слишком длинный для выдачи.
    metaTitle: z.string().optional(),
    description: z.string(),
    date: z.coerce.date(),
    tag: z.string().default('Статья'),
  }),
});

export const collections = { blog };
