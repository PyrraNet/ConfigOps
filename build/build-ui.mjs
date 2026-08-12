import { build } from 'esbuild';
import { mkdir, rm } from 'node:fs/promises';

const outputDirectory = 'assets/ui';
await rm(outputDirectory, { recursive: true, force: true });
await mkdir(outputDirectory, { recursive: true });

await build({
	entryPoints: { runtime: 'ui/runtime.js' },
	outdir: outputDirectory,
	bundle: true,
	splitting: true,
	format: 'esm',
	target: ['es2020'],
	platform: 'browser',
	jsxFactory: 'wp.element.createElement',
	jsxFragment: 'wp.element.Fragment',
	chunkNames: 'chunks/[name]-[hash]',
	entryNames: '[name]',
	assetNames: 'assets/[name]-[hash]',
	minify: false,
	keepNames: true,
	sourcemap: false,
	treeShaking: true,
	legalComments: 'inline',
	lineLimit: 120,
	logLevel: 'info',
	metafile: true,
	outExtension: { '.js': '.js' },
	define: {
		'process.env.NODE_ENV': '"production"',
	},
});
