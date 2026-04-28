import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import laravel from 'laravel-vite-plugin';
import prefixSelector from 'postcss-prefix-selector';

export default defineConfig({
    plugins: [
        laravel({
            input: {
                'clearance-styles': 'resources/css/clearance.css',
            },
            refresh: true,
        }),
        tailwindcss(),
    ],
    css: {
        postcss: {
            plugins: [
                prefixSelector({
                    prefix: '.clearance',
                    exclude: [/^@/],
                    transform(prefix, selector, prefixedSelector) {
                        if (selector === prefix || /^\.clearance[\s,>~+]/.test(selector)) return selector;
                        if (/^(:root|:host)(,(:root|:host))*$/.test(selector.trim())) return prefix;
                        if (selector.includes(':where(.dark')) {
                            const darkMatch = selector.match(/:where\(.dark[^)]*\)/);
                            if (darkMatch) {
                                const darkPart = darkMatch[0];
                                const rest = selector.replace(darkPart, '').trim();
                                return rest ? `${darkPart} ${prefix} ${rest}` : `${darkPart} ${prefix}`;
                            }
                        }
                        return prefixedSelector;
                    },
                }),
            ],
        },
    },
    build: {
        manifest: false,
        outDir: 'src/dist',
        emptyOutDir: true,
        cssMinify: true,
        sourcemap: false,
        minify: true,
        rollupOptions: {
            output: {
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name && assetInfo.name.endsWith('.css')) {
                        return 'css/clearance.min.css';
                    }
                    return 'assets/[name]-[hash][extname]';
                },
            },
        },
    },
})
