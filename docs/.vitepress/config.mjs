import { defineConfig } from 'vitepress';

const base = process.env.DOCS_BASE || (process.env.GITHUB_ACTIONS === 'true' ? '/docs/' : '/');

export default defineConfig({
	title: 'ConfigOps',
	description: 'See what WordPress and plugin settings changed, then undo matching values—even without a dedicated adapter.',
	lang: 'en-US',
	base,
	cleanUrls: true,
	lastUpdated: true,
	sitemap: {
		hostname: 'https://configops.pyrra.net/docs/',
	},
	head: [
		['link', { rel: 'icon', type: 'image/png', href: `${base}favicon.png` }],
		['meta', { name: 'theme-color', content: '#0b1424' }],
		['meta', { name: 'color-scheme', content: 'light dark' }],
	],
	markdown: {
		theme: { light: 'github-light', dark: 'github-dark' },
		lineNumbers: true,
	},
	themeConfig: {
		siteTitle: 'ConfigOps / Docs',
		nav: [
			{ text: 'Use ConfigOps', link: '/guide/getting-started' },
			{ text: 'Safety', link: '/security/secrets-privacy' },
			{ text: 'Reference', link: '/reference/support' },
			{ text: 'v0.5.0', link: '/releases/0.5.0' },
		],
		sidebar: [
			{
				text: 'Use ConfigOps',
				items: [
					{ text: 'Install & record', link: '/guide/getting-started' },
					{ text: 'Observe a change', link: '/guide/first-capture' },
					{ text: 'Read the evidence', link: '/guide/read-change' },
					{ text: 'Undo safely', link: '/guide/undo-safely' },
					{ text: 'Automation & agents', link: '/guide/automation' },
				],
			},
			{
				text: 'Trust boundaries',
				items: [
					{ text: 'Secrets & privacy', link: '/security/secrets-privacy' },
					{ text: 'Failure model', link: '/security/failure-model' },
				],
			},
			{
				text: 'Reference',
				items: [
					{ text: 'Support contracts', link: '/reference/support' },
					{ text: 'Operations', link: '/reference/operations' },
					{ text: 'Known limits', link: '/reference/limits' },
				],
			},
			{
				text: 'Build & extend',
				items: [
					{ text: 'Architecture', link: '/architecture' },
					{ text: 'Testing evidence', link: '/testing' },
					{ text: 'Adapter contracts', link: '/adapters' },
					{ text: 'Frontend architecture', link: '/frontend' },
					{ text: 'WordPress.org release', link: '/wordpress-org-release' },
				],
			},
			{
				text: 'Releases',
				items: [
					{ text: '0.5.0', link: '/releases/0.5.0' },
					{ text: '0.4.3', link: '/releases/0.4.3' },
					{ text: '0.4.2', link: '/releases/0.4.2' },
					{ text: '0.4.1', link: '/releases/0.4.1' },
					{ text: '0.4.0', link: '/releases/0.4.0' },
					{ text: '0.3.1', link: '/releases/0.3.1' },
					{ text: '0.3.0', link: '/releases/0.3.0' },
					{ text: '0.2.0', link: '/releases/0.2.0' },
					{ text: '0.1.0', link: '/releases/0.1.0' },
				],
			},
		],
		search: {
			provider: 'local',
			options: {
				detailedView: true,
			},
		},
		outline: { level: [2, 3], label: 'On this page' },
		lastUpdated: {
			text: 'Page updated',
			formatOptions: { dateStyle: 'medium' },
		},
		footer: {
			message: 'Local evidence. Explicit limits. No account required.',
			copyright: 'ConfigOps by pyrra · GPL-2.0-or-later',
		},
		docFooter: {
			prev: 'Previous page',
			next: 'Next page',
		},
	},
});
